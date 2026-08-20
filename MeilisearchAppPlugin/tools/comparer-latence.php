#!/usr/bin/env php
<?php
/* ---------------------------------------------------------------------------------------------
 * comparer-latence.php — Meilisearch et SqlSearch2 sur les mêmes recherches, la même machine,
 * la même base, dans le même processus.
 *
 *     php app/plugins/Meilisearch/tools/comparer-latence.php --historique=60
 *     php app/plugins/Meilisearch/tools/comparer-latence.php --historique=60 --jours=180
 *
 * Pourquoi cet outil existe. La seule comparaison de latence dont nous disposions opposait les
 * temps mesurés ici aux `execution_time` qu'une *autre* machine avait écrits dans `ca_search_log`
 * des mois plus tôt, sous une autre version du socle. L'écart annoncé — Meilisearch 1,89× plus
 * lent — pouvait donc tenir au matériel, à la charge, à la version, à n'importe quoi. Ici les
 * deux moteurs répondent à la suite, sur la même base et dans le même processus : ce qui reste
 * est imputable aux moteurs.
 *
 * **Piège du socle, payé ici** : `SearchBase::__construct()` accepte bien un moteur en second
 * paramètre, mais `BaseSearch::__construct()` — dont héritent ObjectSearch, EntitySearch et les
 * autres — ne prend aucun paramètre et appelle son parent sans rien. `new ObjectSearch(null,
 * 'SqlSearch2')` rend donc une recherche sur le moteur *configuré*, sans un mot. La première
 * version de cet outil a ainsi mesuré Meilisearch contre lui-même et l'a trouvé, sans surprise,
 * exactement aussi rapide que son adversaire. On remplace donc le moteur après construction.
 *
 * Les deux moteurs doivent être indexés. Pour remplir celui qui n'est pas en service :
 *
 *     php app/plugins/Meilisearch/tools/reindexer.php --moteur=SqlSearch2
 *
 * **La première exécution ment.** Mesurée à froid, `burette` a donné 1 594 ms, puis 36, 34 et
 * 34 ms aux trois suivantes : un facteur 46 entre le premier tir et le régime. Chaque expression
 * est donc jouée `--repetitions` fois (3 par défaut) et l'on retient la **médiane des tirs
 * suivant le premier**. Sans cela l'outil compare des démarrages à froid, pas des moteurs.
 *
 * L'ordre des deux moteurs alterne d'une expression à l'autre, pour que ce qui reste de biais
 * d'ordre — caches de page, tampon InnoDB — se répartisse sur les deux.
 *
 * Enfin, **une latence ne se compare qu'à volume de résultats comparable** : les deux moteurs
 * ne rendent pas les mêmes ensembles, et rapatrier trois fois plus d'identifiants coûte trois
 * fois plus cher. Le bilan sépare donc les expressions dont les deux comptes s'accordent à 5 %
 * près, seules dignes d'être moyennées.
 * --------------------------------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

$racine = getenv('CA_RACINE') ?: null;
if (!$racine) {
	$candidat = getcwd();
	for ($i = 0; $i < 6 && $candidat && $candidat !== '/'; $i++) {
		if (file_exists($candidat . '/setup.php') && is_dir($candidat . '/app/lib')) { $racine = $candidat; break; }
		$candidat = dirname($candidat);
	}
}
if (!$racine) { fwrite(STDERR, "Racine de Providence introuvable ; se placer dedans ou poser CA_RACINE.\n"); exit(2); }

require_once($racine . '/setup.php');
require_once(__CA_LIB_DIR__ . '/Search/SearchBase.php');
require_once(__CA_LIB_DIR__ . '/Search/ObjectSearch.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch.php');

define('SOURCE_LATENCE', 'meilisearch-latence');

// Toutes les marques du connecteur sont écartées de la sélection, pas seulement la nôtre :
// `diagnostiquer-recherches` et les mesures de fidélité écrivent elles aussi dans
// `ca_search_log`, et une exécution qui les relirait comparerait Meilisearch à ses propres
// rejeux. Piège payé quatre fois dans la journée du 20 août — d'où le filtre large.

$combien     = 60;
$jours       = 0;
$min         = 1;
$repetitions = 3;
foreach (array_slice($argv, 1) as $a) {
	if     (preg_match('!^--repetitions=(\d+)$!', $a, $m))     { $repetitions = max(2, (int)$m[1]); }
	elseif (preg_match('!^--historique(?:=(\d+))?$!', $a, $m)) { $combien = (int)($m[1] ?? 60); }
	elseif (preg_match('!^--jours=(\d+)$!', $a, $m))           { $jours   = (int)$m[1]; }
	elseif (preg_match('!^--min=(\d+)$!', $a, $m))             { $min     = (int)$m[1]; }
	else { fwrite(STDERR, "Option inconnue : {$a}\n"); exit(2); }
}

$db        = new Db();
$table_num = Datamodel::getTableNum('ca_objects');
$depuis    = $jours ? (time() - $jours * 86400) : 0;

// Mêmes précautions que le diagnostic : une expression par occurrence la plus récente, nos
// propres rejeux écartés, l'étoile seule écartée.
$qr = $db->query("
	SELECT search_expression FROM (
		SELECT search_expression, log_datetime,
		       ROW_NUMBER() OVER (PARTITION BY search_expression ORDER BY log_datetime DESC) AS rang
		FROM ca_search_log
		WHERE table_num = ? AND search_expression NOT IN ('', '*')
		  AND search_source NOT LIKE ? AND log_datetime >= ? AND num_hits >= ?
	) t WHERE rang = 1 ORDER BY log_datetime DESC LIMIT " . (int)$combien,
	[$table_num, '%meilisearch%', $depuis, $min]);

$expressions = [];
while ($qr->nextRow()) { $expressions[] = (string)$qr->get('search_expression'); }

if (!sizeof($expressions)) {
	printf("\n  Aucune recherche exploitable dans ca_search_log pour ca_objects.\n\n");
	exit(1);
}

// Mesurer pendant qu'un index se reconstruit ne mesure rien : il manque alors une part
// arbitraire des documents, et les comptes comme les temps sont ceux d'un fonds tronqué. Vécu —
// une comparaison lancée pendant une réindexation a montré 5 198 résultats là où il y en avait
// 11 951 dix minutes plus tard, et j'ai failli conclure à un défaut du connecteur.
try {
	$moteur = SearchBase::newSearchEngine('Meilisearch');
	$index  = $moteur->getSchema()->indexName('ca_objects');
	$stats  = $moteur->getClient()->indexStats($index);
	if (!empty($stats['isIndexing'])) {
		fwrite(STDERR, "\n  \033[33mL'index {$index} est en cours d'indexation : la mesure serait fausse.\033[0m\n"
			. "  Attendre la fin de la réindexation, puis relancer.\n\n");
		exit(3);
	}
} catch (\Throwable $e) {
	fwrite(STDERR, "  (état de l'index non vérifiable : " . $e->getMessage() . ")\n");
}

/**
 * Une recherche, son nombre de résultats et le temps qu'elle a pris. Le parcours des hits est
 * compris dans la mesure : c'est lui que paie l'usager, et c'est là que le connecteur rapatrie
 * ses identifiants par HTTP avant de les réinjecter en SQL.
 */
