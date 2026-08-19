<?php
/* ----------------------------------------------------------------------
 * comparer-facettes.php — les facettes du moteur disent-elles la même chose que le SQL ?
 *
 *     cd /var/www/html
 *     php app/plugins/Meilisearch/tools/comparer-facettes.php
 *     php app/plugins/Meilisearch/tools/comparer-facettes.php --table=ca_objects --criteres
 *
 * À lancer **avant de basculer**, sur l'instance visée et sur ses données. Le pont des facettes
 * promet de rendre exactement ce que `BrowseEngine` aurait rendu, ou de décliner ; ici, on le
 * vérifie sur le fonds réel, facette par facette, sans rien changer à ce que voient les usagers.
 *
 * Le connecteur est instancié explicitement : la comparaison marche donc alors même que
 * `search_engine_plugin` vaut encore SqlSearch2, à condition que l'index ait été rempli —
 * `reindexer.php --moteur=Meilisearch` le fait sans toucher au moteur en service.
 *
 * L'outil ne modifie rien : il lit la base, interroge le moteur en lecture, et compare.
 *
 * Un écart connu et légitime : le compte d'un nœud non-feuille d'une facette hiérarchique. Le
 * SQL additionne les comptes des descendants — un enregistrement lié à deux descendants y est
 * compté deux fois — là où le moteur compte les documents distincts. Ces postes sont signalés
 * « branche » et ne sont pas tenus pour des divergences.
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('!^--([a-z-]+)(?:=(.*))?$!', $arg, $m)) { $opts[$m[1]] = $m[2] ?? true; }
}

if (isset($opts['aide']) || isset($opts['help'])) {
	fwrite(STDOUT, <<<TXT

Compare les facettes calculées par Meilisearch et par le SQL du socle.

  --table=nom     table sujet (défaut : ca_objects)
  --criteres      éprouve aussi chaque facette sous un critère posé sur une autre
  --racine=/chemin  racine de Providence, si elle n'est pas déduite correctement

TXT);
	exit(0);
}

$racine = null;
if (!empty($opts['racine'])) {
	$racine = rtrim((string)$opts['racine'], '/');
} else {
	$candidat = getcwd();
	for ($i = 0; $i < 6 && $candidat && $candidat !== '/'; $i++) {
		if (file_exists($candidat . '/setup.php') && is_dir($candidat . '/app/lib')) { $racine = $candidat; break; }
		$candidat = dirname($candidat);
	}
}
if (!$racine) { fwrite(STDERR, "Racine de Providence introuvable ; se placer dedans.\n"); exit(2); }

require_once($racine . '/setup.php');
require_once(__CA_LIB_DIR__ . '/Search/SearchBase.php');
require_once(__CA_APP_DIR__ . '/helpers/browseHelpers.php');

$table = (string)($opts['table'] ?? 'ca_objects');

$moteur = SearchBase::newSearchEngine('Meilisearch');
if (!$moteur || !method_exists($moteur, 'getBrowseFacetContent')) {
	fwrite(STDERR, "Le connecteur Meilisearch n'est pas installé, ou trop ancien pour les facettes.\n");
	exit(2);
}

/**
 * Coupe la délégation le temps d'un calcul SQL. Sans précaution, une instance déjà basculée
 * comparerait le moteur avec lui-même — et trouverait évidemment tout conforme.
 */
$delegation = function ($valeur) {
	if (!property_exists('BrowseEngine', 's_facet_engine')) { return; }
	$p = new ReflectionProperty('BrowseEngine', 's_facet_engine');
	$p->setAccessible(true);
	$p->setValue(null, $valeur);
};

$patch_pose = property_exists('BrowseEngine', 's_facet_engine');

$browse_neuf = function (array $criteres = []) use ($table) {
	$b = caGetBrowseInstance($table);
	foreach ($criteres as $facette => $valeurs) { $b->addCriteria($facette, (array)$valeurs); }
	if (sizeof($criteres)) { $b->execute(['dontCheckFacetAvailability' => true]); }
	return $b;
};

