<?php
/* ----------------------------------------------------------------------
 * tests/compatibilite-socle.php — les outils tiennent-ils sur un socle qui n'est pas le fork ?
 *
 *     docker compose exec app dev-test compatibilite-socle
 *
 * `tools/reindexer.php` s'appuyait sur `SearchIndexer::getFieldDataForReindex()`, qui n'existe
 * que dans le fork `lescollections/providence`. Sur une autre instance, la réindexation meurt
 * à la première tranche — vu sur préprod-130.32 :
 *
 *     Call to undefined method SearchIndexer::getFieldDataForReindex()
 *
 * Les outils passent désormais par `donnees_de_champs()`, qui lit la table elle-même quand la
 * méthode manque. Ce test vérifie ce qui compte vraiment : que le chemin de repli produise le
 * *même index* que celui du fork — pas seulement qu'il s'exécute sans erreur.
 *
 * Le harnais tourne sur le fork, donc le repli ne s'emprunterait jamais ici : on le force par
 * CA_SANS_FIELD_DATA_FORK, prévu pour ça.
 * ---------------------------------------------------------------------- */

require_once(__DIR__ . '/_cadre.php');
require_once(__CA_LIB_DIR__ . '/Search/SearchIndexer.php');
require_once(__CA_MODELS_DIR__ . '/ca_attributes.php');
require_once(__CA_APP_DIR__ . '/plugins/Meilisearch/tools/_socle.php');

$db        = new Db();
$table_num = Datamodel::getTableNum('ca_objects');
$indexeur  = new SearchIndexer($db);

$qr  = $db->query('SELECT object_id FROM ca_objects WHERE deleted = 0 ORDER BY object_id LIMIT 200');
$ids = array_map('intval', $qr->getAllFieldValues('object_id'));

titre('Les deux chemins de lecture des champs');

verifier('le fork et le repli lisent les mêmes enregistrements', function () use ($indexeur, $ids, $db) {
	putenv('CA_SANS_FIELD_DATA_FORK');
	$fork = donnees_de_champs($indexeur, 'ca_objects', $ids, $db);

	putenv('CA_SANS_FIELD_DATA_FORK=1');
	$repli = donnees_de_champs($indexeur, 'ca_objects', $ids, $db);
	putenv('CA_SANS_FIELD_DATA_FORK');

	est_egal(sizeof($ids), sizeof($fork), 'le chemin du fork n\'a pas rendu tous les enregistrements');
	est_egal(array_keys($fork), array_keys($repli), 'les deux chemins ne portent pas sur les mêmes lignes');

	// Le repli lit toutes les colonnes ; ce qui compte est qu'il ne *manque* rien de ce que le
	// fork retient, et que les valeurs communes coïncident.
	$manquants = [];
	foreach ($fork as $id => $champs) {
		foreach ($champs as $nom => $valeur) {
			if (!array_key_exists($nom, $repli[$id])) { $manquants[] = "#{$id}.{$nom}"; }
			elseif ((string)$repli[$id][$nom] !== (string)$valeur) { $manquants[] = "#{$id}.{$nom} (valeur)"; }
		}
	}
	est_vrai(!sizeof($manquants), 'champs absents ou différents dans le repli : ' . join(', ', array_slice($manquants, 0, 5)));
});

titre('L\'index produit');

/**
 * Réindexe l'échantillon comme le fait tools/reindexer.php, et rend les documents écrits.
 */
function indexer_et_lire(array $ids, int $table_num, Db $db): array {
	$indexeur = new SearchIndexer($db);
	$instance = Datamodel::getInstanceByTableName('ca_objects', true);
	$element_ids = method_exists($instance, 'getApplicableElementCodes')
		? array_keys($instance->getApplicableElementCodes(null, false, false))
		: null;

	$field_data = [];
	foreach ($ids as $i => $id) {
		if (!($i % 100)) {
			$tranche = array_slice($ids, $i, 100);
			if ($element_ids) { ca_attributes::prefetchAttributes($db, $table_num, $tranche, $element_ids); }
			$field_data = donnees_de_champs($indexeur, 'ca_objects', $tranche, $db);
			SearchResult::clearCaches();
		}
		$indexeur->indexRow($table_num, $id, $field_data[$id] ?? [], true);
	}
	vider_tampon_moteur(SearchBase::newSearchEngine());

	$moteur = moteur();
	$client = $moteur->getClient();
	$index  = $moteur->getSchema()->indexName('ca_objects');
	$client->updateSettings($index, ['displayedAttributes' => ['*']]);

	$documents = [];
	foreach ($ids as $id) {
		if ($doc = $client->getDocument($index, $id)) {
			foreach ($doc as $k => $v) { if (is_array($v)) { sort($v); $doc[$k] = $v; } }
			ksort($doc);
			$documents[$id] = $doc;
		}
	}
	$client->updateSettings($index, ['displayedAttributes' => ['id']]);
	return $documents;
}

verifier('le repli écrit le même index que le fork', function () use ($ids, $table_num, $db) {
	putenv('CA_SANS_FIELD_DATA_FORK');
	$avec_fork = indexer_et_lire($ids, $table_num, $db);

	putenv('CA_SANS_FIELD_DATA_FORK=1');
	$avec_repli = indexer_et_lire($ids, $table_num, $db);
	putenv('CA_SANS_FIELD_DATA_FORK');

	est_egal(sizeof($ids), sizeof($avec_fork), 'tous les objets n\'ont pas été indexés');

	$differents = [];
	foreach ($avec_fork as $id => $doc) {
		if (($avec_repli[$id] ?? null) != $doc) { $differents[] = '#' . $id; }
		if (sizeof($differents) >= 5) { break; }
	}
	est_vrai(!sizeof($differents), 'documents différents selon le chemin : ' . join(', ', $differents));
});

exit(bilan());
