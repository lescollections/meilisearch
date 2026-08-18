<?php
/* ----------------------------------------------------------------------
 * seed-cleveland.php — charge le jeu d'exemple « cleveland » de rochambeau dans l'instance
 * de développement, pour avoir de quoi éprouver le moteur de recherche : des titres, des
 * auteurs, des techniques, des matériaux, du texte libre, et assez de volume pour que la
 * pertinence veuille dire quelque chose.
 *
 * Repris tel quel du harnais du greffon lescollections, à deux détails près : le corpus arrive
 * public (voir plus bas), et on en charge 2500 par défaut — 250 objets ne disent rien du
 * comportement d'un moteur de recherche.
 *
 * Source : https://github.com/lescollections/rochambeau/tree/main/example/cleveland
 * 2500 œuvres CC0 du Cleveland Museum of Art.
 *
 * Correspondance des champs — le jeu source est plus riche que le profil « default » de
 * CollectiveAccess, on ne prétend pas à une modélisation fine :
 *
 *   id                                  → idno
 *   titre                               → étiquette préférée
 *   denomination                        → type d'objet (mappé ; valeur d'origine dans `notes`)
 *   auteur, datation                    → description
 *   technique (ou materiaux)            → work_medium
 *   provenance (Credit line à la source) → credit_line
 *   credit                              → media_license
 *   domaine, culture, dimensions,
 *     localisation                      → notes
 *   images[0].plein                     → représentation d'objet (téléchargée)
 *
 * Les objets arrivent en `access` = 1 (public) : ce harnais sert à éprouver un moteur de
 * recherche, et les filtres d'accès de CollectiveAccess écarteraient sinon tout le corpus des
 * résultats. Poser CA_SEED_ACCESS=0 pour retrouver le comportement d'origine.
 *
 * Idempotent : un objet dont l'idno existe déjà est laissé tel quel.
 *
 * Usage : dev-seed-cleveland [nombre]
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

require_once('/var/www/html/setup.php');
require_once(__CA_APP_DIR__ . '/helpers/listHelpers.php');
require_once(__CA_MODELS_DIR__ . '/ca_objects.php');
require_once(__CA_MODELS_DIR__ . '/ca_locales.php');

const SOURCE_URL = 'https://raw.githubusercontent.com/lescollections/rochambeau/main/example/cleveland/objets.ndjson';

// Public par défaut : sans quoi les filtres d'accès écarteraient tout le corpus des résultats
// de recherche, et il n'y aurait rien à mesurer.
define('ACCESS', (int)(getenv('CA_SEED_ACCESS') !== false ? getenv('CA_SEED_ACCESS') : 1));

$wanted    = (int)($argv[1] ?? getenv('CA_SEED_CLEVELAND_COUNT') ?: 2500);
// Attention au `?:` : getenv() rend la chaîne "0", que PHP tient pour vide. Écrit
// `(getenv(…) ?: '1') === '1'`, le drapeau restait à 1 quoi qu'on demande — et le semis
// mourait à trois cents objets, mémoire épuisée dans GD.
$media_env  = getenv('CA_SEED_CLEVELAND_MEDIA');
$with_media = ($media_env === false) ? true : ($media_env === '1');
if ($wanted < 1) { die("Nombre d'objets invalide.\n"); }

$t_locale  = new ca_locales();
$locale_id = $t_locale->localeCodeToID(__CA_DEFAULT_LOCALE__) ?: $t_locale->localeCodeToID('en_US');
if (!$locale_id) { die("Aucune locale installée — la base a-t-elle bien été installée ?\n"); }

// ── La source ─────────────────────────────────────────────────────────────────────────────
// Mise en cache dans app/tmp : on ne retélécharge pas trois mégaoctets à chaque relance.
$cache = __CA_TEMP_DIR__ . '/rochambeau-cleveland-objets.ndjson';
if (!file_exists($cache) || filesize($cache) < 1024) {
	fwrite(STDERR, "Téléchargement du jeu d'exemple…\n");
	$ndjson = @file_get_contents(SOURCE_URL);
	if ($ndjson === false) { die("Impossible de récupérer " . SOURCE_URL . "\n"); }
	file_put_contents($cache, $ndjson);
}

// ── Types d'objets ────────────────────────────────────────────────────────────────────────
// Le profil « default » n'a que sept types ; la source en a une trentaine. On rabat sur le
// type le plus proche et on garde la dénomination d'origine dans les notes.
$type_map = [
	'painting'     => 'painting',
	'drawing'      => 'drawing',
	'print'        => 'print',
	'sculpture'    => 'sculpture',
	'photograph'   => 'photography',
	'photography'  => 'photography',
	'film'         => 'film_media',
	'video'        => 'film_media',
	'bound volume' => 'document',
	'manuscript'   => 'document',
];
$default_type = 'document';

$type_ids = [];
foreach (array_unique(array_merge(array_values($type_map), [$default_type])) as $idno) {
	if ($id = caGetListItemID('object_types', $idno)) { $type_ids[$idno] = $id; }
}
if (!isset($type_ids[$default_type])) {
	// Autre profil installé : on prend le premier type actif plutôt que d'échouer.
	foreach ((new ca_objects())->getTypeList() as $tid => $info) {
		if ((int)($info['is_enabled'] ?? 0) === 1) { $type_ids[$default_type] = $tid; break; }
	}
}
if (!isset($type_ids[$default_type])) { die("Aucun type d'objet disponible dans « object_types ».\n"); }

function resolve_type(string $denomination, array $type_map, array $type_ids, string $default_type) {
	$d = mb_strtolower(trim($denomination));
	foreach ($type_map as $needle => $idno) {
		if ($d !== '' && mb_strpos($d, $needle) !== false && isset($type_ids[$idno])) { return $type_ids[$idno]; }
	}
	return $type_ids[$default_type];
}

/** Une valeur de `champs` peut être une chaîne ou une liste. */
function flatten($v) : string {
	if (is_array($v)) { return trim(join(', ', array_filter(array_map('strval', $v)))); }
	return trim((string)$v);
}

