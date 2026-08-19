<?php
/* ----------------------------------------------------------------------
 * tests/_cadre.php — le strict nécessaire pour écrire des vérifications.
 *
 * Pas de PHPUnit : le connecteur s'installe dans l'arborescence de Providence et ne peut pas
 * y apporter son vendor/. Un `require` de setup.php, la vraie instance derrière, et trois
 * fonctions d'assertion — c'est tout ce qu'il faut, et ça garde la règle : on ne conclut que
 * sur une exécution réelle.
 *
 * Usage, dans chaque test :
 *
 *     require_once(__DIR__ . '/_cadre.php');
 *     verifier('les objets sont indexés', fn() => est_vrai($n > 0));
 *     exit(bilan());
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

// Remontée depuis le répertoire courant à la recherche de la racine de Providence, sur le modèle
// de MeilisearchAppPlugin/tools/. L'ancien `require_once('/var/www/html/setup.php')` était hérité
// du harnais Docker : sur toute instance dont la racine n'est pas /var/www/html — c'est le cas de
// comodo2026-preprod — aucun test ne démarrait. CA_RACINE permet de forcer le chemin.
$racine = getenv('CA_RACINE') ?: null;
if (!$racine) {
	$candidat = getcwd();
	for ($i = 0; $i < 6 && $candidat && $candidat !== '/'; $i++) {
		if (file_exists($candidat . '/setup.php') && is_dir($candidat . '/app/lib')) { $racine = $candidat; break; }
		$candidat = dirname($candidat);
	}
}
if (!$racine || !file_exists($racine . '/setup.php')) {
	fwrite(STDERR, "Racine de Providence introuvable ; se placer dedans ou poser CA_RACINE.\n");
	exit(2);
}

require_once($racine . '/setup.php');
require_once(__CA_LIB_DIR__ . '/Datamodel.php');
require_once(__CA_LIB_DIR__ . '/Search/SearchBase.php');
require_once(__CA_LIB_DIR__ . '/Search/ObjectSearch.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');

class EchecAssertion extends Exception {}

# ----------------------------------------------------------------------
# Assertions
# ----------------------------------------------------------------------

function est_vrai($condition, string $detail = ''): void {
	if (!$condition) { throw new EchecAssertion($detail !== '' ? $detail : 'condition fausse'); }
}

function est_egal($attendu, $obtenu, string $detail = ''): void {
	if ($attendu != $obtenu) {
		throw new EchecAssertion(sprintf(
			'%sattendu %s, obtenu %s',
			$detail !== '' ? $detail . ' — ' : '',
			var_export($attendu, true),
			var_export($obtenu, true)
		));
	}
}

function contient($aiguille, array $meule, string $detail = ''): void {
	if (!in_array($aiguille, $meule)) {
		throw new EchecAssertion(sprintf(
			'%s%s absent des %d résultats%s',
			$detail !== '' ? $detail . ' — ' : '',
			var_export($aiguille, true),
			sizeof($meule),
			sizeof($meule) ? ' (' . join(', ', array_slice($meule, 0, 5)) . '…)' : ''
		));
	}
}

function ne_contient_pas($aiguille, array $meule, string $detail = ''): void {
	if (in_array($aiguille, $meule)) {
		throw new EchecAssertion(($detail !== '' ? $detail . ' — ' : '') . var_export($aiguille, true) . ' présent alors qu\'il ne devrait pas');
	}
}

# ----------------------------------------------------------------------
# Déroulé
# ----------------------------------------------------------------------

$GLOBALS['_tests'] = ['ok' => 0, 'echecs' => [], 'ignores' => 0];

function verifier(string $intitule, callable $corps): void {
	$debut = microtime(true);
	try {
		$corps();
		$GLOBALS['_tests']['ok']++;
		printf("  \033[32m✓\033[0m %s \033[90m(%.0f ms)\033[0m\n", $intitule, (microtime(true) - $debut) * 1000);
	} catch (EchecAssertion $e) {
		$GLOBALS['_tests']['echecs'][] = [$intitule, $e->getMessage()];
		printf("  \033[31m✗\033[0m %s\n      %s\n", $intitule, $e->getMessage());
	} catch (Throwable $e) {
		$GLOBALS['_tests']['echecs'][] = [$intitule, get_class($e) . ' : ' . $e->getMessage()];
		printf("  \033[31m✗\033[0m %s\n      \033[31m%s\033[0m : %s\n      %s\n",
			$intitule, get_class($e), $e->getMessage(),
			str_replace("\n", "\n      ", implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 3)))
		);
	}
}

function ignorer(string $intitule, string $raison): void {
	$GLOBALS['_tests']['ignores']++;
	printf("  \033[33m—\033[0m %s \033[90m(%s)\033[0m\n", $intitule, $raison);
}

function titre(string $texte): void {
	printf("\n\033[1m%s\033[0m\n", $texte);
}

function bilan(): int {
	$t = $GLOBALS['_tests'];
	$n = sizeof($t['echecs']);
	printf("\n%s %d vérification(s) passée(s), %d en échec%s\n\n",
		$n ? "\033[31m✗\033[0m" : "\033[32m✓\033[0m",
		$t['ok'], $n,
		$t['ignores'] ? ", {$t['ignores']} ignorée(s)" : ''
	);
	return $n ? 1 : 0;
}

# ----------------------------------------------------------------------
# Raccourcis propres au connecteur
# ----------------------------------------------------------------------

/**
 * Le connecteur, instancié directement — pour les vérifications de bas niveau.
 */