$mediane = function (array $v) {
	if (!sizeof($v)) { return 0.0; }
	sort($v);
	$n = sizeof($v);
	return ($n % 2) ? $v[($n - 1) / 2] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
};

$un_tir = function (string $moteur, string $expression): array {
	$t0 = microtime(true);
	try {
		$recherche = new ObjectSearch();
		// BaseSearch ignore le moteur passé au constructeur (voir l'entête) : on le remplace.
		$champ = (new ReflectionClass('SearchEngine'))->getProperty('opo_engine');
		$champ->setAccessible(true);
		$champ->setValue($recherche, SearchBase::newSearchEngine($moteur));

		$res = $recherche->search($expression, [
			'no_cache' => true, 'search_source' => SOURCE_LATENCE,
		]);
		$n = 0;
		while ($res->nextHit()) { $n++; }
		return ['ms' => (microtime(true) - $t0) * 1000, 'hits' => $n, 'erreur' => null];
	} catch (\Throwable $e) {
		return ['ms' => (microtime(true) - $t0) * 1000, 'hits' => -1, 'erreur' => get_class($e)];
	}
};

/**
 * Le premier tir est jeté — il paie l'ouverture de connexion, les caches froids et, côté
 * connecteur, le relevé des attributs de l'index. On rend la médiane des suivants.
 */
$mesurer = function (string $moteur, string $expression) use ($un_tir, $mediane, $repetitions): array {
	$tirs = [];
	for ($i = 0; $i < $repetitions; $i++) { $tirs[] = $un_tir($moteur, $expression); }
	$retenus = array_slice($tirs, 1);
	$dernier = end($tirs);
	return [
		'ms'     => $mediane(array_map(function ($t) { return $t['ms']; }, $retenus)),
		'froid'  => $tirs[0]['ms'],
		'hits'   => $dernier['hits'],
		'erreur' => $dernier['erreur'],
	];
};

