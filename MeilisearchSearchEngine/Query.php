<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/Query.php — de la requête Lucene au plan Meilisearch.
 *
 * CollectiveAccess analyse l'expression de recherche avec un parseur Lucene, la réécrit
 * (points d'accès, étiquettes, numéros d'inventaire) et passe au connecteur les deux formes :
 * la chaîne, et l'arbre `Zend_Search_Lucene_Search_Query_Boolean`. On travaille sur l'arbre,
 * plus fiable que la chaîne resérialisée.
 *
 * Meilisearch n'a pas de requête booléenne plein texte : une recherche, c'est un `q`, une
 * liste facultative d'attributs où chercher, et un filtre sur des attributs filtrables. On
 * traduit donc l'arbre Lucene en un *plan* de même forme : un arbre, dont les feuilles sont
 * des recherches Meilisearch et les nœuds des combinaisons ensemblistes — intersection pour
 * AND, union pour OR, retrait pour NOT.
 *
 * **L'arbre, et non une liste de clauses.** L'aplatir revenait à perdre les parenthèses :
 * `oil OR (canvas AND paper)` se retrouvait combiné de gauche à droite et ne voulait plus rien
 * dire. Le plan garde donc la structure, et l'évaluation la suit.
 *
 * Une seule mise en commun subsiste, parce qu'elle est exacte : plusieurs termes *exigés* dans
 * la même portée deviennent un seul `q` avec `matchingStrategy: all`, ce que Meilisearch fait
 * mieux qu'une intersection côté PHP — et le classement par pertinence y survit. Les termes
 * *facultatifs* (OR), eux, ne se mettent jamais en commun : un `q` à deux mots exigerait les
 * deux et rendrait l'intersection à la place de l'union.
 *
 * Le classement par pertinence est celui de la première feuille positive : c'est elle qui
 * porte le sens de la recherche, les autres la restreignent.
 * ---------------------------------------------------------------------- */

namespace Meilisearch;

require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Schema.php');

class Query {
	const OP_AND = 'and';   // signe Zend `true`  — exigé
	const OP_OR  = 'or';    // signe Zend `null`  — facultatif
	const OP_NOT = 'not';   // signe Zend `false` — exclu

	/** @var Schema */
	private $schema;

	/** @var array|null plan d'exécution : nœud racine, ou null si la requête ne restreint rien */
	private $plan = null;

	/** @var array feuilles du plan, à plat : chacune est une recherche Meilisearch à exécuter */
	private $leaves = [];

	/** @var array clauses, à plat — pour getSearchedTerms() et le diagnostic */
	private $clauses = [];

	/** @var array constructions rencontrées que Meilisearch ne sait pas rendre */
	private $unsupported = [];

	/** @var string|null table sujet, pour rattacher un champ tapé sans la sienne */
	private $subject_table = null;

	public function __construct(Schema $schema, string $search_expression, $rewritten_query, ?string $subject_table = null) {
		$this->schema        = $schema;
		$this->subject_table = ($subject_table !== null && $subject_table !== '') ? $subject_table : null;

		if ($rewritten_query !== null) {
			$this->plan = $this->nodeFrom($rewritten_query);
		}

		// Repli sur la chaîne quand l'arbre est absent (quickSearch, appels directs).
		if ($this->plan === null) {
			$this->plan = $this->fromString($search_expression);
		}
	}

	public function isMatchAll(): bool { return $this->plan === null; }
	public function getClauses(): array { return $this->clauses; }
	public function getUnsupported(): array { return $this->unsupported; }

	/**
	 * Le plan d'exécution. Deux formes de nœud :
	 *
	 *   ['type' => 'leaf', 'id' => int, 'params' => array]        une recherche Meilisearch
	 *   ['type' => 'bool', 'must' => [], 'should' => [], 'not' => []]   une combinaison
	 */
	public function getPlan(): ?array { return $this->plan; }

