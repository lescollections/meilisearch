<?php
/* ----------------------------------------------------------------------
 * reindexer.php — réindexation complète, en parallèle.
 *
 *     cd /var/www/html
 *     php app/plugins/Meilisearch/tools/reindexer.php --processus=4
 *     php app/plugins/Meilisearch/tools/reindexer.php --processus=4 --tables=ca_objects
 *
 * Fait le même travail que `caUtils rebuild-search-index`, en le répartissant sur plusieurs
 * processus. Mesuré sur 2500 objets, huit cœurs : 8,3 s à un processus, 2,6 s à quatre.
 *
 * Pourquoi un outil à part plutôt qu'une option de la commande du socle : `SearchIndexer::
 * reindex()` énumère les identifiants d'une table et boucle dessus, sans rien exposer pour
 * partitionner le travail. Mais en mode réindexation complète, `indexRow()` est indépendant
 * d'une ligne à l'autre — chaque ligne est visitée une fois et une seule. Il suffit donc de
 * en faire une file où chaque processus vient puiser.
 *
 * La file est dynamique, et c'est le point important : un découpage figé d'avance équilibre le
 * nombre de lignes, jamais leur coût. Une fiche riche en métadonnées demande bien plus de travail
 * qu'une fiche nue, et ces fiches ne se répartissent pas uniformément selon les identifiants. En
 * puisant lot par lot, un ouvrier qui finit tôt reprend du travail au lieu de laisser les autres
 * terminer seuls.
 *
 * Trois précautions, qui expliquent la forme du programme :
 *
 *   • l'index n'est tronqué qu'une fois, par le parent, avant de lancer qui que ce soit.
 *     Un enfant qui tronquerait effacerait le travail des autres ;
 *
 *   • la file et son curseur sont écrits par le parent dans un fichier temporaire ; le curseur
 *     est un entier protégé par un verrou exclusif, seul point de synchronisation entre ouvriers ;
 *
 *   • `__CollectiveAccess_IS_REINDEXING__` est posée dans chaque processus. Sans elle,
 *     l'indexeur croit être en mise à jour et va chercher des dépendances qui n'ont pas lieu
 *     d'être ;
 *
 *   • la file d'attente est vidée d'abord, comme le fait le socle : ses entrées portent sur un
 *     index qui n'existera plus.
 *
 * La file d'attente elle-même (`caUtils process-indexing-queue`) n'est pas parallélisable de
 * cette façon : elle est protégée par un verrou de fichier exclusif (LockingTrait), un seul
 * processus à la fois. La paralléliser demanderait de toucher au socle.
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

// Avant tout chargement : l'indexeur et le connecteur la lisent tous deux.
if (!defined('__CollectiveAccess_IS_REINDEXING__')) { define('__CollectiveAccess_IS_REINDEXING__', 1); }

# ----------------------------------------------------------------------
# Arguments
# ----------------------------------------------------------------------

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
	if (preg_match('!^--([a-z-]+)(?:=(.*))?$!', $arg, $m)) { $opts[$m[1]] = $m[2] ?? true; }
}

if (isset($opts['aide']) || isset($opts['help'])) {
	fwrite(STDOUT, <<<TXT

Réindexation complète en parallèle.

  --processus=N   nombre de processus (défaut : nombre de cœurs, au plus 8)
  --tables=a,b    tables à réindexer (défaut : toutes les tables indexées)
  --moteur=Nom    indexer dans ce moteur plutôt que dans celui qui est configuré
                  (ex. --moteur=Meilisearch pendant que SqlSearch2 sert encore :
                  l'index se remplit sans que la recherche en service soit touchée,
                  et la bascule qui suit trouve un index déjà prêt)
  --lot=N         identifiants retirés de la file à chaque fois (défaut : 100)
  --racine=/chemin  racine de Providence, si elle n'est pas déduite correctement
  --silencieux    n'affiche que les erreurs

TXT);
	exit(0);
}

$silencieux = isset($opts['silencieux']);

# ----------------------------------------------------------------------
# Où est Providence ?
# ----------------------------------------------------------------------
/*
 * Ce fichier est atteint par un lien symbolique (app/plugins/Meilisearch → le dépôt), et
 * __DIR__ rend le chemin réel, dans le dépôt : on ne peut donc pas remonter l'arborescence
 * depuis lui. On cherche setup.php à partir du répertoire courant, ce qui couvre l'usage
 * normal (`cd /var/www/html && php app/plugins/…`), et on accepte --racine pour le reste.
 */
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
if (!$racine || !file_exists($racine . '/setup.php')) {
	fwrite(STDERR, "Racine de Providence introuvable. Se placer dedans, ou passer --racine=/chemin\n");
	exit(2);
}