printf("\n\033[1mLatence comparée — %d expression(s) de ca_search_log\033[0m\n", sizeof($expressions));
printf("\033[90mmême base, même machine, même processus ; %d tirs par mesure, le premier jeté\033[0m\n\n", $repetitions);
printf("  %-42s %9s %9s %8s   %s\n", 'expression', 'Meili', 'SqlS2', 'rapport', 'résultats');

$lignes = [];
foreach ($expressions as $i => $expression) {
	// Alternance de l'ordre : ce qui reste de biais d'ordre se répartit sur les deux moteurs.
	if ($i % 2 === 0) {
		$ms  = $mesurer('Meilisearch', $expression);
		$sql = $mesurer('SqlSearch2',  $expression);
	} else {
		$sql = $mesurer('SqlSearch2',  $expression);
		$ms  = $mesurer('Meilisearch', $expression);
	}

	$rapport = ($ms['ms'] > 0) ? $sql['ms'] / $ms['ms'] : 0;
	$lignes[] = ['expression' => $expression, 'ms' => $ms, 'sql' => $sql, 'rapport' => $rapport];

	$couleur = ($rapport >= 1) ? '32' : '33';
	$accord  = ($ms['hits'] === $sql['hits']) ? sprintf('%d', $ms['hits'])
	                                          : sprintf("\033[33m%d ≠ %d\033[0m", $ms['hits'], $sql['hits']);
	printf("  %-42s %7.1f ms %7.1f ms \033[%sm%7.2f×\033[0m   %s\n",
		mb_strimwidth($expression, 0, 42, '…'), $ms['ms'], $sql['ms'], $couleur, $rapport, $accord);
}

$t_ms  = array_map(function ($l) { return $l['ms']['ms']; },  $lignes);
$t_sql = array_map(function ($l) { return $l['sql']['ms']; }, $lignes);
$plus_rapides = sizeof(array_filter($lignes, function ($l) { return $l['ms']['ms'] < $l['sql']['ms']; }));

// Seules les expressions dont les deux moteurs rendent à peu près le même nombre de résultats
// disent quelque chose sur la latence : ailleurs on compare le prix de deux courses de longueurs
// différentes. Meilisearch rend presque toujours davantage — complétion de préfixe et tolérance
// orthographique — et ces résultats supplémentaires, il faut bien les rapatrier et les filtrer.
$comparables = array_values(array_filter($lignes, function ($l) {
	$a = $l['ms']['hits']; $b = $l['sql']['hits'];
	return ($a > 0 && $b > 0 && abs($a - $b) <= 0.05 * max($a, $b));
}));

printf("\n  \033[1mSur les %d expression(s)\033[0m   médiane Meilisearch %.1f ms · SqlSearch2 %.1f ms"
	. "   ·   total %.1f s contre %.1f s\n",
	sizeof($lignes), $mediane($t_ms), $mediane($t_sql), array_sum($t_ms) / 1000, array_sum($t_sql) / 1000);
printf("  Meilisearch est plus rapide sur \033[1m%d\033[0m des %d.\n", $plus_rapides, sizeof($lignes));

if (sizeof($comparables)) {
	$c_ms  = array_map(function ($l) { return $l['ms']['ms']; },  $comparables);
	$c_sql = array_map(function ($l) { return $l['sql']['ms']; }, $comparables);
	$h_ms  = array_sum(array_map(function ($l) { return $l['ms']['hits']; }, $comparables));
	printf("\n  \033[1mÀ volume comparable\033[0m (±5 %%, %d expression(s)) — la seule mesure qui vaille :\n", sizeof($comparables));
	printf("    médiane   Meilisearch %.1f ms   ·   SqlSearch2 %.1f ms   ·   rapport \033[1m%.2f×\033[0m\n",
		$mediane($c_ms), $mediane($c_sql), $mediane($c_ms) > 0 ? $mediane($c_ms) / $mediane($c_sql) : 0);
	printf("    pour mille résultats   Meilisearch %.1f ms   ·   SqlSearch2 %.1f ms\n",
		$h_ms ? array_sum($c_ms) / $h_ms * 1000 : 0,
		$h_ms ? array_sum($c_sql) / $h_ms * 1000 : 0);
} else {
	printf("\n  \033[33mAucune expression à volume comparable : la latence ne se compare pas ici.\033[0m\n");
}
printf("  \033[90m%d des %d expressions rendent des volumes trop différents pour être moyennées.\033[0m\n",
	sizeof($lignes) - sizeof($comparables), sizeof($lignes));
printf("\n");