/**
 * Cet enregistrement a-t-il des descendants dans sa hiérarchie ?
 *
 * C'est la question qui distingue une divergence d'un écart légitime : sur un nœud
 * non-feuille, le SQL du socle additionne les comptes de ses branches — un enregistrement
 * rattaché à deux descendants y est compté deux fois — là où le moteur compte les documents
 * distincts. Le compte du moteur est le bon ; l'écart n'est pas une divergence.
 */
function est_une_branche(?string $table, $id): bool {
	static $cache = [];
	if (!$table || !is_numeric($id)) { return false; }

	$cle = $table . '/' . $id;
	if (isset($cache[$cle])) { return $cache[$cle]; }

	$t = Datamodel::getInstanceByTableName($table, true);
	if (!$t || !$t->hasField('parent_id')) { return $cache[$cle] = false; }

	$qr = (new Db())->query("SELECT 1 FROM {$table} WHERE parent_id = ? LIMIT 1", [(int)$id]);
	return $cache[$cle] = (bool)$qr->nextRow();
}

/** Réduit un contenu de facette à [id => compte]. */
$comptes = function ($contenu): array {
	$out = [];
	foreach ((is_array($contenu) ? $contenu : []) as $cle => $poste) {
		$out[(string)($poste['id'] ?? $cle)] = (int)($poste['content_count'] ?? -1);
	}
	ksort($out, SORT_STRING);
	return $out;
};

printf("\n\033[1mInstance : %s\033[0m\n", $racine);
printf("\033[90mtable %s — moteur en service : %s%s\033[0m\n\n",
	$table,
	Configuration::load()->get('search_engine_plugin'),
	$patch_pose ? '' : " — \033[33mpatch du Browse absent\033[0m");

$reference   = caGetBrowseInstance($table);
$facettes    = $reference->getInfoForFacets();
$bilan       = ['conforme' => 0, 'decline' => 0, 'divergent' => 0, 'raisons' => []];
$divergences = [];

/**
 * Compare une facette dans un état de browse donné.
 */
$comparer = function (string $facette, array $criteres, string $intitule)
		use (&$bilan, &$divergences, $moteur, $delegation, $browse_neuf, $comptes, $reference) {

	$info = $reference->getInfoForFacet($facette);
	if (!is_array($info)) { return; }

	// `_search` et `_reltypes` sont des critères virtuels, injectés par le constructeur du
	// BrowseEngine : ils n'ont pas de contenu, ni ici ni au SQL. Les compter parmi les
	// facettes « reparties au SQL » gonflerait le bilan de deux entrées sans objet.
	if (empty($info['type'])) { return; }

	$delegation(false);
	try {
		$sql = $browse_neuf($criteres)->getFacetContent($facette, []);
		$b   = $browse_neuf($criteres);
		$ms  = $moteur->getBrowseFacetContent($b, $facette, $info, []);
	} catch (Exception $e) {
		printf("  \033[31m✗\033[0m %-46s %s\n", $intitule, $e->getMessage());
		$bilan['divergent']++;
		$delegation(null);
		return;
	}
	$delegation(null);

	if ($ms === null) {
		$bilan['decline']++;
		$raison = method_exists($moteur, 'lastBrowseFacetReason') ? $moteur::lastBrowseFacetReason() : null;
		$bilan['raisons'][$raison ?: 'sans raison notée'] = ($bilan['raisons'][$raison ?: 'sans raison notée'] ?? 0) + 1;
		printf("  \033[90m·\033[0m %-46s \033[90m%s\033[0m\n", $intitule, $raison ?: (($info['type'] ?? '?') . ' — au SQL'));
		return;
	}

	if (is_bool($ms)) {   // checkAvailabilityOnly n'est pas demandé ici
		$bilan['decline']++;
		return;
	}

	$c_sql = $comptes($sql);
	$c_ms  = $comptes($ms);

	$absents = array_diff(array_keys($c_sql), array_keys($c_ms));
	$en_trop = array_diff(array_keys($c_ms), array_keys($c_sql));
	$ecarts  = [];
	$branches = 0;

	foreach ($c_sql as $id => $n) {
		if (!isset($c_ms[$id]) || $c_ms[$id] === $n) { continue; }
		// Un nœud non-feuille : le SQL somme les branches, le moteur compte les documents
		// distincts. On interroge la hiérarchie réelle plutôt que les seuls enfants présents
		// dans la facette — un ancêtre dont les descendants comptés sont des petits-enfants
		// n'a aucun enfant *dans la facette*, et passerait pour une feuille.
		if (est_une_branche($info['table'] ?? null, $id)) { $branches++; continue; }
		$ecarts[] = sprintf('%s (SQL %d ≠ moteur %d)', $id, $n, $c_ms[$id]);
	}

	if (!sizeof($absents) && !sizeof($en_trop) && !sizeof($ecarts)) {
		$bilan['conforme']++;
		printf("  \033[32m✓\033[0m %-46s %d poste(s)%s\n", $intitule, sizeof($c_ms),
			$branches ? sprintf(" \033[90m(%d branche(s) tolérée(s))\033[0m", $branches) : '');
		return;
	}

	$bilan['divergent']++;
	$detail = [];
	if (sizeof($absents)) { $detail[] = sizeof($absents) . ' absent(s) du moteur : ' . join(',', array_slice($absents, 0, 4)); }
	if (sizeof($en_trop)) { $detail[] = sizeof($en_trop) . ' en trop : ' . join(',', array_slice($en_trop, 0, 4)); }
	if (sizeof($ecarts))  { $detail[] = 'comptes : ' . join(' ; ', array_slice($ecarts, 0, 3)); }

	printf("  \033[31m✗\033[0m %-46s %s\n", $intitule, join(' — ', $detail));
	$divergences[] = $intitule;
};

