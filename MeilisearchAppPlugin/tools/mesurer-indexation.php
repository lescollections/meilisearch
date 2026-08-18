<?php
/* ----------------------------------------------------------------------
 * mesurer-indexation.php — où passe le temps d'une réindexation ?
 *
 *     cd /var/www/html
 *     php app/plugins/Meilisearch/tools/mesurer-indexation.php        # 200 objets
 *     php app/plugins/Meilisearch/tools/mesurer-indexation.php 1000
 *
 * Un outil de mesure, pas un test : il indexe réellement l'échantillon qu'il chronomètre.
 *
 * On rejoue exactement ce que fait SearchIndexer::reindex() — même préchargement des attributs
 * par tranches de 100, même appel à indexRow() — sur un échantillon, en séparant trois postes :
 *
 *   • le temps passé dans CollectiveAccess à fabriquer les données à indexer ;
 *   • le temps passé à parler à Meilisearch (requêtes HTTP d'écriture) ;
 *   • le temps passé à *attendre* que Meilisearch ait absorbé le lot.
 *
 * Les deux derniers sont ceux qu'un connecteur peut réduire. Le premier ne dépend pas de lui :
 * s'il domine, paralléliser le dialogue avec le moteur ne changera rien.
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }
if (!defined('__CollectiveAccess_IS_REINDEXING__')) { define('__CollectiveAccess_IS_REINDEXING__', 1); }

// Ce fichier est atteint par un lien symbolique : on cherche la racine depuis le répertoire
// courant, comme les autres outils du greffon.
$racine = null;
$candidat = getcwd();
for ($i = 0; $i < 6 && $candidat && $candidat !== '/'; $i++) {
	if (file_exists($candidat . '/setup.php') && is_dir($candidat . '/app/lib')) { $racine = $candidat; break; }
	$candidat = dirname($candidat);
}
if (!$racine) { fwrite(STDERR, "Racine de Providence introuvable ; se placer dedans.\n"); exit(2); }

require_once($racine . '/setup.php');
require_once(__CA_LIB_DIR__ . '/Search/SearchBase.php');
require_once(__CA_LIB_DIR__ . '/Search/SearchIndexer.php');
require_once(__CA_MODELS_DIR__ . '/ca_attributes.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Client.php');
require_once(__DIR__ . '/_socle.php');

function nombre_objets(): int {
	$qr = (new Db())->query('SELECT COUNT(*) AS n FROM ca_objects WHERE deleted = 0');
	$qr->nextRow();
	return (int)$qr->get('n');
}

$echantillon = (int)($argv[1] ?? 200);

$db = new Db();
$qr = $db->query("SELECT object_id FROM ca_objects WHERE deleted = 0 ORDER BY object_id LIMIT {$echantillon}");
$ids = $qr->getAllFieldValues('object_id');
if (!sizeof($ids)) { die("Aucun objet en base.\n"); }

printf("\n\033[1mÉchantillon : %d objets sur %d en base\033[0m\n\n", sizeof($ids), nombre_objets());

$indexer   = new SearchIndexer();
$table_num = Datamodel::getTableNum('ca_objects');
$instance  = Datamodel::getInstanceByTableName('ca_objects', true);

$element_ids = method_exists($instance, 'getApplicableElementCodes')
	? array_keys($instance->getApplicableElementCodes(null, false, false))
	: null;

Meilisearch\Client::resetStats();

$t_prefetch = 0.0;
$t_index    = 0.0;
$field_data = [];

$depart = microtime(true);

foreach ($ids as $i => $id) {
	if (!($i % 100)) {
		$t0    = microtime(true);
		$tranche = array_slice($ids, $i, 100);
		if ($element_ids) { ca_attributes::prefetchAttributes($db, $table_num, $tranche, $element_ids); }
		$field_data = donnees_de_champs($indexer, 'ca_objects', $tranche, $db);
		SearchResult::clearCaches();
		$t_prefetch += microtime(true) - $t0;
	}

	$t0 = microtime(true);
	$indexer->indexRow($table_num, $id, $field_data[$id] ?? [], true);
	$t_index += microtime(true) - $t0;
}

$t0 = microtime(true);
vider_tampon_moteur(SearchBase::newSearchEngine());   // le connecteur tient le tampon, pas l'indexeur
$t_flush = microtime(true) - $t0;

$total = microtime(true) - $depart;
$stats = Meilisearch\Client::stats();

$moteur      = $stats['seconds'];
$attente     = $stats['wait_seconds'];
$dans_ca     = max(0.0, $total - $moteur - $attente);

$ligne = function (string $intitule, float $secondes) use ($total) {
	printf("  %-42s %8.2f s   %5.1f %%\n", $intitule, $secondes, $total > 0 ? 100 * $secondes / $total : 0);
};

// Quatre postes qui couvrent le total. Le dialogue avec le moteur est mesuré au niveau du
// transport : il faut donc le retrancher du temps d'indexRow plutôt que l'ajouter à côté.
//
// L'attente des tâches mérite son propre poste, et c'est le piège de cette mesure : le tampon
// se vide tout seul tous les 500 documents, *pendant* indexRow. Rangée dans la fabrication,
// elle fait croire que CollectiveAccess est plus lent qu'il n'est, et surtout elle masque le
// seul temps mort que le connecteur puisse supprimer. Le sondage des tâches est du HTTP, déjà
// compté dans `seconds` : on le retire de l'écriture pour ne pas le compter deux fois.
$ecriture    = max(0.0, $moteur - $stats['wait_request_seconds']);
$fabrication = max(0.0, $t_index - max(0.0, $ecriture - $t_flush) - $attente);

printf("\033[1mRépartition\033[0m — les quatre postes couvrent le total\n");
$ligne('préchargement (attributs, champs)', $t_prefetch);
$ligne('fabrication des données (indexRow)', $fabrication);
$ligne('écriture dans Meilisearch (HTTP)', $ecriture);
$ligne('attente que les tâches aboutissent', $attente);
$ligne('total', $total);
// Le temps mort : presque tout en sommeil, et recouvrable — c'est ce qu'on gagnerait à
// n'attendre les tâches qu'à la toute fin plutôt qu'à chaque lot.
printf("\n  \033[90mattente : %.2f s pour %d sondage(s), dont %.2f s de requêtes HTTP — %.1f %% du total\033[0m\n",
	$attente, $stats['wait_polls'], $stats['wait_request_seconds'], $total > 0 ? 100 * $attente / $total : 0);

printf("\n\033[1mDébit\033[0m\n");
printf("  %-42s %8.1f objets/s\n", 'observé', sizeof($ids) / max($total, 0.001));
printf("  %-42s %8.1f objets/s\n", 'sans le moteur ni l\'attente', sizeof($ids) / max($dans_ca, 0.001));
printf("  %-42s %8.1f objets/s\n", 'sans l\'attente des tâches seule', sizeof($ids) / max($total - $attente, 0.001));
printf("  %-42s %8d requêtes HTTP (%d de sondage de tâche)\n", 'appels au moteur', $stats['requests'], $stats['wait_polls']);

printf("\n\033[1mExtrapolation à %d objets\033[0m\n", nombre_objets());
$facteur = nombre_objets() / max(sizeof($ids), 1);
printf("  %-42s %8.0f s\n", 'en l\'état', $total * $facteur);
printf("  %-42s %8.0f s\n", 'sans le moteur ni l\'attente', $dans_ca * $facteur);
printf("  %-42s %8.0f s\n", 'sans l\'attente des tâches seule', ($total - $attente) * $facteur);
printf("\n");
