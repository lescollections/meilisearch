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

/**
 * Le SQL surcompte-t-il cette autorité ?
 *
 * Sa requête groupe par autorité *et* par type de relation, puis additionne les lignes
 * (`BrowseEngine.php:7232`) : un enregistrement lié deux fois à la même autorité, par deux
 * types de relation, y compte deux fois. Notre compte est celui des fiches — ce que le clic
 * rend vraiment. On le reconnaît à l'existence d'un doublon dans la table de liens.
 */
function sql_surcompte(?string $rel_table, ?string $auth_table, ?string $subject_table, $id): bool {
	static $cache = [];
	if (!$rel_table || !$auth_table || !$subject_table || !is_numeric($id)) { return false; }

	$cle = $rel_table . '/' . $id;
	if (isset($cache[$cle])) { return $cache[$cle]; }

	$t_auth    = Datamodel::getInstanceByTableName($auth_table, true);
	$t_subject = Datamodel::getInstanceByTableName($subject_table, true);
	if (!$t_auth || !$t_subject || !Datamodel::tableExists($rel_table)) { return $cache[$cle] = false; }

	try {
		$qr = (new Db())->query(
			"SELECT 1 FROM {$rel_table} WHERE " . $t_auth->primaryKey() . " = ?
			 GROUP BY " . $t_subject->primaryKey() . " HAVING COUNT(*) > 1 LIMIT 1",
			[(int)$id]
		);
		return $cache[$cle] = (bool)$qr->nextRow();
	} catch (Exception $e) {
		return $cache[$cle] = false;
	}
}

/**
 * Cette valeur de liste est-elle celle d'un item supprimé ?
 *
 * L'indexeur du socle n'écrit rien pour un `type_id` dont l'item de liste est supprimé — il
 * indexe les *libellés* de l'item, et un item supprimé n'en a plus — tandis que la facette SQL,
 * qui lit la colonne directement, l'affiche encore. La valeur manque donc à l'index, et c'est
 * le corollaire assumé de la délégation : ce que l'indexeur saute est invisible aux facettes.
 */
function item_de_liste_supprime($id): bool {
	static $cache = [];
	if (!is_numeric($id)) { return false; }
	if (isset($cache[$id])) { return $cache[$id]; }

	try {
		$qr = (new Db())->query("SELECT deleted FROM ca_list_items WHERE item_id = ?", [(int)$id]);
		return $cache[$id] = ($qr->nextRow() && (int)$qr->get('deleted') === 1);
	} catch (Exception $e) {
		return $cache[$id] = false;
	}
}

/**
 * Les graphies d'un élément, groupées comme le connecteur les groupe.
 *
 * Le regroupement se fait en PHP et non en SQL, parce que `TRIM()` de MySQL ne retire que les
 * espaces : il laisse en place les retours à la ligne, qu'une saisie au clavier dépose plus
 * souvent qu'on ne croit. « Plâtre coulé\n » et « Plâtre coulé » lui paraissent donc distincts
 * là où `trim()` de PHP — celui qu'emploie l'indexation — les réunit. Comparer en SQL faisait
 * échouer la reconnaissance sans bruit.
 *
 * @return array ['groupes' => [clé => ['graphies' => nombre, 'nu' => bool]],
 *                 'cles'    => [value_id => clé]]
 *         `nu` est vrai si le socle sera incapable de retrouver l'identifiant de ce groupe.
 *         `cles` permet de retrouver le groupe d'un poste par *appartenance* : le connecteur et
 *         cet outil ne choisissent pas forcément le même représentant dans un groupe.
 */
