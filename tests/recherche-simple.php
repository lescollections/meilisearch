<?php
/* ----------------------------------------------------------------------
 * tests/recherche-simple.php — l'objectif de la première étape : retrouver un objet par son
 * titre ou par son numéro d'inventaire.
 *
 * Se lance dans l'instance, sans passer par l'interface :
 *
 *     docker compose exec app dev-test recherche-simple
 *
 * Les recherches passent par ObjectSearch, la voie qu'emprunte l'interface : le parseur
 * Lucene, la réécriture des points d'accès, les filtres de résultat, le cache. Vérifier le
 * connecteur seul ne dirait rien de son insertion dans la chaîne.
 * ---------------------------------------------------------------------- */

require_once(__DIR__ . '/_cadre.php');

$moteur = null;
$schema = null;
$client = null;

titre('Le socle');

verifier('le moteur configuré est bien Meilisearch', function () {
	est_egal('Meilisearch', Configuration::load()->get('search_engine_plugin'),
		'directive search_engine_plugin dans app/conf/local/app.conf');
});

verifier('le connecteur s\'instancie', function () use (&$moteur, &$schema, &$client) {
	$moteur = moteur();
	$schema = $moteur->getSchema();
	$client = $moteur->getClient();
	est_egal('Meilisearch', $moteur->engineName());
});

verifier('le greffon applicatif est installé', function () use (&$moteur) {
	est_vrai($moteur->getConfig()->appPluginIsInstalled(),
		'app/plugins/Meilisearch/conf/meilisearch.conf introuvable');
});

verifier('le service répond', function () use (&$client) {
	est_vrai($client->isAvailable(), 'Meilisearch injoignable sur ' . $client->getBaseUrl());
});

titre('L\'index');

$objets_en_base = nombre_objets();
$index          = null;

verifier('l\'index des objets existe', function () use (&$client, &$schema, &$index) {
	$index = $schema->indexName('ca_objects');
	est_vrai($client->indexExists($index), "index {$index} absent — lancer `caUtils rebuild-search-index`");
});

verifier('il contient tous les objets de la base', function () use (&$client, &$index, $objets_en_base) {
	est_vrai($objets_en_base > 0, 'aucun objet en base — lancer `dev-seed-cleveland`');
	est_egal($objets_en_base, $client->documentCount($index),
		'documents indexés vs objets non supprimés en base');
});

$temoin = objet_temoin();
printf("\n  \033[90mobjet témoin : #%d, idno « %s », titre « %s »\033[0m\n",
	$temoin['object_id'], $temoin['idno'], $temoin['titre']);

titre('Recherche par titre');

verifier('le titre exact retrouve l\'objet', function () use ($temoin) {
	contient($temoin['object_id'], chercher_objets('"' . expression_titre($temoin['titre']) . '"'));
});

verifier('un mot du titre retrouve l\'objet', function () use ($temoin) {
	$mots = array_values(array_filter(
		preg_split('!\s+!u', expression_titre($temoin['titre'])),
		fn($m) => mb_strlen($m) > 4
	));
	est_vrai(sizeof($mots) > 0, 'titre témoin sans mot de plus de quatre lettres');
	contient($temoin['object_id'], chercher_objets($mots[0]),
		"recherche du mot « {$mots[0]} »");
});

verifier('deux mots du titre retrouvent l\'objet (et exigent les deux)', function () use ($temoin) {
	$mots = array_values(array_filter(
		preg_split('!\s+!u', expression_titre($temoin['titre'])),
		fn($m) => mb_strlen($m) > 3
	));
	if (sizeof($mots) < 2) { throw new EchecAssertion('titre témoin trop court pour ce cas'); }

	$avec_deux = chercher_objets($mots[0] . ' ' . $mots[1]);
	contient($temoin['object_id'], $avec_deux, 'les deux mots');

	// L'opérateur par défaut de CollectiveAccess est AND : deux mots doivent restreindre.
	$avec_un = chercher_objets($mots[0]);
	est_vrai(sizeof($avec_deux) <= sizeof($avec_un),
		sprintf('deux mots (%d résultats) devraient restreindre par rapport à un seul (%d)',
			sizeof($avec_deux), sizeof($avec_un)));
});

verifier('la recherche restreinte au titre fonctionne', function () use ($temoin) {
	contient($temoin['object_id'],
		chercher_objets('ca_objects.preferred_labels:"' . expression_titre($temoin['titre']) . '"'));
});

titre('Recherche par numéro d\'inventaire');

verifier('le numéro complet retrouve l\'objet', function () use ($temoin) {
	contient($temoin['object_id'], chercher_objets($temoin['idno']));
});

verifier('le numéro qualifié retrouve l\'objet', function () use ($temoin) {
	contient($temoin['object_id'], chercher_objets('ca_objects.idno:"' . $temoin['idno'] . '"'));
});

