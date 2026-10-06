<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/Meilisearch.php — le connecteur.
 *
 * Installé par lien symbolique en app/lib/Plugins/SearchEngine/Meilisearch.php, sélectionné
 * par `search_engine_plugin = Meilisearch` dans app/conf/local/app.conf.
 *
 * Le contrat est celui de IWLPlugSearchEngine : trois blocs — cycle de vie, indexation,
 * recherche. Deux partis pris structurent l'implémentation :
 *
 *   • Un index Meilisearch par table sujet, ne contenant que des identifiants et du texte
 *     cherchable. Rien d'affichable : CollectiveAccess relit toujours la base pour l'affichage.
 *
 *   • Les filtres de résultat (supprimé, accès, type, source…) sont appliqués en SQL sur les
 *     identifiants rendus par le moteur, comme le fait SqlSearch2 — et non traduits en filtres
 *     Meilisearch. Un filtre porte souvent sur un champ qu'on n'a aucune raison d'indexer, et
 *     un filtre qui ne s'applique pas silencieusement, c'est un enregistrement supprimé qui
 *     réapparaît dans les résultats.
 *
 * Comportement en cas de panne du moteur, assumé et dissymétrique :
 *   – à la recherche : on journalise et on rend zéro résultat, l'application reste utilisable ;
 *   – à l'indexation : on lève. Un index silencieusement incomplet est pire qu'un échec franc.
 * ---------------------------------------------------------------------- */

require_once(__CA_LIB_DIR__ . '/Configuration.php');
require_once(__CA_LIB_DIR__ . '/Datamodel.php');
require_once(__CA_LIB_DIR__ . '/Plugins/WLPlug.php');
require_once(__CA_LIB_DIR__ . '/Plugins/IWLPlugSearchEngine.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/BaseSearchPlugin.php');

require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/MeilisearchResult.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Log.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Config.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Client.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Schema.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Document.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Query.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Facets.php');

// `created:` et `modified:` s'interrogent contre l'historique, pas contre l'index :
// il faut savoir lire une expression de date et retrouver un usager par son identifiant.
require_once(__CA_LIB_DIR__ . '/Parsers/TimeExpressionParser.php');
require_once(__CA_MODELS_DIR__ . '/ca_users.php');

class WLPlugSearchEngineMeilisearch extends BaseSearchPlugin implements IWLPlugSearchEngine {
	# -------------------------------------------------------
	/** @var Meilisearch\Config */
	protected $ms_config;

	/** @var Meilisearch\Schema */
	protected $schema;

	/** @var Meilisearch\Document */
	protected $doc_builder;

	/** @var Meilisearch\Client */
	static protected $client;

	/** Document en cours de construction : [attribut => valeurs] */
	protected $row_buffer = [];

	protected $indexing_subject_tablenum = null;
	protected $indexing_subject_row_id   = null;

	/** Documents en attente d'écriture : [nom de table][row_id] => fragment */
	static protected $write_buffer = [];

	/** Identifiants en attente de suppression : [nom de table] => [row_id, …] */
	static protected $delete_buffer = [];

	/** Nombre de documents dans $write_buffer, tenu à jour pour éviter un comptage à chaque appel */
	static protected $write_buffer_size = 0;

	/** Index dont les réglages ont déjà été appliqués dans ce processus */
	static protected $prepared_indexes = [];

	/** Tâches Meilisearch enfilées sans attente, en attente de vérification à la fin */
	static protected $pending_tasks = [];

	/** Attributs réellement présents dans chaque index, relevés une fois par processus */
	static protected $index_fields = [];

	/** Attributs filtrables déclarés par index : [index][attribut] => true */
	static protected $filterable_attributes = [];

	/**
	 * Pourquoi la dernière facette demandée a été déclinée — nul si elle a été rendue.
	 * Sert au diagnostic (`comparer-facettes.php`) : décliner est sûr, mais opaque, et toutes
	 * les raisons ne se valent pas. « type non traduit » est une limite assumée ; « champ non
	 * déclaré filtrable » est du travail qui dort.
	 */
	static protected $last_facet_reason = null;

	public static function lastBrowseFacetReason(): ?string { return self::$last_facet_reason; }

	/**
	 * Compteurs de recherche, sur le modèle de ceux du client : deux additions, et la réponse
	 * à la question qui décide du dimensionnement — le temps d'une recherche part-il dans le
	 * moteur, dans le filtrage SQL, ou dans CollectiveAccess ?
	 *
	 * `filter_ids` est le nombre d'identifiants passés à la clause `IN (…)`, cumulé : c'est
	 * lui qui croît avec le fonds, et c'est lui qu'on soupçonne.
	 */
	static protected $search_stats = ['filter_calls' => 0, 'filter_seconds' => 0.0, 'filter_ids' => 0];

	public static function searchStats(): array { return self::$search_stats; }

	public static function resetSearchStats(): void {
		self::$search_stats = ['filter_calls' => 0, 'filter_seconds' => 0.0, 'filter_ids' => 0];
	}

	# -------------------------------------------------------
	/**
	 * Tokenisation du connecteur.
	 *
	 * Elle délègue à celle de SqlSearch2 — la symétrie entre ce qui est indexé et ce qui est
	 * cherché doit rester celle du socle — après avoir purgé l'encodage du texte reçu.
	 *
	 * Sans cette purge, `preg_replace('![\']+!u', …)` de SqlSearch2::tokenize() rend `null`, et non
	 * une chaîne, dès qu'il bute sur une séquence UTF-8 mal formée. Ce `null` part ensuite dans
	 * `caIdentifyAlphabet(string $text)`, dont le paramètre n'est pas nullable : TypeError fatale en
	 * PHP 8, au milieu d'une réindexation, sur une seule valeur mal encodée. Constaté sur le fonds
	 * 130.32, où une réindexation est tombée après 84 minutes.
	 *
	 * Note : ce point d'entrée n'est atteint que si `caTokenizeString()` interroge réellement la
	 * classe du moteur. Le socle non corrigé teste `method_exists('SearchBase', <nom de classe>)`,
	 * toujours faux, et retombe donc inconditionnellement sur SqlSearch2.
	 */
	static public function tokenize(?string $content, ?bool $for_search=false, ?int $index=0) : array {
		$content = (string)$content;
		if(!mb_check_encoding($content, 'UTF-8')) {
			$content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
		}
		require_once(__CA_LIB_DIR__.'/Plugins/SearchEngine/SqlSearch2.php');

		// SqlSearch2::tokenize() n'initialise que `whitespace_tokenizer_regex` quand elle est
		// nulle ; celles de ponctuation et de séparation ne sont posées que par son
		// *constructeur*. Appelée statiquement sans qu'une instance ait jamais existé — ce qui
		// est le cas dès que Meilisearch est le moteur en service — elle découpe donc sur les
		// espaces sans rien nettoyer : « …Saint-Roch[Ham-sur-Heure]… » garde ses crochets, là où
		// la même chaîne perd sa ponctuation sous SqlSearch2. Les tokens dépendraient alors du
		// moteur configuré, ce qui est l'inverse du but.
		//
		// Une instance, une seule fois, suffit à poser les trois expressions. À remonter en
		// amont avec la correction de caTokenizeString().
		static $amorce = false;
		if (!$amorce) {
			$amorce = true;
			try { new WLPlugSearchEngineSqlSearch2(); } catch (\Exception $e) {
				Meilisearch\Log::warn('Tokeniseur du socle non amorcé : ' . $e->getMessage());
			}
		}

		return WLPlugSearchEngineSqlSearch2::tokenize($content, $for_search, $index);
	}

