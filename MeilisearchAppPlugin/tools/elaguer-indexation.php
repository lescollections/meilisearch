<?php
/* ----------------------------------------------------------------------
 * elaguer-indexation.php — réduire ce que CollectiveAccess indexe, et le dire.
 *
 *     cd /var/www/html
 *     php app/plugins/Meilisearch/tools/elaguer-indexation.php --simuler   # ce qui serait perdu
 *     php app/plugins/Meilisearch/tools/elaguer-indexation.php             # écrit la surcharge
 *     php app/plugins/Meilisearch/tools/elaguer-indexation.php --defaire   # revient à l'état livré
 *
 * `search_indexing.conf` déclare, pour chaque table sujet, les tables liées dont le contenu
 * doit être versé dans l'index de cette table. Pour `ca_objects`, elles sont vingt-quatre :
 * entités, lieux, occurrences, collections, prêts, ensembles, étiquettes libres, transcriptions,
 * sessions de téléversement… Chacune vaut au moins une requête SQL par ligne indexée, qu'elle
 * ramène quelque chose ou non. Mesuré sur le corpus de test : environ quarante-quatre requêtes
 * par objet, et 94 % du temps de réindexation passé là — pas dans le moteur.
 *
 * Élaguer n'est pas une optimisation neutre : ce qui n'est plus indexé n'est plus cherchable.
 * Chercher un objet par le nom d'un lieu qui lui est lié, ou par le titre d'un ensemble qui le
 * contient, cessera de fonctionner. D'où `--simuler`, qui n'écrit rien et énumère exactement ce
 * qu'on abandonne.
 *
 * Le fichier produit est une *surcharge* : `app/conf/local/search_indexing.conf`, dérivé à
 * chaque exécution de celui du socle. On ne recopie pas le fichier livré — il évoluera, et une
 * copie figée se mettrait silencieusement à diverger.
 *
 * (Cette couche locale, elle, est bien chargée par `Configuration::load()` — contrairement à
 * `app/conf/local/app_<nom>.conf`, qui ne l'est pas.)
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

/*
 * Les tables liées retirées par défaut.
 *
 * Le critère : ce qui relève de la gestion interne ou d'un usage occasionnel, par opposition à
 * ce qui porte la description d'une pièce. Les entités (auteurs, donateurs), les listes
 * (matériaux, techniques, types) et les représentations restent : c'est ce sur quoi on cherche.
 *
 * Rien d'intangible — `--retirer=` remplace cette liste, `--garder=` en retire des éléments.
 */
const RETIRER_PAR_DEFAUT = [
	'ca_media_upload_sessions',            // sessions de téléversement : pure mécanique interne
	'ca_item_tags',                        // étiquettes libres posées par les utilisateurs
	'ca_item_comments',                    // commentaires
	'ca_sets', 'ca_set_items',             // ensembles de travail
	'ca_representation_transcriptions',    // transcriptions de médias
	'ca_representation_annotation_labels', // annotations sur les médias
	'ca_object_checkouts',                 // sorties d'objets
	'ca_loans', 'ca_loan_labels',          // prêts
	'ca_movements', 'ca_movement_labels',  // mouvements
	'ca_tours', 'ca_tour_labels',          // parcours
	'ca_tour_stops', 'ca_tour_stop_labels',
];

# ----------------------------------------------------------------------

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('!^--([a-z-]+)(?:=(.*))?$!', $arg, $m)) { $opts[$m[1]] = $m[2] ?? true; }
}

if (isset($opts['aide']) || isset($opts['help'])) {
	fwrite(STDOUT, <<<TXT

Réduit ce que CollectiveAccess indexe, en écrivant app/conf/local/search_indexing.conf.

  --simuler         n'écrit rien ; énumère ce qui serait retiré et ce qu'on perdrait
  --defaire         supprime la surcharge et revient à la configuration livrée
  --retirer=a,b     remplace la liste des tables liées à retirer
  --garder=a,b      conserve ces tables malgré la liste
  --racine=/chemin  racine de Providence, si elle n'est pas déduite correctement

TXT);
	exit(0);
}

# Où est Providence ? (ce fichier est atteint par un lien symbolique : __DIR__ mène au dépôt)
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
if (!$racine) {
	fwrite(STDERR, "Racine de Providence introuvable. Se placer dedans, ou passer --racine=/chemin\n");
	exit(2);
}

