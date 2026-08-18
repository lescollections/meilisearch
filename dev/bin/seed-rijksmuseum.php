<?php
/* ----------------------------------------------------------------------
 * seed-rijksmuseum.php — la collection du Rijksmuseum, pour atteindre l'échelle réelle.
 *
 *     dev-seed-rijksmuseum --colonnes    # inspecte l'en-tête du CSV, n'importe rien
 *     dev-seed-rijksmuseum               # 300 000 œuvres
 *     dev-seed-rijksmuseum 50000
 *
 * Source : https://github.com/Rijksmuseum/rijksmuseum.github.io — archive ZIP d'environ
 * 800 000 objets. On plafonne à 300 000 : c'est la taille des bases visées, et le reste ne
 * ferait qu'allonger les mesures sans rien apprendre.
 *
 * Le CSV du Rijksmuseum est bien plus maigre que celui du Cleveland Museum of Art : sa
 * documentation annonce le numéro d'objet, un identifiant pérenne, un titre, un type, un
 * créateur, une date et une adresse d'image — et rien d'autre. Moins de texte libre, donc, mais
 * le volume qu'on cherche.
 *
 * Les noms exacts des colonnes ne sont pas documentés. Plutôt que de les deviner, le programme
 * les cherche parmi des variantes connues et dit ce qu'il a trouvé ; `--colonnes` affiche
 * l'en-tête réel sans rien importer. Un semeur qui suppose un format et se trompe remplit la
 * base de silence.
 *
 * Comme pour le CMA : pas d'images, indexation de recherche coupée pendant le chargement,
 * idempotent sur l'idno.
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

if (!defined('__CA_DONT_DO_SEARCH_INDEXING__')) { define('__CA_DONT_DO_SEARCH_INDEXING__', 1); }

require_once('/var/www/html/setup.php');
require_once(__CA_APP_DIR__ . '/helpers/listHelpers.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');

const SOURCE_URL = 'https://github.com/Rijksmuseum/rijksmuseum.github.io/releases/download/1.0.0/202001-rma-csv-collection.zip';
const PLAFOND    = 300000;

/*
 * Les colonnes qu'on cherche, et les noms sous lesquels elles peuvent se présenter. La
 * comparaison ignore la casse, les espaces et les séparateurs : « objectNumber », « object
 * number » et « object_number » sont un même nom.
 */
const ALIAS = [
	'idno'    => ['objectnumber', 'objectid', 'inventorynumber', 'identifier', 'priref'],
	'titre'   => ['title', 'objecttitle', 'titel'],
	'type'    => ['type', 'objecttype', 'objectname', 'classification'],
	'auteur'  => ['creator', 'principalmaker', 'artist', 'maker', 'vervaardiger'],
	'date'    => ['date', 'creationdate', 'datingyearearly', 'productiondate', 'datering'],
	'lien'    => ['persistentidentifier', 'handle', 'url', 'link'],
	'image'   => ['imageurl', 'image', 'webimageurl', 'imagelink'],
];

# ----------------------------------------------------------------------
# Options
# ----------------------------------------------------------------------

$inspecter = in_array('--colonnes', array_slice($argv, 1), true);
$demandes  = 0;
foreach (array_slice($argv, 1) as $a) { if (ctype_digit($a)) { $demandes = (int)$a; } }

$voulus = $demandes ?: (int)(getenv('CA_SEED_RIJKS_COUNT') ?: PLAFOND);
if ($voulus > PLAFOND) {
	fwrite(STDERR, sprintf("Plafonné à %s enregistrements.\n", number_format(PLAFOND, 0, ',', ' ')));
	$voulus = PLAFOND;
}
if ($voulus < 1) { die("Nombre d'œuvres invalide.\n"); }

$access     = (int)(getenv('CA_SEED_ACCESS') !== false ? getenv('CA_SEED_ACCESS') : 1);
$with_media = (getenv('CA_SEED_RIJKS_MEDIA') === '1');