function moteur(): WLPlugSearchEngineMeilisearch {
	require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch.php');
	return new WLPlugSearchEngineMeilisearch();
}

/**
 * Une recherche par la voie normale : SearchEngine analyse, réécrit, filtre et met en cache,
 * exactement comme le fait l'interface. C'est la seule façon de vérifier que le connecteur
 * s'insère bien dans la chaîne, et pas seulement qu'il sait parler à Meilisearch.
 *
 * @return array identifiants d'objets
 */
function chercher_objets(string $expression, ?array $options = null): array {
	$search = new ObjectSearch();
	$res    = $search->search($expression, array_merge(['no_cache' => true], (array)$options));

	$ids = [];
	while ($res->nextHit()) { $ids[] = (int)$res->get('ca_objects.object_id'); }
	return $ids;
}

/**
 * Un titre tel qu'on peut le remettre dans une expression de recherche.
 *
 * Guillemets et parenthèses sont des opérateurs de la syntaxe Lucene qu'analyse
 * CollectiveAccess : les laisser dans une expression entre guillemets produit une requête
 * malformée, et zéro résultat. Le corpus cleveland en contient — « Ivory Plaque with Enthroned
 * Mother of God ("The Stroganoff Ivory") ».
 */
function expression_titre(string $titre): string {
	$titre = str_replace(['"', '“', '”', '(', ')', '[', ']', ':'], ' ', $titre);
	return trim(preg_replace('!\s+!u', ' ', $titre));
}

/**
 * Traite la file de réindexation.
 *
 * CollectiveAccess n'écrit pas toujours l'index au fil de l'enregistrement : modifier une
 * étiquette dépose une entrée dans `ca_search_indexing_queue`, que `caUtils
 * process-indexing-queue` traite ensuite. Rien de propre au connecteur — c'est le socle — mais
 * il faut le savoir : sans ce passage, une modification reste invisible en recherche et on
 * accuse le moteur.
 */
function traiter_file_indexation(): void {
	require_once(__CA_MODELS_DIR__ . '/ca_search_indexing_queue.php');
	// Le verrou survit à un processus tué ; en test on repart toujours propre.
	ca_search_indexing_queue::lockRelease();
	ca_search_indexing_queue::process();
}

/**
 * Un objet du corpus qui a un titre et un idno exploitables, choisi au hasard pour ne pas
 * vérifier toujours le même.
 */
function objet_temoin(?int $object_id = null): array {
	$db = new Db();
	$sql = "
		SELECT o.object_id, o.idno, l.name, l.label_id, l.locale_id
		FROM ca_objects o
		INNER JOIN ca_object_labels l ON l.object_id = o.object_id AND l.is_preferred = 1
		WHERE o.deleted = 0 AND o.idno LIKE 'CLE.%' AND CHAR_LENGTH(l.name) > 8
		" . ($object_id ? ' AND o.object_id = ' . (int)$object_id : '') . "
		ORDER BY " . ($object_id ? 'o.object_id' : 'RAND()') . "
		LIMIT 50";

	$qr = $db->query($sql);

	// On tire au hasard pour ne pas vérifier toujours le même objet, mais on écarte les titres
	// d'un seul mot : plusieurs vérifications ont besoin de deux mots pour dire quelque chose.
	$trouve = false;
	while ($qr->nextRow()) {
		$mots = array_filter(preg_split('!\s+!u', (string)$qr->get('name')), fn($m) => mb_strlen($m) > 3);
		if ($object_id || sizeof($mots) >= 2) { $trouve = true; break; }
	}
	if (!$trouve) {
		throw new EchecAssertion('aucun objet du corpus cleveland exploitable en base — lancer `dev-seed-cleveland`');
	}

	// On rend l'identifiant de l'étiquette et sa locale, et pas seulement son texte : modifier
	// un titre passe par editLabel(), qui vise une étiquette précise. replaceLabel() cherche
	// dans la locale qu'on lui donne et crée silencieusement une seconde étiquette préférée si
	// elle n'y trouve rien — la modification semble alors sans effet.
	return [
		'object_id' => (int)$qr->get('object_id'),
		'idno'      => (string)$qr->get('idno'),
		'titre'     => (string)$qr->get('name'),
		'label_id'  => (int)$qr->get('label_id'),
		'locale_id' => (int)$qr->get('locale_id'),
	];
}

