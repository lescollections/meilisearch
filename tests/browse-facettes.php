<?php
/* ----------------------------------------------------------------------
 * tests/browse-facettes.php — équivalence des facettes SQL et Meilisearch.
 *
 * Le pont des facettes (MeilisearchSearchEngine/Facets.php) promet de rendre exactement ce
 * que le SQL du BrowseEngine aurait rendu, ou de décliner. Ce test tient la promesse contre
 * l'instance réelle : pour chaque scénario, la facette est calculée deux fois — une fois par
 * le SQL du socle (délégation désactivée par réflexion), une fois par le pont (appel direct,
 * un déclin y est un échec) — et les deux résultats doivent porter les mêmes identifiants et
 * les mêmes comptes.
 *
 * Écart toléré, et lui seul : le compte d'un nœud non-feuille d'une facette hiérarchique. Le
 * SQL additionne les comptes des descendants (un objet lié à deux descendants compte deux
 * fois) ; le moteur compte les documents distincts. Le compte du moteur est le bon.
 *
 * Le patch du socle (délégation dans BrowseEngine::getFacetContent) et une réindexation
 * complète sont des préalables : sans eux, tout décline et le test échoue franchement.
 * ---------------------------------------------------------------------- */

require_once(__DIR__ . '/_cadre.php');
require_once(__CA_APP_DIR__ . '/helpers/browseHelpers.php');

$plugin = SearchBase::newSearchEngine();

verifier('le moteur configuré expose getBrowseFacetContent()', function () use ($plugin) {
	est_vrai(is_object($plugin), 'aucun moteur de recherche chargé');
	est_vrai(method_exists($plugin, 'getBrowseFacetContent'), get_class($plugin) . ' ne porte pas la méthode');
});

verifier('le socle porte le point de délégation', function () {
	est_vrai(property_exists('BrowseEngine', 's_facet_engine'),
		'BrowseEngine::$s_facet_engine absent — le patch du socle n\'est pas appliqué');
});

# ----------------------------------------------------------------------
# Outillage
# ----------------------------------------------------------------------

/** Force ou libère la délégation dans le socle. false = SQL pur, null = re-résolution. */
function delegation($valeur): void {
	$p = new ReflectionProperty('BrowseEngine', 's_facet_engine');
	$p->setAccessible(true);
	$p->setValue(null, $valeur);
}

/** Un browse neuf porteur des critères donnés, exécuté si nécessaire. */
function browse_avec(array $criteres, array $options = []): BrowseEngine {
	$b = caGetBrowseInstance('ca_objects');
	foreach ($criteres as $facette => $valeurs) {
		$b->addCriteria($facette, is_array($valeurs) ? $valeurs : [$valeurs]);
	}
	if (sizeof($criteres)) {
		// Le SQL restreint les facettes au résultat courant : il faut qu'il existe.
		$b->execute(array_merge(['dontCheckFacetAvailability' => true], $options));
	}
	return $b;
}

/** La facette par le SQL du socle, délégation coupée. Rend un booléen en checkAvailabilityOnly. */
function facette_sql(string $facette, array $criteres = [], array $options = []) {
	delegation(false);
	try {
		return browse_avec($criteres, $options)->getFacetContent($facette, $options);
	} finally {
		delegation(null);
	}
}

/** La facette par le pont, en appel direct — un déclin rend null et se voit. */
function facette_moteur($plugin, string $facette, array $criteres = [], array $options = []) {
	delegation(false);   // l'execute() du browse ne doit pas passer par le pont non plus
	try {
		$b = browse_avec($criteres, $options);
		$info = $b->getInfoForFacet($facette);
		est_vrai(is_array($info), "facette « {$facette} » inconnue de browse.conf");
		return $plugin->getBrowseFacetContent($b, $facette, $info, $options);
	} finally {
		delegation(null);
	}
}

/** Réduit un contenu de facette à [id => compte], pour comparaison. */
function comptes(?array $contenu): array {
	$out = [];
	foreach (($contenu ?? []) as $cle => $poste) {
		$out[(string)($poste['id'] ?? $cle)] = (int)($poste['content_count'] ?? -1);
	}
	ksort($out, SORT_STRING);
	return $out;
}