// ── Chargement ────────────────────────────────────────────────────────────────────────────
$rep_type_id = caGetListItemID('object_representation_types', 'front');

$created = $skipped = $failed = $with_image = 0;
$handle = fopen($cache, 'r');
$seen = 0;

while ($seen < $wanted && ($line = fgets($handle)) !== false) {
	$line = trim($line);
	if ($line === '') { continue; }
	$seen++;

	$src = json_decode($line, true);
	if (!is_array($src) || !isset($src['id'])) { $failed++; continue; }

	$idno = 'CLE.' . $src['id'];
	if ((new ca_objects())->load(['idno' => $idno])) { $skipped++; continue; }

	$champs = is_array($src['champs'] ?? null) ? $src['champs'] : [];
	$get = fn(string $k) => flatten($champs[$k] ?? '');

	$t_object = new ca_objects();
	$t_object->set('type_id',   resolve_type($get('denomination'), $type_map, $type_ids, $default_type));
	$t_object->set('locale_id', $locale_id);
	$t_object->set('idno',      $idno);
	$t_object->set('access',    ACCESS);
	$t_object->set('status',    0);

	$description = trim(join(', ', array_filter([$get('auteur'), $get('datation')])));
	if ($description !== '') {
		$t_object->addAttribute(['description' => $description, 'locale_id' => $locale_id], 'description');
	}

	if ($medium = ($get('technique') ?: $get('materiaux'))) {
		$t_object->addAttribute(['work_medium' => $medium, 'locale_id' => $locale_id], 'work_medium');
	}
	if ($credit = $get('provenance')) {
		$t_object->addAttribute(['credit_line' => $credit, 'locale_id' => $locale_id], 'credit_line');
	}
	if ($license = trim((string)($src['credit'] ?? ''))) {
		$t_object->addAttribute(['media_license' => $license, 'locale_id' => $locale_id], 'media_license');
	}

	// Ce que le profil « default » ne sait pas ranger ailleurs, mais qu'on ne veut pas perdre.
	$notes = [];
	foreach (['denomination' => 'Object type', 'domaine' => 'Department', 'culture' => 'Culture',
	          'dimensions' => 'Dimensions', 'localisation' => 'Location'] as $k => $label) {
		if ($v = $get($k)) { $notes[] = "{$label}: {$v}"; }
	}
	if ($notes) {
		$t_object->addAttribute(['notes' => join("\n", $notes), 'locale_id' => $locale_id], 'notes');
	}

	if ($t_object->insert() === false) {
		fwrite(STDERR, "Échec sur {$idno} : " . join('; ', $t_object->getErrors()) . "\n");
		$failed++;
		continue;
	}

	$titre = trim((string)($src['titre'] ?? '')) ?: $idno;
	$t_object->addLabel(['name' => $titre], $locale_id, null, true);

	// ── L'image ───────────────────────────────────────────────────────────────────────────
	// addRepresentation() accepte une URL (RepresentableBaseModel::addRepresentation → isUrl) :
	// CollectiveAccess télécharge le fichier et fabrique ses dérivés.
	if ($with_media && !empty($src['images'][0]['plein'])) {
		$url = (string)$src['images'][0]['plein'];
		$t_object->addRepresentation(
			$url, $rep_type_id, $locale_id, 0, 1, true,
			['preferred_labels' => ['name' => $titre]],
			['original_filename' => basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg')]
		);
		if ($t_object->numErrors()) {
			fwrite(STDERR, "Image {$idno} : " . join('; ', $t_object->getErrors()) . "\n");
		} else {
			$with_image++;
		}
	}

	$created++;
	if ($created % 25 === 0) { fwrite(STDERR, "  … {$created} objets\n"); }
}
fclose($handle);

printf("Cleveland : %d créé(s) (%d avec image), %d déjà présent(s)%s.\n",
	$created, $with_image, $skipped, $failed ? ", {$failed} en échec" : '');

exit($failed ? 1 : 0);