$source = $racine . '/app/conf/search_indexing.conf';
$cible  = $racine . '/app/conf/local/search_indexing.conf';

# ----------------------------------------------------------------------
# --defaire
# ----------------------------------------------------------------------

if (isset($opts['defaire'])) {
	if (!file_exists($cible)) {
		fwrite(STDOUT, "Aucune surcharge en place — rien à défaire.\n");
		exit(0);
	}
	if (!@unlink($cible)) {
		fwrite(STDERR, "Impossible de supprimer {$cible}\n");
		exit(1);
	}
	fwrite(STDOUT, "Surcharge supprimée. Relancer une réindexation complète pour retrouver l'index d'origine.\n");
	exit(0);
}

# ----------------------------------------------------------------------
# La liste
# ----------------------------------------------------------------------

$a_retirer = !empty($opts['retirer'])
	? preg_split('![,;]+!', (string)$opts['retirer'], -1, PREG_SPLIT_NO_EMPTY)
	: RETIRER_PAR_DEFAUT;

if (!empty($opts['garder'])) {
	$garder    = preg_split('![,;]+!', (string)$opts['garder'], -1, PREG_SPLIT_NO_EMPTY);
	$a_retirer = array_values(array_diff($a_retirer, array_map('trim', $garder)));
}
$a_retirer = array_map('trim', $a_retirer);

# ----------------------------------------------------------------------
# Découpage du fichier livré
# ----------------------------------------------------------------------

if (!is_readable($source)) {
	fwrite(STDERR, "Illisible : {$source}\n");
	exit(2);
}

$lignes = explode("\n", file_get_contents($source));

/**
 * Repère les blocs de premier niveau (`ca_objects = { … }`) et, dans chacun, les sous-blocs
 * d'une tabulation (`ca_places = { … }`).
 *
 * On travaille sur le texte plutôt que sur la configuration analysée : `Configuration` ne sait
 * pas réécrire un `.conf`, et regénérer le fichier depuis la structure perdrait commentaires et
 * mise en forme — donc toute chance de comprendre plus tard ce qu'on a changé.
 */
function fin_de_bloc(array $lignes, int $debut): int {
	$profondeur = 0;
	for ($i = $debut; $i < sizeof($lignes); $i++) {
		$profondeur += substr_count($lignes[$i], '{') - substr_count($lignes[$i], '}');
		if ($profondeur === 0 && $i > $debut) { return $i; }
	}
	return sizeof($lignes) - 1;
}

/**
 * Champs déclarés dans un sous-bloc, pour dire ce qu'on perd.
 *
 * On ne lit que l'intérieur des blocs `fields = { … }` : le reste du sous-bloc décrit le chemin
 * relationnel (`tables`, `keys`, `related`), et le prendre pour des champs rendrait le compte
 * rendu faux là où il doit précisément éclairer une décision.
 */
function champs_du_bloc(array $bloc): array {
	$champs   = [];
	$dans     = false;
	$profondeur = 0;

	foreach ($bloc as $ligne) {
		if (!$dans) {
			if (preg_match('!\bfields = \{!', $ligne)) {
				$dans       = true;
				$profondeur = substr_count($ligne, '{') - substr_count($ligne, '}');
			}
			continue;
		}

		// Un champ est une entrée de premier niveau du bloc `fields`.
		if ($profondeur === 1 && preg_match('!^\s*([a-zA-Z_]+)\s*=!', $ligne, $m)) { $champs[] = $m[1]; }

		$profondeur += substr_count($ligne, '{') - substr_count($ligne, '}');
		if ($profondeur <= 0) { $dans = false; }
	}

	return array_values(array_unique($champs));
}

$blocs_modifies = [];
$perdu          = [];

for ($i = 0; $i < sizeof($lignes); $i++) {
	if (!preg_match('!^([a-z_]+) = \{!', $lignes[$i], $m)) { continue; }

	$table_sujet = $m[1];
	$fin         = fin_de_bloc($lignes, $i);
	$bloc        = array_slice($lignes, $i, $fin - $i + 1);
	$i           = $fin;

	$sortie   = [$bloc[0]];
	$retires  = [];

	for ($j = 1; $j < sizeof($bloc) - 1; $j++) {
		if (preg_match('!^\t([a-zA-Z_]+) = \{!', $bloc[$j], $m2)) {
			$fin_sous = fin_de_bloc($bloc, $j);
			$sous     = array_slice($bloc, $j, $fin_sous - $j + 1);

			if (in_array($m2[1], $a_retirer, true)) {
				$retires[$m2[1]] = champs_du_bloc($sous);
			} else {
				$sortie = array_merge($sortie, $sous);
			}
			$j = $fin_sous;
		} else {
			$sortie[] = $bloc[$j];
		}
	}
	$sortie[] = $bloc[sizeof($bloc) - 1];

	if (sizeof($retires)) {
		$blocs_modifies[$table_sujet] = $sortie;
		$perdu[$table_sujet]          = $retires;
	}
}

