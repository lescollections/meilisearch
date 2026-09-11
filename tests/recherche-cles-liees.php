<?php
/* ----------------------------------------------------------------------
 * tests/recherche-cles-liees.php — `<table>.<clé>:<id>`, la recherche par identifiant
 * de fiche liée.
 *
 * L'inspecteur du socle fabrique exactement ces requêtes : le lien « N liés à … » vaut
 * `caSearchLink(…, <table>.<clé>:<id>)` (displayHelpers.php:1585). Mais une telle clé n'est
 * interrogeable que si `search_indexing.conf` la déclare pour la table cherchée, ce qu'il ne
 * fait que pour une minorité de couples — relevé sur comodo au 11/09/2026 : 13 sur 36.
 * `ca_objects` les déclare toutes ; `ca_movements` et `ca_storage_locations` aucune. Les autres
 * liens rendaient zéro en silence, alors que la relation existe en base. Signalé par l'INRAP
 * sur une demande de versement portant 36 opérations, dont le lien n'en rendait aucune.
 *
 * Compléter `search_indexing.conf` aurait fait entrer ces clés dans le graphe de dépendances de
 * l'indexeur — et donc réindexé, SYNCHRONEMENT, toutes les fiches citant la fiche enregistrée :
 * 7 143 opérations pour une entité, 2 800 pour un centre. Le connecteur les résout donc dans la
 * table de relation, sans rien mettre dans l'index (`Meilisearch::relatedIds()`).
 *
 * Chaque vérification compare le connecteur à une requête SQL écrite ici, indépendante du code
 * testé : c'est le seul témoin qui vaille. Rien n'est écrit en dur — les couples comme les
 * fiches témoins sont tirés du fonds présent — mais on refuse de conclure sur un ensemble vide,
 * qui ferait passer une régression pour un succès.
 *
 * Aucune écriture : ce test se lance sans risque sur une instance en service.
 *
 *     CA_RACINE=/chemin/vers/providence php tests/recherche-cles-liees.php
 * ---------------------------------------------------------------------- */

require_once(__DIR__ . '/_cadre.php');

/** Tables sujettes que l'on croise deux à deux. */
const TABLES = [
	'ca_objects', 'ca_collections', 'ca_entities',
	'ca_occurrences', 'ca_movements', 'ca_places', 'ca_storage_locations',
];

/** Classe de recherche du socle, par table sujet. */
const MOTEURS = [
	'ca_objects'           => 'ObjectSearch',
	'ca_collections'       => 'CollectionSearch',
	'ca_entities'          => 'EntitySearch',
	'ca_occurrences'       => 'OccurrenceSearch',
	'ca_movements'         => 'MovementSearch',
	'ca_places'            => 'PlaceSearch',
	'ca_storage_locations' => 'StorageLocationSearch',
];

function db(): Db { static $db = null; if ($db === null) { $db = new Db(); } return $db; }

/** Un décompte SQL. `getRow()` ne rend rien tant que le curseur n'a pas avancé. */
function compte(string $sql): int {
	$qr = db()->query($sql);
	return $qr->nextRow() ? (int)$qr->get('n') : 0;
}

function tables_de_la_base(): array {
	static $t = null;
	if ($t !== null) { return $t; }
	$t = [];
	$qr = db()->query('SHOW TABLES');
	while ($qr->nextRow()) { $t[array_values($qr->getRow())[0]] = true; }
	return $t;
}

/**
 * La table de relation entre deux tables, telle que le modèle la connaît — jamais reconstituée
 * par concaténation : le parc porte les deux ordres de nommage (`ca_places_x_collections` mais
 * `ca_collections_x_storage_locations`).
 *
 * @return string|null nom de la table, ou null s'il n'y a pas de relation directe
 */
function table_de_relation(string $sujet, string $liee): ?string {
	$chemin = Datamodel::getPath($sujet, $liee);
	if (!is_array($chemin) || sizeof($chemin) !== 3) { return null; }
	$etapes = array_keys($chemin);
	return isset(tables_de_la_base()[$etapes[1]]) ? $etapes[1] : null;
}

function cle(string $table): string {
	return Datamodel::getInstanceByTableName($table, true)->primaryKey();
}

/**
 * Le témoin d'un couple : la fiche LIÉE qui en relie le plus, et les identifiants des fiches
 * sujettes vivantes qu'elle relie. On prend la plus fournie à dessein — c'est le cas où une
 * troncature, une limite ou une pagination oubliée se verrait.
 *
 * @return array|null [identifiant de la fiche liée, identifiants sujets attendus]
 */