function graphies_de_element(?string $element_code): array {
	if (!$element_code) { return []; }

	static $cache = [];
	$parts = explode('.', $element_code);
	$code  = array_pop($parts);
	if (isset($cache[$code])) { return $cache[$code]; }

	$out  = [];
	$cles = [];
	try {
		$qr = (new Db())->query("
			SELECT v.value_id, v.value_longtext1
			FROM ca_attribute_values v
			INNER JOIN ca_metadata_elements e ON e.element_id = v.element_id
			WHERE e.element_code = ? AND v.value_longtext1 IS NOT NULL AND v.value_longtext1 <> ''
		", [$code]);
		$graphies = [];
		while ($qr->nextRow()) {
			$brut = (string)$qr->get('value_longtext1');
			$nu   = trim($brut);
			if (!strlen($nu)) { continue; }
			$cle  = mb_strtolower(function_exists('caRemoveAccents') ? caRemoveAccents($nu) : $nu, 'UTF-8');

			$id = (int)$qr->get('value_id');
			$cles[$id] = $cle;
			if (!isset($out[$cle])) { $out[$cle] = ['id' => $id, 'graphies' => 0, 'nu' => true]; }
			$out[$cle]['id'] = min($out[$cle]['id'], $id);
			// Le socle retrouvera son identifiant si au moins une graphie est déjà « propre » :
			// c'est sur la valeur trimée qu'il interroge getValueIDFor().
			if ($brut === $nu) { $out[$cle]['nu'] = false; }
			$graphies[$cle][$brut] = true;
		}
		foreach ($graphies as $cle => $formes) { $out[$cle]['graphies'] = sizeof($formes); }
	} catch (Exception $e) {
		return $cache[$code] = ['groupes' => [], 'cles' => []];
	}
	return $cache[$code] = ['groupes' => $out, 'cles' => $cles];
}

/**
 * Le socle rend-il ce poste *sans identifiant*, faute de retrouver sa propre valeur ?
 *
 * Sa facette `attribute` affiche `trim(getDisplayValue())` puis redemande l'identifiant de cette
 * valeur à `ca_attribute_values::getValueIDFor()`. Si aucune graphie du groupe n'est déjà propre,
 * la recherche échoue : le poste sort avec `id` à null et le comparateur le range sous son
 * libellé, tandis que nous le rangeons sous son value_id. Rend cet identifiant, ou null.
 */
function socle_sans_identifiant(?string $element_code, $libelle): ?int {
	if (!is_string($libelle) || is_numeric($libelle)) { return null; }

	$cle = mb_strtolower(function_exists('caRemoveAccents') ? caRemoveAccents(trim($libelle)) : trim($libelle), 'UTF-8');
	$tout = graphies_de_element($element_code);
	return (isset($tout['groupes'][$cle]) && $tout['groupes'][$cle]['nu']) ? $tout['groupes'][$cle]['id'] : null;
}

/**
 * Ce poste rassemble-t-il des graphies que le socle éparpille ?
 *
 * Une valeur saisie avec une espace ou un retour à la ligne en trop — au CIPAR, « fer forgé\n »
 * à côté de « Fer forgé » — forme pour MySQL un groupe distinct. Le socle en fait plusieurs
 * postes, puis les réduit à un seul en les indexant par `strToLower(trim(...))` : le dernier
 * écrase les précédents, avec son seul compte, et les autres fiches disparaissent de la facette.
 * Nous trimons à l'indexation, donc nous les rassemblons — notre compte est celui des fiches que
 * le clic rend vraiment.
 *
 * @return int|null nombre de graphies en cause, ou null si ce n'est pas ce cas de figure.
 */
function graphies_eparpillees(?string $element_code, $id): ?int {
	if (!is_numeric($id)) { return null; }
	$tout = graphies_de_element($element_code);
	$cle  = $tout['cles'][(int)$id] ?? null;
	if ($cle === null || !isset($tout['groupes'][$cle])) { return null; }
	return ($tout['groupes'][$cle]['graphies'] > 1) ? $tout['groupes'][$cle]['graphies'] : null;
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

// Comparer pendant qu'un index se reconstruit ne compare rien : il manque alors une part
// arbitraire des documents, et toutes les facettes paraissent diverger. Vécu.
try {
	$moteur_ms = SearchBase::newSearchEngine('Meilisearch');
	$index_ms  = $moteur_ms->getSchema()->indexName($table);
	$stats_ms  = $moteur_ms->getClient()->indexStats($index_ms);
	if (!empty($stats_ms['isIndexing'])) {
		fwrite(STDERR, "\n  \033[33mL'index {$index_ms} est en cours d'indexation : la comparaison serait fausse.\033[0m\n"
			. "  Attendre la fin de la réindexation, puis relancer.\n\n");
		exit(3);
	}
} catch (\Throwable $e) {
	fwrite(STDERR, "  (état de l'index non vérifiable : " . $e->getMessage() . ")\n");
}

$reference   = caGetBrowseInstance($table);
$facettes    = $reference->getInfoForFacets();
$bilan       = ['conforme' => 0, 'decline' => 0, 'divergent' => 0, 'assume' => 0, 'raisons' => [], 'details_assumes' => []];
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
	$assumes  = [];

	// Une valeur que l'index ne peut pas porter parce que son item de liste est supprimé :
	// écart assumé, pas divergence — l'indexeur du socle n'écrit rien pour elle.
	if (($info['type'] ?? null) === 'fieldList') {
		foreach ($absents as $i => $id) {
			if (item_de_liste_supprime($id)) {
				$assumes[] = "{$id} : item de liste supprimé, jamais indexé";
				unset($absents[$i]);
			}
		}
	}

	// Même poste des deux côtés, mais le socle l'a rendu sans identifiant : il apparaît chez lui
	// sous son libellé et chez nous sous son value_id. Ce n'est pas une divergence de contenu.
	if (($info['type'] ?? null) === 'attribute') {
		foreach ($absents as $i => $libelle) {
			$id = socle_sans_identifiant($info['element_code'] ?? null, $libelle);
			if ($id === null) { continue; }
			// Sans le cast : PHP a fait des clés numériques du tableau de comptes des entiers,
			// une recherche stricte de chaîne n'y trouve rien, et le poste « en trop » reste
			// compté comme une divergence alors que son jumeau vient d'être assumé.
			foreach ($en_trop as $j => $trop) {
				if ((string)$trop === (string)$id) { unset($en_trop[$j]); break; }
			}
			$assumes[] = sprintf('%s : valeur entourée d\'espaces, le socle ne retrouve pas son value_id (nous : %d)', $libelle, $id);
			unset($absents[$i]);
		}
	}

	foreach ($c_sql as $id => $n) {
		if (!isset($c_ms[$id]) || $c_ms[$id] === $n) { continue; }

		// Le socle ne rend aucun compte pour certains postes — un booléen de facette `field`
		// recopie son gabarit [id, label] sans content_count (BrowseEngine.php:5618). Nous en
		// fournissons un : l'interface affiche un nombre là où elle n'en avait pas.
		if ($n === -1) {
			$assumes[] = sprintf('%s : le socle ne rend aucun compte pour ce poste (nous : %d)', $id, $c_ms[$id]);
			continue;
		}

		// Un nœud non-feuille : le SQL somme les branches, le moteur compte les documents
		// distincts. On interroge la hiérarchie réelle plutôt que les seuls enfants présents
		// dans la facette — un ancêtre dont les descendants comptés sont des petits-enfants
		// n'a aucun enfant *dans la facette*, et passerait pour une feuille.
		if (est_une_branche($info['table'] ?? null, $id)) { $branches++; continue; }

		// Le SQL additionne une ligne par type de relation : un enregistrement lié deux fois
		// à la même autorité y compte deux fois. Notre compte est celui des fiches.
		if ($n > $c_ms[$id] && sql_surcompte($info['relationship_table'] ?? null, $info['table'] ?? null,
				$reference->getSubjectInstance()->tableName(), $id)) {
			$assumes[] = sprintf('%s : lié par plusieurs types de relation (SQL %d, fiches %d)', $id, $n, $c_ms[$id]);
			continue;
		}

		// Le socle n'a gardé qu'une graphie sur plusieurs, avec son seul compte ; nous les
		// rassemblons. Notre compte est celui des fiches que le clic rend.
		if (($info['type'] ?? null) === 'attribute' && $c_ms[$id] > $n
			&& ($graphies = graphies_eparpillees($info['element_code'] ?? null, $id))) {
			$assumes[] = sprintf('%s : %d graphies que le socle éparpille (SQL %d, fiches %d)', $id, $graphies, $n, $c_ms[$id]);
			continue;
		}

		$ecarts[] = sprintf('%s (SQL %d ≠ moteur %d)', $id, $n, $c_ms[$id]);
	}

	if (!sizeof($absents) && !sizeof($en_trop) && !sizeof($ecarts)) {
		$notes = [];
		if ($branches)        { $notes[] = sprintf('%d branche(s)', $branches); }
		if (sizeof($assumes)) { $notes[] = sprintf('%d écart(s) assumé(s)', sizeof($assumes)); $bilan['assume'] += sizeof($assumes); }

		$bilan['conforme']++;
		printf("  \033[32m✓\033[0m %-46s %d poste(s)%s\n", $intitule, sizeof($c_ms),
			sizeof($notes) ? sprintf(" \033[90m(%s)\033[0m", join(', ', $notes)) : '');

		foreach ($assumes as $a) { $bilan['details_assumes'][$a] = true; }
		return;
	}
	if (sizeof($assumes)) { $bilan['assume'] += sizeof($assumes); foreach ($assumes as $a) { $bilan['details_assumes'][$a] = true; } }

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

// Les écarts assumés : documentés, vérifiés, et où c'est le socle qui a tort. Les compter à
// part évite qu'ils crient au loup à chaque exécution — et qu'on cesse de lire le bilan.
if (sizeof($bilan['details_assumes'])) {
	printf("\n\033[1mÉcarts assumés\033[0m \033[90m(le socle a tort, voir CLAUDE.md)\033[0m\n");
	$details = array_keys($bilan['details_assumes']);
	foreach (array_slice($details, 0, 10) as $d) { printf("  \033[90m·\033[0m %s\n", $d); }
	if (sizeof($details) > 10) {
		printf("  \033[90m·\033[0m … et %d autre(s)\n", sizeof($details) - 10);
	}
}

printf("\n  \033[32m%d conforme(s)\033[0m, \033[90m%d au SQL\033[0m, \033[90m%d assumé(s)\033[0m, %s\n\n",
	$bilan['conforme'], $bilan['decline'], $bilan['assume'],
	$bilan['divergent']
		? sprintf("\033[31m%d divergente(s)\033[0m", $bilan['divergent'])
		: "\033[32m0 divergente\033[0m");

exit($bilan['divergent'] ? 1 : 0);