# ----------------------------------------------------------------------
# La source
# ----------------------------------------------------------------------

$archive = __CA_TEMP_DIR__ . '/rijksmuseum-collection.zip';
if (!file_exists($archive) || filesize($archive) < 100000) {
	fwrite(STDERR, "Téléchargement de l'archive du Rijksmuseum…\n");
	$source = @fopen(SOURCE_URL, 'r');
	if (!$source) { die("Impossible de récupérer " . SOURCE_URL . "\n"); }
	$destination = fopen($archive, 'w');
	stream_copy_to_stream($source, $destination);
	fclose($source);
	fclose($destination);
	fwrite(STDERR, sprintf("Téléchargé : %.0f Mo\n", filesize($archive) / 1048576));
}

// On lit le CSV *dans* l'archive : l'extraire doublerait l'espace disque pour rien.
$zip = new ZipArchive();
if ($zip->open($archive) !== true) { die("Archive illisible : {$archive}\n"); }

$entree = null;
for ($i = 0; $i < $zip->numFiles; $i++) {
	$nom = $zip->getNameIndex($i);
	if (preg_match('!\.csv$!i', $nom)) { $entree = $nom; break; }
}
$zip->close();
if (!$entree) { die("Aucun CSV dans l'archive.\n"); }

$handle = fopen('zip://' . $archive . '#' . $entree, 'r');
if (!$handle) { die("Impossible d'ouvrir {$entree} dans l'archive.\n"); }

// Le séparateur n'est pas garanti : on le déduit de la première ligne.
$premiere = fgets($handle);
$sep = (substr_count($premiere, ';') > substr_count($premiere, ',')) ? ';' : ',';
rewind($handle);

$entetes = fgetcsv($handle, 0, $sep);
if (!$entetes) { die("CSV illisible.\n"); }
$entetes[0] = preg_replace('!^\xEF\xBB\xBF!u', '', $entetes[0]);

/** Réduit un nom de colonne à sa forme comparable. */
function normaliser(string $s): string {
	return preg_replace('![^a-z0-9]+!', '', mb_strtolower(trim($s)));
}

$index_par_nom = [];
foreach ($entetes as $i => $nom) { $index_par_nom[normaliser($nom)] = $i; }

$colonnes = [];
foreach (ALIAS as $role => $variantes) {
	foreach ($variantes as $variante) {
		if (isset($index_par_nom[$variante])) { $colonnes[$role] = $index_par_nom[$variante]; break; }
	}
}

if ($inspecter) {
	fwrite(STDOUT, sprintf("\nFichier : %s  (séparateur « %s », %d colonnes)\n\n", $entree, $sep, sizeof($entetes)));
	foreach ($entetes as $i => $nom) {
		$role = array_search($i, $colonnes, true);
		fwrite(STDOUT, sprintf("  %2d  %-34s %s\n", $i, $nom, $role ? "→ {$role}" : ''));
	}
	$manquants = array_diff(array_keys(ALIAS), array_keys($colonnes));
	fwrite(STDOUT, sizeof($manquants)
		? "\nNon reconnus : " . join(', ', $manquants) . " — compléter ALIAS dans ce fichier.\n\n"
		: "\nToutes les colonnes attendues sont reconnues.\n\n");
	exit(0);
}

if (!isset($colonnes['idno']) || !isset($colonnes['titre'])) {
	fwrite(STDERR, "Colonnes « idno » et « titre » introuvables dans l'en-tête :\n  " . join(' | ', $entetes) . "\n");
	fwrite(STDERR, "Lancer `dev-seed-rijksmuseum --colonnes` pour les voir, puis compléter ALIAS.\n");
	exit(2);
}

# ----------------------------------------------------------------------
# Préparation
# ----------------------------------------------------------------------

