<?php
/* ----------------------------------------------------------------------
 * diagnostiquer-recherches.php — quelles recherches le connecteur rend-il mal ?
 *
 *     cd /var/www/html
 *     php app/plugins/Meilisearch/tools/diagnostiquer-recherches.php
 *     php app/plugins/Meilisearch/tools/diagnostiquer-recherches.php --historique=200
 *     php app/plugins/Meilisearch/tools/diagnostiquer-recherches.php --plan "oil OR canvas"
 *
 * Trois modes :
 *
 *   • par défaut, une batterie de cas construits sur des termes tirés du fonds lui-même —
 *     rien n'est écrit en dur, l'outil se transporte donc d'une instance à l'autre ;
 *   • `--historique[=N]` rejoue les recherches réellement faites par les usagers, lues dans
 *     `ca_search_log`, et compare le nombre de résultats d'alors à celui d'aujourd'hui. Sur une
 *     instance qui tournait sous SqlSearch2, c'est la seule comparaison qui vaille : une
 *     référence produite par le moteur qu'on remplace, sur les requêtes qui comptent vraiment ;
 *   • `--plan "expression"` montre ce que le connecteur a compris d'une expression — clauses,
 *     groupes, constructions non rendues. C'est par là qu'on commence quand un écart surprend.
 *
 * Un connecteur de recherche ne se juge pas au fait qu'il rende « des » résultats : il se juge
 * à ce qu'il rende *les* résultats. Or il n'existe pas de référence à laquelle se comparer
 * — SqlSearch2 aurait besoin de son propre index, construit sur la même base.
 *
 * On se rabat donc sur des vérités qui se vérifient sans référence :
 *
 *   • les identités booléennes. `a AND b` doit rendre exactement l'intersection de `a` et de
 *     `b`, `a OR b` leur union, `a NOT b` leur différence. Ces ensembles, on les mesure par
 *     des recherches simples dont on sait déjà qu'elles marchent ;
 *   • le comptage SQL, quand la question porte sur un champ de la base ;
 *   • l'inclusion : une recherche restreinte à un champ ne peut pas rendre plus que la même
 *     recherche libre.
 *
 * Chaque cas dit ce qu'on attend et ce qu'on obtient. Un écart n'est pas toujours un défaut —
 * la tolérance orthographique de Meilisearch en produit légitimement — mais il doit être vu,
 * nommé, et décidé.
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

$db = new Db();

/** Marque posée dans ca_search_log sur les recherches que cet outil déclenche lui-même. */
define('SOURCE_DIAGNOSTIC', 'meilisearch-diagnostic');

$mode = 'batterie';
$argument = null;
foreach (array_slice($argv, 1) as $a) {
	if (preg_match('!^--historique(?:=(\d+))?$!', $a, $m)) { $mode = 'historique'; $argument = (int)($m[1] ?? 200); }
	elseif ($a === '--plan') { $mode = 'plan'; }
	elseif ($mode === 'plan' && $argument === null) { $argument = $a; }
}

# ----------------------------------------------------------------------
# Exécution d'une recherche, avec ce que le connecteur a signalé au passage
# ----------------------------------------------------------------------

$journal = Meilisearch\Log::path();

function chercher(string $expression): array {
	global $journal;
	// clearstatcache() : sans elle, PHP sert la taille qu'il a déjà lue et le journal semble
	// n'avoir rien reçu — on croit alors le connecteur muet alors qu'il a parlé.
	clearstatcache(true, $journal);
	$taille_avant = file_exists($journal) ? filesize($journal) : 0;

	$ids = [];
	$erreur = null;
	try {
		// `search_source` marque nos rejeux dans ca_search_log. Sans cette marque, l'outil
		// relirait ses propres recherches au passage suivant et comparerait Meilisearch à
		// lui-même — la référence disparaîtrait sans que rien ne le signale.
		$res = (new ObjectSearch())->search($expression, [
			'no_cache'      => true,
			'search_source' => SOURCE_DIAGNOSTIC,
		]);
		while ($res->nextHit()) { $ids[] = (int)$res->get('ca_objects.object_id'); }
	} catch (\Throwable $e) {
		$erreur = get_class($e) . ' : ' . $e->getMessage();
	}

	// Ce que le connecteur a écrit pendant cette recherche : c'est là que se trouvent les
	// constructions qu'il n'a pas su rendre.
	$notes = [];
	clearstatcache(true, $journal);
	if (file_exists($journal) && filesize($journal) > $taille_avant) {
		$f = fopen($journal, 'r');
		fseek($f, $taille_avant);
		while (($ligne = fgets($f)) !== false) {
			if (strpos($ligne, 'ALERTE') !== false || strpos($ligne, 'ERREUR') !== false) {
				$notes[] = trim(preg_replace('!^\[[^\]]+\]\s+\S+\s+\(\w+\)\s+!', '', $ligne));
			}
		}
		fclose($f);
	}

	sort($ids);
	return ['ids' => $ids, 'notes' => $notes, 'erreur' => $erreur];
}

