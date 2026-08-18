<?php
/* ----------------------------------------------------------------------
 * verifier-socle.php — cette instance peut-elle recevoir le connecteur ?
 *
 *     cd /var/www/html
 *     php app/plugins/Meilisearch/tools/verifier-socle.php
 *
 * À lancer **avant** de basculer `search_engine_plugin`, sur l'instance visée. Le connecteur a
 * été écrit contre le fork `lescollections/providence` ; ailleurs, il touche des points qui ne
 * sont garantis par aucune interface. Autant les éprouver en trente secondes plutôt que de le
 * découvrir au milieu d'une réindexation de plusieurs centaines de milliers de fiches — c'est
 * exactement ce qui est arrivé avec `getFieldDataForReindex()`.
 *
 * L'outil ne modifie rien : il lit, appelle le moteur en lecture, et dit ce qui manque.
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

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

$bilan = ['ok' => 0, 'note' => 0, 'bloquant' => 0];

function ligne(string $etat, string $intitule, string $detail = ''): void {
	global $bilan;
	$bilan[$etat]++;
	$marque = ['ok' => "\033[32m✓\033[0m", 'note' => "\033[33m!\033[0m", 'bloquant' => "\033[31m✗\033[0m"][$etat];
	printf("  %s %-46s %s\n", $marque, $intitule, $detail);
}

function titre(string $t): void { printf("\n\033[1m%s\033[0m\n", $t); }

printf("\n\033[1mInstance : %s\033[0m\n", $racine);
printf("  \033[90mPHP %s — CollectiveAccess %s\033[0m\n", PHP_VERSION,
	defined('__CollectiveAccess__') ? __CollectiveAccess__ : 'version inconnue');

# ----------------------------------------------------------------------
titre('Le greffon et le connecteur sont-ils en place ?');

$connecteur = __CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch.php';
ligne(file_exists($connecteur) ? 'ok' : 'bloquant', 'connecteur lié dans app/lib/Plugins/SearchEngine/',
	file_exists($connecteur) ? '' : 'lien manquant — voir dev/bin/dev-link-plugin');

$greffon = __CA_APP_DIR__ . '/plugins/Meilisearch/MeilisearchPlugin.php';
ligne(file_exists($greffon) ? 'ok' : 'bloquant', 'greffon lié dans app/plugins/Meilisearch/',
	file_exists($greffon) ? '' : 'lien manquant — le connecteur y prend ses réglages');

if (!file_exists($connecteur) || !file_exists($greffon)) {
	printf("\n  \033[31mLes liens manquent : rien d'autre ne peut être vérifié.\033[0m\n\n");
	exit(1);
}

require_once($connecteur);

# ----------------------------------------------------------------------
titre('Le contrat du socle');

// Ce que l'interface exige : si le socle a bougé, PHP refuserait la classe. On le vérifie en
// l'instanciant pour de bon plutôt qu'en comparant des listes de méthodes.
try {
	$moteur = new WLPlugSearchEngineMeilisearch();
	ligne('ok', 'le connecteur s\'instancie', 'IWLPlugSearchEngine satisfaite');
} catch (\Throwable $e) {
	ligne('bloquant', 'le connecteur s\'instancie', get_class($e) . ' : ' . $e->getMessage());
	printf("\n");
	exit(1);
}

$indexeur = new SearchIndexer(new Db());

// Hors interface : leur absence ne casse rien tant qu'un repli existe, mais il faut savoir.
$hors_interface = [
	['SearchIndexer::getFieldDataForReindex', method_exists($indexeur, 'getFieldDataForReindex'),
	 'propre au fork — tools/_socle.php lit la table à sa place'],
	['SearchIndexer::indexRow', method_exists($indexeur, 'indexRow'), 'indispensable'],
	['SearchResult::clearCaches', method_exists('SearchResult', 'clearCaches'),
	 'sans elle, la mémoire enfle sur une grande table'],
	['Datamodel::primaryKey', method_exists('Datamodel', 'primaryKey'), 'utilisée par le repli'],
];
foreach ($hors_interface as list($nom, $presente, $note)) {
	$bloquant = in_array($nom, ['SearchIndexer::indexRow'], true);
	ligne($presente ? 'ok' : ($bloquant ? 'bloquant' : 'note'), $nom, $presente ? '' : $note);
}

require_once(__CA_MODELS_DIR__ . '/ca_attributes.php');
ligne(method_exists('ca_attributes', 'prefetchAttributes') ? 'ok' : 'note',
	'ca_attributes::prefetchAttributes', method_exists('ca_attributes', 'prefetchAttributes') ? '' : 'réindexation plus lente');

ligne(method_exists($moteur, 'flushContentBuffer') ? 'ok' : 'note', 'moteur::flushContentBuffer', '');

# ----------------------------------------------------------------------
titre('Le service Meilisearch');

try {
	$client  = $moteur->getClient();
	$version = $client->version();
	ligne('ok', 'le moteur répond', 'Meilisearch ' . ($version['pkgVersion'] ?? '?'));
} catch (\Throwable $e) {
	ligne('bloquant', 'le moteur répond', $e->getMessage());
}

# ----------------------------------------------------------------------
titre('La configuration');

$moteur_configure = (string)Configuration::load()->get('search_engine_plugin');
ligne($moteur_configure === 'Meilisearch' ? 'ok' : 'note', 'search_engine_plugin',
	$moteur_configure !== '' ? $moteur_configure : '(vide)');
if ($moteur_configure !== 'Meilisearch') {
	printf("      \033[90mà poser dans app/conf/local/app.conf — surtout pas dans app_<nom>.conf,\n");
	printf("      que Configuration::load() ne charge pas.\033[0m\n");
}

$journal = Meilisearch\Log::path();
$ecrivable = file_exists($journal) ? is_writable($journal) : is_writable(dirname($journal));
ligne($ecrivable ? 'ok' : 'note', 'journal du connecteur',
	$ecrivable ? $journal : $journal . ' non écrivable — les alertes partiront dans error_log()');

# ----------------------------------------------------------------------
titre('Le fonds');

foreach (['ca_objects', 'ca_entities', 'ca_occurrences'] as $table) {
	if (!Datamodel::getTableNum($table)) { continue; }
	$qr = (new Db())->query("SELECT COUNT(*) AS n FROM {$table} WHERE deleted = 0");
	$qr->nextRow();
	printf("  \033[90m%-12s %s fiche(s)\033[0m\n", $table, number_format((int)$qr->get('n'), 0, ',', ' '));
}

$qr = (new Db())->query("SELECT COUNT(*) AS n FROM ca_search_log");
$qr->nextRow();
$historique = (int)$qr->get('n');
ligne($historique > 0 ? 'ok' : 'note', 'historique des recherches (ca_search_log)',
	$historique > 0 ? number_format($historique, 0, ',', ' ') . ' entrée(s) — de quoi comparer avec --historique'
	                : 'vide : la comparaison avec le moteur précédent sera impossible');

printf("\n  \033[32m%d conforme(s)\033[0m, \033[33m%d à savoir\033[0m, \033[31m%d bloquant(s)\033[0m\n\n",
	$bilan['ok'], $bilan['note'], $bilan['bloquant']);

exit($bilan['bloquant'] ? 1 : 0);
