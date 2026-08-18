<?php
/* ----------------------------------------------------------------------
 * tests/recherche-booleens.php — les opérateurs rendent-ils ce qu'ils promettent ?
 *
 *     docker compose exec app dev-test recherche-booleens
 *
 * Un moteur qui rend « des » résultats n'a rien prouvé. Ce qui se vérifie sans référence
 * extérieure, ce sont les identités : `a AND b` doit rendre exactement l'intersection de `a` et
 * de `b`, `a OR b` leur union, `a -b` leur différence. Les ensembles de gauche sont mesurés par
 * des recherches simples, dont les autres tests montrent qu'elles fonctionnent.
 *
 * Ces vérifications protègent trois corrections que rien d'autre ne retient :
 *
 *   – le `OR` rendait l'intersection, parce que les termes de même portée partaient dans un
 *     seul `q` avec `matchingStrategy: all` ;
 *   – les parenthèses étaient perdues, l'arbre Lucene étant aplati en liste de clauses ;
 *   – une recherche qui n'est qu'une exclusion rendait zéro au lieu du complémentaire.
 *
 * On n'y vérifie que la syntaxe que le socle transmet intacte au connecteur. `NOT` et `!` en
 * sont exclus à dessein : le parseur de CollectiveAccess vide l'expression avant qu'elle
 * n'arrive, et le connecteur n'y peut rien — c'est noté dans CLAUDE.md, ce n'est pas un test à
 * faire échouer ici.
 * ---------------------------------------------------------------------- */

require_once(__DIR__ . '/_cadre.php');

list($a, $ids_a, $b, $ids_b, $c) = mots_de_reference();

$intersection = array_values(array_intersect($ids_a, $ids_b));
$union        = array_values(array_unique(array_merge($ids_a, $ids_b)));
$difference   = array_values(array_diff($ids_a, $ids_b));
sort($intersection); sort($union); sort($difference);

printf("  \033[90mA = « %s » (%d fiches), B = « %s » (%d) — A∩B = %d, A∪B = %d, A\\B = %d\033[0m\n",
	$a, sizeof($ids_a), $b, sizeof($ids_b), sizeof($intersection), sizeof($union), sizeof($difference));

function trie(array $ids): array { sort($ids); return $ids; }

titre('Les identités booléennes');

verifier('AND rend l\'intersection', function () use ($a, $b, $intersection) {
	est_egal($intersection, trie(chercher_objets("{$a} AND {$b}")));
});

verifier('OR rend l\'union, et non l\'intersection', function () use ($a, $b, $union, $intersection) {
	$obtenu = trie(chercher_objets("{$a} OR {$b}"));
	est_vrai($obtenu !== $intersection || $union === $intersection,
		'l\'union est rendue comme une intersection — les termes facultatifs sont mis en commun dans un seul q');
	est_egal($union, $obtenu);
});

verifier('le tiret retranche', function () use ($a, $b, $difference) {
	est_egal($difference, trie(chercher_objets("{$a} -{$b}")));
});

verifier('AND NOT retranche pareil', function () use ($a, $b, $difference) {
	est_egal($difference, trie(chercher_objets("{$a} AND NOT {$b}")));
});

titre('La structure des parenthèses');

verifier('(A OR B) AND C se distingue de A OR (B AND C)', function () use ($a, $b, $c, $union) {
	$ids_c   = chercher_objets($c);
	$attendu = trie(array_values(array_intersect($union, $ids_c)));
	est_egal($attendu, trie(chercher_objets("({$a} OR {$b}) AND {$c}")),
		'les parenthèses doivent être respectées : l\'arbre ne peut pas être aplati');
});

verifier('A OR (B AND C) n\'est pas la même chose', function () use ($a, $b, $c, $ids_a, $ids_b) {
	$ids_c   = chercher_objets($c);
	$attendu = trie(array_values(array_unique(array_merge($ids_a, array_intersect($ids_b, $ids_c)))));
	est_egal($attendu, trie(chercher_objets("{$a} OR ({$b} AND {$c})")));
});

titre('Ce qui n\'est qu\'une exclusion');

verifier('une exclusion seule rend le complémentaire, pas le vide', function () use ($b, $ids_b) {
	$obtenu = chercher_objets("-{$b}");
	est_vrai(sizeof($obtenu) > 0, '« tout sauf ceci » a rendu zéro résultat');
	est_egal(nombre_objets() - sizeof($ids_b), sizeof($obtenu));
	est_vrai(!sizeof(array_intersect($obtenu, $ids_b)), 'le complémentaire contient des fiches exclues');
});

titre('La portée d\'un champ');

verifier('OR sur un champ qualifié reste une union', function () use ($a, $b) {
	$sur_a = chercher_objets("ca_object_labels.name:{$a}");
	$sur_b = chercher_objets("ca_object_labels.name:{$b}");
	$attendu = trie(array_values(array_unique(array_merge($sur_a, $sur_b))));
	est_egal($attendu, trie(chercher_objets("ca_object_labels.name:{$a} OR ca_object_labels.name:{$b}")));
});

verifier('une recherche sur un champ non indexé le fait savoir', function () {
	$journal = Meilisearch\Log::path();
	// clearstatcache() : PHP garde en cache la taille d'un fichier déjà interrogé, et la
	// deuxième mesure rendrait la première — le journal paraîtrait n'avoir rien reçu.
	clearstatcache(true, $journal);
	$avant = file_exists($journal) ? filesize($journal) : 0;

	$ids = chercher_objets('ca_objects.lot_id:1');
	est_egal(0, sizeof($ids), 'un champ non indexé ne peut rien ramener');

	$nouveau = '';
	clearstatcache(true, $journal);
	if (file_exists($journal) && filesize($journal) > $avant) {
		$f = fopen($journal, 'r'); fseek($f, $avant); $nouveau = (string)stream_get_contents($f); fclose($f);
	}
	est_vrai(strpos($nouveau, 'aucun document ne porte cet attribut') !== false,
		'zéro résultat sans un mot au journal se lit « il n\'y a rien de tel » au lieu de « ce champ n\'est pas indexé »'
		. ' — journal ' . $journal . ', ' . strlen($nouveau) . ' octet(s) ajouté(s) : ' . trim(mb_strimwidth($nouveau, 0, 200, '…')));
});

exit(bilan());
