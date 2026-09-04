<?php
/* ----------------------------------------------------------------------
 * reindexer.php — réindexation complète, en parallèle.
 *
 *     cd /var/www/html
 *     php app/plugins/Meilisearch/tools/reindexer.php --processus=4
 *     php app/plugins/Meilisearch/tools/reindexer.php --processus=4 --tables=ca_objects
 *     php app/plugins/Meilisearch/tools/reindexer.php --processus=8 --fantome
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
 * Mode fantôme (`--fantome`) — pour une instance qui vit DÉJÀ sur Meilisearch :
 *
 * Tel quel, le programme tronque l'index avant de le refaire, et la recherche rend zéro résultat
 * tant qu'il n'a pas fini. Mesuré le 25/08/2026 sur la production comodo : 6 h 10 au total, dont
 * 5 h 10 pour ca_objects seul (253 763 lignes à 14 lignes/s sur huit processus). C'était sans
 * conséquence tant que l'application répondait sur SqlSearch2 et que Meilisearch se remplissait à
 * côté (`--moteur=Meilisearch`) ; depuis la bascule, ce sont six heures de recherche vide.
 *
 * `--fantome` construit chaque index à côté du sien, sous `<index>_new`, laisse l'index vivant
 * répondre pendant tout ce temps, et ne permute qu'à la toute fin — une seule requête
 * POST /swap-indexes, atomique (Meilisearch >= 1.12), qui échange les contenus sans toucher aux
 * noms. La coupure de service n'est plus mesurable.
 *
 * Trois propriétés qui expliquent la forme de ce mode :
 *
 *   • tout ou rien. Une seule table en échec annule la permutation ENTIÈRE : un index d'hier
 *     complet vaut mieux qu'un index d'aujourd'hui amputé d'une table, et une permutation
 *     partielle laisserait un ca_objects neuf en face d'un ca_entities d'hier, donc des facettes
 *     croisées incohérentes ;
 *
 *   • les réglages du fantôme sont RECOPIÉS de l'index vivant, non recalculés. `searchableAttributes`,
 *     `nonSeparatorTokens`, `separatorTokens`, `stopWords`, `synonyms`, `rankingRules` : un seul de
 *     ces réglages oublié ne casse rien de visible, il change silencieusement les résultats. Et la
 *     permutation emporte les réglages avec les documents — vérifié le 04/09/2026 sur deux index
 *     jetables en 1.13.3 — donc ce que porte le fantôme est exactement ce qui entrera en service ;
 *
 *   • le fantôme fait doubler la base sur le disque le temps de la reconstruction. D'où le
 *     garde-fou d'espace, qui refuse de démarrer plutôt que de remplir le disque : un disque plein
 *     emporterait AUSSI l'index vivant, c'est-à-dire exactement ce que ce mode protège.
 *
 * Ce qu'il ne fait pas : une fiche modifiée PENDANT la reconstruction et écrite directement dans
 * l'index vivant est perdue à la permutation, le fantôme ayant été bâti sur l'état de la base au
 * moment où l'ouvrier est passé sur elle. Ce qui transite par `ca_search_indexing_queue` est en
 * revanche rattrapable, la file n'étant pas vidée à la fin : rejouer
 * `caUtils process-indexing-queue` après la permutation en récupère la plus grande part.
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
  --fantome       reconstruire dans des index fantômes « <index>_new », puis les permuter
                  d'un coup avec les index vivants (POST /swap-indexes) une fois TOUTES les
                  tables refaites. La recherche en service n'est jamais vide, et une table en
                  échec annule toute la permutation. Réservé à Meilisearch.
  --suffixe=_new  suffixe des index fantômes (défaut : _new)
  --garder-anciens  après la permutation, conserver les anciens index au lieu de les
                  supprimer : le retour arrière est alors une seconde permutation, immédiate.
                  Ils occupent le disque jusqu'à ce qu'on les supprime à la main.
  --chemin-donnees=/chemin  répertoire de données de Meilisearch, pour mesurer l'espace libre
                  (défaut : \$MEILI_DB_PATH, sinon /var/lib/meilisearch/data)
  --sans-garde-place  passer outre le contrôle d'espace disque du mode fantôme
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

# ----------------------------------------------------------------------
# Mode fantôme : où ce processus écrit-il ?
# ----------------------------------------------------------------------
/*
 * Le suffixe est posé sur Schema, statiquement, plutôt que passé de main en main : `indexName()`
 * est le seul endroit du connecteur qui nomme un index — écriture comme lecture — et le poser là
 * détourne le processus entier d'un coup. Le réindexeur fait d'ailleurs vivre deux moteurs côte à
 * côte, celui de SearchIndexer et le sien ; un réglage porté par l'instance n'en toucherait qu'un,
 * et une moitié des écritures partirait dans l'index en service sans que rien ne le signale.
 *
 * Posé ici, avant le mode enfant qui suit immédiatement : chaque ouvrier reçoit --fantome dans sa
 * ligne de commande et refait ce chemin pour son propre compte.
 */