	/**
	 * Les recherches à exécuter, dans l'ordre de leur identifiant : un seul `/multi-search`
	 * suffit ensuite, quelle que soit la profondeur du plan.
	 */
	public function getLeafSearches(): array { return $this->leaves; }

	/**
	 * Termes cherchés, pour `getSearchedTerms()` du connecteur.
	 */
	public function getSearchedTerms(): array {
		$terms = [];
		foreach ($this->clauses as $c) {
			if ($c['op'] !== self::OP_NOT && strlen($c['text'])) { $terms[] = $c['text']; }
		}
		return array_values(array_unique($terms));
	}

	# -------------------------------------------------------
	# Construction du plan depuis l'arbre Zend
	# -------------------------------------------------------

	/**
	 * @return array|null nœud du plan, ou null si la sous-requête ne restreint rien.
	 */
	private function nodeFrom($query, string $inherited_op = self::OP_AND): ?array {
		if (!is_object($query)) { return null; }

		switch (get_class($query)) {
			case 'Zend_Search_Lucene_Search_Query_Boolean':
				return $this->boolNode($query->getSubqueries(), $query->getSigns(), $inherited_op,
					function ($sub, $op) { return $this->nodeFrom($sub, $op); });

			case 'Zend_Search_Lucene_Search_Query_MultiTerm':
				return $this->boolNode($query->getTerms(), $query->getSigns(), $inherited_op,
					function ($term, $op) { return $this->leafNode([$this->clause($term, $op, false)], $op); });

			case 'Zend_Search_Lucene_Search_Query_Term':
				$clause = $this->clause($query->getTerm(), $inherited_op, false);
				return $clause ? $this->leafNode([$clause], $inherited_op) : null;

			case 'Zend_Search_Lucene_Search_Query_Phrase':
				// Une phrase reste une phrase : les guillemets imposent à Meilisearch des mots
				// contigus, exacts, sans tolérance orthographique ni complétion.
				$clauses = [];
				foreach ($query->getTerms() as $term) {
					if ($c = $this->clause($term, $inherited_op, true)) { $clauses[] = $c; }
				}
				return sizeof($clauses) ? $this->leafNode($clauses, $inherited_op) : null;

			case 'Zend_Search_Lucene_Search_Query_Wildcard':
				// Meilisearch ne connaît pas les jokers ; il cherche déjà par préfixe sur le
				// dernier mot. On retire l'astérisque et on le signale.
				$pattern = $query->getPattern();
				$this->unsupported[] = 'joker « ' . $pattern->text . ' » traité comme une recherche par préfixe';
				$clause = $this->clause($pattern, $inherited_op, false);
				return $clause ? $this->leafNode([$clause], $inherited_op) : null;

			case 'Zend_Search_Lucene_Search_Query_Range':
				// Une clause jetée en silence, c'est une recherche qui rend plus large que
				// demandé sans que personne ne le sache. On le dit.
				$this->unsupported[] = 'recherche par intervalle, non traduite : la restriction n\'est pas appliquée';
				return null;

			case 'Zend_Search_Lucene_Search_Query_Insignificant':
			case 'Zend_Search_Lucene_Search_Query_Empty':
				return null;

			default:
				if (method_exists($query, 'getTerm')) {
					$clause = $this->clause($query->getTerm(), $inherited_op, false);
					return $clause ? $this->leafNode([$clause], $inherited_op) : null;
				}
				$this->unsupported[] = 'construction ' . get_class($query) . ' non traduite';
				return null;
		}
	}