/**
 * Les deux contenus portent les mêmes identifiants ; les comptes sont égaux, sauf tolérance
 * sur les nœuds non-feuilles (child_count > 0 côté moteur) des facettes hiérarchiques.
 */
function memes_facettes(?array $sql, $moteur, string $detail, bool $tolerer_branches = false): void {
	est_vrai($moteur !== null, "{$detail} — le pont a décliné");
	est_vrai(is_array($moteur), "{$detail} — le pont n'a pas rendu de tableau");

	$c_sql    = comptes($sql);
	$c_moteur = comptes($moteur);

	$absents = array_diff(array_keys($c_sql), array_keys($c_moteur));
	$en_trop = array_diff(array_keys($c_moteur), array_keys($c_sql));
	est_vrai(!sizeof($absents) && !sizeof($en_trop), sprintf(
		'%s — identifiants divergents : %d absents du moteur (%s), %d en trop (%s)',
		$detail, sizeof($absents), join(',', array_slice($absents, 0, 5)),
		sizeof($en_trop), join(',', array_slice($en_trop, 0, 5))
	));

	foreach ($c_sql as $id => $compte_sql) {
		if ($tolerer_branches && (int)($moteur[$id]['child_count'] ?? 0) > 0) { continue; }
		est_egal($compte_sql, $c_moteur[$id], "{$detail} — compte de {$id}");
	}
}

# ----------------------------------------------------------------------
# Matière authority : le harnais n'a aucun lien objet-entité ni objet-terme,
# le test fabrique donc les siens — une fois, idempotent, marqués TEST-FAC.
#
# La hiérarchie de vocabulaire est le cas qui compte : « Abyssinie » porte deux
# enfants, et deux objets sont liés aux deux enfants à la fois — c'est là que le
# compte SQL d'un ancêtre (somme des descendants) et celui du moteur (documents
# distincts) divergent légitimement.
# ----------------------------------------------------------------------