require_once($racine . '/setup.php');
require_once(__CA_LIB_DIR__ . '/Search/SearchBase.php');
require_once(__CA_LIB_DIR__ . '/Search/SearchIndexer.php');
require_once(__CA_MODELS_DIR__ . '/ca_attributes.php');
require_once(__CA_MODELS_DIR__ . '/ca_search_indexing_queue.php');
require_once(__DIR__ . '/_socle.php');

/**
 * Nom du moteur à remplir : celui que `--moteur` désigne, sinon celui qui est configuré.
 *
 * Nommer le moteur explicitement sert la migration d'une instance en service : on remplit
 * l'index du nouveau moteur pendant que l'ancien continue de répondre aux usagers, et la
 * bascule qui suit ne fait que désigner un index déjà prêt. Sans cela, il faudrait basculer
 * d'abord et réindexer ensuite — c'est-à-dire laisser la recherche vide le temps du travail.
 */
function nom_du_moteur(): ?string {
	global $opts;
	$nom = $opts['moteur'] ?? null;
	return (is_string($nom) && strlen($nom)) ? $nom : null;
}

/**
 * Le moteur à remplir.
 *
 * `SearchIndexer` garde le sien pour lui ; on en instancie un second, ce qui est sans
 * conséquence : les tampons d'écriture du connecteur sont statiques, partagés par toutes les
 * instances d'un même processus. C'est aussi le cas du connecteur ElasticSearch livré avec
 * CollectiveAccess, et c'est ce qui permet de vider ici ce que l'indexeur a accumulé là.
 */
function moteur_de_recherche() {
	static $moteur = null;
	if ($moteur === null) {
		$moteur = SearchBase::newSearchEngine(nom_du_moteur());
		if (!$moteur) {
			throw new Exception(nom_du_moteur()
				? 'Moteur « ' . nom_du_moteur() . ' » introuvable — vérifier le nom passé à --moteur'
				: 'Moteur de recherche introuvable — vérifier search_engine_plugin dans app/conf/local/app.conf');
		}
	}
	return $moteur;
}

# ----------------------------------------------------------------------
# Mode enfant : indexer une part
# ----------------------------------------------------------------------

if (isset($opts['travail'])) {
	exit(indexer_dynamique(
		(string)$opts['table'],
		(string)$opts['travail'],
		(string)$opts['curseur'],
		(int)($opts['lot'] ?? 100)
	));
}

# ----------------------------------------------------------------------
# Mode parent : orchestrer
# ----------------------------------------------------------------------

$processus = (int)($opts['processus'] ?? 0);
if ($processus < 1) { $processus = min(8, max(1, nombre_de_coeurs())); }

// Taille du lot que chaque ouvrier retire de la file. Elle fixe le déséquilibre résiduel en
// fin de table : au pire un ouvrier finit un lot pendant que les autres attendent.
$lot = max(1, (int)($opts['lot'] ?? 100));

$indexeur = new SearchIndexer(null, nom_du_moteur());
$tables   = tables_a_traiter($opts['tables'] ?? null, $indexeur);
if (!sizeof($tables)) {
	fwrite(STDERR, "Aucune table à réindexer.\n");
	exit(2);
}