	/**
	 * Un nœud de combinaison, construit à partir des sous-éléments et de leurs signes.
	 *
	 * @param array    $items   sous-requêtes ou termes
	 * @param mixed    $signs   signes Zend, alignés sur $items (true exigé, null facultatif, false exclu)
	 * @param string   $inherited_op portée héritée : sous une négation, tout est négation
	 * @param callable $make    fabrique le nœud d'un sous-élément, appelée avec ($item, $op)
	 */
	private function boolNode(array $items, $signs, string $inherited_op, callable $make): ?array {
		$node = ['type' => 'bool', 'must' => [], 'should' => [], 'not' => []];

		foreach ($items as $i => $item) {
			// Attention : un signe *facultatif* vaut `null`, ce qui n'est pas la même chose
			// qu'un signe absent. `??` confondrait les deux et ferait d'un OR un AND.
			$sign = (is_array($signs) && array_key_exists($i, $signs)) ? $signs[$i] : true;
			$op   = $this->signToOp($sign, $inherited_op);

			if (!($child = $make($item, $op))) { continue; }

			$node[$this->bucketFor($op)][] = $child;
		}

		if (!sizeof($node['must']) && !sizeof($node['should']) && !sizeof($node['not'])) { return null; }

		$this->mergeRequiredLeaves($node);

		return $node;
	}

	/**
	 * La catégorie où ranger un enfant. C'est le seul endroit où la négation est prise en
	 * compte : une feuille ne se nie pas elle-même, sans quoi elle serait niée deux fois — une
	 * fois par elle, une fois par le nœud qui la range, et « tout sauf ceci » rendrait ceci.
	 */
	private function bucketFor(string $op): string {
		if ($op === self::OP_NOT) { return 'not'; }
		return ($op === self::OP_OR) ? 'should' : 'must';
	}

	/**
	 * Fusionne les feuilles *exigées* de même portée en une seule recherche.
	 *
	 * C'est exact — `matchingStrategy: all` exige tous les mots — et c'est mieux qu'une
	 * intersection côté PHP : une requête de moins, et le classement par pertinence tient
	 * compte des deux mots au lieu du seul premier. On ne le fait jamais pour les facultatifs.
	 */
	private function mergeRequiredLeaves(array &$node): void {
		if (sizeof($node['must']) < 2) { return; }

		$fusionnables = [];
		$autres       = [];

		foreach ($node['must'] as $child) {
			if ($child['type'] !== 'leaf' || $child['phrase']) { $autres[] = $child; continue; }
			$fusionnables[$child['scope']][] = $child;
		}

		$node['must'] = $autres;
		foreach ($fusionnables as $groupe) {
			if (sizeof($groupe) === 1) { $node['must'][] = $groupe[0]; continue; }

			$clauses = [];
			foreach ($groupe as $feuille) {
				$clauses = array_merge($clauses, $feuille['clauses']);
				unset($this->leaves[$feuille['id']]);   // la feuille fusionnée ne sera pas exécutée
			}
			$node['must'][] = $this->leafNode($clauses, self::OP_AND);
		}
	}

	# -------------------------------------------------------
	# Feuilles
	# -------------------------------------------------------

	/**
	 * Une feuille : la recherche Meilisearch qui correspond à un ou plusieurs termes de même
	 * portée.
	 */
	private function leafNode(array $clauses, string $op): ?array {
		$clauses = array_values(array_filter($clauses));
		if (!sizeof($clauses)) { return null; }

		$field  = $clauses[0]['field'];
		$phrase = !empty($clauses[0]['phrase']);

		if ($phrase) {
			// Les guillemets imposent des mots contigus : la phrase se reconstitue telle quelle.
			$q = '"' . join(' ', array_column($clauses, 'text')) . '"';
		} else {
			$parts = [];
			foreach ($clauses as $c) {
				// Un terme « exact » est entouré de guillemets pour couper la complétion du
				// dernier mot — c'est ce qui distingue `CLE.1928.8` de `CLE.1928.856`.
				$parts[] = !empty($c['exact']) ? '"' . $c['text'] . '"' : $c['text'];
			}
			$q = join(' ', $parts);
		}

		$params = [
			'q'                    => $q,
			'limit'                => 2000000,
			'offset'               => 0,
			'attributesToRetrieve' => [Schema::PK],
			// `all` : tous les mots doivent être présents. C'est l'opérateur par défaut de
			// CollectiveAccess (LuceneSyntaxParser::B_AND), il faut le suivre — sinon une
			// recherche à deux mots ramènerait la moitié du fonds.
			'matchingStrategy'     => 'all',
		];
		if ($field !== null) { $params['attributesToSearchOn'] = [$field]; }

		$id = sizeof($this->leaves) ? (max(array_keys($this->leaves)) + 1) : 0;
		$this->leaves[$id] = $params;

		return [
			'type'    => 'leaf',
			'id'      => $id,
			'params'  => $params,
			'clauses' => $clauses,
			'scope'   => ($field === null ? '*' : $field),
			'phrase'  => $phrase,
			'op'      => $op,
		];
	}