function compte_sql(string $where): int {
	global $db;
	$qr = $db->query("SELECT COUNT(*) AS n FROM ca_objects WHERE deleted = 0 AND ({$where})");
	$qr->nextRow();
	return (int)$qr->get('n');
}

# ----------------------------------------------------------------------
# Mode --plan : ce que le connecteur a compris d'une expression
# ----------------------------------------------------------------------

if ($mode === 'plan') {
	if (!$argument) { fwrite(STDERR, "Usage : --plan \"expression\"\n"); exit(2); }

	// On passe par ObjectSearch pour voir l'expression *réécrite* — celle que reçoit le
	// connecteur, qui n'est pas celle que l'usager a tapée. C'est souvent là qu'est la
	// surprise : une expression que le socle a vidée avant même d'arriver au moteur.
	$espion = new class extends WLPlugSearchEngineMeilisearch {
		public function search($table_num, $expression, $filters, $rewritten) {
			printf("  expression reçue : \033[1m%s\033[0m\n", $expression === '' ? '(vide)' : $expression);
			printf("  arbre réécrit    : %s\n\n", is_object($rewritten) ? get_class($rewritten) : 'aucun');

			$q = new Meilisearch\Query($this->getSchema(), $expression, $rewritten);
			if ($q->isMatchAll()) {
				printf("  \033[33mrien à chercher : la requête ne restreint rien, tout le fonds sera rendu\033[0m\n");
			}
			foreach ($q->getClauses() as $c) {
				printf("  clause  op=%-4s champ=%-30s texte=%-20s %s%s\n", $c['op'],
					$c['field'] === null ? '(tous)' : $c['field'], $c['text'],
					$c['phrase'] ? 'phrase ' : '', !empty($c['exact']) ? 'exact' : '');
			}
			foreach ($q->getSearchGroups() as $i => $g) {
				printf("  groupe %d : op=%-4s q=%-30s sur %s\n", $i, $g['op'], $g['params']['q'],
					join(', ', $g['params']['attributesToSearchOn'] ?? ['(tous)']));
			}
			foreach ($q->getUnsupported() as $u) { printf("  \033[33mnon rendu : %s\033[0m\n", $u); }

			return parent::search($table_num, $expression, $filters, $rewritten);
		}
	};

	$recherche = new ObjectSearch();
	$r = new ReflectionObject($recherche);
	foreach (['opo_engine', 'engine', 'opo_search_engine'] as $nom) {
		if ($r->hasProperty($nom)) { $prop = $r->getProperty($nom); $prop->setAccessible(true); $prop->setValue($recherche, $espion); break; }
	}

	printf("\n\033[1mExpression tapée : %s\033[0m\n\n", $argument);
	printf("  → \033[1m%d résultat(s)\033[0m\n\n", $recherche->search($argument, ['no_cache' => true])->numHits());
	exit(0);
}

# ----------------------------------------------------------------------
# Mode --historique : rejouer ce que les usagers ont réellement cherché
# ----------------------------------------------------------------------