$fantome         = isset($opts['fantome']);
$suffixe_fantome = (isset($opts['suffixe']) && is_string($opts['suffixe']) && strlen($opts['suffixe']))
	? (string)$opts['suffixe'] : '_new';

if ($fantome) {
	if (!class_exists('WLPlugSearchEngineMeilisearch')) {
		require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch.php');
	}
	\Meilisearch\Schema::setShadowSuffix($suffixe_fantome);
	$suffixe_fantome = \Meilisearch\Schema::shadowSuffix();

	if (!strlen($suffixe_fantome)) {
		fwrite(STDERR, "Suffixe de mode fantôme vide : il ne peut contenir que [A-Za-z0-9_-].\n");
		exit(2);
	}
}

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

// Les contrôles du mode fantôme sont faits AVANT le moindre travail : refuser de démarrer est la
// seule position tenable quand l'index en service est en jeu.
$paires = [];   // [nom de table => [index vivant, index fantôme]], pour la permutation finale
if ($fantome) {
	verifier_moteur_permutable(moteur_de_recherche());
	verifier_place(moteur_de_recherche(), $opts['chemin-donnees'] ?? null, isset($opts['sans-garde-place']), $dire);
	$dire(sprintf("  mode fantôme    écriture dans « <index>%s », permutation à la fin\n\n", $suffixe_fantome));
}

// Vider la file avant de tronquer : ses entrées portent sur un index qui va disparaître.
// Le socle fait de même au début d'une réindexation complète.
//
// En mode fantôme rien ne disparaît, mais ces entrées n'ont pas davantage de raison d'être : le
// fantôme est bâti sur la base telle qu'elle est maintenant, il portera donc déjà ce qu'elles
// demandaient d'écrire. Ce qui sera enfilé PENDANT la reconstruction, en revanche, est laissé en
// place à dessein — la permutation emporte les écritures faites à l'index vivant depuis le
// départ, et rejouer la file après coup en rattrape la plus grande part.
if (empty($opts['tables'])) { ca_search_indexing_queue::flush(); }

$depart = microtime(true);
$echecs = 0;

foreach ($tables as $table) {
	$t0 = microtime(true);

	if ($fantome) {
		// Pas de troncature : l'index vivant continue de répondre aux usagers. On fabrique à
		// côté un index neuf, réglé exactement comme lui, et c'est celui-là que les ouvriers
		// rempliront — ils y sont détournés par le suffixe posé sur Schema.
		try {
			$paires[$table['name']] = preparer_fantome(moteur_de_recherche(), $table['name'], $suffixe_fantome);
		} catch (Throwable $e) {
			// Sans ce filet, l'exception traverse et le programme meurt sans supprimer les
			// fantômes déjà bâtis : ils resteraient sur le disque sans que personne ne les
			// réclame.
			fwrite(STDERR, sprintf("Fantôme de %s impossible à préparer : %s\n", $table['name'], $e->getMessage()));
			$echecs++;
			break;
		}
	} else {
		// Une seule troncature, par le parent.
		moteur_de_recherche()->truncateIndex($table['num']);
	}

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
		$code = lancer_enfants($parts, $table['name'], $racine, $fichier_travail, $fichier_curseur, $lot,
			$fantome ? $suffixe_fantome : '');
	}
	if ($code !== 0) { $echecs++; }

	@unlink($fichier_travail);
	@unlink($fichier_curseur);

	$duree = microtime(true) - $t0;
	$dire(sprintf("  %-28s %6d lignes  %6.1f s  %7.0f lignes/s\n",
		$table['name'], $n, $duree, $n / max($duree, 0.001)));

	// En mode fantôme, une table en échec condamne la permutation entière : refaire les tables
	// suivantes coûterait des heures pour un index qui n'entrera jamais en service. On s'arrête
	// là, et le ménage se fait plus bas.
	if ($echecs && $fantome) { break; }
}