$dire = function (string $texte) use ($silencieux) { if (!$silencieux) { fwrite(STDOUT, $texte); } };

$dire(sprintf("\nRéindexation de %s sur %d processus\n\n", join(', ', array_column($tables, 'name')), $processus));

// Vider la file avant de tronquer : ses entrées portent sur un index qui va disparaître.
// Le socle fait de même au début d'une réindexation complète.
if (empty($opts['tables'])) { ca_search_indexing_queue::flush(); }

$depart = microtime(true);
$echecs = 0;

foreach ($tables as $table) {
	$t0 = microtime(true);

	// Une seule troncature, par le parent.
	moteur_de_recherche()->truncateIndex($table['num']);

	$ids = identifiants($table['name']);
	$n   = sizeof($ids);

	if (!$n) {
		$dire(sprintf("  %-28s %s\n", $table['name'], 'vide'));
		continue;
	}

	// En dessous de ce seuil, lancer des processus coûte plus cher que le travail lui-même :
	// chacun doit charger CollectiveAccess avant d'indexer sa première ligne.
	$parts = ($n < 250) ? 1 : $processus;

	// La file de travail est écrite une seule fois, par le parent ; les ouvriers y puisent.
	[$fichier_travail, $fichier_curseur] = ecrire_file_de_travail($ids);

	if ($parts === 1) {
		$code = indexer_dynamique($table['name'], $fichier_travail, $fichier_curseur, $lot);
	} else {
		$code = lancer_enfants($parts, $table['name'], $racine, $fichier_travail, $fichier_curseur, $lot);
	}
	if ($code !== 0) { $echecs++; }

	@unlink($fichier_travail);
	@unlink($fichier_curseur);

	$duree = microtime(true) - $t0;
	$dire(sprintf("  %-28s %6d lignes  %6.1f s  %7.0f lignes/s\n",
		$table['name'], $n, $duree, $n / max($duree, 0.001)));
}

$total = microtime(true) - $depart;
$dire(sprintf("\n%s en %.1f s\n\n", $echecs ? "Terminé avec {$echecs} table(s) en échec" : 'Terminé', $total));

exit($echecs ? 1 : 0);

# ----------------------------------------------------------------------
# Fonctions
# ----------------------------------------------------------------------

/**
 * Écrit la file de travail : les identifiants à traiter, plus un curseur partagé.
 *
 * Les identifiants sont rangés en binaire, huit octets chacun, pour qu'un ouvrier puisse sauter
 * directement au n-ième sans lire ce qui précède. Le curseur est un simple entier dans un
 * fichier à part, protégé par un verrou exclusif.
 *
 * @return array [chemin des identifiants, chemin du curseur]
 */
function ecrire_file_de_travail(array $ids): array {
	$fichier = tempnam(sys_get_temp_dir(), 'meili-travail-');
	$curseur = $fichier . '.curseur';

	$f = fopen($fichier, 'wb');
	if (!$f) { throw new Exception("File de travail impossible à écrire : {$fichier}"); }
	foreach ($ids as $id) { fwrite($f, pack('J', (int)$id)); }
	fclose($f);

	file_put_contents($curseur, '0');
	@chmod($fichier, 0664);
	@chmod($curseur, 0664);

	return [$fichier, $curseur];
}

/**
 * Retire le prochain lot de la file, sous verrou exclusif.
 *
 * C'est le seul point de synchronisation entre ouvriers, et il ne dure que le temps de lire et
 * de réécrire un entier.
 *
 * @return array [rang du premier identifiant, nombre d'identifiants] — [0, 0] quand la file est vide
 */