if ($mode === 'historique') {
	$table_num = Datamodel::getTableNum('ca_objects');
	$qr = $db->query("
		SELECT search_expression, num_hits, execution_time, log_datetime, search_source
		FROM ca_search_log
		WHERE table_num = ? AND search_expression <> ''
		  AND search_source NOT LIKE ?
		ORDER BY log_datetime DESC
		LIMIT " . (int)$argument, [$table_num, '%' . SOURCE_DIAGNOSTIC . '%']);

	$lignes = [];
	while ($qr->nextRow()) {
		$lignes[] = [
			'expression' => (string)$qr->get('search_expression'),
			'hits'       => (int)$qr->get('num_hits'),
			'temps'      => (float)$qr->get('execution_time'),
			'date'       => (int)$qr->get('log_datetime'),
		];
	}

	if (!sizeof($lignes)) {
		printf("\n  Aucune recherche enregistrée sur ca_objects dans ca_search_log.\n\n");
		exit(0);
	}

	// Une même expression revient souvent : on la rejoue une fois, en gardant le décompte le
	// plus récent comme référence.
	$uniques = [];
	foreach ($lignes as $l) { if (!isset($uniques[$l['expression']])) { $uniques[$l['expression']] = $l; } }

	printf("\n\033[1mHistorique : %d recherche(s), %d expression(s) distincte(s)\033[0m\n", sizeof($lignes), sizeof($uniques));
	printf("  \033[90mles rejeux de cet outil sont écartés de la lecture (search_source « %s ») : l'outil\n"
		. "  peut donc être relancé sans se comparer à lui-même.\033[0m\n", SOURCE_DIAGNOSTIC);
	printf("\n  \033[90mLe décompte de référence est celui qu'a enregistré le moteur en place au moment de la\n"
		. "  recherche. Sur une instance passée de SqlSearch2 à Meilisearch, un écart n'est pas\n"
		. "  forcément une régression — la tolérance orthographique en produit — mais un « 0 »\n"
		. "  là où il y avait des résultats en est toujours une.\033[0m\n\n");

	printf("  %-46s %9s %9s %8s %8s\n", 'expression', 'avant', 'après', 'écart', 'durée');
	printf("  %s\n", str_repeat('─', 86));

	$bilan = ['identique' => 0, 'proche' => 0, 'plus' => 0, 'moins' => 0, 'vide' => 0, 'erreur' => 0];
	$ecarts = [];

	foreach ($uniques as $expression => $l) {
		$t0 = microtime(true);
		$r  = chercher($expression);
		$ms = (microtime(true) - $t0) * 1000;
		$n  = sizeof($r['ids']);

		if ($r['erreur'])                       { $classe = 'erreur'; }
		elseif ($n === $l['hits'])              { $classe = 'identique'; }
		elseif ($n === 0 && $l['hits'] > 0)     { $classe = 'vide'; }
		elseif ($l['hits'] > 0 && abs($n - $l['hits']) / max($l['hits'], 1) <= 0.05) { $classe = 'proche'; }
		elseif ($n > $l['hits'])                { $classe = 'plus'; }
		else                                    { $classe = 'moins'; }
		$bilan[$classe]++;

		$couleur = ['identique' => '32', 'proche' => '32', 'plus' => '33', 'moins' => '33', 'vide' => '31', 'erreur' => '31'][$classe];
		printf("  \033[%sm%-46s %9s %9s %8s %6.0f ms\033[0m\n", $couleur,
			mb_strimwidth($expression, 0, 46, '…'),
			number_format($l['hits'], 0, ',', ' '), number_format($n, 0, ',', ' '),
			$l['hits'] === $n ? '=' : sprintf('%+d', $n - $l['hits']), $ms);

		foreach ($r['notes'] as $note) { printf("      \033[90m%s\033[0m\n", mb_strimwidth($note, 0, 100, '…')); }
		if ($classe === 'vide' || $classe === 'erreur') { $ecarts[] = $expression; }
	}

	printf("\n  \033[32m%d identique(s), %d à 5 %% près\033[0m — \033[33m%d plus large(s), %d plus étroite(s)\033[0m — \033[31m%d vidée(s), %d en erreur\033[0m\n",
		$bilan['identique'], $bilan['proche'], $bilan['plus'], $bilan['moins'], $bilan['vide'], $bilan['erreur']);

	if (sizeof($ecarts)) {
		printf("\n  \033[1mÀ examiner en premier\033[0m — ces recherches ne rendent plus rien :\n");
		foreach (array_slice($ecarts, 0, 15) as $e) {
			printf("    php app/plugins/Meilisearch/tools/diagnostiquer-recherches.php --plan %s\n", escapeshellarg($e));
		}
	}
	printf("\n");
	exit(0);
}

# ----------------------------------------------------------------------
# Rapport
# ----------------------------------------------------------------------

$verdicts = ['ok' => 0, 'alerte' => 0, 'faux' => 0];

function titre(string $t): void { printf("\n\033[1m%s\033[0m\n", $t); }

/**
 * @param string $verdict 'ok' | 'alerte' | 'faux'
 */
function cas(string $expression, int $obtenu, string $verdict, string $explication, array $notes = []): void {
	global $verdicts;
	$verdicts[$verdict]++;
	$marque = ['ok' => "\033[32m✓\033[0m", 'alerte' => "\033[33m!\033[0m", 'faux' => "\033[31m✗\033[0m"][$verdict];
	printf("  %s %-38s %7s  %s\n", $marque, mb_strimwidth($expression, 0, 38, '…'),
		number_format($obtenu, 0, ',', ' '), $explication);
	foreach ($notes as $n) { printf("      \033[90m%s\033[0m\n", mb_strimwidth($n, 0, 100, '…')); }
}

function verdict_ensemble(array $obtenu, array $attendu): string {
	if ($obtenu === $attendu) { return 'ok'; }
	$manquants = sizeof(array_diff($attendu, $obtenu));
	$en_trop   = sizeof(array_diff($obtenu, $attendu));
	return ($manquants > 0) ? 'faux' : 'alerte';
}

function ecart(array $obtenu, array $attendu): string {
	$manquants = sizeof(array_diff($attendu, $obtenu));
	$en_trop   = sizeof(array_diff($obtenu, $attendu));
	if (!$manquants && !$en_trop) { return 'conforme'; }
	$bouts = [];
	if ($manquants) { $bouts[] = "{$manquants} manquant(s)"; }
	if ($en_trop)   { $bouts[] = "{$en_trop} en trop"; }
	return join(', ', $bouts);
}

$total = compte_sql('1=1');
printf("\n\033[1mFonds : %s objets\033[0m\n", number_format($total, 0, ',', ' '));

/**
 * Deux mots du fonds qui se recouvrent partiellement : A et B doivent avoir une intersection
 * et une différence non vides, sinon les identités booléennes ne prouvent rien — `a AND b` et
 * `a OR b` rendraient la même chose et l'erreur passerait inaperçue.
 *
 * On les tire des titres plutôt que de les écrire en dur : l'outil doit pouvoir être lancé sur
 * une instance dont on ne connaît pas le fonds.
 */
function mots_frequents(): array {
	static $cache = null;
	if ($cache !== null) { return $cache; }

	global $db;
	$qr = $db->query("SELECT l.name FROM ca_object_labels l
		INNER JOIN ca_objects o ON o.object_id = l.object_id AND o.deleted = 0
		WHERE l.is_preferred = 1 LIMIT 20000");

	$frequences = [];
	while ($qr->nextRow()) {
		foreach (preg_split('![^\p{L}\p{N}]+!u', mb_strtolower((string)$qr->get('name')), -1, PREG_SPLIT_NO_EMPTY) as $mot) {
			if (mb_strlen($mot) < 5) { continue; }   // sous 5 lettres, Meilisearch ne corrige pas : on veut le cas ordinaire
			$frequences[$mot] = ($frequences[$mot] ?? 0) + 1;
		}
	}
	arsort($frequences);
	return $cache = array_slice(array_keys($frequences), 0, 12);
}

function termes_de_reference(): array {
	$candidats = mots_frequents();

	$vus = [];
	foreach ($candidats as $mot) { $vus[$mot] = chercher($mot)['ids']; }

	foreach ($candidats as $a) {
		foreach ($candidats as $b) {
			if ($a === $b) { continue; }
			$inter = array_intersect($vus[$a], $vus[$b]);
			$diff  = array_diff($vus[$a], $vus[$b]);
			if (sizeof($inter) >= 5 && sizeof($diff) >= 5 && sizeof($vus[$b]) >= 10) {
				return [$a, $vus[$a], $b, $vus[$b]];
			}
		}
	}

	// Faute de recouvrement, on prend les deux plus fréquents : les identités resteront
	// vérifiables, simplement moins parlantes.
	$a = $candidats[0] ?? 'a'; $b = $candidats[1] ?? 'b';
	return [$a, $vus[$a] ?? [], $b, $vus[$b] ?? []];
}

# ----------------------------------------------------------------------
titre('Les briques de base — mesurées pour servir de référence aux identités');

list($mot_a, $ids_a, $mot_b, $ids_b) = termes_de_reference();
$A = ['ids' => $ids_a, 'notes' => []];
$B = ['ids' => $ids_b, 'notes' => []];
cas($mot_a, sizeof($A['ids']), 'ok', 'référence A, tirée du fonds');
cas($mot_b, sizeof($B['ids']), 'ok', 'référence B, tirée du fonds');

// Un troisième terme, pour les cas à trois clauses. On le prend distinct des deux premiers ;
// peu importe ce qu'il ramène, seules les identités comptent.
$mot_c = null;
foreach (mots_frequents() as $mot) {
	if ($mot !== $mot_a && $mot !== $mot_b) { $mot_c = $mot; break; }
}
$mot_c = $mot_c ?: $mot_a;

/**
 * Une faute de frappe plausible : une lettre changée au milieu du mot. C'est ce que corrige la
 * tolérance orthographique de Meilisearch, et ce que SqlSearch2 ne corrigeait pas.
 */
function faute(string $mot): string {
	$i = max(1, intdiv(mb_strlen($mot), 2));
	$lettre = mb_substr($mot, $i) === 'o' ? 'a' : 'o';
	return mb_substr($mot, 0, $i) . $lettre . mb_substr($mot, $i + 1);
}

$inter = array_values(array_intersect($A['ids'], $B['ids'])); sort($inter);
$union = array_values(array_unique(array_merge($A['ids'], $B['ids']))); sort($union);
$diff  = array_values(array_diff($A['ids'], $B['ids'])); sort($diff);

# ----------------------------------------------------------------------
titre('Opérateurs booléens — l\'identité doit tenir exactement');

$r = chercher("{$mot_a} AND {$mot_b}");
cas("{$mot_a} AND {$mot_b}", sizeof($r['ids']), verdict_ensemble($r['ids'], $inter),
	'attendu A∩B = ' . sizeof($inter) . ' — ' . ecart($r['ids'], $inter), $r['notes']);

$r = chercher("{$mot_a} OR {$mot_b}");
cas("{$mot_a} OR {$mot_b}", sizeof($r['ids']), verdict_ensemble($r['ids'], $union),
	'attendu A∪B = ' . sizeof($union) . ' — ' . ecart($r['ids'], $union), $r['notes']);

$r = chercher("{$mot_a} NOT {$mot_b}");
cas("{$mot_a} NOT {$mot_b}", sizeof($r['ids']), verdict_ensemble($r['ids'], $diff),
	'attendu A\\B = ' . sizeof($diff) . ' — ' . ecart($r['ids'], $diff), $r['notes']);

$r = chercher("NOT {$mot_b}");
$attendu_not = $total - sizeof($B['ids']);
cas("NOT {$mot_b}", sizeof($r['ids']), sizeof($r['ids']) === 0 ? 'faux' : (abs(sizeof($r['ids']) - $attendu_not) < 2 ? 'ok' : 'alerte'),
	"attendu ≈ tout sauf B = {$attendu_not}", $r['notes']);

$r = chercher("({$mot_a} OR {$mot_b}) AND {$mot_c}");
$troisieme = chercher($mot_c);
$attendu = array_values(array_intersect($union, $troisieme['ids'])); sort($attendu);
cas("({$mot_a} OR {$mot_b}) AND {$mot_c}", sizeof($r['ids']), verdict_ensemble($r['ids'], $attendu),
	'attendu ' . sizeof($attendu) . ' — ' . ecart($r['ids'], $attendu), $r['notes']);

# ----------------------------------------------------------------------
titre('Portée : recherche libre contre recherche sur un champ');

$libre  = chercher($mot_c);
$titre_ = chercher('ca_object_labels.name:' . $mot_c);
cas($mot_c . ' (libre)', sizeof($libre['ids']), 'ok', 'référence', $libre['notes']);
cas('ca_object_labels.name:' . $mot_c, sizeof($titre_['ids']),
	sizeof(array_diff($titre_['ids'], $libre['ids'])) ? 'faux' : 'ok',
	'doit être inclus dans la recherche libre — ' . sizeof(array_diff($titre_['ids'], $libre['ids'])) . ' hors périmètre',
	$titre_['notes']);

$inexistant = chercher('ca_objects.champ_qui_nexiste_pas:' . $mot_c);
cas('ca_objects.champ_qui_nexiste_pas:…', sizeof($inexistant['ids']),
	sizeof($inexistant['ids']) === 0 ? 'alerte' : 'alerte',
	'champ inconnu : que fait le connecteur ?', $inexistant['notes']);

$non_indexe = chercher('ca_objects.lot_id:1');
cas('ca_objects.lot_id:1 (champ non indexé)', sizeof($non_indexe['ids']),
	'alerte', 'champ existant mais absent de l\'index', $non_indexe['notes']);

# ----------------------------------------------------------------------
titre('Jokers et troncatures');

$r = chercher(mb_substr($mot_a, 0, -1) . '*');
cas(mb_substr($mot_a, 0, -1) . '* (troncature finale)', sizeof($r['ids']),
	sizeof(array_diff($A['ids'], $r['ids'])) ? 'faux' : 'ok',
	"doit contenir « {$mot_a} » (" . sizeof($A['ids']) . ') — ' . sizeof(array_diff($A['ids'], $r['ids'])) . ' manquant(s)',
	$r['notes']);

$r = chercher('*' . mb_substr($mot_b, 1));
cas('*' . mb_substr($mot_b, 1) . ' (troncature initiale)', sizeof($r['ids']),
	sizeof(array_diff($B['ids'], $r['ids'])) ? 'faux' : 'ok',
	"devrait contenir « {$mot_b} » (" . sizeof($B['ids']) . ') — ' . sizeof(array_diff($B['ids'], $r['ids'])) . ' manquant(s)',
	$r['notes']);

$r = chercher(mb_substr($mot_b, 0, 1) . '*' . mb_substr($mot_b, 2));
cas(mb_substr($mot_b, 0, 1) . '*' . mb_substr($mot_b, 2) . ' (joker interne)', sizeof($r['ids']),
	sizeof(array_diff($B['ids'], $r['ids'])) ? 'faux' : 'ok',
	"devrait contenir « {$mot_b} » — " . sizeof(array_diff($B['ids'], $r['ids'])) . ' manquant(s)', $r['notes']);

# ----------------------------------------------------------------------
titre('Intervalles et comparaisons');

$r = chercher('ca_objects.object_id:[1 TO 100]');
$attendu_n = compte_sql('object_id BETWEEN 1 AND 100');
cas('ca_objects.object_id:[1 TO 100]', sizeof($r['ids']),
	sizeof($r['ids']) === $attendu_n ? 'ok' : 'faux',
	"attendu {$attendu_n} (SQL) — l'intervalle est-il appliqué ?", $r['notes']);

$r = chercher('ca_objects.access:1');
$attendu_n = compte_sql('access = 1');
cas('ca_objects.access:1', sizeof($r['ids']),
	sizeof($r['ids']) === $attendu_n ? 'ok' : 'alerte', "attendu {$attendu_n} (SQL)", $r['notes']);

$r = chercher('created:"2026"');
cas('created:"2026" (date de création)', sizeof($r['ids']), sizeof($r['ids']) ? 'ok' : 'faux',
	'les métadonnées de journal sont-elles cherchables ?', $r['notes']);

# ----------------------------------------------------------------------
titre('Bruit : ce que Meilisearch ajoute de lui-même');

$r = chercher(mb_substr($mot_a, 0, 2));
cas(mb_substr($mot_a, 0, 2) . ' (deux lettres)', sizeof($r['ids']), sizeof($r['ids']) > sizeof($A['ids']) ? 'alerte' : 'ok',
	"la complétion de préfixe élargit : « {$mot_a} » en donne " . sizeof($A['ids']), $r['notes']);

$r = chercher(faute($mot_b));
cas(faute($mot_b) . ' (faute de frappe)', sizeof($r['ids']), sizeof($r['ids']) ? 'alerte' : 'ok',
	'tolérance orthographique : ' . (sizeof($r['ids']) ? 'ramène des résultats' : 'aucun'), $r['notes']);

$r = chercher('"' . $mot_a . ' ' . $mot_b . '"');
cas('"' . $mot_a . ' ' . $mot_b . '" (phrase exacte)', sizeof($r['ids']), sizeof($r['ids']) <= sizeof($inter) ? 'ok' : 'alerte',
	'ne doit pas dépasser A∩B = ' . sizeof($inter), $r['notes']);

# ----------------------------------------------------------------------
titre('Cas dégénérés');

foreach (['', '*', 'AND', '"', '(', 'a', 'de'] as $expr) {
	$r = chercher($expr);
	cas($expr === '' ? '(expression vide)' : $expr, sizeof($r['ids']), 'alerte',
		$r['erreur'] ? "\033[31m{$r['erreur']}\033[0m" : 'à décider', $r['notes']);
}

printf("\n  \033[32m%d conforme(s)\033[0m, \033[33m%d à examiner\033[0m, \033[31m%d faux\033[0m\n\n",
	$verdicts['ok'], $verdicts['alerte'], $verdicts['faux']);