$t_locale  = new ca_locales();
$locale_id = $t_locale->localeCodeToID(__CA_DEFAULT_LOCALE__) ?: $t_locale->localeCodeToID('en_US');
if (!$locale_id) { die("Aucune locale installée — la base a-t-elle bien été installée ?\n"); }

$type_map = [
	'schilderij' => 'painting',    'painting'   => 'painting',
	'tekening'   => 'drawing',     'drawing'    => 'drawing',
	'prent'      => 'print',       'print'      => 'print',
	'beeldhouw'  => 'sculpture',   'sculpture'  => 'sculpture',
	'foto'       => 'photography', 'photograph' => 'photography',
	'film'       => 'film_media',  'video'      => 'film_media',
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

// Les idno déjà en base, lus d'un coup : un SELECT par œuvre coûterait plus cher que
// l'insertion elle-même sur un corpus de cette taille.
$deja = [];
foreach ((new Db())->query("SELECT idno FROM ca_objects")->getAllFieldValues('idno') as $idno) {
	$deja[$idno] = true;
}
fwrite(STDERR, sprintf("%s objet(s) déjà en base.\n", number_format(sizeof($deja), 0, ',', ' ')));

$rep_type_id = caGetListItemID('object_representation_types', 'front');

# ----------------------------------------------------------------------
# Chargement
# ----------------------------------------------------------------------

$cree = $ignore = $echec = $lus = 0;
$depart = microtime(true);

while ($lus < $voulus && ($ligne = fgetcsv($handle, 0, $sep)) !== false) {
	$lus++;

	$get = function (string $role) use ($ligne, $colonnes) {
		$i = $colonnes[$role] ?? null;
		return ($i === null) ? '' : trim((string)($ligne[$i] ?? ''));
	};

	$idno  = $get('idno');
	$titre = $get('titre');
	if ($idno === '') { $echec++; continue; }
	if (isset($deja[$idno])) { $ignore++; continue; }
	if ($titre === '') { $titre = '[' . $idno . ']'; }

	$t_object = new ca_objects();
	$t_object->set('type_id',   resolve_type($get('type'), $type_map, $type_ids, $default_type));
	$t_object->set('locale_id', $locale_id);
	$t_object->set('idno',      mb_substr($idno, 0, 255));
	$t_object->set('access',    $access);
	$t_object->set('status',    0);

	$description = trim(join(', ', array_filter([$get('auteur'), $get('date')])));
	if ($description !== '') {
		$t_object->addAttribute(['description' => $description, 'locale_id' => $locale_id], 'description');
	}

	$notes = [];
	foreach (['type' => 'Object type', 'lien' => 'Persistent identifier'] as $role => $etiquette) {
		if ($v = $get($role)) { $notes[] = "{$etiquette}: " . mb_substr($v, 0, 2000); }
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

	if ($with_media && ($url = $get('image'))) {
		$t_object->addRepresentation($url, $rep_type_id, $locale_id, 0, 0, true);
	}

	$cree++;
	$deja[$idno] = true;

	if (!($cree % 5000)) {
		$ecoule = microtime(true) - $depart;
		fwrite(STDERR, sprintf("  … %s objets  (%.0f/s, %s écoulées, mémoire %s)\n",
			number_format($cree, 0, ',', ' '), $cree / max($ecoule, 0.001),
			gmdate('H:i:s', (int)$ecoule), caGetMemoryUsage()));
	}
}
fclose($handle);

$ecoule = microtime(true) - $depart;
fwrite(STDOUT, sprintf(
	"\nRijksmuseum : %s créé(s), %s déjà présent(s), %d en échec — %s, %.0f objets/s\n",
	number_format($cree, 0, ',', ' '), number_format($ignore, 0, ',', ' '), $echec,
	gmdate('H:i:s', (int)$ecoule), $cree / max($ecoule, 0.001)
));
fwrite(STDOUT, "L'index de recherche n'a pas été alimenté (indexation coupée pendant le chargement).\n");
fwrite(STDOUT, "Réindexer : dev-reindex --processus=4\n\n");
