<?php
/* ----------------------------------------------------------------------
 * tests/reindexation-parallele.php — le réindexeur parallèle rend-il le même index ?
 *
 *     docker compose exec app dev-test reindexation-parallele
 *
 * Un outil qui réindexe plus vite n'a d'intérêt que s'il réindexe pareil. On compare donc le
 * résultat de `caUtils rebuild-search-index` à celui de tools/reindexer.php, document par
 * document — pas seulement le décompte, qui ne dirait rien du contenu.
 *
 * Le test réindexe deux fois de suite ; comptez une poignée de secondes.
 * ---------------------------------------------------------------------- */

require_once(__DIR__ . '/_cadre.php');

$moteur = moteur();
$client = $moteur->getClient();
$index  = $moteur->getSchema()->indexName('ca_objects');

/**
 * Met un document sous une forme comparable : les tableaux de valeurs n'ont pas d'ordre
 * significatif, deux indexations également correctes peuvent les produire dans un autre ordre.
 */
function normaliser_document(array $doc): array {
	array_walk_recursive($doc, function (&$v) { $v = is_scalar($v) ? (string)$v : $v; });
	foreach ($doc as $k => $v) { if (is_array($v)) { sort($v); $doc[$k] = $v; } }
	ksort($doc);
	return $doc;
}

/**
 * Empreinte de l'index : une somme de contrôle par document, pas les documents eux-mêmes.
 *
 * Garder les documents entiers marchait à 2500 fiches et fait mourir le test à 70 000, mémoire
 * épuisée. Une empreinte pèse 32 octets ; le document, plusieurs kilo-octets. On perd le détail
 * des champs, qu'on va rechercher dans le moteur pour les rares documents qui diffèrent.
 *
 * `displayedAttributes` est restreint à l'identifiant pour les recherches ; on le rouvre le
 * temps de la lecture, puis on le remet — sinon on ne compare que des identifiants, ce qui ne
 * prouverait rien du contenu indexé.
 */
function empreinte_index($client, string $index): array {
	$client->updateSettings($index, ['displayedAttributes' => ['*']]);

	$empreintes = [];
	$offset     = 0;
	do {
		$r = $client->getDocuments($index, 1000, $offset);
		foreach (($r['results'] ?? []) as $doc) {
			$normalise = normaliser_document($doc);
			// Deux empreintes et non une : celle du document entier dit *qu'il* diffère, celle
			// des seuls noms de champs dit *en quoi* — un champ absent ou un champ en trop n'a
			// pas la même cause qu'une valeur qui a changé. Sans cette distinction, le message
			// d'échec ne peut nommer que les premiers attributs du document, ce qui n'oriente
			// rien et fait perdre le diagnostic (constaté).
			$champs = array_keys($normalise);
			sort($champs);
			$empreintes[$doc['id']] = ['tout' => md5(json_encode($normalise)), 'champs' => md5(join('|', $champs))];
		}
		$offset += 1000;
	} while (sizeof($r['results'] ?? []) === 1000);

	$client->updateSettings($index, ['displayedAttributes' => ['id']]);

	ksort($empreintes);
	return $empreintes;
}

/**
 * Relit quelques documents entiers, pour dire *ce qui* diffère une fois qu'on sait *lesquels*.
 */
function documents_par_id($client, string $index, array $ids): array {
	$client->updateSettings($index, ['displayedAttributes' => ['*']]);
	$documents = [];
	foreach ($ids as $id) {
		if ($doc = $client->getDocument($index, $id)) { $documents[$id] = normaliser_document($doc); }
	}
	$client->updateSettings($index, ['displayedAttributes' => ['id']]);
	return $documents;
}

function chrono(callable $f): float {
	$t = microtime(true);
	$f();
	return microtime(true) - $t;
}

titre('Réindexation par le socle');

$reference = [];
$duree_socle = chrono(function () use (&$reference, $client, $index) {
	exec('cd /var/www/html && ./support/bin/caUtils rebuild-search-index --tables=ca_objects 2>&1', $sortie, $code);
	if ($code !== 0) { throw new EchecAssertion("rebuild-search-index a échoué : " . join("\n", array_slice($sortie, -3))); }
});
$reference = empreinte_index($client, $index);

printf("  \033[90m%d documents en %.1f s\033[0m\n", sizeof($reference), $duree_socle);

verifier('le socle indexe tous les objets', function () use ($reference) {
	est_egal(nombre_objets(), sizeof($reference));
});

titre('Réindexation par tools/reindexer.php');

$duree_parallele = chrono(function () {
	exec('cd /var/www/html && php app/plugins/Meilisearch/tools/reindexer.php --tables=ca_objects --processus=4 --silencieux 2>&1', $sortie, $code);
	if ($code !== 0) { throw new EchecAssertion("le réindexeur a échoué : " . join("\n", array_slice($sortie, -5))); }
});
$parallele = empreinte_index($client, $index);

printf("  \033[90m%d documents en %.1f s — %.1f× plus rapide\033[0m\n",
	sizeof($parallele), $duree_parallele, $duree_socle / max($duree_parallele, 0.001));

verifier('le même nombre de documents', function () use ($reference, $parallele) {
	est_egal(sizeof($reference), sizeof($parallele));
});

verifier('les mêmes identifiants', function () use ($reference, $parallele) {
	$manquants = array_diff(array_keys($reference), array_keys($parallele));
	$en_trop   = array_diff(array_keys($parallele), array_keys($reference));
	est_vrai(!sizeof($manquants), sizeof($manquants) . ' document(s) manquant(s) : ' . join(', ', array_slice($manquants, 0, 5)));
	est_vrai(!sizeof($en_trop), sizeof($en_trop) . ' document(s) en trop : ' . join(', ', array_slice($en_trop, 0, 5)));
});

verifier('le même contenu, document par document', function () use ($reference, $parallele, $client, $index) {
	$divergents = [];
	$par_champs = 0;
	foreach ($reference as $id => $empreinte) {
		if (!isset($parallele[$id])) { continue; }
		if ($empreinte['tout'] === $parallele[$id]['tout']) { continue; }
		$divergents[$id] = ($empreinte['champs'] !== $parallele[$id]['champs']) ? 'jeu de champs' : 'valeurs';
		if ($empreinte['champs'] !== $parallele[$id]['champs']) { $par_champs++; }
		if (sizeof($divergents) >= 5) { break; }
	}

	$details = [];
	foreach ($divergents as $id => $nature) { $details[] = "#{$id} ({$nature})"; }

	est_vrai(!sizeof($divergents), sprintf(
		'%d document(s) différent(s), dont %d par le jeu de champs : %s',
		sizeof($divergents), $par_champs, join(' ; ', $details)));
});

titre('La recherche donne les mêmes résultats');

verifier('un titre retrouve le même objet après indexation parallèle', function () {
	$temoin = objet_temoin();
	contient($temoin['object_id'], chercher_objets('"' . expression_titre($temoin['titre']) . '"'));
});

verifier('un numéro d\'inventaire ne ramène rien d\'étranger', function () {
	$temoin = objet_temoin();
	$ids = chercher_objets('ca_objects.idno:"' . $temoin['idno'] . '"');
	sort($ids);
	est_egal(serie_du_numero($temoin['idno']), $ids);
});

exit(bilan());