	/**
	 * @param \Zend_Search_Lucene_Index_Term $term
	 * @return array|null clause, ou null si le terme ne restreint rien
	 */
	private function clause($term, string $op, bool $phrase): ?array {
		if (!is_object($term)) { return null; }

		$text = (string)$term->text;

		// `*` seul, ou vide : la requête ne restreint rien.
		if ($text === '' || $text === '*') { return null; }

		// Le suffixe `|` marque « ne pas raciniser » côté SqlSearch ; sans objet ici.
		$text = rtrim($text, '|');

		// Un joker explicite vaut demande de recherche par préfixe — ce que Meilisearch fait
		// nativement sur le dernier mot.
		$wildcard = (strpos($text, '*') !== false);
		$text     = str_replace('*', '', $text);
		// Les guillemets sont notre marqueur de correspondance exacte dans `q` ; un guillemet
		// resté dans le texte casserait la requête en silence. Vu sur un titre du corpus :
		// « Ivory Plaque … ("The Stroganoff Ivory") » rendait zéro résultat.
		$text     = str_replace(['"', '“', '”'], ' ', $text);
		$text     = trim($text, " \t\n\r\0\x0B");
		if ($text === '') { return null; }

		$field = (string)$term->field;

		$clause = [
			'field'  => $this->fieldToAttribute($field),
			'text'   => $text,
			'phrase' => $phrase,
			// Nommer un champ, c'est demander cette valeur — pas ses préfixes. Sans cela
			// Meilisearch complète le dernier mot et la valeur ne restreint plus rien :
			// `ca_objects.parent_id:6` rendait les 8 443 fiches dont le parent commence par 6,
			// au lieu des 26 dont il vaut 6, au chiffre près ce que rend `LIKE '6%'`. Même
			// raison pour un identifiant en recherche libre, où « CLE.1928.8 » ramenait aussi
			// « CLE.1928.856 ». Une troncature explicite reste une demande de préfixe.
			'exact'  => !$wildcard && ($this->isFieldScoped($field) || $this->looksLikeIdentifier($text)),
			'op'     => $op,
		];

		$this->clauses[] = $clause;
		return $clause;
	}

	/**
	 * Le terme vise-t-il un champ nommé, par opposition à la recherche libre ? Ce seul critère
	 * a remplacé le test « ce champ est-il le numéro d'inventaire de sa table », qu'il englobe :
	 * un numéro d'inventaire n'est qu'un cas particulier de champ dont la valeur doit
	 * correspondre, et rien ne justifiait que les autres champs, eux, soient élargis en préfixe.
	 */
	private function isFieldScoped(string $field): bool {
		$field = trim($field);
		return ($field !== '' && $field !== '_fulltext');
	}

	/**
	 * Un terme qui mêle chiffres et séparateurs — « CLE.1928.8 », « 2019-45/2 » — est un
	 * identifiant bien plus souvent qu'un mot du langage. On le cherche exactement, même en
	 * recherche libre : c'est là que le besoin se fait sentir, quand on tape un numéro relevé
	 * sur un objet sans préciser dans quel champ le chercher.
	 */
	private function looksLikeIdentifier(string $text): bool {
		return (bool)preg_match('![0-9]!', $text) && (bool)preg_match('![./_-]!', $text);
	}