function temoin(string $sujet, string $liee, string $rel): ?array {
	$cle_sujet = cle($sujet);
	$cle_liee  = cle($liee);

	$qr = db()->query("
		SELECT l.{$cle_liee} AS liee, COUNT(DISTINCT l.{$cle_sujet}) AS n
		FROM {$rel} l
		INNER JOIN {$sujet} s ON s.{$cle_sujet} = l.{$cle_sujet} AND s.deleted = 0
		GROUP BY l.{$cle_liee}
		ORDER BY n DESC
		LIMIT 1
	");
	if (!$qr->nextRow()) { return null; }
	$id_liee = (int)$qr->get('liee');
	if ($id_liee <= 0) { return null; }

	$qr = db()->query("
		SELECT DISTINCT l.{$cle_sujet} AS id
		FROM {$rel} l
		INNER JOIN {$sujet} s ON s.{$cle_sujet} = l.{$cle_sujet} AND s.deleted = 0
		WHERE l.{$cle_liee} = ?
	", [$id_liee]);
	$ids = [];
	while ($qr->nextRow()) { $ids[] = (int)$qr->get('id'); }

	return sizeof($ids) ? [$id_liee, $ids] : null;
}

/** Les identifiants que le connecteur rend pour une expression, sur une table sujet donnée. */
function chercher(string $sujet, string $expression): array {
	$classe = MOTEURS[$sujet];
	require_once(__CA_LIB_DIR__ . "/Search/{$classe}.php");
	$moteur = new $classe();
	$res    = $moteur->search($expression, ['search_source' => 'Test', 'no_cache' => true]);

	$cle = cle($sujet);
	$ids = [];
	while ($res->nextHit()) { $ids[] = (int)$res->get($cle); }
	return $ids;
}

# ----------------------------------------------------------------------

titre('Recherche par identifiant de fiche liée');

$couples = 0;
foreach (TABLES as $sujet) {
	foreach (TABLES as $liee) {
		if ($sujet === $liee) { continue; }                    // auto-relation : autre schéma de colonnes
		if (!($rel = table_de_relation($sujet, $liee))) { continue; }
		if (!($t = temoin($sujet, $liee, $rel))) { continue; }  // couple sans donnée : rien à conclure

		[$id_liee, $attendus] = $t;
		$couples++;

		$cle_liee   = cle($liee);
		$expression = "{$liee}.{$cle_liee}:{$id_liee}";

		verifier("{$expression} rend les " . sizeof($attendus) . ' ' . $sujet, function () use ($sujet, $expression, $attendus, $rel) {
			$obtenus = chercher($sujet, $expression);

			// L'ordre n'a pas de sens pour une recherche par clé : on compare des ensembles.
			sort($attendus);
			sort($obtenus);

			est_egal(
				sizeof($attendus), sizeof($obtenus),
				"décompte — témoin SQL sur {$rel}"
			);
			est_egal(
				$attendus, $obtenus,
				'identifiants — le connecteur doit rendre exactement les fiches liées, ni plus ni moins'
			);
		});
	}
}

verifier('le fonds offrait assez de couples pour conclure', function () use ($couples) {
	// Un test qui ne teste rien passe toujours. Sur comodo, 36 couples portent des données ;
	// en dessous de quelques-uns, c'est le fonds qu'il faut regarder, pas le connecteur.
	est_vrai($couples >= 3, "couples éprouvés : {$couples}");
});

titre('Ce que la recherche par clé ne doit PAS faire');

verifier('une valeur non numérique ne restreint rien d\'inventé', function () {
	// `collection_id:abc` n'a pas de sens. Le connecteur décline et reprend la voie ordinaire ;
	// ce qu'il ne doit pas faire, c'est rendre le fonds entier.
	$total = compte('SELECT COUNT(*) AS n FROM ca_objects WHERE deleted = 0');
	est_vrai($total > 0, 'le fonds doit contenir des objets pour que la vérification ait un sens');
	$ids = chercher('ca_objects', 'ca_collections.collection_id:pasunnombre');
	est_vrai(sizeof($ids) < $total, 'rendu : ' . sizeof($ids) . " sur {$total}");
});

verifier('un identifiant inexistant rend zéro, pas tout', function () {
	$max = compte('SELECT COALESCE(MAX(collection_id), 0) AS n FROM ca_collections');
	$ids = chercher('ca_objects', 'ca_collections.collection_id:' . ($max + 1000000));
	est_egal(0, sizeof($ids), 'aucune fiche ne peut être liée à une collection qui n\'existe pas');
});

exit(bilan());