	# -------------------------------------------------------
	public function __construct($db = null) {
		$this->ms_config = new Meilisearch\Config();

		// Les deux moitiés vont ensemble : les réglages du connecteur vivent dans la
		// configuration du greffon applicatif. Sans lui, on ne sait même pas qui appeler.
		$this->ms_config->requireAppPlugin();

		Meilisearch\Log::setDebug($this->ms_config->debug());

		$this->schema      = new Meilisearch\Schema($this->ms_config->indexPrefix());
		$this->doc_builder = new Meilisearch\Document($this->schema, $this->ms_config->tokenizeLikeSqlSearch());

		parent::__construct($db);
	}
	# -------------------------------------------------------
	public function init() {
		$this->options = [
			'start'                 => 0,
			'limit'                 => $this->ms_config->searchLimit(),
			'maxIndexingBufferSize' => $this->ms_config->indexingBufferSize(),
			// Attendre que Meilisearch ait vraiment absorbé le lot avant de rendre la main.
			// Coûteux, mais c'est la seule façon qu'un `rebuild-search-index` qui se termine
			// signifie « l'index est prêt » — et que les tests soient reproductibles.
			// 06/10/2026 : attendre en ligne de commande, pas dans une requete web. La
			// garantie qui compte — reindexation terminee = index pret — ne vaut que pour
			// le CLI. Dans une requete web l'attente ne servait qu'au confort « retrouvable
			// des la page suivante », et coutait 15 s par enregistrement.
			'waitForIndexing'       => (caIsRunFromCLI()
				? $this->ms_config->waitForIndexingCli()
				: $this->ms_config->waitForIndexingInWeb()),
			// Combien de lots peuvent rester enfilés sans qu'on attende. Les versements de
			// mi-parcours n'attendent pas — c'est le seul temps mort qu'un connecteur puisse
			// supprimer, et il pesait 10 % d'une réindexation — mais laisser la file grossir
			// sans limite ferait porter à Meilisearch des payloads que personne ne réclame.
			// Le producteur est de toute façon dix fois plus lent que le moteur : ce plafond
			// est une sécurité, pas un régulateur.
			'maxPendingTasks'       => 32,
			// Posée par SearchIndexer du fork lescollections (SearchIndexer.php:82). Le
			// connecteur n'a pas d'indexation asynchrone propre — Meilisearch l'est déjà, et
			// c'est nous qui attendons — mais l'option doit exister : setOption() refuse en
			// silence ce qu'il ne connaît pas, et un réglage refusé en silence est un piège.
			'useAsync'              => false,
		];

		$this->capabilities = [
			'incremental_reindexing' => true,
			'restrict_to_fields'     => false,
		];
	}
	# -------------------------------------------------------
	public function engineName() {
		return 'Meilisearch';
	}
	# -------------------------------------------------------
	public function __destruct() {
		if (defined('__CollectiveAccess_Installer__') && __CollectiveAccess_Installer__) { return; }
		try {
			$this->flush();
		} catch (\Exception $e) {
			Meilisearch\Log::error('Écriture finale de l\'index impossible : ' . $e->getMessage());
		}
	}
	# -------------------------------------------------------
	/**
	 * @return Meilisearch\Client
	 */
	public function getClient() {
		if (!self::$client) {
			self::$client = new Meilisearch\Client(
				$this->ms_config->url(),
				$this->ms_config->apiKey(),
				$this->ms_config->timeout(),
				$this->ms_config->taskTimeout()
			);
		}
		return self::$client;
	}

	public function getConfig(): Meilisearch\Config { return $this->ms_config; }
	public function getSchema(): Meilisearch\Schema { return $this->schema; }

	/**
	 * Le moteur répond-il ? Utilisé par le greffon applicatif pour l'écran de vérification.
	 */
	public function isAvailable(): bool {
		return $this->getClient()->isAvailable();
	}

	public function isReindexing(): bool {
		return (defined('__CollectiveAccess_IS_REINDEXING__') && __CollectiveAccess_IS_REINDEXING__);
	}