function fabriquer_matiere_authority($plugin): array {
	$locale_id = ca_locales::getDefaultCataloguingLocaleID();
	$db        = new Db();

	$qr = $db->query("SELECT object_id FROM ca_objects WHERE deleted = 0 ORDER BY object_id LIMIT 10");
	$objets = array_map('intval', $qr->getAllFieldValues('object_id'));
	est_vrai(sizeof($objets) === 10, 'moins de dix objets dans le fonds');

	$rt = new ca_relationship_types();
	$type_rel = function (string $table) use ($rt): string {
		foreach (($rt->getRelationshipInfo($table) ?: []) as $info) {
			$code = $info['type_code'] ?? '';
			if (strlen($code) && strpos($code, 'root_for') !== 0) { return $code; }
		}
		throw new EchecAssertion("aucun type de relation utilisable pour {$table}");
	};

	// Deux entités : E1 sur 5 objets, E2 sur 3, le premier objet portant les deux.
	$entites = [];
	foreach ([['TEST-FAC-E1', 'Testeur', 'Abebe'], ['TEST-FAC-E2', 'Testeuse', 'Marta']] as $def) {
		list($idno, $nom, $prenom) = $def;
		$id = ca_entities::find(['idno' => $idno], ['returnAs' => 'firstId']);
		if (!$id) {
			$t = new ca_entities();
			$types = $t->getTypeList();
			$t->set('type_id', array_key_first($types));
			$t->set('idno', $idno);
			$t->set('access', 1);
			$t->insert();
			est_vrai(!$t->numErrors(), "création {$idno} : " . join('; ', $t->getErrors()));
			$t->addLabel(['surname' => $nom, 'forename' => $prenom, 'displayname' => "{$prenom} {$nom}"], $locale_id, null, true);
			$id = (int)$t->getPrimaryKey();
		}
		$entites[$idno] = (int)$id;
	}

	// Un vocabulaire hiérarchique : Abyssinie > { Addis-Abeba, Harar }.
	$list_id = (int)($db->query("SELECT list_id FROM ca_lists WHERE list_code = 'test_facettes_vocab'")->getAllFieldValues('list_id')[0] ?? 0);
	if (!$list_id) {
		$t_list = new ca_lists();
		$t_list->set('list_code', 'test_facettes_vocab');
		$t_list->set('is_hierarchical', 1);
		$t_list->set('is_system_list', 0);
		$t_list->set('use_as_vocabulary', 1);
		$t_list->insert();
		est_vrai(!$t_list->numErrors(), 'création de la liste : ' . join('; ', $t_list->getErrors()));
		$t_list->addLabel(['name' => 'Vocabulaire de test des facettes'], $locale_id, null, true);
		$list_id = (int)$t_list->getPrimaryKey();
	}

	$racine = (int)($db->query("SELECT item_id FROM ca_list_items WHERE list_id = ? AND parent_id IS NULL", [$list_id])->getAllFieldValues('item_id')[0] ?? 0);
	est_vrai($racine > 0, 'liste de test sans racine');

	$terme = function (string $idno, string $libelle, int $parent_id) use ($db, $list_id, $locale_id): int {
		$id = (int)($db->query("SELECT item_id FROM ca_list_items WHERE list_id = ? AND idno = ?", [$list_id, $idno])->getAllFieldValues('item_id')[0] ?? 0);
		if ($id) { return $id; }
		$t = new ca_list_items();
		$t->set('list_id', $list_id);
		$t->set('parent_id', $parent_id);
		$t->set('idno', $idno);
		$t->set('item_value', $idno);
		$t->set('access', 1);
		$t->set('is_enabled', 1);
		$t->insert();
		est_vrai(!$t->numErrors(), "création du terme {$idno} : " . join('; ', $t->getErrors()));
		$t->addLabel(['name_singular' => $libelle, 'name_plural' => $libelle], $locale_id, null, true);
		return (int)$t->getPrimaryKey();
	};

	$abyssinie = $terme('TEST-FAC-ABYSSINIE', 'Abyssinie (test)', $racine);
	$addis     = $terme('TEST-FAC-ADDIS', 'Addis-Abeba (test)', $abyssinie);
	$harar     = $terme('TEST-FAC-HARAR', 'Harar (test)', $abyssinie);

	// Le socle n'affiche un ancêtre que s'il porte un type : on en pose un si le modèle en
	// offre, et les attentes du test s'ajustent à sa présence. Le type d'un terme ne passe
	// pas dans l'index — pas de réindexation à prévoir.
	$types_de_termes = (new ca_list_items())->getTypeList();
	$termes_types = is_array($types_de_termes) && sizeof($types_de_termes);
	if ($termes_types) {
		$db->query("UPDATE ca_list_items SET type_id = ? WHERE idno LIKE 'TEST-FAC-%' AND type_id IS NULL",
			[(int)array_key_first($types_de_termes)]);
	}

	// Les liens : posés une fois — la présence du premier suffit à dire que tout y est.
	$deja = (int)($db->query("SELECT COUNT(*) c FROM ca_objects_x_entities WHERE entity_id = ?", [$entites['TEST-FAC-E1']])->getAllFieldValues('c')[0] ?? 0);
	if (!$deja) {
		$code_entite = $type_rel('ca_objects_x_entities');
		$code_terme  = $type_rel('ca_objects_x_vocabulary_terms');

		$lier = function (int $object_id, string $table, int $rel_id, string $code) {
			$t = new ca_objects($object_id);
			$t->addRelationship($table, $rel_id, $code);
			est_vrai(!$t->numErrors(), "lien {$object_id} → {$table}#{$rel_id} : " . join('; ', $t->getErrors()));
		};

		foreach (array_slice($objets, 0, 5) as $oid) { $lier($oid, 'ca_entities', $entites['TEST-FAC-E1'], $code_entite); }
		foreach (array_slice($objets, 0, 3) as $oid) { $lier($oid, 'ca_entities', $entites['TEST-FAC-E2'], $code_entite); }

		foreach (array_slice($objets, 0, 6) as $oid) { $lier($oid, 'ca_list_items', $addis, $code_terme); }
		foreach (array_slice($objets, 4, 4) as $oid) { $lier($oid, 'ca_list_items', $harar, $code_terme); }
		// objets 5 et 6 (indices 4-5) sont liés aux deux enfants : le cas du double compte.

		// Réindexation ciblée des objets touchés, par le chemin de l'outil du greffon.
		require_once(__CA_APP_DIR__ . '/plugins/Meilisearch/tools/_socle.php');
		$indexeur   = new SearchIndexer($db);
		$table_num  = Datamodel::getTableNum('ca_objects');
		$field_data = donnees_de_champs($indexeur, 'ca_objects', $objets, $db);
		foreach ($objets as $oid) {
			$indexeur->indexRow($table_num, $oid, $field_data[$oid] ?? [], true);
		}
		$plugin->flushContentBuffer();
	}

	return ['entites' => $entites, 'abyssinie' => $abyssinie, 'addis' => $addis, 'harar' => $harar,
		'objets' => $objets, 'termes_types' => (bool)$termes_types];
}