# ----------------------------------------------------------------------
# Compte rendu
# ----------------------------------------------------------------------

if (!sizeof($blocs_modifies)) {
	fwrite(STDOUT, "Rien à retirer : aucune des tables demandées n'est indexée.\n");
	exit(0);
}

fwrite(STDOUT, "\n\033[1mCe qui ne sera plus indexé — donc plus cherchable\033[0m\n\n");

$total_sous_blocs = 0;
foreach ($perdu as $sujet => $retires) {
	fwrite(STDOUT, "  \033[1m{$sujet}\033[0m ne sera plus trouvable par :\n");
	foreach ($retires as $table => $champs) {
		$total_sous_blocs++;
		fwrite(STDOUT, sprintf("    %-38s %s\n", $table, sizeof($champs) ? join(', ', $champs) : '—'));
	}
	fwrite(STDOUT, "\n");
}

fwrite(STDOUT, sprintf(
	"  %d bloc(s) retiré(s) sur %d table(s) sujet : autant de requêtes SQL en moins par ligne indexée.\n\n",
	$total_sous_blocs, sizeof($perdu)
));

if (isset($opts['simuler'])) {
	fwrite(STDOUT, "Simulation : rien n'a été écrit.\n\n");
	exit(0);
}

# ----------------------------------------------------------------------
# Écriture
# ----------------------------------------------------------------------

$entete = <<<TXT
# ----------------------------------------------------------------------
# Surcharge de search_indexing.conf — produite par
# app/plugins/Meilisearch/tools/elaguer-indexation.php
#
# NE PAS MODIFIER À LA MAIN : ce fichier est dérivé de app/conf/search_indexing.conf à chaque
# exécution de l'outil. Pour changer ce qui est retiré, relancer l'outil avec --retirer= ou
# --garder=. Pour revenir à la configuration livrée : --defaire.
#
# Tables liées retirées : {LISTE}
#
# Produit le {DATE}
# ----------------------------------------------------------------------


TXT;

$contenu = str_replace(
	['{LISTE}', '{DATE}'],
	[join(', ', $a_retirer), date('Y-m-d H:i')],
	$entete
);

foreach ($blocs_modifies as $sujet => $bloc) {
	$contenu .= join("\n", $bloc) . "\n\n";
}

$dossier = dirname($cible);
if (!is_dir($dossier) && !@mkdir($dossier, 0775, true)) {
	fwrite(STDERR, "Impossible de créer {$dossier}\n");
	exit(1);
}

if (@file_put_contents($cible, $contenu) === false) {
	fwrite(STDERR, "Impossible d'écrire {$cible}\n");
	exit(1);
}

# Contrôle : on relit par la voie normale et on vérifie que la configuration tient debout.
require_once($racine . '/setup.php');
$conf = Configuration::load($racine . '/app/conf/search_indexing.conf');
$manquants = [];
foreach (array_keys($blocs_modifies) as $sujet) {
	$bloc = $conf->getAssoc($sujet);
	if (!is_array($bloc) || !sizeof($bloc)) { $manquants[] = $sujet; }
}

if (sizeof($manquants)) {
	@unlink($cible);
	fwrite(STDERR, "\nLa surcharge produite est illisible pour CollectiveAccess (" . join(', ', $manquants) . ").\n");
	fwrite(STDERR, "Elle a été supprimée ; rien n'a changé. Le format de search_indexing.conf a peut-être évolué.\n\n");
	exit(1);
}

fwrite(STDOUT, "Écrit : {$cible}\n");
fwrite(STDOUT, "Relancer une réindexation complète pour que l'index reflète ce choix :\n");
fwrite(STDOUT, "  php app/plugins/Meilisearch/tools/reindexer.php --processus=4\n\n");
exit(0);