	# -------------------------------------------------------
	# Recherche
	# -------------------------------------------------------
	/**
	 * @param int    $subject_tablenum   Table sujet.
	 * @param string $search_expression  Expression réécrite, sérialisée.
	 * @param array  $filters            Filtres de résultat posés par SearchEngine.
	 * @param mixed  $rewritten_query    Zend_Search_Lucene_Search_Query_Boolean, ou null.
	 *
	 * @return WLPlugSearchEngineMeilisearchResult
	 */
	public function search(int $subject_tablenum, string $search_expression, array $filters, $rewritten_query) {
		$this->initSearch($subject_tablenum, $search_expression, $filters, $rewritten_query);

		$started = microtime(true);
		$index   = $this->schema->indexName($subject_tablenum);

		try {
			$query = new Meilisearch\Query(
				$this->schema, $search_expression, $rewritten_query,
				(string)\Datamodel::getTableName($subject_tablenum),
				$this->ms_config->tokenizeLikeSqlSearch()
			);
			$this->searched_terms = $query->getSearchedTerms();

			foreach ($query->getUnsupported() as $note) {
				Meilisearch\Log::warn("Requête « {$search_expression} » : {$note}");
			}

			$hits = $this->execute($index, $subject_tablenum, $query);
		} catch (Meilisearch\ClientException $e) {
			Meilisearch\Log::error("Recherche « {$search_expression} » sur {$index} : " . $e->getMessage());

			// **Une requête refusée rend zéro ; une panne lève.** C'est la convention du socle,
			// tenue par le connecteur ElasticSearch : il n'attrape que `BadRequest400Exception`
			// — la requête malformée — et laisse remonter tout le reste, nœud injoignable
			// compris (ElasticSearch.php:409).
			//
			// Le connecteur attrapait tout, et rendait donc zéro moteur éteint. Une panne
			// devenait indiscernable d'un fonds vide : le catalogueur lit « il n'y a rien de
			// tel » là où il faut lire « la recherche n'a pas eu lieu », et il agit en
			// conséquence. C'est le pire mode de défaillance possible, et le journal ne le
			// rattrape que pour qui le surveille.
			//
			// Le code porté par l'exception vaut 0 quand le moteur est injoignable, le statut
			// HTTP sinon (Client::request).
			if ((int)$e->getCode() === 400) {
				return new WLPlugSearchEngineMeilisearchResult([], [], $subject_tablenum);
			}
			throw new ApplicationException(_t('Search engine unavailable: %1', $e->getMessage()));
		}

		$hits = $this->applyResultFilters($subject_tablenum, $hits, $filters);

		Meilisearch\Log::debug(sprintf(
			'Recherche %s « %s » → %d résultat(s) en %.1f ms',
			$index, $search_expression, sizeof($hits), (microtime(true) - $started) * 1000
		));

		return new WLPlugSearchEngineMeilisearchResult($hits, [], $subject_tablenum);
	}
	# -------------------------------------------------------
	/**
	 * Exécute le plan de requête.
	 *
	 * Toutes les feuilles partent en un seul `/multi-search`, quelle que soit la profondeur du
	 * plan : le coût d'une requête booléenne, c'est un aller-retour, pas un par opérateur.
	 * L'arbre est ensuite évalué sur les identifiants rendus.
	 *
	 * @return array identifiants, dans l'ordre de pertinence de la première feuille positive
	 */
	private function execute(string $index, int $subject_tablenum, Meilisearch\Query $query): array {
		if ($query->isMatchAll()) { return $this->allIds($index); }

		$plan = $query->getPlan();
		if (!$plan) { return []; }

		// Un plan peut n'avoir aucune feuille et restreindre quand même : `created:2020` ne
		// demande rien au moteur, tout à l'historique. On ne rend donc plus zéro d'emblée.
		$searches = $query->getLeafSearches();
		$results  = [];

		if (sizeof($searches)) {
			$this->warnAboutUnindexedFields($index, $searches);

			if (sizeof($searches) === 1) {
				$params  = reset($searches);
				$results = [key($searches) => $this->extractIds($this->getClient()->search($index, $params))];
			} else {
				$queries = [];
				$ids     = [];
				foreach ($searches as $leaf_id => $params) {
					$queries[] = array_merge(['indexUid' => $index], $params);
					$ids[]     = $leaf_id;
				}
				$responses = $this->getClient()->multiSearch($queries);

				foreach ($ids as $rang => $leaf_id) {
					$results[$leaf_id] = $this->extractIds($responses[$rang] ?? []);
				}
			}
		}

		return $this->evaluate($index, $subject_tablenum, $plan, $results);
	}
	# -------------------------------------------------------
	/**
	 * Évalue un nœud du plan sur les résultats des feuilles.
	 *
	 * Trois catégories, et l'ordre dans lequel on les combine porte le sens :
	 *
	 *   – les *exigés* s'intersectent ;
	 *   – les *facultatifs* s'unissent entre eux, puis s'ajoutent aux exigés : `a AND b OR c`
	 *     rend bien (a∧b)∨c, ce qu'attend celui qui a tapé la requête ;
	 *   – les *exclus* se retranchent en dernier. S'il n'y a rien à retrancher — une recherche
	 *     qui n'est qu'une négation — on part du fonds entier plutôt que de rendre vide : « tout
	 *     sauf ceci » est une demande légitime, et répondre « rien » est une réponse fausse.
	 *
	 * L'ordre de pertinence est celui du premier ensemble non vide rencontré ; les opérations
	 * ensemblistes de PHP préservent l'ordre de leur premier opérande.
	 */
	private function evaluate(string $index, int $subject_tablenum, array $node, array $results): array {
		if ($node['type'] === 'leaf') {
			// Une feuille qui porte un attribut absent de l'index ne peut rien rendre. Quand cet
			// attribut désigne la clé primaire d'une table liée, la question a pourtant un sens et
			// une réponse : on va la chercher dans la table de relation. Voir relatedIds().
			$ids = $this->relatedIds($index, $subject_tablenum, $node);
			if ($ids !== null) { return $ids; }

			return $results[$node['id']] ?? [];
		}
		if ($node['type'] === 'changelog') { return $this->changeLogIds($subject_tablenum, $node); }

		$resultat = null;

		foreach ($node['must'] as $enfant) {
			$ids = $this->evaluate($index, $subject_tablenum, $enfant, $results);
			$resultat = ($resultat === null) ? $ids : array_values(array_intersect($resultat, $ids));
		}

		if (sizeof($node['should'])) {
			$union = [];
			foreach ($node['should'] as $enfant) {
				$union = array_merge($union, $this->evaluate($index, $subject_tablenum, $enfant, $results));
			}
			$union = array_values(array_unique($union));

			$resultat = ($resultat === null) ? $union : array_values(array_unique(array_merge($resultat, $union)));
		}

		if (sizeof($node['not'])) {
			if ($resultat === null) { $resultat = $this->allIds($index); }
			foreach ($node['not'] as $enfant) {
				$resultat = array_values(array_diff($resultat, $this->evaluate($index, $subject_tablenum, $enfant, $results)));
			}
		}

		return $resultat === null ? [] : $resultat;
	}
	# -------------------------------------------------------
	/**
	 * Les identifiants qu'une recherche par clé de fiche liée désigne — `ca_collections.collection_id:123`
	 * lancé sur les objets, `ca_storage_locations.location_id:6120` lancé sur les opérations.
	 *
	 * POURQUOI CE CHEMIN EXISTE
	 *
	 * L'inspecteur du socle fabrique exactement ces requêtes : le lien « N liés à … » vaut
	 * `caSearchLink(…, <table>.<clé>:<id>)` (displayHelpers.php:1585). Or une telle clé n'est
	 * indexée que si `search_indexing.conf` la déclare pour la table cherchée, ce qu'il ne fait
	 * que pour une minorité de couples : relevé sur comodo au 11/09/2026, 13 couples sur 36 —
	 * `ca_objects` les déclare tous, `ca_movements` et `ca_storage_locations` aucun. Les autres
	 * liens rendent zéro, en silence, alors que la relation existe en base. Constaté sur une
	 * demande de versement portant 36 opérations : le lien n'en rendait aucune.
	 *
	 * Le réflexe serait de compléter `search_indexing.conf`. C'est un piège, et il est mesuré :
	 * déclarer une clé la fait entrer dans le graphe de dépendances de l'indexeur, si bien que
	 * l'enregistrement de la fiche liée réindexe TOUTES les fiches qui la citent — et
	 * `disable_out_of_process_search_indexing = 1` (app.conf:893) rend cette réindexation
	 * SYNCHRONE, dans la requête web de l'usager. Enregistrer l'entité « SRA de Montpellier »
	 * réindexerait 7 143 opérations avant de rendre la main ; un centre de conservation, 2 800.
	 * C'est le mode de défaillance déjà rencontré le 29/08/2026 puis le 11/09/2026.
	 *
	 * D'où ce chemin : la clé n'entre pas dans l'index, elle ne pèse rien, et elle n'est
	 * consultée que si quelqu'un la demande nommément. Un identifiant ne changeant jamais, il
	 * n'y a rien à tenir à jour — la relation elle-même est déjà réindexée des deux côtés quand
	 * elle est créée ou défaite.
	 *
	 * QUAND CE CHEMIN PREND LA MAIN
	 *
	 * Seulement si l'attribut visé est ABSENT de l'index, c'est-à-dire quand la voie Meilisearch
	 * ne peut structurellement rendre que zéro. Les couples que `search_indexing.conf` déclare
	 * gardent leur comportement au bit près — ce qui compte, car l'index n'a pas la même
	 * sémantique : il range aussi les ANCÊTRES d'une fiche hiérarchique (chercher un
	 * département rend les objets de ses communes), là où la table de relation ne connaît que
	 * le lien direct. On ne remplace donc rien ; on comble.
	 *
	 * @return array|null identifiants, ou null si le nœud n'est pas une recherche par clé liée
	 * — auquel cas l'appelant reprend la voie normale.
	 */
	private function relatedIds(string $index, int $subject_tablenum, array $node): ?array {
		$scope = (string)($node['scope'] ?? '');
		if ($scope === '' || $scope === '*') { return null; }

		// L'attribut est-il vraiment absent de l'index ? Si le relevé a échoué, on ne présume
		// rien et on laisse la voie normale : mieux vaut le comportement d'hier qu'un détour
		// pris sur une information incertaine.
		$fields = $this->indexFields($index);
		if (!sizeof($fields) || in_array($scope, $fields, true)) { return null; }

		// `ca_collections__collection_id`, éventuellement suivi d'un code de type de relation
		// que l'on ignore ici : restreindre au type demanderait de le résoudre, et le socle ne
		// fabrique pas de tels liens.
		$parts = explode('__', $scope);
		if (sizeof($parts) < 2) { return null; }
		$table = $parts[0];
		$field = $parts[1];

		if (!($t_rel = \Datamodel::getInstanceByTableName($table, true))) { return null; }
		if ($field !== $t_rel->primaryKey()) { return null; }

		if (!($t_subject = \Datamodel::getInstanceByTableNum($subject_tablenum, true))) { return null; }
		$subject_table = $t_subject->tableName();
		if ($subject_table === $table) { return null; }   // auto-relation : colonnes gauche/droite, pas ce cas

		// Une seule valeur, exacte, numérique. `collection_id:abc` n'a pas de sens, et une
		// troncature (`collection_id:12*`) encore moins : on décline plutôt que d'inventer.
		$clauses = $node['clauses'] ?? [];
		if (sizeof($clauses) !== 1) { return null; }
		$texte = (string)($clauses[0]['text'] ?? '');
		if (empty($clauses[0]['exact']) || !preg_match('!^[0-9]+$!', $texte)) { return null; }
		$row_id = (int)$texte;
		if ($row_id <= 0) { return null; }

		// La table de relation, telle que le modèle la connaît — jamais reconstituée par
		// concaténation : le parc porte les deux ordres de nommage (`ca_places_x_collections`
		// mais `ca_collections_x_storage_locations`).
		$path = \Datamodel::getPath($subject_table, $table);
		if (!is_array($path) || sizeof($path) !== 3) { return null; }
		$etapes   = array_keys($path);
		$rel_table = $etapes[1];
		if (!($t_link = \Datamodel::getInstanceByTableName($rel_table, true))) { return null; }

		$subject_key = $t_subject->primaryKey();
		if (!$t_link->hasField($subject_key) || !$t_link->hasField($field)) { return null; }

		$started = microtime(true);
		try {
			$qr = $this->db->query(
				"SELECT DISTINCT l.{$subject_key} FROM {$rel_table} l WHERE l.{$field} = ?", [$row_id]
			);
		} catch (\Exception $e) {
			Meilisearch\Log::error("Recherche par clé liée {$scope}:{$row_id} sur {$index} : " . $e->getMessage());
			return null;
		}

		$ids = [];
		while ($qr->nextRow()) { $ids[] = (int)$qr->get($subject_key); }

		Meilisearch\Log::debug(sprintf(
			'Clé liée %s:%d sur %s → %d identifiant(s) via %s en %.1f ms',
			$scope, $row_id, $index, sizeof($ids), $rel_table, (microtime(true) - $started) * 1000
		));

		// Les fiches supprimées ne sont pas écartées ici : les filtres de résultat posés par
		// SearchEngine (`deleted = 0`) s'en chargent, comme pour les identifiants venus de
		// l'historique. Même chemin que changeLogIds().
		return $ids;
	}
	# -------------------------------------------------------
	/**
	 * Les identifiants que l'historique désigne, pour un nœud `created:` / `modified:`.
	 *
	 * Transposition de `SqlSearch2::_processQueryChangeLog()`, dont la sémantique est reprise au
	 * mot près : c'est la référence, et l'objectif du connecteur est de l'égaler.
	 *
	 *   – `created:` — une entrée d'insertion (`changetype = 'I'`) portant sur la table sujet ;
	 *   – `modified:` — les entrées de mise à jour (`'U'`) portant sur la table sujet, *union*
	 *     les entrées dont la fiche n'est que sujet indirect, par `ca_change_log_subjects`.
	 *     Cette seconde branche n'est pas un raffinement : modifier une étiquette, une valeur
	 *     attributaire ou une relation n'écrit rien dans `ca_objects`, et sans elle « modifié
	 *     cette année » ne rendrait presque rien. Elle ne filtre pas le type de changement,
	 *     comme chez SqlSearch2 ;
	 *   – `modified.lpelletier:` — restriction à un usager. Les blancs d'un identifiant de
	 *     connexion se tapent avec un souligné.
	 *
	 * Les fiches supprimées ne sont pas écartées ici. Elles le sont par les filtres de résultat
	 * (`deleted = 0`) que SearchEngine pose sur toute recherche, et qui s'appliquent aussi bien
	 * aux identifiants venus de l'historique qu'à ceux venus du moteur — un identifiant dont la
	 * fiche a disparu de la table ne survit pas au `WHERE … IN (…)`. Même chemin que SqlSearch2.
	 *
	 * Deux écarts assumés avec le socle, tous deux du côté du dire :
	 *
	 *   – un numéro d'usager est accepté tel quel (`modified.3:2026`). Chez SqlSearch2, le test
	 *     `is_int()` porte sur un fragment de chaîne et ne peut jamais être vrai : le numéro y
	 *     part en recherche d'identifiant de connexion, n'en trouve aucun, et la restriction
	 *     disparaît en silence. La forme documentée est rétablie plutôt que recopiée ;
	 *   – un usager introuvable est journalisé. Le socle laisse alors tomber la restriction sans
	 *     rien dire, ce qui rend « modifié par X » comme « modifié par n'importe qui » — on garde
	 *     ce résultat, pour ne pas diverger, mais on le dit dans le journal.
	 *
	 * @return array identifiants, du plus récemment touché au plus ancien. Une recherche par
	 * date n'a pas d'ordre de pertinence — SqlSearch2 rend l'ordre où la base a servi les lignes,
	 * qui ne veut rien dire ; le plus récent d'abord en veut un, et ne coûte rien de plus.
	 */
	private function changeLogIds(int $subject_tablenum, array $node): array {
		$started = microtime(true);

		$champ = $node['mode'] . (($node['qualifier'] !== null) ? '.' . $node['qualifier'] : '');

		// Le parseur du socle, celui-là même qu'emploie SqlSearch2 : il lit « 2020 », « avril
		// 2020 », « 12/4/2020 », « hier », dans la langue de l'instance.
		$tep = new TimeExpressionParser();
		if (!$tep->parse($node['text'])) {
			// Le cas courant : `modified.login:lpelletier`, où l'usager a été mis après le
			// deux-points. La syntaxe du socle veut l'inverse — `modified.lpelletier:2026` —
			// parce que ce qui suit le deux-points est toujours une date.
			Meilisearch\Log::warn(
				"Recherche « {$champ}:{$node['text']} » : « {$node['text']} » ne se lit pas comme une date, "
				. "la recherche ne rend rien. Après le deux-points on attend une date (2026, « avril 2020 », "
				. "22/4/2020) ; un usager se pose avant, comme dans modified.lpelletier:2026."
			);
			return [];
		}
		$range = $tep->getUnixTimestamps();
		$start = (int)$range['start'];
		$end   = (int)$range['end'];

		$user_sql = '';
		if (($qualifier = $node['qualifier']) !== null) {
			if (($user_id = $this->userIdForQualifier($qualifier)) === null) {
				Meilisearch\Log::warn("Recherche « {$champ}:{$node['text']} » : usager « {$qualifier} » inconnu ; la restriction par usager n'est pas appliquée (comportement du socle).");
			} else {
				$user_sql = ' AND (ccl.user_id = ' . (int)$user_id . ')';
			}
		}

		// [identifiant => date de la trace la plus récente], pour trier ensuite.
		$dates = [];

		if ($node['mode'] === 'created') {
			$this->collectChangeLog($dates, "
				SELECT ccl.logged_row_id AS row_id, MAX(ccl.log_datetime) AS d
				FROM ca_change_log ccl
				WHERE
					(ccl.log_datetime BETWEEN ? AND ?)
					AND (ccl.logged_table_num = ?)
					AND (ccl.changetype = 'I')
					{$user_sql}
				GROUP BY ccl.logged_row_id", [$start, $end, $subject_tablenum]);
		} else {
			// La fiche elle-même a été enregistrée.
			$this->collectChangeLog($dates, "
				SELECT ccl.logged_row_id AS row_id, MAX(ccl.log_datetime) AS d
				FROM ca_change_log ccl
				WHERE
					(ccl.log_datetime BETWEEN ? AND ?)
					AND (ccl.logged_table_num = ?)
					AND (ccl.changetype = 'U')
					{$user_sql}
				GROUP BY ccl.logged_row_id", [$start, $end, $subject_tablenum]);

			// La fiche n'est que sujet du changement : étiquette, valeur attributaire, relation.
			if ($fenetre = $this->changeLogIdWindow($start, $end)) {
				$this->collectChangeLog($dates, "
					SELECT ccls.subject_row_id AS row_id, MAX(ccl.log_datetime) AS d
					FROM ca_change_log_subjects ccls
					STRAIGHT_JOIN ca_change_log ccl ON ccl.log_id = ccls.log_id
					WHERE
						(ccls.log_id BETWEEN ? AND ?)
						AND (ccls.subject_table_num = ?)
						AND (ccl.log_datetime BETWEEN ? AND ?)
						{$user_sql}
					GROUP BY ccls.subject_row_id",
					[$fenetre[0], $fenetre[1], $subject_tablenum, $start, $end]);
			}
		}

		arsort($dates, SORT_NUMERIC);
		$ids = array_keys($dates);

		Meilisearch\Log::debug(sprintf(
			'Historique %s:%s → %d identifiant(s) en %.1f ms',
			$champ, $node['text'], sizeof($ids), (microtime(true) - $started) * 1000
		));

		return $ids;
	}
	# -------------------------------------------------------
	/**
	 * Le numéro d'usager désigné par ce qui suit le point dans `modified.xxx:`, ou null.
	 *
	 * Un numéro est pris pour tel ; sinon c'est un identifiant de connexion, cherché aussi avec
	 * les soulignés rendus à leurs blancs — un identifiant de connexion peut en contenir, une
	 * expression de recherche non.
	 */
	private function userIdForQualifier(string $qualifier): ?int {
		if (preg_match('!^[0-9]+$!', $qualifier)) { return (int)$qualifier; }

		$t_user = new ca_users();
		if (
			$t_user->load(['user_name' => $qualifier])
			||
			((strpos($qualifier, '_') !== false) && $t_user->load(['user_name' => str_replace('_', ' ', $qualifier)]))
		) {
			return (int)$t_user->getPrimaryKey();
		}
		return null;
	}
	# -------------------------------------------------------
	/**
	 * Verse le résultat d'une requête d'historique dans [identifiant => date la plus récente].
	 */
	private function collectChangeLog(array &$dates, string $sql, array $params): void {
		$qr = $this->db->query($sql, $params);
		while ($qr->nextRow()) {
			if (!($id = (int)$qr->get('row_id'))) { continue; }
			$d = (int)$qr->get('d');
			if (!isset($dates[$id]) || ($d > $dates[$id])) { $dates[$id] = $d; }
		}
	}
	# -------------------------------------------------------
	/**
	 * L'intervalle de `log_id` qui contient à coup sûr toutes les traces de l'intervalle de
	 * dates — ou null s'il n'y en a aucune.
	 *
	 * **C'est ce qui rend `modified:` utilisable sur un gros fonds.** `ca_change_log_subjects`
	 * ne porte pas de date : la seule façon d'y appliquer un intervalle est de passer par
	 * `ca_change_log`. Écrite comme chez SqlSearch2 — jointure dirigée par la date — la requête
	 * fait parcourir au serveur toutes les traces de la période, puis va chercher les sujets une
	 * à une. Sur ce fonds, 26 millions de traces pour l'année 2026 : 23 secondes, quand
	 * `max_execution_time` du serveur web en accorde 30 pour toute la requête HTTP. La recherche
	 * serait rétablie et inutilisable.
	 *
	 * Bornée en `log_id`, la même question se lit dans le seul index `i_log_plus`
	 * (log_id, subject_table_num, subject_row_id) sans jamais ouvrir la table : 7 secondes au
	 * lieu de 23, pour un résultat identique — 39 379 fiches dans les deux cas.
	 *
	 * **La borne ne suppose rien.** On ne postule pas que `log_id` croît avec `log_datetime` —
	 * ce serait vrai en pratique et faux le jour d'une reprise de données. On demande au serveur
	 * le plus petit et le plus grand `log_id` *parmi les traces de l'intervalle* : par
	 * construction, toute trace de l'intervalle a son `log_id` entre les deux. La fenêtre est un
	 * sur-ensemble démontré, et la condition de date reste posée dans la requête, qui décide
	 * seule de ce qui est retenu.
	 *
	 * @return array|null [borne basse, borne haute]
	 */
	private function changeLogIdWindow(int $start, int $end): ?array {
		$qr = $this->db->query(
			'SELECT MIN(log_id) AS a, MAX(log_id) AS b FROM ca_change_log WHERE log_datetime BETWEEN ? AND ?',
			[$start, $end]
		);
		if (!$qr->nextRow()) { return null; }

		$a = $qr->get('a');
		$b = $qr->get('b');
		if ($a === null || $b === null) { return null; }   // aucune trace dans l'intervalle

		return [(int)$a, (int)$b];
	}
	# -------------------------------------------------------
	/**
	 * Signale une recherche portant sur un attribut qu'aucun document ne porte.
	 *
	 * C'est le piège le plus discret du connecteur : chercher dans un champ absent de l'index
	 * ne lève rien et ne rend rien. Zéro résultat se lit « il n'y a rien de tel dans le fonds »
	 * alors qu'il faut lire « ce champ n'est pas indexé » — et comme on élague
	 * `search_indexing.conf` pour gagner du temps à l'indexation, chaque élagage fabrique de
	 * ces recherches muettes. Le connecteur ne peut pas y remédier, mais il peut le dire.
	 *
	 * Le relevé coûte une requête par index et par processus, et seulement si la recherche vise
	 * un attribut précis — le cas minoritaire.
	 */
	private function warnAboutUnindexedFields(string $index, array $searches): void {
		$vises = [];
		foreach ($searches as $params) {
			foreach (($params['attributesToSearchOn'] ?? []) as $attribut) { $vises[$attribut] = true; }
		}
		if (!sizeof($vises)) { return; }

		if (!sizeof($this->indexFields($index))) { return; }

		foreach (array_keys($vises) as $attribut) {
			if (in_array($attribut, self::$index_fields[$index], true)) { continue; }

			// Une clé de fiche liée absente de l'index n'est pas une impasse : relatedIds() la
			// résout dans la table de relation. L'annoncer comme sans résultat serait faux.
			if ($this->looksLikeRelatedKey($attribut)) { continue; }

			Meilisearch\Log::warn(
				"Recherche sur « {$attribut} » dans {$index} : aucun document ne porte cet attribut — "
				. "champ absent de search_indexing.conf, ou jamais rempli. La recherche ne rendra rien."
			);
		}
	}
	# -------------------------------------------------------
	/**
	 * L'attribut désigne-t-il la clé primaire d'une table du modèle ? Test de forme seulement,
	 * sans la base : il ne sert qu'à choisir ce qu'on journalise.
	 */
	private function looksLikeRelatedKey(string $attribut): bool {
		$parts = explode('__', $attribut);
		if (sizeof($parts) < 2) { return false; }
		if (!($t = \Datamodel::getInstanceByTableName($parts[0], true))) { return false; }
		return ($parts[1] === $t->primaryKey());
	}
	# -------------------------------------------------------
	/**
	 * Attributs réellement présents dans l'index, relevés une fois par processus. Sert à
	 * l'alerte de champ non indexé et au pont des facettes, qui refuse de filtrer sur un
	 * attribut absent — un filtre muet fabrique des comptes faux.
	 */
	public function indexFields(string $index): array {
		if (!isset(self::$index_fields[$index])) {
			try {
				$stats = $this->getClient()->indexStats($index);
				self::$index_fields[$index] = array_keys($stats['fieldDistribution'] ?? []);
			} catch (Meilisearch\ClientException $e) {
				self::$index_fields[$index] = [];   // sans relevé, on ne dit rien plutôt que de dire faux
			}
		}
		return self::$index_fields[$index];
	}
	# -------------------------------------------------------
	/**
	 * Attributs déclarés filtrables sur l'index, relevés une fois par processus.
	 *
	 * Présent dans les documents et filtrable sont deux choses différentes, et les confondre
	 * coûte cher : filtrer ou facetter sur un attribut non déclaré fait répondre HTTP 400
	 * (`invalid_search_facets`) — un aller-retour perdu et une erreur au journal à chaque
	 * affichage, pour une facette qui de toute façon repartira au SQL. Le pont s'en sert pour
	 * décliner avant d'appeler.
	 */
	public function indexFilterableAttributes(string $index): array {
		if (!isset(self::$filterable_attributes[$index])) {
			try {
				$settings = $this->getClient()->getSettings($index);
				$attributs = is_array($settings['filterableAttributes'] ?? null) ? $settings['filterableAttributes'] : [];
				self::$filterable_attributes[$index] = array_flip($attributs);
			} catch (Meilisearch\ClientException $e) {
				self::$filterable_attributes[$index] = [];
			}
		}
		return array_keys(self::$filterable_attributes[$index]);
	}
	# -------------------------------------------------------
	public function isFilterable(string $index, string $attribute): bool {
		$this->indexFilterableAttributes($index);
		return isset(self::$filterable_attributes[$index][$attribute]);
	}
	# -------------------------------------------------------
	/**
	 * Tous les identifiants de l'index : la recherche qui ne restreint rien, et le point de
	 * départ d'une recherche qui n'est qu'une exclusion.
	 */
	private function allIds(string $index): array {
		return $this->extractIds($this->getClient()->search($index, [
			'q'                    => '',
			'limit'                => $this->getOption('limit'),
			'attributesToRetrieve' => [Meilisearch\Schema::PK],
		]));
	}
	# -------------------------------------------------------
	private function extractIds(array $response): array {
		$ids = [];
		foreach (($response['hits'] ?? []) as $hit) {
			if (isset($hit[Meilisearch\Schema::PK])) { $ids[] = (int)$hit[Meilisearch\Schema::PK]; }
		}
		return $ids;
	}
	# -------------------------------------------------------
	/**
	 * Applique en SQL les filtres de résultat posés par SearchEngine (supprimé, accès, type,
	 * source, hiérarchie…). Même approche que SqlSearch2 : on interroge la base sur les
	 * identifiants rendus par le moteur.
	 */
	private function applyResultFilters(int $subject_tablenum, array $hits, array $filters): array {
		if (!sizeof($filters) || !sizeof($hits)) { return $hits; }

		$started = microtime(true);
		self::$search_stats['filter_calls']++;
		self::$search_stats['filter_ids'] += sizeof($hits);

		if (!($t_instance = Datamodel::getInstance($subject_tablenum, true))) {
			throw new ApplicationException(_t('Invalid subject table: %1', $subject_tablenum));
		}
		$table_name = $t_instance->tableName();
		$wheres     = [];
		$joins      = [];

		foreach ($filters as $filter) {
			$tmp = explode('.', $filter['field']);
			if (sizeof($tmp) < 2) { continue; }
			if (!($fi = Datamodel::getInstance($tmp[0], true))) { continue; }
			if (!$fi->hasField($tmp[1])) { continue; }

			if ($tmp[0] !== $table_name) {
				$path = Datamodel::getPath($table_name, $tmp[0]);
				if (is_array($path) && sizeof($path)) {
					$last_table = null;
					foreach ($path as $table => $info) {
						if ($last_table) {
							$rels = Datamodel::getOneToManyRelations($last_table, $table);
							if (!sizeof($rels)) { $rels = Datamodel::getOneToManyRelations($table, $last_table); }
							if (!sizeof($rels)) { continue; }
							$joins[$table] = ($table == $rels['one_table'])
								? "INNER JOIN {$rels['one_table']} ON {$rels['one_table']}.{$rels['one_table_field']} = {$rels['many_table']}.{$rels['many_table_field']}"
								: "INNER JOIN {$rels['many_table']} ON {$rels['many_table']}.{$rels['many_table_field']} = {$rels['one_table']}.{$rels['one_table_field']}";
						}
						$last_table = $table;
					}
				}
			}

			$where = '(' . $filter['field'] . ' ' . $filter['operator'] . ' ' . $this->filterValueToSql($filter) . ')';

			switch (strtolower($filter['operator'])) {
				case 'in':
					if (stripos((string)$filter['value'], 'null') !== false) {
						$where = "({$where} OR (" . $filter['field'] . ' IS NULL))';
					}
					break;
				case 'not in':
					if (stripos((string)$filter['value'], 'null') !== false) {
						$where = "({$where} OR (" . $filter['field'] . ' IS NOT NULL))';
					}
					break;
			}

			$wheres[] = $where;
		}

		if (!sizeof($wheres)) {
			self::$search_stats['filter_seconds'] += microtime(true) - $started;
			return $hits;
		}

		$pk        = $t_instance->primaryKey(true);
		$sql_joins = join("\n", $joins);

		$qr = $this->db->query("
			SELECT {$pk}
			FROM {$table_name}
			{$sql_joins}
			WHERE {$pk} IN (?) AND " . join(' AND ', $wheres), [$hits]);

		$kept = array_flip($qr->getAllFieldValues($pk));

		// On préserve l'ordre de pertinence rendu par le moteur.
		$filtered = array_values(array_filter($hits, function ($id) use ($kept) { return isset($kept[$id]); }));

		self::$search_stats['filter_seconds'] += microtime(true) - $started;

		return $filtered;
	}
	# -------------------------------------------------------
	private function filterValueToSql(array $filter): string {
		switch (strtolower($filter['operator'])) {
			case '>': case '<': case '=': case '>=': case '<=': case '<>':
				return (string)(int)$filter['value'];

			case 'in': case 'not in':
				$values = [];
				foreach (explode(',', (string)$filter['value']) as $t) {
					if (strtoupper(trim($t)) === 'NULL') { continue; }
					$values[] = (int)preg_replace('![^\d]+!', '', $t);
				}
				// Une liste vide rendrait `IN ()`, une erreur de syntaxe : on rend une
				// condition qui ne retient rien, ce qui est le sens voulu.
				return sizeof($values) ? '(' . join(',', $values) . ')' : '(NULL)';

			case 'is': case 'is not':
			default:
				return is_null($filter['value']) ? 'NULL' : (string)$filter['value'];
		}
	}
	# -------------------------------------------------------
	/**
	 * Recherche rapide, pour l'autocomplétion : pas de syntaxe, pas de filtre, on veut la
	 * réponse la plus courte possible.
	 */
	public function quickSearch($table_num, $search, $options = null) {
		if (!is_array($options)) { $options = []; }
		$limit = (int)caGetOption('limit', $options, 0);

		$index = $this->schema->indexName($table_num);
		try {
			$response = $this->getClient()->search($index, [
				'q'                    => $search,
				'limit'                => $limit > 0 ? $limit : 100,
				'attributesToRetrieve' => [Meilisearch\Schema::PK],
				'matchingStrategy'     => 'all',
			]);
		} catch (Meilisearch\ClientException $e) {
			Meilisearch\Log::error("Recherche rapide « {$search} » sur {$index} : " . $e->getMessage());
			return [];
		}

		return array_flip($this->extractIds($response));
	}
	# -------------------------------------------------------
	/**
	 * Description des correspondances. Meilisearch peut la fournir (`_formatted`), mais le
	 * connecteur ne la demande pas encore : `return_search_result_description_data` doit rester
	 * à 0 dans search.conf.
	 */
	public function _resolveHitInformation($res) {
		return $res;
	}
	# -------------------------------------------------------
	/**
	 * Contenu d'une facette du Browse, calculé par le moteur — le point d'appel du socle,
	 * découvert par method_exists() depuis BrowseEngine::getFacetContent().
	 *
	 * Rend le contenu au format du socle, un booléen si checkAvailabilityOnly, ou null pour
	 * décliner — auquel cas le calcul SQL existant reprend la main, à l'identique. Décliner
	 * est toujours sûr ; répondre engage l'exactitude des comptes, c'est pourquoi le pont
	 * refuse tout ce qu'il ne traduit pas exactement (voir Facets.php).
	 *
	 * @param \BrowseEngine $browse
	 * @return array|bool|null
	 */
	public function getBrowseFacetContent($browse, string $facet_name, array $facet_info, ?array $options = null) {
		self::$last_facet_reason = null;

		if (!$this->ms_config->browseFacets()) {
			self::$last_facet_reason = 'facettes du Browse désactivées (meilisearch_browse_facets = 0)';
			return null;
		}
		if (!is_object($browse) || !method_exists($browse, 'getCriteria')) { return null; }

		try {
			$facets = new Meilisearch\Facets($this);
			$contenu = $facets->facetContent($browse, $facet_name, $facet_info, is_array($options) ? $options : []);
			self::$last_facet_reason = $facets->derniereRaison();
			return $contenu;
		} catch (\Exception $e) {
			self::$last_facet_reason = $e->getMessage();
			// Quoi qu'il arrive ici, le SQL sait faire : on décline, et on le dit au journal.
			Meilisearch\Log::error("Facette « {$facet_name} » : " . $e->getMessage());
			return null;
		}
	}
	# -------------------------------------------------------
	# Indexation
	# -------------------------------------------------------
	public function startRowIndexing(int $subject_tablenum, int $subject_row_id): void {
		$this->row_buffer               = [];
		$this->indexing_subject_tablenum = $subject_tablenum;
		$this->indexing_subject_row_id   = $subject_row_id;
	}
	# -------------------------------------------------------
	public function indexField(int $content_tablenum, string $content_fieldname, int $content_row_id, $content, ?array $options = null) {
		if ($this->indexing_subject_row_id === null) { return null; }

		$fragment = $this->doc_builder->fragment($content_tablenum, $content_fieldname, $content, $options);

		// Le même appel nourrit la facette de la table liée : l'identifiant de l'enregistrement
		// lié (et ses ancêtres) sous `facet__<table>`. C'est la matière du Browse — elle passe
		// ici gratuitement, l'indexeur fournit déjà le row_id. Sauf pour les décomptes : un
		// appel COUNT porte la table liée mais l'identifiant du *sujet* (SearchIndexer::1237),
		// qui passerait pour un lien vers un enregistrement homonyme.
		if (strpos($content_fieldname, 'COUNT') !== 0) {
			$fragment += $this->doc_builder->facetFragment(
				$content_tablenum, $content_row_id, (int)$this->indexing_subject_tablenum, $options
			);

			// Et les valeurs des éléments que browse.conf facette, lues en base. Contrairement
			// à ce qui précède, elles ne passent pas gratuitement : c'est une requête par fiche,
			// consentie parce que les valeurs transmises par l'indexeur portent des variantes de
			// recherche qui n'ont rien à faire dans une facette. Rien n'est lu si la table sujet
			// ne déclare aucune facette de ce type.
			$fragment += $this->doc_builder->attributeFacetFragment(
				(int)$this->indexing_subject_tablenum, (int)$this->indexing_subject_row_id,
				$content_tablenum, (int)$content_row_id
			);
		}

		foreach ($fragment as $attribute => $values) {
			if (!isset($this->row_buffer[$attribute])) { $this->row_buffer[$attribute] = []; }
			$this->row_buffer[$attribute] = array_merge($this->row_buffer[$attribute], $values);
		}

		return null;
	}
	# -------------------------------------------------------
	public function commitRowIndexing() {
		if ($this->indexing_subject_row_id === null) { return; }

		if (sizeof($this->row_buffer)) {
			$table = Datamodel::getTableName($this->indexing_subject_tablenum);
			$this->bufferDocument($table, (int)$this->indexing_subject_row_id, $this->row_buffer);
		}

		$this->row_buffer               = [];
		$this->indexing_subject_tablenum = null;
		$this->indexing_subject_row_id   = null;

		// Le versement n'a lieu qu'entre deux documents : un document n'est jamais coupé en
		// deux écritures, ce qui garantit qu'une fusion partielle ne perd pas de valeurs.
		$this->flushIfBufferFull();
	}
	# -------------------------------------------------------
	/**
	 * Mise à jour d'une valeur déjà indexée, hors cycle startRowIndexing/commitRowIndexing.
	 *
	 * Limite connue : la fusion se fait au niveau de l'attribut. Pour un champ à valeurs
	 * multiples, la valeur envoyée ici remplace l'ensemble des valeurs du champ au lieu de n'en
	 * remplacer qu'une. Le greffon applicatif compense en replanifiant une réindexation
	 * complète de l'enregistrement après enregistrement.
	 */
	public function updateIndexingInPlace($subject_tablenum, $subject_row_ids, $content_tablenum, $content_fieldnum, $content_container_id, $content_row_id, $content, $options = null) {
		// Les valeurs de conteneur arrivent préfixées « CV<n>_ ».
		if (preg_match('!^CV[0-9]+_(.+)$!', (string)$content_fieldnum, $m)) { $content_fieldnum = $m[1]; }

		$fragment = $this->doc_builder->fragment((int)$content_tablenum, (string)$content_fieldnum, $content, is_array($options) ? $options : null);
		if (!sizeof($fragment)) { return; }

		$table = Datamodel::getTableName((int)$subject_tablenum);
		Meilisearch\Log::debug(sprintf('mise à jour en place %s [%s] ← %s',
			$table, join(',', (array)$subject_row_ids), join(',', array_keys($fragment))));
		foreach ((array)$subject_row_ids as $row_id) {
			$this->bufferDocument($table, (int)$row_id, $fragment);
		}

		$this->flushIfBufferFull();
	}
	# -------------------------------------------------------
	public function removeRowIndexing(int $subject_tablenum, int $subject_row_id, ?int $field_tablenum = null, $field_nums = null, ?int $field_row_id = null, ?int $rel_type_id = null) {
		$table = Datamodel::getTableName($subject_tablenum);

		if ($field_tablenum && is_array($field_nums) && sizeof($field_nums)) {
			// Effacement ciblé : on vide les attributs concernés sans toucher au reste.
			$fragment = [];
			foreach ($field_nums as $field_num) {
				if ($attribute = $this->doc_builder->attributeFor($field_tablenum, (string)$field_num)) {
					$fragment[$attribute] = [];
				}
			}

			// Les facettes de la table de contenu suivent le même sort que ses attributs
			// texte : l'effacement est grossier — tout l'attribut, pas la seule valeur — et le
			// greffon applicatif compense en replanifiant une réindexation complète de
			// l'enregistrement, qui remet les liens restants.
			$content_table = Datamodel::getTableName($field_tablenum);
			if ($content_table && in_array($content_table, Meilisearch\Schema::FACET_TABLES, true)) {
				$fragment[$this->schema->facetAttribute($content_table)] = [];
				if ($rel_type_id && function_exists('caGetRelationshipTypeCode') && ($code = caGetRelationshipTypeCode($rel_type_id))) {
					$fragment[$this->schema->facetAttribute($content_table, $code)] = [];
				}
			}

			if (sizeof($fragment)) { $this->bufferDocument($table, $subject_row_id, $fragment, true); }
			return;
		}

		// Effacement complet de l'enregistrement.
		unset(self::$write_buffer[$table][$subject_row_id]);
		self::$delete_buffer[$table][] = $subject_row_id;
	}
	# -------------------------------------------------------
	/**
	 * Vide l'index : suppression pure et simple des index, qui seront recréés au premier
	 * document écrit. Plus rapide et plus sûr qu'un effacement document par document.
	 */
	public function truncateIndex($table_num = null) {
		$this->clearBuffers();

		try {
			if ($table_num) {
				$index = $this->schema->indexName($table_num);
				// Rien à supprimer si l'index n'a jamais été créé : sur une base où beaucoup de
				// tables sont vides, cette vérification économise autant d'allers-retours et
				// d'attentes de tâche que de tables.
				if ($this->getClient()->indexExists($index)) {
					$this->getClient()->deleteIndex($index);
					Meilisearch\Log::info("Index {$index} vidé");
				}
				unset(self::$prepared_indexes[$index]);
			} else {
				foreach ($this->getClient()->listIndexes() as $index) {
					$uid = $index['uid'] ?? null;
					if ($uid && strpos($uid, $this->schema->prefix() . '_') === 0) {
						$this->getClient()->deleteIndex($uid);
						unset(self::$prepared_indexes[$uid]);
					}
				}
				Meilisearch\Log::info('Tous les index du préfixe « ' . $this->schema->prefix() . ' » ont été vidés');
			}
		} catch (Meilisearch\ClientException $e) {
			Meilisearch\Log::error('Vidage de l\'index impossible : ' . $e->getMessage());
			throw new ApplicationException(_t('Could not truncate Meilisearch index: %1', $e->getMessage()));
		}

		return true;
	}
	# -------------------------------------------------------
	/**
	 * Meilisearch tient ses index à jour tout seul : rien à optimiser. On en profite pour
	 * s'assurer que tout ce qui est en attente est bien écrit.
	 */
	public function optimizeIndex(int $table_num) {
		$this->flush();
		return true;
	}
	# -------------------------------------------------------
	/**
	 * Crée l'index d'une table et y pose ses réglages, sans attendre qu'un document l'exige.
	 *
	 * Publique pour le mode fantôme du réindexeur, qui doit fabriquer un index même là où il n'y
	 * aura rien à écrire : la permutation exige que les deux index existent, et une table vidée de
	 * toutes ses lignes doit voir son index vivant devenir vide, pas rester tel qu'il était.
	 *
	 * Le nom rendu est celui que le processus courant vise — donc l'index fantôme quand
	 * Schema::setShadowSuffix() a été posé.
	 *
	 * @return string nom de l'index préparé
	 */
	public function prepareIndexForTable($table): string {
		$name = is_numeric($table) ? (string)\Datamodel::getTableName((int)$table) : (string)$table;
		if (!strlen($name)) { throw new ApplicationException(_t('Unknown table: %1', $table)); }

		$index = $this->schema->indexName($name);
		$this->prepareIndex($index, $name);
		return $index;
	}
	# -------------------------------------------------------
	public function clearCaches(): void {
		$this->clearBuffers();
	}
	# -------------------------------------------------------
	/**
	 * Nom attendu par SearchIndexer::reindex() du fork lescollections (SearchIndexer.php:322),
	 * qui l'appelle sans method_exists() : sans cette méthode, une réindexation complète meurt
	 * sur une erreur fatale — après avoir tout indexé, ce qui rend le symptôme trompeur.
	 * Elle ne figure pas dans IWLPlugSearchEngine ; SqlSearch2 et ElasticSearch la portent.
	 */
	public function flushContentBuffer(): void {
		$this->flush();
	}
	# -------------------------------------------------------
	public function setTableNum($table_num) {
		$this->indexing_subject_tablenum = $table_num;
	}
	# -------------------------------------------------------
	# Écriture
	# -------------------------------------------------------
	/**
	 * Ajoute un fragment au document en attente. `$replace` remplace les attributs au lieu de
	 * leur ajouter des valeurs (effacement ciblé).
	 */
	private function bufferDocument(string $table, int $row_id, array $fragment, bool $replace = false): void {
		if (!isset(self::$write_buffer[$table][$row_id])) {
			self::$write_buffer[$table][$row_id] = [];
			self::$write_buffer_size++;
		}

		foreach ($fragment as $attribute => $values) {
			if ($replace || !isset(self::$write_buffer[$table][$row_id][$attribute])) {
				self::$write_buffer[$table][$row_id][$attribute] = $values;
			} else {
				self::$write_buffer[$table][$row_id][$attribute] = array_merge(
					self::$write_buffer[$table][$row_id][$attribute], $values
				);
			}
		}
	}
	# -------------------------------------------------------
	/**
	 * Écrit tout ce qui est en attente, et ne rend la main qu'une fois le moteur à jour.
	 *
	 * C'est le point où la garantie se paie : quand flush() revient, l'index est prêt et aucun
	 * lot n'a échoué en silence. Les versements de mi-parcours, eux, n'attendent pas — voir
	 * flushIfBufferFull().
	 *
	 * @throws ApplicationException si le moteur refuse, ne répond pas, ou si un lot a échoué :
	 *         mieux vaut une réindexation qui casse qu'un index incomplet dont personne ne
	 *         saura rien.
	 */
	public function flush(): void {
		$this->writeBuffers();
		$this->awaitPendingTasks();
	}
	# -------------------------------------------------------
	/**
	 * Versement de mi-parcours, déclenché quand le tampon est plein : on enfile le lot et on
	 * repart sans attendre qu'il soit absorbé. L'attente est reportée au flush final, qui la
	 * fait une fois pour toutes.
	 */
	private function flushIfBufferFull(): void {
		if (self::$write_buffer_size < (int)$this->getOption('maxIndexingBufferSize')) { return; }

		$this->writeBuffers();

		// Sécurité : si le moteur prenait du retard, on le laisse rattraper plutôt que de
		// laisser la file enfler indéfiniment.
		if (sizeof(self::$pending_tasks) >= (int)$this->getOption('maxPendingTasks')) {
			$this->awaitPendingTasks();
		}
	}
	# -------------------------------------------------------
	/**
	 * Attend l'aboutissement des lots enfilés et vérifie qu'aucun n'a échoué.
	 *
	 * @throws ApplicationException si une tâche a échoué : l'index serait incomplet sans que
	 *         personne ne le sache, et c'est exactement ce qu'on refuse.
	 */
	private function awaitPendingTasks(): void {
		if (!sizeof(self::$pending_tasks)) { return; }
		if (!(bool)$this->getOption('waitForIndexing')) { self::$pending_tasks = []; return; }

		$tasks = self::$pending_tasks;
		self::$pending_tasks = [];

		try {
			$this->getClient()->waitForTasks($tasks);
		} catch (Meilisearch\ClientException $e) {
			Meilisearch\Log::error('Indexation impossible : ' . $e->getMessage());
			throw new ApplicationException(_t('Meilisearch indexing failed: %1', $e->getMessage()));
		}
	}
	# -------------------------------------------------------
	private function noteTask(array $task): void {
		if (isset($task['taskUid'])) { self::$pending_tasks[] = (int)$task['taskUid']; }
	}
	# -------------------------------------------------------
	/**
	 * Envoie ce qui est en attente, sans attendre que le moteur l'ait absorbé : les tâches
	 * enfilées sont retenues et vérifiées ensuite par awaitPendingTasks().
	 */
	private function writeBuffers(): void {
		if (!self::$write_buffer_size && !sizeof(self::$delete_buffer)) { return; }

		try {
			foreach (self::$delete_buffer as $table => $row_ids) {
				if (!sizeof($row_ids)) { continue; }
				$index = $this->schema->indexName($table);
				if (!$this->getClient()->indexExists($index)) { continue; }
				$this->noteTask($this->getClient()->deleteDocuments($index, array_values(array_unique($row_ids)), false));
			}

			foreach (self::$write_buffer as $table => $rows) {
				if (!sizeof($rows)) { continue; }
				$index = $this->schema->indexName($table);
				$this->prepareIndex($index, $table);

				$documents = [];
				foreach ($rows as $row_id => $attributes) {
					$documents[] = array_merge(
						[Meilisearch\Schema::PK => (int)$row_id, Meilisearch\Schema::TABLE => $table],
						$this->flattenAttributes($attributes)
					);
				}

				$this->declareFacetAttributes($index, $documents);

				Meilisearch\Log::debug(sprintf('écriture de %d document(s) dans %s', sizeof($documents), $index));

				// PUT : ajout ou fusion. Les attributs absents du document envoyé sont
				// conservés — c'est ce qui rend l'indexation incrémentale possible sans
				// relire le document, contrairement à ElasticSearch.
				$this->noteTask($this->getClient()->updateDocuments($index, $documents, false));
			}
		} catch (Meilisearch\ClientException $e) {
			$this->clearBuffers();
			Meilisearch\Log::error('Indexation impossible : ' . $e->getMessage());
			throw new ApplicationException(_t('Meilisearch indexing failed: %1', $e->getMessage()));
		}

		$this->clearBuffers();
	}
	# -------------------------------------------------------
	/**
	 * Un attribut à valeur unique est stocké tel quel plutôt que dans un tableau d'un élément :
	 * l'index est plus lisible au diagnostic, et Meilisearch traite les deux formes de la même
	 * manière en recherche. Un attribut vidé est stocké à null, ce que Meilisearch sait
	 * distinguer d'un attribut absent.
	 */
	private function flattenAttributes(array $attributes): array {
		$out = [];
		foreach ($attributes as $attribute => $values) {
			if (!is_array($values))      { $out[$attribute] = $values; continue; }
			$values = array_values(array_unique($values, SORT_REGULAR));
			if (sizeof($values) === 0)   { $out[$attribute] = null; }
			elseif (sizeof($values) === 1) { $out[$attribute] = $values[0]; }
			else                         { $out[$attribute] = $values; }
		}
		return $out;
	}
	# -------------------------------------------------------
	/**
	 * Crée l'index et pose ses réglages, une fois par processus.
	 *
	 * Les attributs filtrables se fusionnent avec l'existant au lieu de le remplacer : les
	 * variantes de facette par type de relation sont découvertes au fil des versements
	 * (voir declareFacetAttributes()), et un nouveau processus qui réappliquerait les seuls
	 * réglages de base les effacerait — chaque effacement recoûtant une reconstruction des
	 * bases de facettes.
	 */
	private function prepareIndex(string $index, string $table): void {
		if (isset(self::$prepared_indexes[$index])) { return; }

		$this->getClient()->createIndex($index, Meilisearch\Schema::PK);

		$existing = [];
		try {
			$settings = $this->getClient()->getSettings($index);
			$existing = is_array($settings['filterableAttributes'] ?? null) ? $settings['filterableAttributes'] : [];
		} catch (Meilisearch\ClientException $e) {
			// Index tout juste créé : rien à préserver.
		}

		$filterable = array_values(array_unique(array_merge(
			$this->schema->baseFilterableAttributes($table), $existing
		)));

		$this->getClient()->updateSettings($index, $this->schema->indexSettings(
			null, $filterable, $this->ms_config->tokenizeLikeSqlSearch()
		));

		self::$filterable_attributes[$index] = array_flip($filterable);
		self::$prepared_indexes[$index]     = true;
	}
	# -------------------------------------------------------
	/**
	 * Déclare filtrables les attributs de facette qui apparaissent pour la première fois —
	 * les variantes par type de relation (`facet__ca_entities__artist`), qu'on ne peut pas
	 * énumérer d'avance. L'appel est fait avant l'envoi des documents : déclarer d'abord évite
	 * au moteur de reconstruire ses bases de facettes après coup.
	 */
	private function declareFacetAttributes(string $index, array $documents): void {
		if (!isset(self::$filterable_attributes[$index])) { return; }

		$new = [];
		foreach ($documents as $document) {
			foreach ($document as $attribute => $v) {
				if (isset(self::$filterable_attributes[$index][$attribute])) { continue; }
				if (strpos($attribute, Meilisearch\Schema::FACET) === 0
					|| strpos($attribute, Meilisearch\Schema::FACET_DIRECT) === 0
					|| strpos($attribute, Meilisearch\Schema::FACET_DATE) === 0
					|| strpos($attribute, Meilisearch\Schema::FACET_ATTR) === 0) {
					$new[$attribute] = true;
				}
			}
		}
		if (!sizeof($new)) { return; }

		self::$filterable_attributes[$index] += $new;

		$this->noteTask($this->getClient()->updateSettings($index, [
			'filterableAttributes' => array_keys(self::$filterable_attributes[$index]),
		], false));
	}
	# -------------------------------------------------------
	private function clearBuffers(): void {
		$this->row_buffer        = [];
		self::$write_buffer      = [];
		self::$delete_buffer     = [];
		self::$write_buffer_size = 0;
	}
	# -------------------------------------------------------
}