$matiere = fabriquer_matiere_authority($plugin);

/**
 * Matière pour les facettes de dates : l'élément `creation_date` que le browse.conf livré
 * désigne n'existe dans aucun de nos corpus. On le crée, et on pose des dates dont les
 * tranches sont connues d'avance — dont un intervalle à cheval sur deux décennies, qui est
 * le cas que l'éclatement doit couvrir.
 */
function fabriquer_matiere_dates($plugin): array {
	$db = new Db();

    // Dix objets, distincts de ceux qui portent la matière d'autorité, pour que les comptes
    // de chaque facette restent lisibles.
	$qr = $db->query("SELECT object_id FROM ca_objects WHERE deleted = 0 ORDER BY object_id DESC LIMIT 6");
	$objets = array_map('intval', $qr->getAllFieldValues('object_id'));
	est_vrai(sizeof($objets) === 6, 'moins de six objets dans le fonds');

	$element_id = (int)($db->query("SELECT element_id FROM ca_metadata_elements WHERE element_code = 'creation_date'")->getAllFieldValues('element_id')[0] ?? 0);
	if (!$element_id) {
		$t = new ca_metadata_elements();
		$t->set('element_code', 'creation_date');
		$t->set('datatype', __CA_ATTRIBUTE_VALUE_DATERANGE__);
		$t->set('parent_id', null);
		$t->insert();
		est_vrai(!$t->numErrors(), 'création de l\'élément creation_date : ' . join('; ', $t->getErrors()));
		$element_id = (int)$t->getPrimaryKey();

		// L'étiquette n'est pas décorative : getApplicableElementCodes() joint les libellés en
		// INNER JOIN (BaseModelWithAttributes.php:3676). Un élément sans libellé n'est
		// applicable à rien, ses valeurs ne s'indexent pas, et rien ne le dit.
		$t->addLabel(['name' => 'Date de création (test)'], ca_locales::getDefaultCataloguingLocaleID(), null, true);
		est_vrai(!$t->numErrors(), 'étiquette de creation_date : ' . join('; ', $t->getErrors()));

		$n = (int)($db->query("SELECT COUNT(*) c FROM ca_metadata_element_labels WHERE element_id = ?", [$element_id])->getAllFieldValues('c')[0] ?? 0);
		est_vrai($n > 0, 'l\'élément creation_date est resté sans étiquette');

		// Sans restriction de type, l'élément ne se pose sur aucune fiche — et l'échec est
		// silencieux : addAttribute() n'élève rien, la valeur ne s'écrit simplement pas.
		$t_restriction = new ca_metadata_type_restrictions();
		$t_restriction->set('table_num', Datamodel::getTableNum('ca_objects'));
		$t_restriction->set('type_id', null);          // null : tous les types
		$t_restriction->set('include_subtypes', 1);
		$t_restriction->set('element_id', $element_id);
		$t_restriction->set('rank', 1);
		$t_restriction->insert();
		est_vrai(!$t_restriction->numErrors(), 'restriction de type : ' . join('; ', $t_restriction->getErrors()));

		// Les codes applicables sont mis en cache par le socle : sans purge, l'élément qu'on
		// vient de créer reste invisible pour le reste du processus.
		if (method_exists('ca_metadata_elements', 'flushCache')) { ca_metadata_elements::flushCache(); }
		if (class_exists('ExternalCache')) { ExternalCache::flush('ElementList'); ExternalCache::flush('ElementSets'); }
	}

	// Les dates, et les tranches qu'on en attend.
	$dates = ['1946', '1946', '1947', '1950', '1955', '1948 - 1952'];
	$deja  = (int)($db->query("SELECT COUNT(*) c FROM ca_attributes WHERE element_id = ?", [$element_id])->getAllFieldValues('c')[0] ?? 0);

	if (!$deja) {
		ca_metadata_elements::getElementID('creation_date');   // amorce les caches du socle
		foreach ($objets as $i => $oid) {
			$t = new ca_objects($oid);
			$t->addAttribute(['creation_date' => $dates[$i]], 'creation_date');
			$t->update();
			est_vrai(!$t->numErrors(), "date sur l'objet {$oid} : " . join('; ', $t->getErrors()));
		}

		require_once(__CA_APP_DIR__ . '/plugins/Meilisearch/tools/_socle.php');
		$indexeur   = new SearchIndexer($db);
		$table_num  = Datamodel::getTableNum('ca_objects');
		$field_data = donnees_de_champs($indexeur, 'ca_objects', $objets, $db);
		foreach ($objets as $oid) { $indexeur->indexRow($table_num, $oid, $field_data[$oid] ?? [], true); }
		$plugin->flushContentBuffer();
	}

	return ['objets' => $objets, 'element_id' => $element_id];
}

