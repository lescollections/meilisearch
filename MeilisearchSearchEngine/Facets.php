<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/Facets.php — le pont entre le Browse et les facettes du moteur.
 *
 * Le BrowseEngine de CollectiveAccess calcule chaque facette par un GROUP BY, restreint au
 * résultat courant par une clause `IN (…)` portant la liste littérale des identifiants — à
 * 300 000 résultats et dix facettes, chaque page fabrique dix requêtes de plusieurs Mo. Ici,
 * la même question est posée à Meilisearch : les critères du browse deviennent un `filter`,
 * la distribution (`facetDistribution`) rend les comptes par valeur, et CollectiveAccess ne
 * fait plus que ce qu'il fait bien — convertir des identifiants en libellés.
 *
 * La règle de sûreté est unique et sans exception : **traduire exactement ou décliner**.
 * Toute option de facette, tout critère, tout état du browse qui ne se traduit pas à
 * l'identique fait rendre `null`, et le socle continue en SQL comme si ce fichier n'existait
 * pas. Un browse aux comptes faux est pire qu'un browse lent.
 *
 * Périmètre traduit (phase 1) : les types `fieldList`, `has` et `authority`, les restrictions
 * d'accès et de type/source du browse, et le critère `_search` quand l'expression est un
 * texte nu. Restent au SQL : `location`, `checkouts`, `violations`, `tags`, `dupeidno`,
 * `normalized*`, `attribute`, `label`, `field`, `hierarchy`, ainsi que `relative_to`,
 * `filter`, les ACL par enregistrement et les relations réflexives.
 *
 * Correspondance des sémantiques, vérifiée sur le code du socle :
 *   – un critère `authority` s'applique en SQL avec expansion descendante (bornes
 *     hiérarchiques, BrowseEngine::execute()) ; ici, chaque document porte les ancêtres de
 *     ses liens (`facet__ca_places` contient Addis-Abeba ET l'Abyssinie), le filtre d'égalité
 *     couvre donc les descendants par construction ;
 *   – les valeurs multiples d'une même facette s'intersectent (une par ensemble), sauf
 *     `multiple = 1` où elles s'unissent — comme dans execute() ;
 *   – le compte d'un ancêtre est ici le nombre de documents distincts sous sa branche, là où
 *     le SQL additionne les comptes des descendants (un objet lié à deux descendants y compte
 *     deux fois). Notre compte est le bon ; l'écart est toléré par le test d'équivalence.
 * ---------------------------------------------------------------------- */

namespace Meilisearch;

require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Schema.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Log.php');

class Facets {
	/** @var \WLPlugSearchEngineMeilisearch */
	private $engine;

	/** @var Schema */
	private $schema;

	/** @var \Db */
	private $db;

	/** @var array cache des listes de valeurs : [list_code] => [items par id, items par valeur, tri] */
	private static $list_cache = [];

	/**
	 * Pourquoi le dernier appel a décliné.
	 *
	 * Décliner est sûr, mais opaque : « le SQL a repris la main » ne dit pas s'il s'agit d'un
	 * type qu'on ne traduit pas encore, d'un champ qu'on n'a pas rendu filtrable, ou d'une
	 * option qui interdit la traduction. La première est une limite assumée, la deuxième se
	 * corrige en une ligne — les confondre, c'est laisser dormir du travail facile.
	 *
	 * @var string|null
	 */
	private $raison = null;

	public function derniereRaison(): ?string { return $this->raison; }

	/** Note la raison et décline. Toujours `return $this->decliner(…)`. */
	private function decliner(string $raison) {
		$this->raison = $raison;
		return null;
	}

	public function __construct($engine) {
		$this->engine = $engine;
		$this->schema = $engine->getSchema();
		$this->db     = new \Db();
	}

	# -------------------------------------------------------
	# Point d'entrée
	# -------------------------------------------------------

	/**
	 * Contenu d'une facette, calculé par Meilisearch — ou `null` pour laisser le SQL faire.
	 *
	 * @param \BrowseEngine $browse     Le browse courant (critères, restrictions).
	 * @param string        $facet_name Nom de la facette dans browse.conf.
	 * @param array         $facet_info Configuration de la facette.
	 * @param array         $options    Options de getFacetContent() (checkAccess, checkAvailabilityOnly…).
	 *
	 * @return array|bool|null Le contenu au format du socle, un booléen si checkAvailabilityOnly,
	 *         ou null pour décliner.
	 */
	public function facetContent($browse, string $facet_name, array $facet_info, array $options) {
		$this->raison = null;

		$type = $facet_info['type'] ?? null;
		if ($type === null)  { return $this->decliner('facette virtuelle, sans contenu'); }
		if (!in_array($type, ['fieldList', 'has', 'authority', 'field', 'normalizedDates', 'attribute'], true)) {
			return $this->decliner("type « {$type} » non traduit");
		}
		if ($type === 'normalizedDates') {
			// Ces options changent la nature du calcul — envelope imposée, poste « date
			// inconnue », dates ouvertes traitées comme approximatives. Le SQL les fait ; nous
			// n'indexons pas de quoi les refaire.
			foreach (['include_unknown', 'minimum_date', 'maximum_date',
				'treat_before_dates_as_circa', 'treat_after_dates_as_circa'] as $key) {
				if (!empty($facet_info[$key])) { return $this->decliner("option « {$key} » non traduite"); }
			}
		}
		// Un gabarit compose le libellé à partir d'une table liée en un-vers-plusieurs : c'est
		// une jointure, pas une distribution.
		if ($type === 'field' && !empty($facet_info['template'])) {
			return $this->decliner('facette field à gabarit non traduite');
		}

		// Options de facette sans traduction exacte : au SQL.
		foreach (['relative_to', 'filter', 'filter_values', 'single_value', 'exclude_values', 'suppress',
			'restrict_to_lists', 'exclude_relationship_types', 'filter_on_interstitial'] as $key) {
			if (!empty($facet_info[$key])) { return $this->decliner("option « {$key} » non traduite"); }
		}
		// L'index porte toujours les ancêtres : une facette qui refuse l'expansion
		// hiérarchique rendrait des comptes plus larges que son SQL.
		if ($type === 'authority'
			&& (!empty($facet_info['dontExpandHierarchically']) || !empty($facet_info['dont_expand_hierarchically']))) {
			return $this->decliner('expansion hiérarchique refusée par la facette');
		}

		$t_subject = $browse->getSubjectInstance();
		if (!$t_subject) { return $this->decliner('table sujet introuvable'); }
		if (function_exists('caACLIsEnabled') && caACLIsEnabled($t_subject)) {
			return $this->decliner('ACL par enregistrement active');
		}

		$subject_table = $t_subject->tableName();
		$index         = $this->schema->indexName($subject_table);

		// Un index sans aucun attribut de facette date d'avant cette version du connecteur :
		// tout décliner tant qu'une réindexation ne l'a pas mis au niveau.
		if (!$this->indexHasFacets($index)) {
			return $this->decliner('index sans attribut de facette — réindexer');
		}

		$state = $this->translateBrowseState($browse, $t_subject, $options);
		if ($state === null) { return null; }   // translateBrowseState a nommé sa raison
		list($q, $filter_clauses) = $state;

		try {
			switch ($type) {
				case 'fieldList':
					return $this->fieldListFacet($browse, $index, $t_subject, $facet_name, $facet_info, $options, $q, $filter_clauses);
				case 'field':
					return $this->fieldFacet($browse, $index, $t_subject, $facet_name, $facet_info, $options, $q, $filter_clauses);
				case 'normalizedDates':
					return $this->datesFacet($browse, $index, $t_subject, $facet_name, $facet_info, $options, $q, $filter_clauses);
				case 'has':
					return $this->hasFacet($browse, $index, $t_subject, $facet_name, $facet_info, $options, $q, $filter_clauses);
				case 'authority':
					return $this->authorityFacet($browse, $index, $t_subject, $facet_name, $facet_info, $options, $q, $filter_clauses);
				case 'attribute':
					return $this->attributeFacet($browse, $index, $t_subject, $facet_name, $facet_info, $options, $q, $filter_clauses);
			}
		} catch (ClientException $e) {
			// Moteur en panne : le browse doit rester utilisable, le SQL prend le relais.
			Log::error("Facette « {$facet_name} » sur {$index} : " . $e->getMessage());
			return $this->decliner('moteur : ' . $e->getMessage());
		}

		return $this->decliner('cas non traité');
	}

	# -------------------------------------------------------
	# Traduction de l'état du browse
	# -------------------------------------------------------

	/**
	 * Traduit l'état courant du browse — critères posés et restrictions transversales — en
	 * une requête Meilisearch : un `q` (au plus un critère _search, en texte nu) et des
	 * clauses de filtre conjointes.
	 *
	 * @return array|null [q, clauses] — null si un seul élément ne se traduit pas exactement.
	 */
	private function translateBrowseState($browse, $t_subject, array $options): ?array {
		$subject_table = $t_subject->tableName();
		$index         = $this->schema->indexName($subject_table);

		// Ce qui compte pour un filtre, c'est d'être *déclaré filtrable*, pas d'être présent
		// dans les documents : filtrer sur un attribut non déclaré fait répondre HTTP 400.
		$fields = $this->engine->indexFilterableAttributes($index);

		$q       = '';
		$clauses = [];

		$criteria = $browse->getCriteria();
		if (!is_array($criteria)) { $criteria = []; }

		foreach ($criteria as $criterion_facet => $values) {
			$values = array_keys(array_filter(is_array($values) ? $values : []));
			if (!sizeof($values)) { continue; }

			if ($criterion_facet === '_search') {
				$q = $this->translateSearch($values);
				if ($q === null) { return $this->decliner('recherche en cours à syntaxe Lucene'); }
				continue;
			}
			if ($criterion_facet === '_reltypes') { return $this->decliner('critère _reltypes en cours'); }

			$info = $browse->getInfoForFacet($criterion_facet);
			if (!is_array($info)) { return $this->decliner("critère « {$criterion_facet} » inconnu"); }
			foreach (['relative_to', 'filter_on_interstitial', 'exclude_relationship_types'] as $key) {
				if (!empty($info[$key])) { return $this->decliner("critère en cours porte « {$key} »"); }
			}

			$clause = null;
			switch ($info['type'] ?? null) {
				case 'fieldList':
					$clause = $this->fieldListCriterion($subject_table, $info, $values, $fields, $options);
					break;
				case 'field':
					$clause = $this->fieldCriterion($subject_table, $t_subject, $info, $values, $fields);
					break;
				case 'normalizedDates':
					$clause = $this->datesCriterion($info, $values, $fields);
					break;
				case 'has':
					$clause = $this->hasCriterion($subject_table, $info, $values, $fields);
					break;
				case 'authority':
					$clause = $this->authorityCriterion($subject_table, $info, $values, $fields);
					break;
				case 'attribute':
					$clause = $this->attributeCriterion($subject_table, $info, $values, $fields);
					break;
				default:
					return $this->decliner("critère de type « " . ($info['type'] ?? '?') . " » non traduit");
			}
			if ($clause === null) { return $this->decliner("critère « {$criterion_facet} » non traduit"); }
			if (strlen($clause)) { $clauses[] = $clause; }
		}

		// Restrictions transversales. Chacune exige que le champ soit réellement dans
		// l'index : filtrer sur un attribut absent rendrait des comptes faux en silence.
		if (($check_access = $options['checkAccess'] ?? null) && is_array($check_access) && sizeof($check_access) && $t_subject->hasField('access')) {
			$attr = $this->schema->fieldName($subject_table, 'access');
			if (!in_array($attr, $fields, true)) { return $this->decliner('filtre d\'accès : champ non filtrable'); }
			$clauses[] = $attr . ' IN ' . $this->inList($check_access);
		}

		if (($type_ids = $browse->getTypeRestrictionList()) && is_array($type_ids) && sizeof($type_ids)) {
			$attr = $this->schema->fieldName($subject_table, 'type_id');
			if (!in_array($attr, $fields, true)) { return $this->decliner('restriction de type : champ non filtrable'); }
			$clause = $attr . ' IN ' . $this->inList($type_ids);
			if ($t_subject->getFieldInfo('type_id', 'IS_NULL')) {
				$clause = '(' . $clause . ' OR ' . $attr . ' NOT EXISTS)';
			}
			$clauses[] = $clause;
		}

		if (method_exists($t_subject, 'getSourceFieldName') && $t_subject->getSourceFieldName()
			&& ($source_ids = $browse->getSourceRestrictionList()) && is_array($source_ids) && sizeof($source_ids)) {
			$attr = $this->schema->fieldName($subject_table, $t_subject->getSourceFieldName());
			if (!in_array($attr, $fields, true)) { return $this->decliner('restriction de source : champ non filtrable'); }
			$clauses[] = $attr . ' IN ' . $this->inList($source_ids);
		}

		if (!empty($options['filterDeaccessionedRecords']) && $t_subject->hasField('is_deaccessioned')) {
			$attr = $this->schema->fieldName($subject_table, 'is_deaccessioned');
			if (!in_array($attr, $fields, true)) { return $this->decliner('filtre aliénation : champ non filtrable'); }
			$clauses[] = $attr . ' IN ' . $this->inList([0]);
		}

		// Ceinture et bretelles : les enregistrements supprimés sont désindexés, mais si le
		// champ est là, le filtre ne coûte rien.
		if ($t_subject->hasField('deleted')) {
			$attr = $this->schema->fieldName($subject_table, 'deleted');
			if (in_array($attr, $fields, true)) {
				$clauses[] = $attr . ' IN ' . $this->inList([0]);
			}
		}

		return [$q, $clauses];
	}

	/**
	 * Le critère _search ne se traduit que s'il est un texte nu : dès qu'une syntaxe Lucene
	 * s'y glisse (champ qualifié, guillemets, parenthèses, booléens, joker, exclusion), la
	 * requête relève du parseur du socle et le browse entier est décliné.
	 */
	private function translateSearch(array $expressions): ?string {
		$expression = trim(join(' ', $expressions));
		if (!strlen($expression) || $expression === '*') { return ''; }

		if (preg_match('![":\(\)\[\]\*\?~^|!={}<>]!', $expression)) { return null; }
		if (preg_match('!(^|\s)(AND|OR|NOT)(\s|$)!', $expression)) { return null; }
		if (preg_match('!(^|\s)[-+]\S!', $expression)) { return null; }

		// Chaque mot entre guillemets, comme le fait la recherche depuis qu'elle s'aligne sur
		// SqlSearch2 (voir Query::clause) : sans cela le critère du browse chercherait par
		// préfixe là où la recherche ne le fait plus, et la facette compterait sur un ensemble
		// de fiches différent de celui que le browse affiche. Vu sur le harnais — `has_media`
		// sous « oil » donnait 1 712 contre 1 703 au SQL.
		$mots = preg_split('!\s+!u', $expression, -1, PREG_SPLIT_NO_EMPTY);
		return join(' ', array_map(function ($mot) { return '"' . $mot . '"'; }, $mots));
	}

	/**
	 * Critère fieldList : la valeur choisie, étendue à ses enfants directs — la même
	 * expansion que fait execute(). Les valeurs d'une facette ordinaire s'intersectent ;
	 * `multiple = 1` les unit.
	 */
	private function fieldListCriterion(string $subject_table, array $info, array $values, array $fields, array $options): ?string {
		$field = $info['field'] ?? null;
		if (!$field || !empty($info['table'])) { return null; }

		$attr = $this->schema->fieldName($subject_table, $field);
		if (!in_array($attr, $fields, true)) { return null; }

		$dont_expand = !empty($info['dontExpandHierarchically']) || !empty($info['dont_expand_hierarchically']);

		$per_value = [];
		foreach ($values as $value) {
			$ids = [$value];
			if (!in_array($field, ['access', 'status'], true) && !$dont_expand) {
				$t_item = \ca_list_items::find((int)$value, ['returnAs' => 'firstModelInstance']);
				if (!$t_item) { return null; }
				$children = $t_item->get('ca_list_items.children.item_id', ['returnAsArray' => true]);
				if (is_array($children)) { $ids = array_merge($ids, $children); }
			}
			$per_value[] = $attr . ' IN ' . $this->inList($ids);
		}

		if (!empty($info['multiple'])) {
			return '(' . join(' OR ', $per_value) . ')';
		}
		return '(' . join(' AND ', $per_value) . ')';
	}

	/**
	 * Critère field : égalité sur la valeur de l'intrinsèque. La barre oblique a été échappée
	 * dans l'identifiant du poste (voir fieldFacet) ; on la rétablit avant de filtrer.
	 */
	private function fieldCriterion(string $subject_table, $t_subject, array $info, array $values, array $fields): ?string {
		$field = $info['field'] ?? null;
		if (!$field || !empty($info['template'])) { return null; }

		$field_info = \Datamodel::getFieldInfo($subject_table, $field);
		if (!is_array($field_info)) { return null; }
		if (isset($field_info['LIST_CODE']) || isset($field_info['LIST'])) { return null; }
		if ($t_subject->getProperty('ID_NUMBERING_ID_FIELD') === $field) { return null; }

		$attr = $this->schema->fieldName($subject_table, $field);
		if (!in_array($attr, $fields, true)) { return null; }

		$is_bit = (($field_info['FIELD_TYPE'] ?? null) === FT_BIT);

		$per_value = [];
		foreach ($values as $value) {
			$value = str_replace('&#47;', '/', (string)$value);
			if ($is_bit) {
				// Le booléen est indexé en nombre ; on couvre les deux écritures possibles.
				$etat = (int)$value;
				$per_value[] = '(' . $attr . ' = ' . $etat . ' OR ' . $attr . ' = ' . $this->quote((string)$etat) . ')';
			} else {
				$per_value[] = $attr . ' IN ' . $this->inList([$value]);
			}
		}

		if (!empty($info['multiple'])) { return '(' . join(' OR ', $per_value) . ')'; }
		return '(' . join(' AND ', $per_value) . ')';
	}

	/**
	 * Critère normalizedDates : égalité sur la tranche.
	 *
	 * Le socle applique ce critère en recouvrement d'intervalles ; l'attribut portant toutes
	 * les tranches qu'une fiche recouvre, l'égalité sélectionne exactement les mêmes fiches.
	 * Une tranche que l'usager aurait tapée à la main plutôt que choisie dans la facette ne
	 * s'y trouverait pas : on ne traduit donc que les valeurs présentes dans l'index, et on
	 * décline sinon — c'est le seul cas où l'égalité et le recouvrement divergeraient.
	 */
	private function datesCriterion(array $info, array $values, array $fields): ?string {
		$parts = explode('.', (string)($info['element_code'] ?? ''));
		$code  = (string)array_pop($parts);
		$normalisation = (string)($info['normalization'] ?? '');
		if (!strlen($code) || !strlen($normalisation) || sizeof($parts) > 1) { return null; }

		foreach (['include_unknown', 'minimum_date', 'maximum_date',
			'treat_before_dates_as_circa', 'treat_after_dates_as_circa'] as $key) {
			if (!empty($info[$key])) { return null; }
		}

		$attr = $this->schema->dateFacetAttribute($code, $normalisation);
		if (!in_array($attr, $fields, true)) { return null; }

		$per_value = [];
		foreach ($values as $value) {
			$per_value[] = $attr . ' IN ' . $this->inList([(string)$value]);
		}

		if (!empty($info['multiple'])) { return '(' . join(' OR ', $per_value) . ')'; }
		return '(' . join(' AND ', $per_value) . ')';
	}

	/**
	 * Critère has : présence ou absence de l'attribut visé.
	 */
	private function hasCriterion(string $subject_table, array $info, array $values, array $fields): ?string {
		$attrs = $this->hasAttributes($subject_table, $info);
		if ($attrs === null) { return null; }
		foreach ($attrs as $a) {
			if (!in_array($a, $fields, true)) { return null; }
		}

		$per_value = [];
		foreach ($values as $value) {
			if ((int)$value) {
				$exists = array_map(function ($a) { return $a . ' EXISTS'; }, $attrs);
				$per_value[] = '(' . join(' OR ', $exists) . ')';
			} else {
				$missing = array_map(function ($a) { return $a . ' NOT EXISTS'; }, $attrs);
				$per_value[] = '(' . join(' AND ', $missing) . ')';
			}
		}
		return '(' . join(' AND ', $per_value) . ')';
	}

	/**
	 * Critère authority : égalité sur l'attribut de facette de la table liée. Les ancêtres
	 * étant indexés, l'égalité sur un nœud couvre sa descendance — la sémantique exacte de
	 * l'expansion hiérarchique d'execute().
	 */
	private function authorityCriterion(string $subject_table, array $info, array $values, array $fields): ?string {
		$attr = $this->authorityAttribute($subject_table, $info);
		if ($attr === null) { return null; }
		if (!in_array($attr, $fields, true)) { return null; }

		$ids = array_map('intval', $values);

		if (!empty($info['multiple'])) {
			return $attr . ' IN ' . $this->inList($ids);
		}
		$per_value = [];
		foreach ($ids as $id) { $per_value[] = $attr . ' = ' . $id; }
		return '(' . join(' AND ', $per_value) . ')';
	}

	/**
	 * Critère attribute : le browse le pose sous la forme de l'identifiant du poste, c'est-à-dire
	 * le `value_id` rendu par la facette. L'index, lui, ne porte que la valeur — on refait donc
	 * le chemin inverse en base, exactement comme le socle qui appelle `getValuesFor()`.
	 *
	 * Un critère dont la valeur ne se retrouve pas décline plutôt que de filtrer à côté : mieux
	 * vaut le SQL du socle qu'un browse silencieusement vide.
	 */
	private function attributeCriterion(string $subject_table, array $info, array $values, array $fields): ?string {
		$element = $this->facetElement($info);
		if ($element === null) { return null; }

		$attr = $this->schema->attributeFacetAttribute($element['code']);
		if (!in_array($attr, $fields, true)) { return null; }

		$par_critere = [];
		foreach ($values as $value) {
			// Le socle défait l'échappement que la facette a posé pour que la valeur voyage
			// dans une URL de browse.
			$value = str_replace('&#47;', '/', urldecode((string)$value));
			if (!strlen($value)) { return null; }

			if ($element['datatype'] === 3) {
				// Le critère porte l'identifiant de l'item, celui-là même que l'index contient.
				if (!ctype_digit($value)) { return null; }

				// Le socle étend un critère de liste à toute la descendance de l'item
				// (`getHierarchy(includeSelf)`) ; chaque document portant les ancêtres de ses
				// items, l'égalité sur un ancêtre couvre sa descendance par construction. Même
				// mécanique que pour les autorités.
				$par_critere[] = $attr . ' = ' . $this->quote($value);
				continue;
			}

			$cle = $this->cleDuCritere($element['element_id'], $value);
			if ($cle === null) { return null; }

			$par_critere[] = $attr . ' = ' . $this->quote($cle);
		}
		if (!sizeof($par_critere)) { return null; }

		// Plusieurs valeurs d'un même critère se cumulent comme pour les autres types : le socle
		// exige chacune d'elles, sauf en facette « multiple » où elles s'unissent.
		$liaison = !empty($info['multiple']) ? ' OR ' : ' AND ';
		return '(' . join($liaison, $par_critere) . ')';
	}

	/**
	 * La clé d'index correspondant à un critère. Le browse le pose sous la forme du `value_id`
	 * rendu par la facette, parfois sous celle de la valeur en clair ; l'index, lui, ne connaît
	 * que la clé normalisée.
	 *
	 * Rend null si la valeur ne se retrouve pas : mieux vaut le SQL du socle qu'un browse
	 * silencieusement vide.
	 */
	private function cleDuCritere(int $element_id, string $value): ?string {
		if (is_numeric($value)) {
			$qr = $this->db->query(
				"SELECT value_longtext1 FROM ca_attribute_values WHERE value_id = ?", [(int)$value]
			);
			$value = $qr->nextRow() ? trim((string)$qr->get('value_longtext1')) : '';
			if (!strlen($value)) { return null; }
		}
		$cle = $this->schema->valueKey($value);
		return strlen($cle) ? $cle : null;
	}

	# -------------------------------------------------------
	# Calcul des facettes
	# -------------------------------------------------------

	/**
	 * Facette fieldList : distribution d'un intrinsèque adossé à une liste (type_id, status,
	 * access…), rendue au format du socle — un poste par valeur de liste, trié selon le tri
	 * par défaut de la liste.
	 */
	private function fieldListFacet($browse, string $index, $t_subject, string $facet_name, array $facet_info, array $options, string $q, array $clauses) {
		$subject_table = $t_subject->tableName();
		$field         = $facet_info['field'] ?? null;
		if (!$field) { return $this->decliner('facette sans champ'); }
		if (!empty($facet_info['table'])) { return $this->decliner('fieldList sur table liée non traduite'); }

		$field_info = \Datamodel::getFieldInfo($subject_table, $field);
		if (!is_array($field_info)) { return $this->decliner("champ « {$field} » inconnu du modèle"); }
		$list_code = $field_info['LIST_CODE'] ?? ($field_info['LIST'] ?? null);
		if (!$list_code) { return $this->decliner("champ « {$field} » sans liste de valeurs"); }
		$by_value = !isset($field_info['LIST_CODE']);

		$attr = $this->schema->fieldName($subject_table, $field);
		if (!$this->engine->isFilterable($index, $attr)) {
			$this->raison = "« {$attr} » non déclaré filtrable";
			// Champ non déclaré filtrable : le moteur refuserait la distribution (HTTP 400).
			// C'est le cas courant d'un intrinsèque indexé comme texte et jamais facetté —
			// `status`, par exemple. Au SQL, sans bruit.
			return null;
		}

		$distribution = $this->distribution($index, $attr, $q, $clauses);
		if ($distribution === null) { return null; }

		list($items_by_id, $items_by_value, $sort_key) = $this->listItems($list_code);

		$criteria = $browse->getCriteria($facet_name);
		if (!is_array($criteria)) { $criteria = []; }

		// Le filtre d'accès du socle porte aussi sur les items de liste eux-mêmes
		// (`li.access IN (…)`) : un type dont l'item n'est pas public disparaît de la facette.
		$check_access = null;
		if (!empty($options['checkAccess']) && is_array($options['checkAccess'])) {
			$check_access = array_map('intval', $options['checkAccess']);
		}

		$values = [];
		foreach ($distribution as $value => $count) {
			$value = (string)$value;
			if (isset($criteria[$value])) { continue; }   // déjà en critère : ne pas re-proposer

			$item = $by_value ? ($items_by_value[$value] ?? null) : ($items_by_id[$value] ?? null);
			if (!$item) { continue; }   // valeur hors liste : même silence que le SQL
			if ($check_access !== null && $item['access'] !== null && !in_array((int)$item['access'], $check_access, true)) { continue; }

			$values[$value] = [
				'id'            => $value,
				'label'         => $item['label'],
				'sort'          => $sort_key ? ($item[$sort_key] ?? null) : null,
				'content_count' => (int)$count,
			];
		}

		if (!empty($options['checkAvailabilityOnly'])) {
			// Même règle que le SQL : une facette à valeur unique ne discrimine rien.
			return sizeof($values) > 1;
		}

		if (function_exists('caSortArrayByKeyInValue')) {
			$values = caSortArrayByKeyInValue($values, ['sort']);
		}
		return $values;
	}

	/**
	 * Facette field : distribution d'un intrinsèque qui n'est pas adossé à une liste — un
	 * booléen (« aliéné », « transcriptible »), une clé étrangère, une valeur libre.
	 *
	 * Le socle groupe sur la colonne et écarte les valeurs vides, sauf pour un booléen où les
	 * deux états sont nommés. Il échappe aussi la barre oblique dans l'identifiant du poste,
	 * qui voyage ensuite dans une URL de browse : on reproduit, sans quoi les identifiants ne
	 * se correspondraient plus d'un calcul à l'autre.
	 */
	private function fieldFacet($browse, string $index, $t_subject, string $facet_name, array $facet_info, array $options, string $q, array $clauses) {
		$subject_table = $t_subject->tableName();
		$field         = $facet_info['field'] ?? null;
		if (!$field) { return $this->decliner('facette sans champ'); }

		$field_info = \Datamodel::getFieldInfo($subject_table, $field);
		if (!is_array($field_info)) { return $this->decliner("champ « {$field} » inconnu du modèle"); }

		// Un champ adossé à une liste relève de fieldList : le socle y montre des identifiants
		// bruts, l'index y porte les libellés de l'item. Deux langages, aucun accord possible.
		if (isset($field_info['LIST_CODE']) || isset($field_info['LIST'])) {
			return $this->decliner("champ « {$field} » adossé à une liste — relève de fieldList");
		}

		// Un numéro d'inventaire est indexé éclaté en ses variantes (« CLE.42 » donne aussi
		// « CLE » et « 42 ») : sa distribution ne serait pas celle de la colonne.
		if ($t_subject->getProperty('ID_NUMBERING_ID_FIELD') === $field) {
			return $this->decliner("champ « {$field} » indexé en variantes de numéro");
		}

		$attr = $this->schema->fieldName($subject_table, $field);
		if (!$this->engine->isFilterable($index, $attr)) {
			return $this->decliner("« {$attr} » non déclaré filtrable");
		}

		$distribution = $this->distribution($index, $attr, $q, $clauses);

		$is_bit = (($field_info['FIELD_TYPE'] ?? null) === FT_BIT);

		if (!empty($options['checkAvailabilityOnly'])) {
			return sizeof($distribution) > 1;
		}

		$criteria = $browse->getCriteria($facet_name);
		if (!is_array($criteria)) { $criteria = []; }

		if ($is_bit) {
			$oui = $facet_info['label_yes'] ?? _t('Yes');
			$non = $facet_info['label_no']  ?? _t('No');

			$valeurs = [];
			foreach ([1 => $oui, 0 => $non] as $etat => $libelle) {
				// Les booléens arrivent de l'indexeur en nombres ; la distribution les rend en
				// chaînes, parfois « 1 », parfois « 1.0 ».
				$compte = (int)(($distribution[(string)$etat] ?? 0) + ($distribution[$etat . '.0'] ?? 0));
				if (!$compte || isset($criteria[(string)$etat])) { continue; }
				$valeurs[(string)$etat] = ['id' => $etat, 'label' => $libelle, 'content_count' => $compte];
			}
			return $valeurs;
		}

		$valeurs = [];
		foreach ($distribution as $valeur => $compte) {
			$valeur = trim((string)$valeur);
			if (!strlen($valeur)) { continue; }              // le socle écarte les vides
			if (isset($criteria[$valeur])) { continue; }     // déjà en critère

			$id = str_replace('/', '&#47;', $valeur);
			$valeurs[$valeur] = ['id' => $id, 'label' => $valeur, 'content_count' => (int)$compte];
		}
		return $valeurs;
	}

	/**
	 * Facette attribute : la distribution des valeurs d'un élément de métadonnée.
	 *
	 * C'est le type que les profils métier emploient le plus : au CIPAR, « matériau », « technique »
	 * et « objet présent ».
	 *
	 * La distribution se prend sur `facet_attr__<code>` et non sur l'attribut texte du même nom.
	 * L'attribut texte porte bien les valeurs, mais aussi les variantes que l'indexeur fabrique
	 * pour la recherche — « beton » à côté de « béton » — qui ajouteraient à la facette des
	 * postes que le SQL n'a pas. Voir Schema::FACET_ATTR.
	 *
	 * Le socle indexe son tableau de postes par la valeur en minuscules et le trie sur cette
	 * clé. On reproduit, y compris ce que ce regroupement emporte : deux graphies ne différant
	 * que par la casse s'écrasent au lieu de se cumuler. Cumuler donnerait des comptes plus
	 * justes mais différents des siens, et l'écart se lirait comme une divergence.
	 *
	 * L'identifiant d'un poste est le `value_id` que le socle va chercher en base : il voyage
	 * dans l'URL du browse, il doit être le même des deux côtés. Une requête suffit pour toute
	 * la distribution, là où le socle en fait une par valeur.
	 *
	 * Seul l'élément à valeur textuelle est traduit. Une liste se compte sur des identifiants
	 * d'item et porte une hiérarchie à déplier ; les types adossés à une table liée relèvent
	 * d'authority. Les uns comme les autres déclinent.
	 */
	private function attributeFacet($browse, string $index, $t_subject, string $facet_name, array $facet_info, array $options, string $q, array $clauses) {
		$element = $this->facetElement($facet_info);
		if ($element === null) { return null; }   // facetElement a nommé sa raison

		$attr = $this->schema->attributeFacetAttribute($element['code']);
		if (!$this->engine->isFilterable($index, $attr)) {
			// Attribut jamais déclaré filtrable : le moteur refuserait la distribution. Au SQL,
			// sans bruit — c'est le cas d'un index construit avant que la facette n'existe.
			$this->raison = "« {$attr} » non déclaré filtrable — réindexer";
			return null;
		}

		$distribution = $this->distribution($index, $attr, $q, $clauses);
		if ($distribution === null) { return null; }

		$criteria = $browse->getCriteria($facet_name);
		if (!is_array($criteria)) { $criteria = []; }

		// Un élément adossé à une liste porte l'identifiant de l'item : la distribution est déjà
		// celle des postes du socle, il ne reste qu'à leur donner leur libellé et leur place dans
		// la liste.
		if ($element['datatype'] === 3) {
			$valeurs = $this->postesDeListe($element, $distribution, $criteria, $options);
			if ($valeurs === null) { return null; }
		} else {
			// Sinon l'index ne porte que des clés — casse et accents ôtés, comme le fait la
			// collation sous laquelle le socle groupe. La distribution est donc déjà celle de ses
			// postes ; il ne reste qu'à leur rendre leur libellé et leur identifiant, que la base
			// seule connaît.
			$groupes = $this->valueGroups($element['element_id']);

			$valeurs = [];
			foreach ($distribution as $cle => $compte) {
				$cle = trim((string)$cle);
				if (!strlen($cle)) { continue; }                 // le socle écarte les vides

				// Clé que la base ne porte pas : même silence que le SQL, qui ne compte que ce
				// qu'il lit dans ca_attribute_values.
				if (!isset($groupes[$cle])) { continue; }

				$id = (string)$groupes[$cle]['id'];
				if (isset($criteria[$id])) { continue; }         // déjà en critère : ne pas re-proposer

				$valeurs[$cle] = [
					'id'            => $groupes[$cle]['id'],
					'label'         => $groupes[$cle]['label'],
					'content_count' => (int)$compte,
				];
			}
		}

		if (!empty($options['checkAvailabilityOnly'])) {
			// Même règle que le SQL : une facette à valeur unique ne discrimine rien.
			return sizeof($valeurs) > 1;
		}
		if (!sizeof($valeurs)) { return []; }

		// Le socle trie une liste selon le tri déclaré pour elle, et une valeur textuelle par
		// `ksort()` sur la clé en minuscules — ce que nos clés sont déjà.
		if ($element['datatype'] === 3) {
			if (function_exists('caSortArrayByKeyInValue')) { $valeurs = caSortArrayByKeyInValue($valeurs, ['sort']); }
		} else {
			ksort($valeurs);
		}
		return $valeurs;
	}

	/**
	 * Postes d'une facette `attribute` adossée à une liste.
	 *
	 * L'index porte l'identifiant de l'item ; la distribution est donc déjà celle des postes du
	 * socle. Reste à leur donner leur libellé, leur rang et leur place dans la hiérarchie de la
	 * liste — et à appliquer le filtre d'accès, qui porte chez le socle sur l'item de liste
	 * lui-même et non sur les fiches (`li.access`, même règle que pour `fieldList`).
	 *
	 * @return array|null les postes, ou null pour décliner.
	 */
	private function postesDeListe(array $element, array $distribution, array $criteria, array $options): ?array {
		$list_code = $this->listCode((int)$element['list_id']);
		if ($list_code === null) { return $this->decliner("liste {$element['list_id']} introuvable"); }

		list($items_par_id, , $sort_key) = $this->listItems($list_code);
		$hierarchie = $this->listHierarchy((int)$element['list_id']);

		$check_access = null;
		if (!empty($options['checkAccess']) && is_array($options['checkAccess'])) {
			$check_access = array_map('intval', $options['checkAccess']);
		}

		$valeurs = [];
		foreach ($distribution as $item_id => $compte) {
			$item_id = (string)$item_id;
			if (!ctype_digit($item_id)) { continue; }
			if (isset($criteria[$item_id])) { continue; }        // déjà en critère

			$item = $items_par_id[$item_id] ?? null;
			if (!$item) { continue; }                            // hors liste : même silence que le SQL
			if (!empty($hierarchie[$item_id]['deleted'])) { continue; }
			if ($check_access !== null && $item['access'] !== null && !in_array((int)$item['access'], $check_access, true)) { continue; }

			$valeurs[$item_id] = [
				'id'            => (int)$item_id,
				'label'         => $item['label'],
				'parent_id'     => $hierarchie[$item_id]['parent_id'] ?? null,
				'child_count'   => $hierarchie[$item_id]['child_count'] ?? 0,
				'sort'          => $sort_key ? ($item[$sort_key] ?? null) : null,
				'content_count' => (int)$compte,
			];
		}
		return $valeurs;
	}

	/** Code d'une liste, par son identifiant. */
	private function listCode(int $list_id): ?string {
		static $cache = [];
		if (array_key_exists($list_id, $cache)) { return $cache[$list_id]; }
		$qr = $this->db->query("SELECT list_code FROM ca_lists WHERE list_id = ?", [$list_id]);
		return $cache[$list_id] = ($qr->nextRow() ? (string)$qr->get('list_code') : null);
	}

	/** Parent, nombre d'enfants et état de suppression de chaque item d'une liste. */
	private function listHierarchy(int $list_id): array {
		static $cache = [];
		if (isset($cache[$list_id])) { return $cache[$list_id]; }

		$out = [];
		$qr = $this->db->query(
			"SELECT item_id, parent_id, deleted FROM ca_list_items WHERE list_id = ?", [$list_id]
		);
		while ($qr->nextRow()) {
			$out[(string)$qr->get('item_id')] = [
				'parent_id'   => $qr->get('parent_id'),
				'deleted'     => (int)$qr->get('deleted'),
				'child_count' => 0,
			];
		}
		foreach ($out as $id => $ligne) {
			$parent = (string)($ligne['parent_id'] ?? '');
			if (strlen($parent) && isset($out[$parent])) { $out[$parent]['child_count']++; }
		}
		return $cache[$list_id] = $out;
	}

	/**
	 * L'élément visé par une facette attribute, si le pont sait la rendre. Rend null en ayant
	 * nommé sa raison — les options écartées ici sont celles qui changent la population comptée
	 * ou l'identité des postes, et qu'on ne saurait honorer sans risquer des comptes faux.
	 */
	private function facetElement(array $facet_info): ?array {
		$element_code = (string)($facet_info['element_code'] ?? '');
		if (!strlen($element_code)) { return $this->decliner('facette attribute sans element_code'); }

		foreach (['filter', 'single_value', 'suppress', 'exclude_values', 'restrict_to_types', 'relabel'] as $cle) {
			if (!empty($facet_info[$cle])) { return $this->decliner("facette attribute porte « {$cle} »"); }
		}

		$parts = explode('.', $element_code);
		$code  = (string)array_pop($parts);

		$element = $this->element($code);
		if ($element === null) { return $this->decliner("élément « {$code} » inconnu"); }

		// Valeur textuelle, ou liste de valeurs. Un élément adossé à une table liée relève
		// d'authority, une date de normalizedDates : ni l'un ni l'autre ne se compte ici.
		if (!in_array($element['datatype'], [1, 3], true)) {
			return $this->decliner("élément « {$code} » de type " . $element['datatype'] . " non traduit");
		}
		if ($element['datatype'] === 3 && !$element['list_id']) {
			return $this->decliner("élément « {$code} » de type liste sans liste");
		}
		return $element + ['code' => $code];
	}

	/** @var array cache des éléments de métadonnée, par code */
	private static $element_cache = [];

	/**
	 * Identifiant et type d'un élément de métadonnée, par son code.
	 */
	private function element(string $code): ?array {
		if (!array_key_exists($code, self::$element_cache)) {
			$qr = $this->db->query(
				"SELECT element_id, datatype, list_id FROM ca_metadata_elements WHERE element_code = ?", [$code]
			);
			self::$element_cache[$code] = $qr->nextRow()
				? ['element_id' => (int)$qr->get('element_id'), 'datatype' => (int)$qr->get('datatype'), 'list_id' => $qr->get('list_id')]
				: null;
		}
		return self::$element_cache[$code];
	}

	/**
	 * Les groupes de valeurs d'un élément, tels que le socle les obtient : une seule requête,
	 * dont le `GROUP BY` passe par la collation de la base et fait donc exactement le
	 * rapprochement que fait sa facette.
	 *
	 * L'identifiant retenu est `MIN(value_id)`. Le socle appelle `getValueIDFor()`, dont le
	 * `LIMIT 1` sans ordre rend en pratique la première ligne servie par l'index, c'est-à-dire
	 * la plus petite. Cet identifiant voyage dans l'URL du browse : il doit correspondre.
	 *
	 * @return array [clé normalisée => ['id' => int, 'label' => string]]
	 */
	private function valueGroups(int $element_id): array {
		if (isset(self::$value_group_cache[$element_id])) { return self::$value_group_cache[$element_id]; }

		$out = [];
		$qr = $this->db->query(
			"SELECT MIN(value_id) AS value_id, MIN(value_longtext1) AS valeur
			 FROM ca_attribute_values
			 WHERE element_id = ? AND value_longtext1 IS NOT NULL AND value_longtext1 <> ''
			 GROUP BY value_longtext1", [$element_id]
		);
		while ($qr->nextRow()) {
			$valeur = trim((string)$qr->get('valeur'));
			if (!strlen($valeur)) { continue; }
			$out[$this->schema->valueKey($valeur)] = ['id' => (int)$qr->get('value_id'), 'label' => $valeur];
		}
		return self::$value_group_cache[$element_id] = $out;
	}

	/** @var array cache des groupes de valeurs, par élément */
	private static $value_group_cache = [];


	/**
	 * Facette normalizedDates : la distribution des tranches posées à l'indexation.
	 *
	 * Tout le travail a été fait en amont — chaque document porte les années (ou décennies, ou
	 * siècles) que ses dates recouvrent. Ne reste qu'à ordonner : les tranches sont des
	 * expressions (« 1946 », « 1940s »), pas des nombres, et l'ordre alphabétique y serait
	 * faux. On les fait dater par l'analyseur, comme le socle ordonne par sa clé de tri.
	 */
	private function datesFacet($browse, string $index, $t_subject, string $facet_name, array $facet_info, array $options, string $q, array $clauses) {
		$parts = explode('.', (string)($facet_info['element_code'] ?? ''));
		$code  = (string)array_pop($parts);
		$normalisation = (string)($facet_info['normalization'] ?? '');
		if (!strlen($code) || !strlen($normalisation)) { return $this->decliner('facette de dates incomplète'); }
		if (sizeof($parts) > 1) { return $this->decliner('élément de date dans un conteneur'); }

		$attr = $this->schema->dateFacetAttribute($code, $normalisation);
		if (!$this->engine->isFilterable($index, $attr)) {
			return $this->decliner("« {$attr} » non déclaré filtrable — réindexer");
		}

		$distribution = $this->distribution($index, $attr, $q, $clauses);

		if (!empty($options['checkAvailabilityOnly'])) { return sizeof($distribution) > 0; }
		if (!sizeof($distribution)) { return []; }

		$criteria = $browse->getCriteria($facet_name);
		if (!is_array($criteria)) { $criteria = []; }

		if (!class_exists('\TimeExpressionParser')) { return $this->decliner('analyseur de dates indisponible'); }
		$tep = new \TimeExpressionParser();

		$valeurs = [];
		foreach ($distribution as $tranche => $compte) {
			$tranche = (string)$tranche;
			if (isset($criteria[$tranche])) { continue; }

			$debut = $fin = null;
			if ($tep->parse($tranche)) {
				$bornes = $tep->getHistoricTimestamps();
				$debut  = (int)($bornes['start'] ?? 0);
				$fin    = (int)($bornes['end'] ?? 0);
			}

			$valeurs[$tranche] = [
				'id'            => $tranche,
				'label'         => $tranche,
				'start'         => $debut,
				'end'           => $fin,
				'content_count' => (int)$compte,
				'_tri'          => $debut,
			];
		}

		$decroissant = (strtoupper((string)($facet_info['sort'] ?? '')) === 'DESC');
		uasort($valeurs, function ($a, $b) use ($decroissant) {
			$c = $a['_tri'] <=> $b['_tri'];
			return $decroissant ? -$c : $c;
		});
		foreach ($valeurs as $k => $v) { unset($valeurs[$k]['_tri']); }

		return $valeurs;
	}

	/**
	 * Facette has : deux comptes exacts — avec et sans — en un seul aller-retour. La règle du
	 * socle est conservée : si l'une des deux options est vide, la facette entière disparaît.
	 */
	private function hasFacet($browse, string $index, $t_subject, string $facet_name, array $facet_info, array $options, string $q, array $clauses) {
		$all_criteria = $browse->getCriteria();
		if (isset($all_criteria[$facet_name])) { return []; }   // une seule occurrence par browse

		$attrs = $this->hasAttributes($t_subject->tableName(), $facet_info);
		if ($attrs === null) { return $this->decliner('facette has non traduisible'); }
		foreach ($attrs as $a) {
			if (!$this->engine->isFilterable($index, $a)) { return $this->decliner("« {$a} » non déclaré filtrable"); }
		}

		$exists  = '(' . join(' OR ', array_map(function ($a) { return $a . ' EXISTS'; }, $attrs)) . ')';
		$missing = '(' . join(' AND ', array_map(function ($a) { return $a . ' NOT EXISTS'; }, $attrs)) . ')';

		$base = ['q' => $q, 'hitsPerPage' => 0, 'page' => 1, 'attributesToRetrieve' => [Schema::PK]];
		$responses = $this->engine->getClient()->multiSearch([
			array_merge($base, ['indexUid' => $index, 'filter' => $this->filterString(array_merge($clauses, [$exists]))]),
			array_merge($base, ['indexUid' => $index, 'filter' => $this->filterString(array_merge($clauses, [$missing]))]),
		]);

		$yes = (int)($responses[0]['totalHits'] ?? 0);
		$no  = (int)($responses[1]['totalHits'] ?? 0);

		if (!empty($options['checkAvailabilityOnly'])) {
			return ($yes > 0) && ($no > 0);
		}
		if (!$yes || !$no) { return []; }

		$yes_text = !empty($facet_info['label_yes']) ? $facet_info['label_yes'] : _t('Yes');
		$no_text  = !empty($facet_info['label_no'])  ? $facet_info['label_no']  : _t('No');

		return [
			'yes' => ['id' => 1, 'label' => $yes_text, 'content_count' => $yes],
			'no'  => ['id' => 0, 'label' => $no_text,  'content_count' => $no],
		];
	}

	/**
	 * Facette authority : distribution des identifiants liés — ancêtres compris, donc
	 * l'arborescence arrive déjà agrégée — puis libellés et généalogie relus en SQL, comme le
	 * socle le fait lui-même (trois requêtes séparées y sont plus rapides qu'une jointure).
	 */
	private function authorityFacet($browse, string $index, $t_subject, string $facet_name, array $facet_info, array $options, string $q, array $clauses) {
		$subject_table = $t_subject->tableName();
		$table         = $facet_info['table'] ?? null;
		if (!$table) { return $this->decliner('facette authority sans table'); }
		if ($table === $subject_table) { return $this->decliner('relation réflexive non traduite'); }

		$attr = $this->authorityAttribute($subject_table, $facet_info);
		if ($attr === null) { return $this->decliner('table ou type de relation non facettable'); }

		// Non déclaré filtrable : l'index date d'avant cette version du connecteur, ou la table
		// n'est pas facettée. On décline plutôt que de se faire refuser la requête.
		if (!$this->engine->isFilterable($index, $attr)) { return $this->decliner("« {$attr} » non déclaré filtrable"); }

		if (!in_array($attr, $this->engine->indexFields($index), true)) {
			// Déclaré, mais aucun document ne le porte : contenu réellement vide.
			return !empty($options['checkAvailabilityOnly']) ? false : [];
		}

		$t_rel = \Datamodel::getInstanceByTableName($table, true);
		if (!$t_rel) { return $this->decliner("table « {$table} » inconnue du modèle"); }

		// Pour une table hiérarchique, la distribution des liens directs s'obtient dans la
		// même requête : le socle traite différemment un enregistrement lié (conservé même
		// sans type ni accès) et un nœud présent par simple ascendance (écarté dans ce cas).
		$rel_types  = $facet_info['restrict_to_relationship_types'] ?? null;
		$rel_code   = (is_array($rel_types) && sizeof($rel_types) === 1) ? (string)reset($rel_types) : null;
		$direct_attr = $t_rel->isHierarchical() ? $this->schema->directFacetAttribute($table, $rel_code) : null;

		if ($direct_attr) {
			$both = $this->distribution($index, [$attr, $direct_attr], $q, $clauses);
			$distribution = is_array($both[$attr] ?? null) ? $both[$attr] : [];
			$direct       = is_array($both[$direct_attr] ?? null) ? $both[$direct_attr] : [];
		} else {
			$distribution = $this->distribution($index, $attr, $q, $clauses);
			$direct       = null;
		}

		if (!empty($options['checkAvailabilityOnly'])) {
			return sizeof($distribution) > 0;
		}
		if (!sizeof($distribution)) { return []; }

		$criteria = $browse->getCriteria($facet_name);
		if (!is_array($criteria)) { $criteria = []; }

		$restrict_to_types = [];
		if (!empty($facet_info['restrict_to_types']) && function_exists('caMakeTypeIDList')) {
			$restrict_to_types = caMakeTypeIDList($table, $facet_info['restrict_to_types']);
		}
		$exclude_types = [];
		if (!empty($facet_info['exclude_types']) && function_exists('caMakeTypeIDList')) {
			$exclude_types = caMakeTypeIDList($table, $facet_info['exclude_types']);
		}

		$check_access = null;
		if (!empty($options['checkAccess']) && is_array($options['checkAccess']) && $t_rel->hasField('access')) {
			$check_access = array_map('intval', $options['checkAccess']);
		}

		$rows = $this->authorityRows($t_rel, array_map('intval', array_keys($distribution)));

		$items = [];
		foreach ($distribution as $id => $count) {
			$id = (int)$id;
			if (isset($criteria[$id]) || isset($criteria[(string)$id])) { continue; }

			$row = $rows[$id] ?? null;
			if (!$row) { continue; }   // supprimé ou disparu depuis l'indexation

			// Le sort d'un enregistrement dépend de la façon dont il entre dans la facette.
			// Un lien direct est conservé même sans type ni accès (le socle le marque
			// seulement `no_access`) ; un nœud présent par simple ascendance est écarté dès
			// qu'il n'a pas de type, pas d'accès, ou sort des restrictions — les mêmes règles
			// que l'expansion d'ancêtres du socle.
			$est_direct = ($direct === null) || isset($direct[(string)$id]) || isset($direct[$id]);
			$sans_acces = ($check_access !== null && isset($row['access']) && !in_array((int)$row['access'], $check_access, true));

			if (!$est_direct) {
				if (!$row['type_id']) { continue; }
				if ($sans_acces) { continue; }
			}
			if (sizeof($restrict_to_types) && !in_array((int)$row['type_id'], $restrict_to_types)) { continue; }
			if (sizeof($exclude_types) && in_array((int)$row['type_id'], $exclude_types)) { continue; }
			if ($est_direct && $sans_acces && sizeof($restrict_to_types)) { continue; }

			$items[$id] = [
				'id'            => $id,
				'type_id'       => $row['type_id'] ? [$row['type_id']] : [],
				'parent_id'     => $row['parent_id'] ?? null,
				'hierarchy_id'  => $row['hierarchy_id'] ?? null,
				'rel_type_id'   => [],
				'child_count'   => 0,
				'content_count' => (int)$count,
				'label'         => $row['label'],
			];
			if ($est_direct && $sans_acces) { $items[$id]['no_access'] = 1; }
		}

		// Compte d'enfants directs présents dans la facette, pour l'affichage hiérarchique.
		foreach ($items as $item) {
			$parent_id = $item['parent_id'];
			if ($parent_id && isset($items[$parent_id])) { $items[$parent_id]['child_count']++; }
		}

		uasort($items, function ($a, $b) {
			return strnatcasecmp((string)$a['label'], (string)$b['label']);
		});

		return $items;
	}

	# -------------------------------------------------------
	# Aides
	# -------------------------------------------------------

	/**
	 * Une requête, toutes les valeurs : la distribution exhaustive d'un ou plusieurs
	 * attributs sur le résultat courant du browse.
	 *
	 * @param string|array $attrs
	 * @return array [valeur => compte] pour un attribut unique, sinon [attribut => [valeur => compte]]
	 */
	private function distribution(string $index, $attrs, string $q, array $clauses): array {
		$attrs = is_array($attrs) ? $attrs : [$attrs];
		$params = [
			'q'                    => $q,
			'limit'                => 0,
			'facets'               => $attrs,
			'attributesToRetrieve' => [Schema::PK],
		];
		if ($filter = $this->filterString($clauses)) { $params['filter'] = $filter; }

		$response = $this->engine->getClient()->search($index, $params);
		$distributions = $response['facetDistribution'] ?? [];

		if (sizeof($attrs) === 1) {
			$d = $distributions[$attrs[0]] ?? null;
			return is_array($d) ? $d : [];
		}
		return is_array($distributions) ? $distributions : [];
	}

	/**
	 * Attributs à interroger pour une facette ou un critère `has` : l'attribut d'un élément
	 * de métadonnée, ou la facette de la table liée — restreinte à ses types de relation si
	 * la configuration le demande.
	 */
	private function hasAttributes(string $subject_table, array $info): ?array {
		if (!empty($info['element_code'])) {
			// Divergence connue et assumée : un attribut posé mais vide compte « oui » en SQL
			// (sauf omit_blank_values) alors que l'index ne stocke jamais les vides. On ne
			// traduit que la variante omit_blank_values, la seule dont le sens coïncide.
			if (empty($info['omit_blank_values'])) { return null; }
			$parts = explode('.', (string)$info['element_code']);
			return [$this->schema->fieldName($subject_table, array_pop($parts))];
		}

		$table = $info['table'] ?? null;
		if (!$table || !in_array($table, Schema::FACET_TABLES, true)) { return null; }

		$rel_types = $info['restrict_to_relationship_types'] ?? null;
		if (is_array($rel_types) && sizeof($rel_types)) {
			$attrs = [];
			foreach ($rel_types as $code) {
				if (!is_string($code) || !strlen($code)) { return null; }   // ids numériques : au SQL
				$attrs[] = $this->schema->facetAttribute($table, $code);
			}
			return $attrs;
		}

		return [$this->schema->facetAttribute($table)];
	}

	/**
	 * Attribut de facette d'une autorité, compte tenu d'une éventuelle restriction à un type
	 * de relation. Plusieurs types ne s'agrègent pas sans double compte : au SQL.
	 */
	private function authorityAttribute(string $subject_table, array $info): ?string {
		$table = $info['table'] ?? null;
		if (!$table || !in_array($table, Schema::FACET_TABLES, true)) { return null; }

		$rel_types = $info['restrict_to_relationship_types'] ?? null;
		if (is_array($rel_types) && sizeof($rel_types)) {
			if (sizeof($rel_types) > 1) { return null; }
			$code = reset($rel_types);
			if (!is_string($code) || !strlen($code)) { return null; }
			return $this->schema->facetAttribute($table, $code);
		}

		return $this->schema->facetAttribute($table);
	}

	/**
	 * Libellés et généalogie des enregistrements d'autorité, en deux requêtes — la même
	 * décomposition que le socle, qui l'a mesurée plus rapide que la jointure.
	 *
	 * @return array [id => [label, parent_id, hierarchy_id, type_id, access]]
	 */
	private function authorityRows($t_rel, array $ids): array {
		if (!sizeof($ids)) { return []; }

		$table = $t_rel->tableName();
		$pk    = $t_rel->primaryKey();

		$fields = [$pk];
		if ($t_rel->hasField('parent_id')) { $fields[] = 'parent_id'; }
		if ($t_rel->hasField('type_id'))   { $fields[] = 'type_id'; }
		if ($t_rel->hasField('access'))    { $fields[] = 'access'; }
		if ($hier_id_fld = $t_rel->getProperty('HIERARCHY_ID_FLD')) { $fields[] = $hier_id_fld; }
		$has_deleted = $t_rel->hasField('deleted');

		$rows = [];
		foreach (array_chunk($ids, 5000) as $chunk) {
			$qr = $this->db->query("
				SELECT " . join(', ', $fields) . "
				FROM {$table}
				WHERE {$pk} IN (?)" . ($has_deleted ? " AND deleted = 0" : ''), [$chunk]);
			while ($qr->nextRow()) {
				$row = $qr->getRow();
				$rows[(int)$row[$pk]] = [
					'parent_id'    => $row['parent_id'] ?? null,
					'type_id'      => $row['type_id'] ?? null,
					'access'       => $row['access'] ?? null,
					'hierarchy_id' => $hier_id_fld ? ($row[$hier_id_fld] ?? null) : null,
					'label'        => null,
				];
			}
		}

		if (method_exists($t_rel, 'getPreferredDisplayLabelsForIDs')) {
			$labels = $t_rel->getPreferredDisplayLabelsForIDs(array_keys($rows));
			foreach ($rows as $id => $row) {
				$label = $labels[$id] ?? null;
				if (is_array($label)) { $label = reset($label); }
				$label = (string)$label;

				// Une autorité sans étiquette préférée disparaît de la facette du socle : sa
				// requête de libellés ne la rend pas, et caExtractValuesByUserLocale écarte
				// l'entrée vide. Rendre « ??? » à sa place ferait apparaître dans la facette
				// une valeur que le SQL n'y met pas — et qui ne dirait rien à personne.
				if (!strlen($label)) { unset($rows[$id]); continue; }

				$rows[$id]['label'] = $label;
			}
		}

		return $rows;
	}

	/**
	 * Valeurs d'une liste, indexées par identifiant et par valeur, avec la clé de tri de la
	 * liste — l'équivalent du `$va_list_items_by_value` du socle, en cache par processus.
	 */
	private function listItems(string $list_code): array {
		if (isset(self::$list_cache[$list_code])) { return self::$list_cache[$list_code]; }

		$t_list = new \ca_lists();
		$by_id = $by_value = [];
		$sort_key = 'label';

		if ($t_list->load(['list_code' => $list_code])) {
			switch ((int)$t_list->get('default_sort')) {
				case __CA_LISTS_SORT_BY_RANK__:       $sort_key = 'rank';       break;
				case __CA_LISTS_SORT_BY_VALUE__:      $sort_key = 'item_value'; break;
				case __CA_LISTS_SORT_BY_IDENTIFIER__: $sort_key = 'idno';       break;
				default:                              $sort_key = 'label';      break;
			}

			$items = $t_list->getItemsForList($list_code);
			if (is_array($items) && function_exists('caExtractValuesByUserLocale')) {
				foreach (caExtractValuesByUserLocale($items) as $item_id => $item) {
					$entry = [
						'label'      => $item['name_plural'] ?? ($item['name_singular'] ?? ($item['idno'] ?? (string)$item_id)),
						'rank'       => $item['rank'] ?? null,
						'item_value' => $item['item_value'] ?? null,
						'idno'       => $item['idno'] ?? null,
						'access'     => isset($item['access']) ? (int)$item['access'] : null,
					];
					$entry['label'] = (string)$entry['label'];
					// La clé de tri « label » se lit dans l'entrée elle-même.
					$entry['label_sort'] = $entry['label'];
					$by_id[(string)$item_id] = $entry;
					if (isset($item['item_value']) && strlen((string)$item['item_value'])) {
						$by_value[(string)$item['item_value']] = $entry;
					}
				}
			}
		}

		if ($sort_key === 'label') { $sort_key = 'label_sort'; }

		return self::$list_cache[$list_code] = [$by_id, $by_value, $sort_key];
	}

	/**
	 * L'index porte-t-il au moins un attribut de facette ? Sinon, il date d'avant cette
	 * version du connecteur et tout est décliné jusqu'à réindexation.
	 */
	private function indexHasFacets(string $index): bool {
		foreach ($this->engine->indexFields($index) as $attr) {
			if (strpos($attr, Schema::FACET) === 0) { return true; }
		}
		return false;
	}

	private function filterString(array $clauses): string {
		return join(' AND ', array_filter($clauses, 'strlen'));
	}

	/**
	 * Liste `IN` du langage de filtre. Les intrinsèques adossés à des listes sont indexés en
	 * chaînes, les compteurs et bits en nombres : chaque valeur numérique est émise sous les
	 * deux formes, l'égalité de Meilisearch ne confondant jamais `1` et `"1"`.
	 */
	private function inList(array $values): string {
		$out = [];
		foreach ($values as $value) {
			if (is_int($value) || is_float($value)) {
				$out[] = (string)$value;
				$out[] = $this->quote((string)$value);
			} elseif (is_numeric((string)$value)) {
				$out[] = (string)$value;
				$out[] = $this->quote((string)$value);
			} else {
				$out[] = $this->quote((string)$value);
			}
		}
		return '[' . join(', ', array_unique($out)) . ']';
	}

	private function quote(string $value): string {
		return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
	}
}