function prendre_lot(string $curseur, int $total, int $lot): array {
	$f = fopen($curseur, 'c+');
	if (!$f) { throw new Exception("Curseur illisible : {$curseur}"); }
	if (!flock($f, LOCK_EX)) { fclose($f); throw new Exception("Verrou du curseur impossible : {$curseur}"); }

	rewind($f);
	$debut = (int)stream_get_contents($f);

	if ($debut >= $total) {
		flock($f, LOCK_UN);
		fclose($f);
		return [0, 0];
	}

	$combien = min($lot, $total - $debut);
	ftruncate($f, 0);
	rewind($f);
	fwrite($f, (string)($debut + $combien));
	fflush($f);
	flock($f, LOCK_UN);
	fclose($f);

	return [$debut, $combien];
}

/**
 * Indexe les lignes que l'ouvrier retire de la file, lot après lot, jusqu'à l'épuiser.
 *
 * Le découpage était auparavant statique — chaque processus recevait les identifiants valant
 * son rang modulo le nombre de processus. Cela équilibre le NOMBRE de lignes, jamais leur COÛT :
 * une fiche riche en métadonnées demande bien plus de travail qu'une fiche nue, et la répartition
 * de ces fiches ne suit pas les identifiants. Mesuré sur les 253 392 objets de l'instance INRAP,
 * le débit tombait de 25,9 à 13,6 lignes par seconde à mesure que les parts légères s'achevaient,
 * la fin de table étant dictée par la part la plus lourde restée seule.
 *
 * En puisant dans une file commune, un ouvrier qui finit tôt reprend aussitôt du travail : le
 * déséquilibre résiduel ne dépasse jamais un lot.
 *
 * On reproduit fidèlement ce que fait SearchIndexer::reindex(), préchargement compris : sans
 * lui, chaque ligne redemande ses attributs une par une.
 *
 * @return int code de sortie
 */
function indexer_dynamique(string $table, string $fichier, string $curseur, int $lot): int {
	try {
		$db        = new Db();
		$indexeur  = new SearchIndexer($db, nom_du_moteur());
		$table_num = Datamodel::getTableNum($table);
		$instance  = Datamodel::getInstanceByTableName($table, true);
		if (!$instance) { throw new Exception("Table inconnue : {$table}"); }

		$element_ids = method_exists($instance, 'getApplicableElementCodes')
			? array_keys($instance->getApplicableElementCodes(null, false, false))
			: null;

		$fh = fopen($fichier, 'rb');
		if (!$fh) { throw new Exception("File de travail illisible : {$fichier}"); }
		$total = (int)(filesize($fichier) / 8);

		while (true) {
			[$debut, $combien] = prendre_lot($curseur, $total, $lot);
			if (!$combien) { break; }

			fseek($fh, $debut * 8);
			$ids = array_values(unpack('J*', (string)fread($fh, $combien * 8)));

			if ($element_ids) { ca_attributes::prefetchAttributes($db, $table_num, $ids, $element_ids); }
			$field_data = donnees_de_champs($indexeur, $table, $ids, $db);
			// Sans ce vidage, les caches de SearchResult grossissent jusqu'à saturer la mémoire.
			SearchResult::clearCaches();

			foreach ($ids as $id) {
				$indexeur->indexRow($table_num, $id, $field_data[$id] ?? [], true);
			}
		}
		fclose($fh);

		// Le tampon du connecteur n'est vidé qu'ici : il ne l'est automatiquement qu'à la
		// destruction de l'objet, ce qui arriverait trop tard pour que le parent puisse
		// constater un échec.
		vider_tampon_moteur(moteur_de_recherche());

		return 0;
	} catch (Throwable $e) {
		fwrite(STDERR, sprintf("Ouvrier de %s en échec : %s\n", $table, $e->getMessage()));
		return 1;
	}
}

/**
 * Lance les processus enfants et attend leur terme.
 *
 * @return int 0 si tous ont abouti
 */