function nombre_objets(): int {
	$db = new Db();
	$qr = $db->query('SELECT COUNT(*) AS n FROM ca_objects WHERE deleted = 0');
	$qr->nextRow();
	return (int)$qr->get('n');
}

/**
 * Les objets qu'une recherche sur ce numéro d'inventaire a le droit de ramener : celui qui le
 * porte, et ceux dont le numéro le prolonge.
 *
 * CollectiveAccess indexe les préfixes hiérarchiques d'un numéro — getIDNoPlugInInstance()
 * rend « CLE », « CLE.1938 », « CLE.1938.388 » pour « CLE.1938.388.a ». C'est voulu : chercher
 * « CLE.1938 » doit ramener la série. Le prix en est qu'un numéro exact ramène aussi ses
 * parties, et ce n'est pas au connecteur de le défaire. Exiger l'univocité pure, c'est exiger
 * du connecteur qu'il contredise le socle ; on vérifie donc qu'il ne ramène rien d'*étranger*.
 */
function serie_du_numero(string $idno): array {
	$db = new Db();
	$qr = $db->query(
		'SELECT object_id FROM ca_objects WHERE deleted = 0 AND (idno = ? OR idno LIKE ?)',
		[$idno, $idno . '.%']
	);
	$ids = array_map('intval', $qr->getAllFieldValues('object_id'));
	sort($ids);
	return $ids;
}

/**
 * Deux mots du fonds dont les résultats se recouvrent partiellement, et un troisième quelconque.
 *
 * Les identités booléennes ne prouvent quelque chose que si A∩B et A\B sont tous deux non
 * vides : sans recouvrement, `a AND b` et `a OR b` rendraient la même chose et une confusion
 * entre les deux passerait inaperçue. On les tire du fonds plutôt que de les écrire en dur —
 * le même test doit pouvoir tourner sur une autre instance.
 *
 * @return array [mot_a, ids_a, mot_b, ids_b, mot_c]
 */
function mots_de_reference(): array {
	$db = new Db();
	$qr = $db->query("SELECT l.name FROM ca_object_labels l
		INNER JOIN ca_objects o ON o.object_id = l.object_id AND o.deleted = 0
		WHERE l.is_preferred = 1 LIMIT 20000");

	$frequences = [];
	while ($qr->nextRow()) {
		foreach (preg_split('![^\p{L}\p{N}]+!u', mb_strtolower((string)$qr->get('name')), -1, PREG_SPLIT_NO_EMPTY) as $mot) {
			if (mb_strlen($mot) < 5) { continue; }
			$frequences[$mot] = ($frequences[$mot] ?? 0) + 1;
		}
	}
	arsort($frequences);
	$candidats = array_slice(array_keys($frequences), 0, 12);

	$vus = [];
	foreach ($candidats as $mot) { $vus[$mot] = chercher_objets($mot); }

	foreach ($candidats as $a) {
		foreach ($candidats as $b) {
			if ($a === $b) { continue; }
			if (sizeof(array_intersect($vus[$a], $vus[$b])) >= 5
				&& sizeof(array_diff($vus[$a], $vus[$b])) >= 5
				&& sizeof($vus[$b]) >= 10) {
				$c = null;
				foreach ($candidats as $t) { if ($t !== $a && $t !== $b) { $c = $t; break; } }
				return [$a, $vus[$a], $b, $vus[$b], $c ?: $a];
			}
		}
	}

	throw new EchecAssertion('aucun couple de mots exploitable dans le fonds — corpus trop maigre ?');
}

