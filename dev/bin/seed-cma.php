<?php
/* ----------------------------------------------------------------------
 * seed-cma.php — le corpus complet du Cleveland Museum of Art.
 *
 *     dev-seed-cma            # les 68 953 œuvres
 *     dev-seed-cma 20000      # les 20 000 premières
 *
 * Source : https://github.com/ClevelandMuseumArt/openaccess — le jeu de données ouvert du
 * musée, 68 953 œuvres, distribué en CSV via Git LFS (131 Mo).
 *
 * Pourquoi celui-là plutôt que les 2500 de `dev-seed-cleveland` : à cette échelle, on mesure
 * enfin ce qui compte. Une réindexation de 2500 fiches ne dit rien du comportement à 300 000 —
 * la mémoire, les caches, la taille de l'index et le débit d'écriture de Meilisearch n'entrent
 * en jeu que bien plus loin. Et le jeu est réel : des titres, des auteurs, des techniques, des
 * cultures, des inscriptions, de la provenance. Du texte, pas du remplissage.
 *
 * Correspondance vers le profil `default`, volontairement grossière — c'est un banc d'essai,
 * pas une modélisation :
 *
 *   accession_number                    → idno       (un vrai numéro d'inventaire : « 2020.113 »)
 *   title                               → étiquette préférée
 *   type                                → type d'objet (mappé sur les sept types du profil)
 *   creators, creation_date             → description
 *   technique (ou support_materials)    → work_medium
 *   creditline                          → credit_line
 *   culture, department, measurements,
 *     inscriptions, provenance          → notes
 *
 * Les images ne sont pas chargées : elles n'apprennent rien sur un moteur de recherche et
 * coûteraient des heures. CA_SEED_CMA_MEDIA=1 pour les avoir malgré tout.
 *
 * L'indexation de recherche est coupée pendant le chargement (`__CA_DONT_DO_SEARCH_INDEXING__`).
 * Ce n'est pas qu'une économie : on veut mesurer la réindexation comme une opération à part,
 * pas la voir diluée dans le temps d'insertion.
 *
 * Idempotent : une œuvre dont l'idno existe déjà est laissée telle quelle.
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

// Avant le chargement de CollectiveAccess : c'est BaseModel qui la consulte à chaque écriture.
$media_env  = getenv('CA_SEED_CMA_MEDIA');
$with_media = ($media_env !== false && $media_env === '1');
if (!defined('__CA_DONT_DO_SEARCH_INDEXING__')) { define('__CA_DONT_DO_SEARCH_INDEXING__', 1); }

require_once('/var/www/html/setup.php');
require_once(__CA_APP_DIR__ . '/helpers/listHelpers.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');

const SOURCE_URL = 'https://media.githubusercontent.com/media/ClevelandMuseumArt/openaccess/master/data.csv';
const TOTAL_CONNU = 68953;

$voulus = (int)($argv[1] ?? getenv('CA_SEED_CMA_COUNT') ?: TOTAL_CONNU);
$access = (int)(getenv('CA_SEED_ACCESS') !== false ? getenv('CA_SEED_ACCESS') : 1);
if ($voulus < 1) { die("Nombre d'œuvres invalide.\n"); }

$t_locale  = new ca_locales();
$locale_id = $t_locale->localeCodeToID(__CA_DEFAULT_LOCALE__) ?: $t_locale->localeCodeToID('en_US');
if (!$locale_id) { die("Aucune locale installée — la base a-t-elle bien été installée ?\n"); }

// ── La source ─────────────────────────────────────────────────────────────────────────────
// Mise en cache : 131 Mo qu'on ne retélécharge pas à chaque relance. Le fichier est servi par
// le point d'accès « media » de GitHub — le chemin `raw` ne rendrait que le pointeur LFS.
$cache = __CA_TEMP_DIR__ . '/cma-openaccess.csv';
if (!file_exists($cache) || filesize($cache) < 1000000) {
	fwrite(STDERR, "Téléchargement du jeu de données (131 Mo)…\n");
	$source = @fopen(SOURCE_URL, 'r');
	if (!$source) { die("Impossible de récupérer " . SOURCE_URL . "\n"); }
	$destination = fopen($cache, 'w');
	stream_copy_to_stream($source, $destination);
	fclose($source);
	fclose($destination);
	fwrite(STDERR, sprintf("Téléchargé : %.0f Mo\n", filesize($cache) / 1048576));
}

// ── Types d'objets ────────────────────────────────────────────────────────────────────────
// Le profil « default » n'a que sept types ; le musée en emploie une trentaine. On rabat sur
// le plus proche et on garde la valeur d'origine dans les notes.
$type_map = [
	'painting'    => 'painting',
	'drawing'     => 'drawing',
	'print'       => 'print',
	'sculpture'   => 'sculpture',
	'photograph'  => 'photography',
	'photography' => 'photography',
	'film'        => 'film_media',
	'video'       => 'film_media',
	'book'        => 'document',
	'manuscript'  => 'document',
];
$default_type = 'document';

$type_ids = [];
foreach (array_unique(array_merge(array_values($type_map), [$default_type])) as $code) {
	if ($id = caGetListItemID('object_types', $code)) { $type_ids[$code] = $id; }
}
if (!isset($type_ids[$default_type])) {
	foreach ((new ca_objects())->getTypeList() as $tid => $info) {
		if ((int)($info['is_enabled'] ?? 0) === 1) { $type_ids[$default_type] = $tid; break; }
	}
}
if (!isset($type_ids[$default_type])) { die("Aucun type d'objet disponible dans « object_types ».\n"); }

function resolve_type(string $type, array $type_map, array $type_ids, string $default_type) {
	$t = mb_strtolower(trim($type));
	foreach ($type_map as $motif => $code) {
		if ($t !== '' && mb_strpos($t, $motif) !== false && isset($type_ids[$code])) { return $type_ids[$code]; }
	}
	return $type_ids[$default_type];
}

/** Les listes du CSV sont écrites à la python : "['Jewish artists', 'male']". */
function aplatir(string $v): string {
	$v = trim($v);
	if ($v === '' || $v === '[]') { return ''; }
	if ($v[0] === '[' && substr($v, -1) === ']') {
		$v = substr($v, 1, -1);
		$v = str_replace(["', '", "', \"", "\", '"], ', ', $v);
		$v = trim($v, " '\"");
	}
	return trim($v);
}