	/**
	 * `ca_object_labels.name` → `ca_object_labels__name`. Un champ vide (recherche libre)
	 * donne null : on cherche alors dans tous les attributs.
	 */
	private function fieldToAttribute(string $field): ?string {
		$field = trim($field);
		if ($field === '' || $field === '_fulltext') { return null; }

		$field = str_replace('\\/', '/', $field);

		// Un champ tapé sans sa table — `parent_id:6`, `code_eglise:05R1000` — vise la table
		// sujet. Sans ce rattachement il était cherché sous son nom nu, qu'aucun document ne
		// porte, et la recherche rendait zéro : le pire des silences, puisqu'il se lit « il n'y
		// a rien de tel ». Au CIPAR, `parent_id:<édifice>` est la manière ordinaire de lister le
		// mobilier d'une église ; 1 347 fiches étaient devenues introuvables.
		if ($this->subject_table !== null && strpos(explode('|', $field)[0], '.') === false) {
			$field = $this->subject_table . '.' . $field;
		}

		// Le type de relation éventuel (`ca_entities.idno|creator`) est conservé : c'est un
		// attribut à part entière dans l'index (voir Document::fragment()).
		return $this->schema->accessPointToFieldName($field);
	}

	private function signToOp($sign, string $inherited_op): string {
		if ($sign === false) { return self::OP_NOT; }
		if ($sign === null)  { return ($inherited_op === self::OP_NOT) ? self::OP_NOT : self::OP_OR; }
		return ($inherited_op === self::OP_NOT) ? self::OP_NOT : self::OP_AND;
	}

	# -------------------------------------------------------
	# Repli : analyse sommaire de la chaîne
	# -------------------------------------------------------

	private function fromString(string $expression): ?array {
		$expression = trim($expression);
		if ($expression === '' || $expression === '*') { return null; }

		$node = ['type' => 'bool', 'must' => [], 'should' => [], 'not' => []];

		// `champ:valeur` ou `champ:"valeur avec espaces"`, sinon mots libres.
		if (preg_match_all('!([A-Za-z0-9_./\\\\|]+):("([^"]*)"|\S+)!', $expression, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $m) {
				$value    = isset($m[3]) && $m[3] !== '' ? $m[3] : trim($m[2], '"');
				$wildcard = (strpos($value, '*') !== false);
				$value    = str_replace('*', '', $value);
				if ($value === '') { $expression = str_replace($m[0], ' ', $expression); continue; }
				$clause = [
					'field'  => $this->fieldToAttribute($m[1]),
					'text'   => $value,
					'phrase' => (strpos($m[2], '"') === 0),
					'exact'  => !$wildcard && ($this->isFieldScoped($m[1]) || $this->looksLikeIdentifier($value)),
					'op'     => self::OP_AND,
				];
				$this->clauses[] = $clause;
				if ($feuille = $this->leafNode([$clause], self::OP_AND)) { $node['must'][] = $feuille; }
				$expression = str_replace($m[0], ' ', $expression);
			}
		}

		$rest = trim(preg_replace('![()\'"]+!', ' ', $expression));
		$rest = trim(preg_replace('!\b(AND|OR|NOT)\b!', ' ', $rest));

		$mots = [];
		foreach (preg_split('!\s+!', $rest, -1, PREG_SPLIT_NO_EMPTY) as $word) {
			$word = str_replace('*', '', $word);
			if ($word === '') { continue; }
			$clause = ['field' => null, 'text' => $word, 'phrase' => false,
			           'exact' => $this->looksLikeIdentifier($word), 'op' => self::OP_AND];
			$this->clauses[] = $clause;
			$mots[] = $clause;
		}
		if (sizeof($mots) && ($feuille = $this->leafNode($mots, self::OP_AND))) { $node['must'][] = $feuille; }

		return sizeof($node['must']) ? $node : null;
	}
}
