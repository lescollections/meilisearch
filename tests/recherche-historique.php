<?php
/* ----------------------------------------------------------------------
 * tests/recherche-historique.php — `created:` et `modified:`, les points d'accès qui
 * n'interrogent pas l'index mais `ca_change_log`.
 *
 * Ces deux-là ne sont pas des champs. Rien dans l'index ne dit quand une fiche a été créée :
 * la réponse est dans l'historique, et le socle l'y va chercher à part
 * (`SqlSearch2::_processQueryChangeLog`). Le connecteur les laissait partir vers Meilisearch
 * comme un attribut ordinaire — aucun document ne le portait, la recherche rendait zéro sans
 * rien dire, et l'INRAP a fini par le signaler (ZD-8266).
 *
 * Chaque vérification compare le connecteur à une requête SQL écrite ici, indépendante du code
 * corrigé : c'est le seul témoin qui vaille. Les décomptes ne sont pas écrits en dur — ils
 * dépendent du fonds — mais on refuse de conclure sur un ensemble vide, qui ferait passer une
 * régression pour un succès.
 *
 * Aucune écriture : ce test se lance sans risque sur une instance en service.
 *
 *     CA_RACINE=/chemin/vers/providence php tests/recherche-historique.php
 * ---------------------------------------------------------------------- */

require_once(__DIR__ . '/_cadre.php');

$tablenum = (int)Datamodel::getTableNum('ca_objects');

/**
 * Les fiches vivantes parmi une liste d'identifiants — ce que le connecteur rend après les
 * filtres de résultat que SearchEngine pose sur toute recherche.
 */
function vivants(array $ids): array {
	if (!sizeof($ids)) { return []; }
	$db = new Db();
	$qr = $db->query('SELECT object_id FROM ca_objects WHERE object_id IN (?) AND deleted = 0', [$ids]);
	return array_map('intval', $qr->getAllFieldValues('object_id'));
}

/**
 * L'intervalle d'une expression de date, tel que le socle le lit.
 */
function intervalle(string $expression): array {
	$tep = new TimeExpressionParser();
	est_vrai($tep->parse($expression), "expression de date incomprise : « {$expression} »");
	$r = $tep->getUnixTimestamps();
	return [(int)$r['start'], (int)$r['end']];
}

/**
 * Les fiches créées dans l'intervalle, par une requête SQL qui ne doit rien au code vérifié.
 */
function creees_sql(int $tablenum, string $expression, ?int $user_id = null): array {
	list($debut, $fin) = intervalle($expression);
	$db  = new Db();
	$sql = 'SELECT DISTINCT logged_row_id AS r FROM ca_change_log
			WHERE log_datetime BETWEEN ? AND ? AND logged_table_num = ? AND changetype = \'I\''
		. ($user_id ? ' AND user_id = ' . (int)$user_id : '');
	$qr = $db->query($sql, [$debut, $fin, $tablenum]);
	return vivants(array_map('intval', $qr->getAllFieldValues('r')));
}

/**
 * Les fiches modifiées dans l'intervalle : enregistrées elles-mêmes, ou sujet d'un changement
 * porté ailleurs — étiquette, valeur attributaire, relation.
 */
function modifiees_sql(int $tablenum, string $expression, ?int $user_id = null): array {
	list($debut, $fin) = intervalle($expression);
	$db      = new Db();
	$user    = $user_id ? ' AND ccl.user_id = ' . (int)$user_id : '';
	$qr = $db->query("
		SELECT DISTINCT ccl.logged_row_id AS r
		FROM ca_change_log ccl
		WHERE ccl.log_datetime BETWEEN ? AND ? AND ccl.logged_table_num = ? AND ccl.changetype = 'U' {$user}
		UNION
		SELECT DISTINCT ccls.subject_row_id
		FROM ca_change_log ccl
		INNER JOIN ca_change_log_subjects ccls ON ccls.log_id = ccl.log_id
		WHERE ccl.log_datetime BETWEEN ? AND ? AND ccls.subject_table_num = ? {$user}",
		[$debut, $fin, $tablenum, $debut, $fin, $tablenum]);
	return vivants(array_map('intval', $qr->getAllFieldValues('r')));
}

/**
 * Une année où le fonds a été alimenté, et un mois de cette année-là : écrire une date en dur
 * ferait passer le test au vert sur une instance et au rouge sur la suivante.
 */