$dates = fabriquer_matiere_dates($plugin);

verifier('year_facet éclate les intervalles en années, aux mêmes comptes que le SQL', function () use ($plugin) {
	memes_facettes(facette_sql('year_facet'), facette_moteur($plugin, 'year_facet'), 'year_facet');
});

verifier('decade_facet regroupe par décennie, aux mêmes comptes que le SQL', function () use ($plugin) {
	memes_facettes(facette_sql('decade_facet'), facette_moteur($plugin, 'decade_facet'), 'decade_facet');
});

verifier('un intervalle compte dans chacune des années qu\'il recouvre', function () use ($plugin) {
	$m = comptes(facette_moteur($plugin, 'year_facet'));
	// 1946 est posée deux fois ; 1948-1952 recouvre 1948 à 1952, dont 1950 déjà posée seule.
	est_egal(2, $m['1946'] ?? -1, 'deux fiches en 1946');
	est_egal(1, $m['1947'] ?? -1, 'une fiche en 1947');
	est_egal(1, $m['1949'] ?? -1, '1949 vient du seul intervalle');
	est_egal(2, $m['1950'] ?? -1, '1950 : la date seule et l\'intervalle');
	est_egal(1, $m['1955'] ?? -1, 'une fiche en 1955');
});

verifier('un critère de date restreint comme le recouvrement du socle', function () use ($plugin) {
	$criteres = ['year_facet' => ['1950']];
	memes_facettes(facette_sql('type_facet', $criteres), facette_moteur($plugin, 'type_facet', $criteres),
		'type_facet sous l\'année 1950');
});

# ----------------------------------------------------------------------
# Matière : un type, une entité, tirés du fonds
# ----------------------------------------------------------------------

$contenu_types = facette_sql('type_facet');
est_vrai(is_array($contenu_types) && sizeof($contenu_types), 'le fonds n\'offre aucun type d\'objet — corpus non chargé ?');
$un_type = (string)array_key_first($contenu_types);

// Le socle réindexe parfois ses postes séquentiellement : l'identifiant se lit dans le poste.
$contenu_entites = facette_sql('entity_facet');
$premier_poste = is_array($contenu_entites) && sizeof($contenu_entites) ? reset($contenu_entites) : null;
$une_entite = $premier_poste ? (string)$premier_poste['id'] : null;

# ----------------------------------------------------------------------
# Équivalences, du simple au combiné
# ----------------------------------------------------------------------

verifier('type_facet, fonds entier', function () use ($plugin) {
	memes_facettes(facette_sql('type_facet'), facette_moteur($plugin, 'type_facet'), 'type_facet');
});

verifier('has_media_facet, fonds entier', function () use ($plugin) {
	memes_facettes(facette_sql('has_media_facet'), facette_moteur($plugin, 'has_media_facet'), 'has_media_facet');
});

