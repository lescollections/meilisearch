<?php
/* ----------------------------------------------------------------------
 * mesurer-recherche.php — combien coûte une recherche, et où.
 *
 *     cd /var/www/html
 *     php app/plugins/Meilisearch/tools/mesurer-recherche.php
 *     php app/plugins/Meilisearch/tools/mesurer-recherche.php "peinture" "1920" "vase"
 *
 * Une recherche traverse trois étages, et le coût ne se répartit pas du tout de la même façon
 * selon le nombre de fiches trouvées :
 *
 *   1. Meilisearch cherche et rend des identifiants ;
 *   2. le connecteur applique les filtres de résultat en SQL, sur ces identifiants —
 *      `WHERE object_id IN (…)`, dont la taille croît avec le nombre de résultats ;
 *   3. CollectiveAccess trie, met en cache et fabrique son jeu de résultats.
 *
 * L'étage 2 est celui qui se dégrade : sur un fonds de 300 000 fiches, une requête peu
 * sélective rend des centaines de milliers d'identifiants, et la clause `IN` devient énorme.
 * C'est précisément ce qu'un corpus de 2500 fiches ne peut pas montrer.
 *
 * Les trois étages sont mesurés *dans* la même recherche, par les compteurs que tiennent le
 * client (temps HTTP) et le connecteur (temps de filtrage). Mesurer le moteur à part puis
 * soustraire ne marche pas : la requête isolée n'est pas celle que pose le connecteur, et la
 * soustraction rend des nombres négatifs qu'on prend pour des zéros.
 *
 * On mesure à plusieurs niveaux de sélectivité, du terme rare au terme qui ramène tout.
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
require_once(__CA_LIB_DIR__ . '/Search/ObjectSearch.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch.php');

$expressions = array_slice($argv, 1);
if (!sizeof($expressions)) {
	// Du plus sélectif au moins sélectif. Le dernier ramène tout le fonds : c'est le cas
	// extrême, et c'est celui qui compte pour dimensionner.
	$expressions = ['zzzzqqqq', 'Pissarro', 'canvas', 'oil', '*'];
}

// Nombre de passes par expression : la première paie l'ouverture des connexions et le
// remplissage des caches de Meilisearch, elle ne dit rien du régime établi. On garde la
// médiane des passes suivantes.
$passes = max(1, (int)(getenv('CA_MESURE_PASSES') ?: 3));

$db = new Db();
$qr = $db->query('SELECT COUNT(*) AS n FROM ca_objects WHERE deleted = 0');
$qr->nextRow();
$total = (int)$qr->get('n');

/**
 * Une recherche complète, par la voie que suit l'interface, décomposée par les compteurs.
 */
function mesurer(string $expression): array {
	Meilisearch\Client::resetStats();
	WLPlugSearchEngineMeilisearch::resetSearchStats();

	$memoire_avant = memory_get_usage(true);
	$t0  = microtime(true);
	$res = (new ObjectSearch())->search($expression, ['no_cache' => true]);
	$n   = $res->numHits();
	$t_total = microtime(true) - $t0;

	$client  = Meilisearch\Client::stats();
	$filtres = WLPlugSearchEngineMeilisearch::searchStats();

	return [
		'resultats' => $n,
		'total'     => $t_total,
		'moteur'    => $client['seconds'],
		'requetes'  => $client['requests'],
		'filtres'   => $filtres['filter_seconds'],
		'ids'       => $filtres['filter_ids'],
		'reste'     => max(0.0, $t_total - $client['seconds'] - $filtres['filter_seconds']),
		'memoire'   => memory_get_usage(true) - $memoire_avant,
	];
}

function mediane(array $valeurs) {
	sort($valeurs);
	return $valeurs[intdiv(sizeof($valeurs), 2)];
}

printf("\n\033[1mFonds : %s objets\033[0m — %d passe(s) mesurée(s) après chauffe\n\n", number_format($total, 0, ',', ' '), $passes);
printf("  %-20s %9s %9s %9s %9s %9s %8s\n", 'expression', 'résultats', 'moteur', 'filtres', 'CA', 'total', 'mémoire');
printf("  %s\n", str_repeat('─', 80));

foreach ($expressions as $expression) {
	mesurer($expression);                                    // chauffe, jetée
	$mesures = [];
	for ($i = 0; $i < $passes; $i++) { $mesures[] = mesurer($expression); }

	$reference = $mesures[0];
	$t_total   = mediane(array_column($mesures, 'total'));
	$t_moteur  = mediane(array_column($mesures, 'moteur'));
	$t_filtres = mediane(array_column($mesures, 'filtres'));
	$t_reste   = mediane(array_column($mesures, 'reste'));
	$memoire   = mediane(array_column($mesures, 'memoire'));

	printf("  %-20s %9s %7.0f ms %7.0f ms %7.0f ms %7.0f ms %8s\n",
		mb_strimwidth($expression, 0, 20, '…'),
		number_format($reference['resultats'], 0, ',', ' '),
		$t_moteur * 1000, $t_filtres * 1000, $t_reste * 1000, $t_total * 1000,
		$memoire > 0 ? round($memoire / 1048576) . ' Mo' : '—'
	);

	printf("  %-20s \033[90m%d requête(s) au moteur, %s identifiant(s) passés à la clause IN\033[0m\n", '',
		$reference['requetes'], number_format($reference['ids'], 0, ',', ' '));
}

printf("\n  \033[90mmoteur : temps HTTP vers Meilisearch — filtres : filtrage SQL du connecteur — CA : le reste de la chaîne CollectiveAccess\033[0m\n\n");