function annee_alimentee(int $tablenum): ?string {
	$db = new Db();
	$qr = $db->query("
		SELECT YEAR(FROM_UNIXTIME(log_datetime)) AS a, COUNT(*) AS c
		FROM ca_change_log
		WHERE logged_table_num = ? AND changetype = 'I'
		GROUP BY a ORDER BY c DESC LIMIT 1", [$tablenum]);
	return $qr->nextRow() ? (string)$qr->get('a') : null;
}

titre('Le diagnostic');

verifier('le moteur configuré est bien Meilisearch', function () {
	est_egal('Meilisearch', Configuration::load()->get('search_engine_plugin'));
});

$annee = annee_alimentee($tablenum);
if ($annee === null) {
	ignorer('tout le reste', 'aucune trace de création dans ca_change_log');
	exit(bilan());
}

$attendu_cree = creees_sql($tablenum, $annee);

titre("Les fiches créées — année {$annee}");

verifier('le fonds a de quoi vérifier', function () use ($attendu_cree, $annee) {
	est_vrai(sizeof($attendu_cree) > 0, "aucune fiche vivante créée en {$annee} : le test ne dirait rien");
});

verifier("created:{$annee} rend les fiches créées cette année-là", function () use ($annee, $attendu_cree) {
	est_egal(sizeof($attendu_cree), sizeof(chercher_objets("created:{$annee}")));
});

verifier("créé:{$annee} — la forme française — rend la même chose", function () use ($annee, $attendu_cree) {
	est_egal(sizeof($attendu_cree), sizeof(chercher_objets("créé:{$annee}")));
});

verifier('une date à plusieurs mots est lue comme une date, pas comme une phrase', function () use ($tablenum, $annee) {
	// Le parseur Lucene en fait une phrase ; sans traitement, elle partait chercher les mots
	// « avril » et « 2020 » contigus dans le texte des fiches.
	$expression = "avril {$annee}";
	$attendu    = creees_sql($tablenum, $expression);
	est_vrai(sizeof($attendu) > 0, "aucune fiche créée en {$expression}");
	est_egal(sizeof($attendu), sizeof(chercher_objets("created:\"{$expression}\"")));
});

titre("Les fiches modifiées — année {$annee}");

verifier("modified:{$annee} compte aussi les changements portés ailleurs", function () use ($tablenum, $annee) {
	$attendu = modifiees_sql($tablenum, $annee);
	est_vrai(sizeof($attendu) > 0, "aucune fiche modifiée en {$annee}");
	est_egal(sizeof($attendu), sizeof(chercher_objets("modified:{$annee}")));
});

verifier("modifié:{$annee} — la forme française — rend la même chose", function () use ($tablenum, $annee) {
	est_egal(sizeof(modifiees_sql($tablenum, $annee)), sizeof(chercher_objets("modifié:{$annee}")));
});

titre('La restriction par usager');

$db = new Db();
$qr = $db->query("
	SELECT u.user_id, u.user_name, COUNT(*) AS c
	FROM ca_change_log ccl
	INNER JOIN ca_users u ON u.user_id = ccl.user_id
	WHERE ccl.logged_table_num = ? AND ccl.changetype = 'U' AND u.user_name NOT LIKE '% %'
	GROUP BY u.user_id ORDER BY c DESC LIMIT 1", [$tablenum]);

if (!$qr->nextRow()) {
	ignorer('modified.<usager>:', 'aucune modification attribuée à un usager');
} else {
	$user_id   = (int)$qr->get('user_id');
	$user_name = (string)$qr->get('user_name');

	verifier("modified.{$user_name}:{$annee} restreint à cet usager", function () use ($tablenum, $annee, $user_id, $user_name) {
		$attendu = modifiees_sql($tablenum, $annee, $user_id);
		est_vrai(sizeof($attendu) > 0, "aucune modification de {$user_name} en {$annee}");
		est_egal(sizeof($attendu), sizeof(chercher_objets("modified.{$user_name}:{$annee}")));
	});

	verifier('un numéro d\'usager vaut son identifiant de connexion', function () use ($annee, $user_id, $user_name) {
		est_egal(
			sizeof(chercher_objets("modified.{$user_name}:{$annee}")),
			sizeof(chercher_objets("modified.{$user_id}:{$annee}"))
		);
	});

	verifier('la restriction change bien le résultat', function () use ($annee, $user_name) {
		$tous = sizeof(chercher_objets("modified:{$annee}"));
		$sien = sizeof(chercher_objets("modified.{$user_name}:{$annee}"));
		est_vrai($sien < $tous, "modified.{$user_name}: rend autant que modified: — la restriction n'est pas appliquée");
	});
}

titre('En combinaison');

verifier('l\'historique s\'intersecte avec une recherche plein texte', function () use ($tablenum, $annee, $attendu_cree) {
	// Un mot présent dans le fonds, tiré d'un titre au hasard plutôt qu'écrit en dur —
	// `objet_temoin()` ne sert pas ici, il cherche le corpus de développement.
	$db = new Db();
	$qr = $db->query("
		SELECT l.name
		FROM ca_object_labels l
		INNER JOIN ca_objects o ON o.object_id = l.object_id
		WHERE l.is_preferred = 1 AND o.deleted = 0 AND CHAR_LENGTH(l.name) > 12
		LIMIT 200");

	$mot = null;
	while (($mot === null) && $qr->nextRow()) {
		foreach (preg_split('!\s+!u', expression_titre((string)$qr->get('name'))) as $candidat) {
			if (mb_strlen($candidat) > 5) { $mot = $candidat; break; }
		}
	}
	if ($mot === null) { throw new EchecAssertion('aucun mot exploitable dans les titres du fonds'); }

	$sur_mot = chercher_objets($mot);
	est_vrai(sizeof($sur_mot) > 0, "« {$mot} » ne rend rien");

	est_egal(sizeof(array_intersect($sur_mot, $attendu_cree)), sizeof(chercher_objets("{$mot} AND created:{$annee}")));
	est_egal(sizeof(array_diff($sur_mot, $attendu_cree)),      sizeof(chercher_objets("{$mot} NOT created:{$annee}")));
});

titre('Ce qui ne se lit pas');

verifier('une date incompréhensible rend zéro, et non le fonds entier', function () {
	// `modified.login:lpelletier` — l'usager mis après le deux-points, où l'on attend une date.
	// Le socle rend zéro ; le connecteur en dit la raison dans son journal.
	est_egal(0, sizeof(chercher_objets('modified.login:pas_une_date')));
});

exit(bilan());