verifier('entity_facet, fonds entier', function () use ($plugin) {
	memes_facettes(facette_sql('entity_facet'), facette_moteur($plugin, 'entity_facet'), 'entity_facet');
});

verifier('term_facet, fonds entier (hiérarchique : branches tolérées)', function () use ($plugin) {
	memes_facettes(facette_sql('term_facet'), facette_moteur($plugin, 'term_facet'), 'term_facet', true);
});

verifier('entity_facet restreinte par un critère type_facet', function () use ($plugin, $un_type) {
	$criteres = ['type_facet' => [$un_type]];
	memes_facettes(facette_sql('entity_facet', $criteres), facette_moteur($plugin, 'entity_facet', $criteres),
		"entity_facet sous type {$un_type}");
});

verifier('type_facet restreinte par un critère entity_facet', function () use ($plugin, $une_entite) {
	if ($une_entite === null) { throw new EchecAssertion('aucune entité liée dans le fonds'); }
	$criteres = ['entity_facet' => [$une_entite]];
	memes_facettes(facette_sql('type_facet', $criteres), facette_moteur($plugin, 'type_facet', $criteres),
		"type_facet sous entité {$une_entite}");
});

verifier('has_media_facet sous une recherche texte simple', function () use ($plugin) {
	$criteres = ['_search' => ['oil']];
	memes_facettes(facette_sql('has_media_facet', $criteres), facette_moteur($plugin, 'has_media_facet', $criteres),
		'has_media_facet sous « oil »');
});

verifier('type_facet avec filtre d\'accès', function () use ($plugin) {
	$options = ['checkAccess' => [1]];
	memes_facettes(facette_sql('type_facet', [], $options), facette_moteur($plugin, 'type_facet', [], $options),
		'type_facet, access=1');
});

verifier('type_facet sous critères combinés (type + recherche)', function () use ($plugin, $un_type) {
	$criteres = ['type_facet' => [$un_type], '_search' => ['painting']];
	memes_facettes(facette_sql('entity_facet', $criteres), facette_moteur($plugin, 'entity_facet', $criteres),
		"entity_facet sous type {$un_type} et « painting »");
});

# ----------------------------------------------------------------------
# Hiérarchies : le cas Abyssinie
# ----------------------------------------------------------------------

verifier('term_facet : les feuilles portent les mêmes comptes, l\'ancêtre suit la règle du socle', function () use ($plugin, $matiere) {
	$sql    = facette_sql('term_facet');
	$moteur = facette_moteur($plugin, 'term_facet');
	memes_facettes($sql, $moteur, 'term_facet', true);

	$m = comptes($moteur);
	est_egal(6, $m[(string)$matiere['addis']] ?? -1, 'Addis-Abeba (moteur)');
	est_egal(4, $m[(string)$matiere['harar']] ?? -1, 'Harar (moteur)');

	if ($matiere['termes_types']) {
		// L'ancêtre : 6 + 4 liens, mais 8 objets distincts — le moteur compte les documents,
		// le SQL additionne les branches. Les deux présences sont exigées, chacune à sa valeur.
		$s = comptes($sql);
		est_egal(8,  $m[(string)$matiere['abyssinie']] ?? -1, 'Abyssinie (moteur, documents distincts)');
		est_egal(10, $s[(string)$matiere['abyssinie']] ?? -1, 'Abyssinie (SQL, somme des branches)');
	} else {
		// Sans type d'item, le socle écarte l'ancêtre — le pont doit l'écarter à l'identique.
		est_vrai(!isset($m[(string)$matiere['abyssinie']]), 'Abyssinie devrait être absente (ancêtre sans type)');
	}
});

verifier('un critère posé sur l\'ancêtre restreint comme sa descendance, des deux côtés', function () use ($plugin, $matiere) {
	$criteres = ['term_facet' => [(string)$matiere['abyssinie']]];
	memes_facettes(facette_sql('type_facet', $criteres), facette_moteur($plugin, 'type_facet', $criteres),
		'type_facet sous Abyssinie');
	memes_facettes(facette_sql('entity_facet', $criteres), facette_moteur($plugin, 'entity_facet', $criteres),
		'entity_facet sous Abyssinie');
});