verifier('un préfixe du numéro retrouve l\'objet', function () use ($temoin) {
	// Ce que `INDEX_AS_IDNO` garantit, ce sont les **préfixes hiérarchiques** : « CLE.1938.388.a »
	// est indexé avec « CLE », « CLE.1938 », « CLE.1938.388 ». Chercher la série doit ramener ses
	// numéros — c'est voulu, et ce n'est pas au connecteur de le défaire.
	//
	// Le test cherchait auparavant le *dernier* composant (« 388.a » → « a »), qui n'est pas dans
	// les variantes : il ne passait que parce que Meilisearch découpait lui-même sur les points.
	// Depuis que le connecteur indexe les mots du socle et lui déclare « . _ - / » non-séparateurs,
	// ce découpage n'a plus lieu — et SqlSearch2 ne l'a jamais fait non plus. Vérifié sur le
	// fonds CIPAR : sur « 0211.190.873_Saint-Amand_1 », les deux moteurs rendent zéro pour un
	// composant isolé et un pour le numéro entier. Nous sommes alignés, c'est ce qui est demandé.
	$parties = explode('.', $temoin['idno']);
	est_vrai(sizeof($parties) > 1, 'numéro témoin sans préfixe hiérarchique');
	array_pop($parties);
	$prefixe = join('.', $parties);
	contient($temoin['object_id'], chercher_objets('ca_objects.idno:"' . $prefixe . '"'),
		"recherche du préfixe « {$prefixe} » dans idno");
});

verifier('le numéro qualifié ne ramène rien d\'étranger', function () use ($temoin) {
	$ids = chercher_objets('ca_objects.idno:"' . $temoin['idno'] . '"');
	sort($ids);
	est_egal(serie_du_numero($temoin['idno']), $ids,
		'la recherche sur un numéro exact ne doit ramener que ce numéro et ses sous-numéros');
});

titre('Ce qui ne doit rien ramener');

verifier('un terme absent ne ramène rien', function () {
	est_egal(0, sizeof(chercher_objets('zzzzqqqqxxxx')));
});

verifier('un objet supprimé sort des résultats', function () use ($temoin) {
	$t = new ca_objects();
	est_vrai($t->load($temoin['object_id']), 'objet témoin introuvable');

	$t->setMode(ACCESS_WRITE);
	$t->set('deleted', 1);
	$t->update();

	try {
		ne_contient_pas($temoin['object_id'], chercher_objets('"' . expression_titre($temoin['titre']) . '"'),
			'un enregistrement supprimé ne doit pas remonter');
	} finally {
		$t->set('deleted', 0);
		$t->update();
	}
});

verifier('un objet privé sort des résultats quand checkAccess le demande', function () use ($temoin) {
	$t = new ca_objects();
	est_vrai($t->load($temoin['object_id']));

	$t->setMode(ACCESS_WRITE);
	$t->set('access', 0);
	$t->update();

	try {
		ne_contient_pas($temoin['object_id'],
			chercher_objets('"' . expression_titre($temoin['titre']) . '"', ['checkAccess' => [1]]),
			'access = 0 avec checkAccess = [1]');
		contient($temoin['object_id'], chercher_objets('"' . expression_titre($temoin['titre']) . '"'),
			'sans checkAccess, l\'objet reste visible');
	} finally {
		$t->set('access', 1);
		$t->update();
	}
});

titre('Ce qui doit rester vrai après modification');

verifier('un titre modifié est retrouvable, l\'ancien ne l\'est plus', function () use ($temoin) {
	$marqueur = 'Zibeline' . $temoin['object_id'] . 'Quetzal';

	$t = new ca_objects();
	est_vrai($t->load($temoin['object_id']));
	$t->setMode(ACCESS_WRITE);

	// editLabel() sur l'étiquette existante, et non replaceLabel() : celui-ci cherche dans la
	// locale qu'on lui passe et ajoute une seconde étiquette préférée s'il n'y trouve rien.
	$renommer = function (string $nom) use ($t, $temoin) {
		$t->editLabel($temoin['label_id'], ['name' => $nom], $temoin['locale_id'], null, true);
		$t->update();
		est_vrai(!sizeof($t->getErrors()), 'échec du renommage : ' . join('; ', $t->getErrors()));

		// Une étiquette modifiée part en file de réindexation, pas directement à l'index.
		traiter_file_indexation();
	};

	$renommer($marqueur);
	try {
		contient($temoin['object_id'], chercher_objets($marqueur), 'le nouveau titre');
		ne_contient_pas($temoin['object_id'], chercher_objets('"' . expression_titre($temoin['titre']) . '"'),
			'l\'ancien titre ne doit plus rien ramener');
	} finally {
		$renommer($temoin['titre']);
	}
});

exit(bilan());