# ----------------------------------------------------------------------
# Chaque facette, sur le fonds entier
# ----------------------------------------------------------------------

printf("\033[1mSur le fonds entier\033[0m\n");
foreach (array_keys($facettes) as $facette) {
	$comparer($facette, [], $facette);
}

# ----------------------------------------------------------------------
# Chaque facette, sous un critère — le cas où le SQL injecte la liste des identifiants
# ----------------------------------------------------------------------

if (isset($opts['criteres'])) {
	printf("\n\033[1mSous un critère\033[0m\n");

	// On cherche une facette traduite qui offre au moins une valeur : elle servira de critère.
	$porteuse = null;
	foreach (array_keys($facettes) as $facette) {
		$info = $reference->getInfoForFacet($facette);
		$b = caGetBrowseInstance($table);
		$contenu = $moteur->getBrowseFacetContent($b, $facette, $info, []);
		if (is_array($contenu) && sizeof($contenu)) {
			$premier = reset($contenu);
			$porteuse = [$facette, (string)($premier['id'] ?? array_key_first($contenu))];
			break;
		}
	}

	if (!$porteuse) {
		printf("  \033[33m!\033[0m aucune facette traduite n'offre de valeur — rien à croiser\n");
	} else {
		list($f_critere, $valeur) = $porteuse;
		printf("\033[90m  critère : %s = %s\033[0m\n", $f_critere, $valeur);
		foreach (array_keys($facettes) as $facette) {
			$comparer($facette, [$f_critere => [$valeur]], $facette . ' sous ' . $f_critere);
		}
	}
}

# ----------------------------------------------------------------------

// Le détail des déclins vaut mieux qu'un total : « type non traduit » est une limite assumée,
// « champ non déclaré filtrable » est du travail qui dort.
if (sizeof($bilan['raisons'])) {
	printf("\n\033[1mPourquoi le SQL a repris la main\033[0m\n");
	arsort($bilan['raisons']);
	foreach ($bilan['raisons'] as $raison => $n) {
		printf("  \033[90m%3d ×\033[0m %s\n", $n, $raison);
	}
}

printf("\n  \033[32m%d conforme(s)\033[0m, \033[90m%d au SQL\033[0m, %s\n\n",
	$bilan['conforme'], $bilan['decline'],
	$bilan['divergent']
		? sprintf("\033[31m%d divergente(s)\033[0m", $bilan['divergent'])
		: "\033[32m0 divergente\033[0m");

exit($bilan['divergent'] ? 1 : 0);