verifier('entity_facet porte la matière fabriquée, à comptes exacts', function () use ($plugin, $matiere) {
	$moteur = facette_moteur($plugin, 'entity_facet');
	est_egal(5, (int)($moteur[$matiere['entites']['TEST-FAC-E1']]['content_count'] ?? -1), 'E1');
	est_egal(3, (int)($moteur[$matiere['entites']['TEST-FAC-E2']]['content_count'] ?? -1), 'E2');
});

# ----------------------------------------------------------------------
# Modes et règles annexes
# ----------------------------------------------------------------------

verifier('deaccession_facet (type field, booléen) est traduite et compte juste', function () use ($plugin) {
	$moteur = facette_moteur($plugin, 'deaccession_facet');
	est_vrai(is_array($moteur), 'le pont a décliné une facette field pourtant traduisible');

	// Le socle recopie son gabarit [id, label] sans content_count pour un booléen : on ne peut
	// pas comparer les comptes, seulement les identifiants — et vérifier que le nôtre existe.
	$sql = facette_sql('deaccession_facet');
	est_egal(array_keys(comptes($sql)), array_keys(comptes($moteur)), 'identifiants de deaccession_facet');

	$db = new Db();
	$qr = $db->query("SELECT COUNT(*) c FROM ca_objects WHERE deleted = 0 AND is_deaccessioned = 0");
	$qr->nextRow();
	$attendu = (int)$qr->get('c');
	est_egal($attendu, (int)($moteur['0']['content_count'] ?? -1), 'compte des non aliénés');
});

verifier('une facette field à gabarit ou relative_to décline', function () use ($plugin) {
	foreach (['submission_user', 'transcribable_facet'] as $facette) {
		$b = caGetBrowseInstance('ca_objects');
		$info = $b->getInfoForFacet($facette);
		if (!is_array($info)) { continue; }
		est_vrai($plugin->getBrowseFacetContent($b, $facette, $info, []) === null,
			"{$facette} devrait décliner");
	}
});

verifier('checkAvailabilityOnly rend le même verdict', function () use ($plugin) {
	foreach (['type_facet', 'has_media_facet', 'entity_facet'] as $facette) {
		$sql    = facette_sql($facette, [], ['checkAvailabilityOnly' => true]);
		$moteur = facette_moteur($plugin, $facette, [], ['checkAvailabilityOnly' => true]);
		est_vrai($moteur !== null, "{$facette} — le pont a décliné la disponibilité");
		est_egal((bool)$sql, (bool)$moteur, "{$facette} — disponibilité");
	}
});

verifier('une facette has déjà en critère disparaît, des deux côtés', function () use ($plugin) {
	$criteres = ['has_media_facet' => ['1']];
	$sql    = facette_sql('has_media_facet', $criteres);
	$moteur = facette_moteur($plugin, 'has_media_facet', $criteres);
	est_egal([], $sql ?: [], 'côté SQL');
	est_egal([], $moteur ?: [], 'côté moteur');
});

# ----------------------------------------------------------------------
# Déclins attendus : ce que le pont ne traduit pas, il le laisse au SQL
# ----------------------------------------------------------------------

verifier('les facettes non traduites déclinent proprement', function () use ($plugin) {
	foreach (['transcribable_facet', 'title_facet', 'violation_facet', 'checkouts_facet'] as $facette) {
		$b = caGetBrowseInstance('ca_objects');
		$info = $b->getInfoForFacet($facette);
		if (!is_array($info)) { continue; }
		$resultat = $plugin->getBrowseFacetContent($b, $facette, $info, []);
		est_vrai($resultat === null, "{$facette} devrait décliner, a rendu " . gettype($resultat));
	}
});

verifier('une recherche à syntaxe Lucene fait décliner tout le browse', function () use ($plugin) {
	$criteres = ['_search' => ['oil AND canvas']];
	$b = browse_avec([]);   // les critères sont posés à la main pour éviter l'execute
	$b->addCriteria('_search', ['oil AND canvas']);
	$info = $b->getInfoForFacet('type_facet');
	$resultat = $plugin->getBrowseFacetContent($b, 'type_facet', $info, []);
	est_vrai($resultat === null, 'une expression booléenne doit décliner, a rendu ' . gettype($resultat));
});

exit(bilan());