// ── Chargement ────────────────────────────────────────────────────────────────────────────

$handle = fopen($cache, 'r');
$entetes = fgetcsv($handle);
if (!$entetes) { die("CSV illisible.\n"); }
// La première colonne porte une marque d'ordre des octets.
$entetes[0] = preg_replace('!^\xEF\xBB\xBF!u', '', $entetes[0]);
$colonne = array_flip($entetes);

// Les idno déjà en base, lus d'un coup : un SELECT par œuvre coûterait plus cher que
// l'insertion elle-même sur un corpus de cette taille.
$deja = [];
$qr = (new Db())->query("SELECT idno FROM ca_objects");
foreach ($qr->getAllFieldValues('idno') as $idno) { $deja[$idno] = true; }
fwrite(STDERR, sprintf("%d objet(s) déjà en base.\n", sizeof($deja)));

$rep_type_id = caGetListItemID('object_representation_types', 'front');

$cree = $ignore = $echec = 0;
$lus  = 0;
$depart = microtime(true);

while ($lus < $voulus && ($ligne = fgetcsv($handle)) !== false) {
	$lus++;

	$get = function (string $nom) use ($ligne, $colonne) {
		$i = $colonne[$nom] ?? null;
		return ($i === null) ? '' : aplatir((string)($ligne[$i] ?? ''));
	};

	$idno  = $get('accession_number');
	$titre = $get('title');
	if ($idno === '') { $echec++; continue; }
	if (isset($deja[$idno])) { $ignore++; continue; }
	if ($titre === '') { $titre = '[' . $idno . ']'; }

	$t_object = new ca_objects();
	$t_object->set('type_id',   resolve_type($get('type'), $type_map, $type_ids, $default_type));
	$t_object->set('locale_id', $locale_id);
	$t_object->set('idno',      $idno);
	$t_object->set('access',    $access);
	$t_object->set('status',    0);

	$description = trim(join(', ', array_filter([$get('creators'), $get('creation_date')])));
	if ($description !== '') {
		$t_object->addAttribute(['description' => $description, 'locale_id' => $locale_id], 'description');
	}
	if ($medium = ($get('technique') ?: $get('support_materials'))) {
		$t_object->addAttribute(['work_medium' => $medium, 'locale_id' => $locale_id], 'work_medium');
	}
	if ($credit = $get('creditline')) {
		$t_object->addAttribute(['credit_line' => $credit, 'locale_id' => $locale_id], 'credit_line');
	}

	$notes = [];
	foreach (['type' => 'Object type', 'culture' => 'Culture', 'department' => 'Department',
	          'measurements' => 'Dimensions', 'inscriptions' => 'Inscriptions',
	          'provenance' => 'Provenance', 'tombstone' => 'Tombstone'] as $champ => $etiquette) {
		if ($v = $get($champ)) { $notes[] = "{$etiquette}: " . mb_substr($v, 0, 2000); }
	}
	if ($notes) {
		$t_object->addAttribute(['notes' => join("\n", $notes), 'locale_id' => $locale_id], 'notes');
	}

	if ($t_object->insert() === false) {
		if ($echec < 10) { fwrite(STDERR, "Échec sur {$idno} : " . join('; ', $t_object->getErrors()) . "\n"); }
		$echec++;
		continue;
	}

	$t_object->addLabel(['name' => mb_substr($titre, 0, 1024)], $locale_id, null, true);

	if ($with_media && ($url = $get('image_web'))) {
		$t_object->addRepresentation($url, $rep_type_id, $locale_id, 0, 0, true);
	}

	$cree++;
	$deja[$idno] = true;

	if (!($cree % 1000)) {
		$ecoule = microtime(true) - $depart;
		fwrite(STDERR, sprintf("  … %d objets  (%.0f/s, %s écoulées, mémoire %s)\n",
			$cree, $cree / max($ecoule, 0.001), gmdate('H:i:s', (int)$ecoule),
			caGetMemoryUsage()));
	}
}
fclose($handle);

$ecoule = microtime(true) - $depart;
fwrite(STDOUT, sprintf(
	"\nCMA : %d créé(s), %d déjà présent(s), %d en échec — %s, %.0f objets/s\n",
	$cree, $ignore, $echec, gmdate('H:i:s', (int)$ecoule), $cree / max($ecoule, 0.001)
));
fwrite(STDOUT, "L'index de recherche n'a pas été alimenté (indexation coupée pendant le chargement).\n");
fwrite(STDOUT, "Réindexer : dev-reindex --processus=4\n\n");