function lancer_enfants(int $parts, string $table, string $racine, string $fichier, string $curseur, int $lot): int {
	$enfants = [];

	for ($i = 0; $i < $parts; $i++) {
		$commande = sprintf(
			'%s %s --travail=%s --curseur=%s --lot=%d --table=%s --racine=%s%s',
			escapeshellarg(PHP_BINARY),
			escapeshellarg(__FILE__),
			escapeshellarg($fichier),
			escapeshellarg($curseur),
			$lot,
			escapeshellarg($table),
			escapeshellarg($racine),
			// L'enfant doit remplir le même moteur que le parent, sans quoi il écrirait dans
			// celui qui est configuré — c'est-à-dire ailleurs.
			nom_du_moteur() ? ' --moteur=' . escapeshellarg(nom_du_moteur()) : ''
		);

		$pipes = [];
		$p = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $racine);
		if (!is_resource($p)) {
			fwrite(STDERR, "Impossible de lancer le processus {$i}\n");
			return 1;
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$enfants[] = ['proc' => $p, 'pipes' => $pipes, 'rang' => $i, 'err' => ''];
	}

	$echecs = 0;
	foreach ($enfants as &$enfant) {
		// On lit avant d'attendre : un enfant qui remplit son tuyau d'erreur se bloquerait.
		while (true) {
			$statut = proc_get_status($enfant['proc']);
			$enfant['err'] .= (string)stream_get_contents($enfant['pipes'][2]);
			stream_get_contents($enfant['pipes'][1]);
			if (!$statut['running']) { break; }
			usleep(20000);
		}
		$enfant['err'] .= (string)stream_get_contents($enfant['pipes'][2]);

		fclose($enfant['pipes'][1]);
		fclose($enfant['pipes'][2]);
		$code = proc_close($enfant['proc']);

		if ($code !== 0) {
			$echecs++;
			fwrite(STDERR, sprintf("  ouvrier %d de %s : code %d\n%s\n", $enfant['rang'], $table, $code, trim($enfant['err'])));
		} elseif (trim($enfant['err']) !== '') {
			fwrite(STDERR, trim($enfant['err']) . "\n");
		}
	}

	return $echecs ? 1 : 0;
}

/**
 * Identifiants d'une table, dans l'ordre de la clé primaire.
 *
 * La liste entière est rendue au parent, qui en fait la file de travail ; le découpage n'est
 * plus décidé ici.
 */
function identifiants(string $table, ?Db $db = null): array {
	$db       = $db ?: new Db();
	$instance = Datamodel::getInstanceByTableName($table, true);
	$pk       = $instance->primaryKey();

	$where = [];
	if ($instance->hasField('deleted')) { $where[] = 'deleted = 0'; }

	$sql = "SELECT {$pk} FROM {$table}" . (sizeof($where) ? ' WHERE ' . join(' AND ', $where) : '') . " ORDER BY {$pk}";
	return $db->query($sql)->getAllFieldValues($pk);
}

/**
 * Tables à traiter : celles demandées, ou toutes celles que le socle déclare indexées.
 */
function tables_a_traiter($demandees, SearchIndexer $indexeur): array {
	if ($demandees && is_string($demandees)) {
		$tables = [];
		foreach (preg_split('![,;]+!', $demandees, -1, PREG_SPLIT_NO_EMPTY) as $nom) {
			$nom = trim($nom);
			if (!Datamodel::tableExists($nom)) {
				fwrite(STDERR, "Table inconnue, ignorée : {$nom}\n");
				continue;
			}
			$tables[] = ['name' => $nom, 'num' => Datamodel::getTableNum($nom)];
		}
		return $tables;
	}

	$tables = [];
	foreach ($indexeur->getIndexedTables() as $num => $info) {
		$tables[] = ['name' => $info['name'], 'num' => $num];
	}
	return $tables;
}

function nombre_de_coeurs(): int {
	if (is_readable('/proc/cpuinfo')) {
		$n = substr_count((string)file_get_contents('/proc/cpuinfo'), 'processor');
		if ($n > 0) { return $n; }
	}
	$n = (int)@shell_exec('sysctl -n hw.ncpu 2>/dev/null');
	return $n > 0 ? $n : 4;
}
