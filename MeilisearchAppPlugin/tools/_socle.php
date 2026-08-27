<?php
/* ----------------------------------------------------------------------
 * tools/_socle.php — ce que les outils demandent au socle, et qui n'est pas garanti.
 *
 * Les outils du greffon tournent sur des instances qui ne partagent pas toutes la même version
 * de CollectiveAccess : le fork `lescollections/providence` du harnais, mais aussi des socles
 * plus anciens ou simplement différents. Tout ce qui n'appartient pas à une interface publique
 * doit donc être demandé avec précaution, et remplacé quand il manque.
 *
 * Un chargement direct par `require_once` depuis chaque outil : ces fichiers sont atteints par
 * un lien symbolique, l'autoloader du socle ne les voit pas.
 * ---------------------------------------------------------------------- */

/**
 * Les valeurs des champs intrinsèques d'un lot d'enregistrements, sous la forme
 * `[row_id => [champ => valeur]]` attendue par `SearchIndexer::indexRow()`.
 *
 * `getFieldDataForReindex()` n'existe que dans le fork lescollections. Ailleurs, l'appeler tue
 * la réindexation dès la première tranche — vu sur préprod-130.32 :
 * « Call to undefined method SearchIndexer::getFieldDataForReindex() ». On la reproduit alors
 * en lisant la table directement.
 *
 * Le repli lit toutes les colonnes plutôt que les seules colonnes indexées : sans
 * `getFieldsToIndex(…, ['intrinsicOnly' => true])` — option elle aussi propre au fork — on ne
 * sait pas lesquelles retenir, et un sur-ensemble est sans danger. `indexRow()` ne prend que
 * ce qui le concerne ; c'est du trafic SQL en plus, pas un index différent.
 *
 * @param SearchIndexer $indexeur
 * @param string        $table    nom de la table (« ca_objects »)
 * @param array         $ids      identifiants de la tranche
 */
function donnees_de_champs($indexeur, string $table, array $ids, ?Db $db = null): array {
	if (!sizeof($ids)) { return []; }

	// CA_SANS_FIELD_DATA_FORK=1 force le repli sur une instance qui a pourtant la méthode :
	// c'est le seul moyen d'éprouver ce chemin là où il ne s'exécuterait jamais.
	$forcer = (getenv('CA_SANS_FIELD_DATA_FORK') === '1');

	if (!$forcer && method_exists($indexeur, 'getFieldDataForReindex')) {
		return $indexeur->getFieldDataForReindex($table, $ids);
	}

	static $prevenu = false;
	if (!$prevenu) {
		$prevenu = true;
		// STDERR n'existe qu'en CLI ; sous FPM (la compensation du greffon passe ici),
		// écrire dessus est une erreur fatale — silencieusement, alors.
		if (defined('STDERR')) {
			fwrite(STDERR, "  [socle] getFieldDataForReindex() absente : lecture directe de {$table}.\n");
		}
	}

	$pk = Datamodel::primaryKey($table);
	if (!$pk) { return []; }

	$qr = ($db ?: new Db())->query("SELECT * FROM {$table} WHERE {$pk} IN (?)", [$ids]);

	$donnees = [];
	while ($qr->nextRow()) {
		$donnees[(int)$qr->get($pk)] = $qr->getRow();
	}
	return $donnees;
}

/**
 * Vide le tampon d'écriture du moteur.
 *
 * `flushContentBuffer()` ne figure pas dans `IWLPlugSearchEngine` — SqlSearch2, ElasticSearch et
 * le connecteur Meilisearch la portent, mais rien ne l'impose. Un outil qui l'appelle sans
 * précaution meurt *après* avoir tout indexé, ce qui rend le symptôme trompeur.
 */
function vider_tampon_moteur($moteur): void {
	if (method_exists($moteur, 'flushContentBuffer')) { $moteur->flushContentBuffer(); }
	elseif (method_exists($moteur, 'flush'))          { $moteur->flush(); }
}