if ($fantome) {
	if ($echecs) {
		// Tout ou rien. On défait ce qui a été construit, ne serait-ce que pour rendre au disque
		// la place que les fantômes occupent : une reprise repartira de zéro de toute façon,
		// puisque rien ne dit jusqu'où la table en échec était allée.
		$dire(sprintf("\n%d table(s) en échec : AUCUNE permutation. L'index en service est intact.\n", $echecs));
		supprimer_fantomes(moteur_de_recherche(), $paires, $dire);
	} else {
		permuter(moteur_de_recherche(), $paires, $dire, isset($opts['garder-anciens']));
	}
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
 * de ces fiches ne suit pas les identifiants. Mesuré sur les 253 392 objets de l'instance 130.32,
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
function lancer_enfants(int $parts, string $table, string $racine, string $fichier, string $curseur, int $lot, string $suffixe_fantome = ''): int {
	$enfants = [];

	for ($i = 0; $i < $parts; $i++) {
		$commande = sprintf(
			'%s %s --travail=%s --curseur=%s --lot=%d --table=%s --racine=%s%s%s',
			escapeshellarg(PHP_BINARY),
			escapeshellarg(__FILE__),
			escapeshellarg($fichier),
			escapeshellarg($curseur),
			$lot,
			escapeshellarg($table),
			escapeshellarg($racine),
			// L'enfant doit remplir le même moteur que le parent, sans quoi il écrirait dans
			// celui qui est configuré — c'est-à-dire ailleurs.
			nom_du_moteur() ? ' --moteur=' . escapeshellarg(nom_du_moteur()) : '',
			// Même raison pour le fantôme : sans le suffixe, l'ouvrier écrirait dans l'index en
			// service — c'est-à-dire qu'il ferait exactement ce que ce mode évite.
			$suffixe_fantome !== '' ? ' --fantome --suffixe=' . escapeshellarg($suffixe_fantome) : ''
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

# ----------------------------------------------------------------------
# Mode fantôme
# ----------------------------------------------------------------------

/**
 * La permutation atomique d'index est une opération de Meilisearch, et d'aucun autre moteur du
 * socle : refuser tout de suite vaut mieux que réindexer six heures pour buter sur l'absence de
 * getClient() au moment de permuter.
 */
function verifier_moteur_permutable($moteur): void {
	if ($moteur instanceof WLPlugSearchEngineMeilisearch) { return; }

	fwrite(STDERR, sprintf(
		"--fantome ne vaut que pour Meilisearch : la permutation atomique d'index (POST /swap-indexes)\n"
		. "est une opération de ce moteur. Moteur visé ici : %s.\n",
		method_exists($moteur, 'engineName') ? $moteur->engineName() : get_class($moteur)
	));
	exit(2);
}

/**
 * Refuse de démarrer s'il n'y a pas de quoi faire tenir le fantôme à côté du vivant.
 *
 * Un fantôme qui remplit le disque emporterait AUSSI l'index en service — c'est-à-dire qu'il
 * causerait précisément la panne que ce mode existe pour éviter. On exige donc le double de ce que
 * la base occupe : le fantôme atteindra la même taille, et Meilisearch a besoin de place de
 * travail par-dessus, chaque déclaration d'attribut filtrable lui faisant reconstruire ses bases
 * de facettes.
 *
 * Relevé le 04/09/2026 sur la production comodo : usedDatabaseSize 3,6 Gio pour un databaseSize de
 * 5,2 Gio, et 183 Gio libres sur /dev/md3 — la marge est confortable, le garde-fou est là pour les
 * instances qui ne l'ont pas.
 *
 * L'API n'expose pas le chemin des données : on prend --chemin-donnees, sinon $MEILI_DB_PATH,
 * sinon le chemin usuel, et l'on remonte au premier ancêtre mesurable. Vérifié sous www-data :
 * disk_free_space('/var/lib/meilisearch/data') échoue (le répertoire parent est en 750
 * meilisearch:meilisearch) là où '/var/lib' répond — et c'est le même système de fichiers, qui est
 * la seule chose que l'on mesure ici.
 */
function verifier_place($moteur, ?string $chemin, bool $forcer, callable $dire): void {
	$hote = (string)parse_url($moteur->getClient()->getBaseUrl(), PHP_URL_HOST);
	if (!in_array($hote, ['127.0.0.1', 'localhost', '::1'], true)) {
		$dire(sprintf("  espace disque   non vérifié : Meilisearch est sur %s, son disque n'est pas le nôtre\n", $hote));
		return;
	}

	try {
		$stats = $moteur->getClient()->instanceStats();
	} catch (Throwable $e) {
		fwrite(STDERR, 'Statistiques Meilisearch illisibles, espace disque non vérifiable : ' . $e->getMessage() . "\n");
		if (!$forcer) { exit(3); }
		return;
	}

	$occupe = (float)($stats['usedDatabaseSize'] ?? $stats['databaseSize'] ?? 0);
	$requis = $occupe * 2.0;

	$chemin = $chemin ?: ((string)getenv('MEILI_DB_PATH') ?: '/var/lib/meilisearch/data');
	$libre  = espace_libre($chemin);

	if ($libre === null) {
		fwrite(STDERR, "Espace libre indéterminable sous {$chemin} : passer --chemin-donnees=/… ou --sans-garde-place.\n");
		if (!$forcer) { exit(3); }
		return;
	}

	$dire(sprintf("  espace disque   base %s, fantôme %s attendus, %s libres sous %s\n",
		octets($occupe), octets($occupe), octets($libre), $chemin));

	if ($libre >= $requis) { return; }

	$message = sprintf(
		"Place insuffisante : le fantôme demande environ %s (autant que la base actuelle, %s, plus\n"
		. "la place de travail de Meilisearch), et il ne reste que %s sous %s.\n",
		octets($requis), octets($occupe), octets($libre), $chemin
	);

	if ($forcer) {
		fwrite(STDERR, $message . "--sans-garde-place : on passe outre.\n");
		return;
	}

	fwrite(STDERR, $message
		. "Rien n'a été touché. --sans-garde-place passe outre, en sachant qu'un disque plein\n"
		. "emporterait AUSSI l'index en service.\n");
	exit(3);
}

/**
 * Espace libre du système de fichiers qui porte ce chemin, en remontant au premier ancêtre
 * mesurable : ce qui nous intéresse est le système de fichiers, et il est le même tout au long de
 * la remontée jusqu'à un point de montage.
 */
function espace_libre(string $chemin): ?float {
	for ($i = 0; $i < 12 && strlen($chemin); $i++) {
		$libre = @disk_free_space($chemin);
		if (is_float($libre) || is_int($libre)) { return (float)$libre; }

		$parent = dirname($chemin);
		if ($parent === $chemin) { break; }
		$chemin = $parent;
	}
	return null;
}

/**
 * Fabrique l'index fantôme d'une table : vide, mais réglé exactement comme l'index vivant.
 *
 * Les réglages sont RECOPIÉS de l'index vivant plutôt que recalculés depuis le schéma. La
 * différence n'est pas théorique : `filterableAttributes` s'enrichit au fil des versements des
 * variantes de facette par type de relation (voir declareFacetAttributes()), que le schéma ne sait
 * pas énumérer d'avance ; et `searchableAttributes`, `separatorTokens`, `stopWords`, `synonyms`
 * peuvent avoir été posés à la main sur l'instance. Un réglage oublié ne casse rien de visible, il
 * change silencieusement les résultats. Vérifié le 04/09/2026 contre l'index vivant
 * ca_ca_user_groups : GET /settings réinjecté tel quel par PATCH rend un jeu identique clé pour
 * clé (Meilisearch 1.13.3). La recopie passe par copySettings(), qui transporte le JSON sans le
 * décoder — un objet vide décodé en tableau PHP repart en `[]` et Meilisearch refuse tout le lot.
 *
 * prepareIndexForTable() passe ensuite par-dessus : il ajoute aux attributs filtrables recopiés
 * ceux que le schéma sait déduire, et n'écrase pas searchableAttributes — Schema::indexSettings()
 * ne le pose que si on le lui donne.
 *
 * L'index vivant est créé s'il manque, pour la seule raison que la permutation exige que les deux
 * index existent : sinon Meilisearch accepte la requête et fait échouer la tâche en
 * `index_not_found` (vérifié sur 1.13.3), ce qui perdrait la permutation de toutes les autres
 * tables avec elle.
 *
 * @return array [index vivant, index fantôme]
 */
function preparer_fantome($moteur, string $table, string $suffixe): array {
	$client  = $moteur->getClient();
	$vivant  = $moteur->getSchema()->liveIndexName($table);
	$spectre = $vivant . $suffixe;

	// Un fantôme laissé par une tentative interrompue porterait des documents d'avant, que rien
	// ne viendrait effacer : on repart d'un index neuf plutôt que d'écrire par-dessus.
	$client->deleteIndex($spectre);
	$client->createIndex($spectre, \Meilisearch\Schema::PK);

	if ($client->indexExists($vivant)) {
		$client->copySettings($vivant, $spectre);
	}

	// Vaut aussi pour une table sans aucune ligne : son index vivant doit devenir vide, et non
	// rester tel qu'il était.
	$moteur->prepareIndexForTable($table);
	$client->createIndex($vivant, \Meilisearch\Schema::PK);

	return [$vivant, $spectre];
}

/**
 * Permute d'un seul coup tous les fantômes avec leurs index vivants, puis supprime les anciens.
 *
 * Une seule requête pour toutes les tables : la tâche est atomique, et permuter table par table
 * laisserait une fenêtre où ca_objects serait neuf en face d'un ca_entities d'hier — donc des
 * facettes croisées incohérentes, le temps de la fenêtre.
 *
 * Ce sont les CONTENUS qui changent de place, pas les noms : après la permutation, le nom vivant
 * porte l'index neuf et le nom fantôme porte l'ancien, qu'on supprime. C'est cette suppression, et
 * elle seule, qui rend la place au disque.
 */
function permuter($moteur, array $paires, callable $dire, bool $garder_anciens = false): void {
	if (!sizeof($paires)) { return; }

	$client = $moteur->getClient();
	$t0     = microtime(true);

	try {
		$client->swapIndexes(array_values($paires));
	} catch (Throwable $e) {
		fwrite(STDERR, "Permutation impossible : " . $e->getMessage() . "\n"
			. "Aucun index n'a été permuté, la recherche répond toujours sur l'ancien. Les fantômes\n"
			. "sont conservés pour diagnostic ; les supprimer libère le disque.\n");
		exit(1);
	}

	$dire(sprintf("\nPermutation de %d index en %.2f s — la recherche répond sur le nouvel index.\n",
		sizeof($paires), microtime(true) - $t0));

	// Les noms fantômes portent maintenant les ANCIENS index. Les garder fait du retour arrière
	// une seconde permutation, immédiate, là où les avoir supprimés impose de tout réindexer :
	// six heures pour revenir en arrière, ou vingt secondes. Le prix en est le disque, qui reste
	// doublé jusqu'au ménage — d'où le choix laissé à l'exploitant plutôt qu'un défaut imposé.
	if ($garder_anciens) {
		$dire(sprintf(
			"Anciens index conservés (%d). Retour arrière — une seule requête, sans coupure :\n"
			. "  curl -X POST \"%s/swap-indexes\" -H \"Authorization: Bearer \$MEILI_MASTER_KEY\" \\\n"
			. "       -H 'Content-Type: application/json' -d '%s'\n"
			. "Puis supprimer les index « %s » quand la nouvelle version est acquise.\n",
			sizeof($paires),
			$client->getBaseUrl(),
			json_encode(array_map(function ($p) { return ['indexes' => array_values($p)]; }, array_values($paires)),
				JSON_UNESCAPED_SLASHES),
			join(', ', array_column($paires, 1))
		));
		return;
	}

	foreach ($paires as $paire) {
		try {
			$client->deleteIndex($paire[1]);
		} catch (Throwable $e) {
			fwrite(STDERR, sprintf("Ancien index %s non supprimé (%s) : le supprimer à la main pour rendre la place.\n",
				$paire[1], $e->getMessage()));
		}
	}
	$dire(sprintf("Anciens index supprimés (%d).\n", sizeof($paires)));
}

/**
 * Supprime les fantômes construits, quand la permutation n'aura pas lieu.
 */
function supprimer_fantomes($moteur, array $paires, callable $dire): void {
	if (!sizeof($paires)) { return; }

	foreach ($paires as $paire) {
		try {
			$moteur->getClient()->deleteIndex($paire[1]);
		} catch (Throwable $e) {
			fwrite(STDERR, sprintf("Index fantôme %s non supprimé (%s) : le supprimer à la main.\n",
				$paire[1], $e->getMessage()));
		}
	}
	$dire(sprintf("%d index fantôme(s) supprimé(s).\n", sizeof($paires)));
}

/**
 * Un volume en unités lisibles — les octets bruts ne se comparent pas d'un coup d'œil, et c'est
 * précisément ce qu'on demande à l'exploitant au moment de décider s'il lance.
 */
function octets(float $n): string {
	$unites = ['o', 'Kio', 'Mio', 'Gio', 'Tio'];
	$i = 0;
	while ($n >= 1024.0 && $i < sizeof($unites) - 1) { $n /= 1024.0; $i++; }
	return sprintf('%.1f %s', $n, $unites[$i]);
}

function nombre_de_coeurs(): int {
	if (is_readable('/proc/cpuinfo')) {
		$n = substr_count((string)file_get_contents('/proc/cpuinfo'), 'processor');
		if ($n > 0) { return $n; }
	}
	$n = (int)@shell_exec('sysctl -n hw.ncpu 2>/dev/null');
	return $n > 0 ? $n : 4;
}
