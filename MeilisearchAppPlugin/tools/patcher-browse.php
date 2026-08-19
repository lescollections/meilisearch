<?php
/* ----------------------------------------------------------------------
 * tools/patcher-browse.php — poser la délégation des facettes dans BrowseEngine.
 *
 * Le connecteur sait calculer les facettes du Browse, mais il ne peut pas se faire appeler
 * tout seul : `BrowseEngine` n'a ni hook, ni greffon, ni classe substituable — 8 480 lignes et
 * `caGetBrowseInstance()` en dur. Il faut donc trente-six lignes dans le socle, et c'est ce que
 * pose ce script.
 *
 * Il existe parce que les instances ne tirent pas toutes d'un dépôt qu'on patche : sur une
 * instance qui suit `collectiveaccess/providence`, on ne peut pas demander de changer de fork
 * pour éprouver un connecteur. Les branches `feature/meilisearch-browse-facets` des deux socles
 * portent exactement la même chose, à l'octet près ; ce script est leur équivalent pour les
 * autres.
 *
 * Ce qu'il pose est inerte tant que le moteur configuré n'est pas Meilisearch : la délégation
 * interroge le moteur *sélectionné*, et SqlSearch2 ne porte pas getBrowseFacetContent().
 *
 * Usage :
 *   php patcher-browse.php <chemin de BrowseEngine.php>            → pose
 *   php patcher-browse.php <chemin> --verifier                     → dit seulement s'il est posé
 *
 * Rend 0 si le fichier porte le patch en sortant, 1 sinon. Idempotent.
 * ---------------------------------------------------------------------- */

if (php_sapi_name() !== 'cli') { die("À lancer en ligne de commande.\n"); }

$chemin   = $argv[1] ?? null;
$verifier = in_array('--verifier', $argv, true);

if (!$chemin) {
	fwrite(STDERR, "Usage : php patcher-browse.php <chemin de BrowseEngine.php> [--verifier]\n");
	exit(2);
}
if (!is_file($chemin)) {
	fwrite(STDERR, "Introuvable : {$chemin}\n");
	exit(2);
}

$source = file_get_contents($chemin);

if (strpos($source, 'getBrowseFacetContent') !== false) {
	echo "Le patch est déjà posé.\n";
	exit(0);
}
if ($verifier) {
	echo "Le patch n'est pas posé.\n";
	exit(1);
}

/*
 * Trois insertions, chacune ancrée sur un fragment qui doit se trouver une fois et une seule.
 * Une ancre absente ou en double fait échouer le script sans rien écrire : mieux vaut un patch
 * qui refuse de se poser qu'un patch posé au mauvais endroit.
 */
$insertions = [
	// 1. La propriété qui retient le moteur, résolu une fois par requête.
	[
		'ancre' => "	private \$opb_dont_expand_type_restrictions = false;\n	private \$opb_dont_expand_source_restrictions = false;\n",
		'apres' => "
	/**
	 * @var Search engine plugin instance used to delegate facet content computation, if the
	 *		configured engine supports it (implements getBrowseFacetContent()); false when the
	 *		engine does not support delegation; null when not yet resolved.
	 */
	static private \$s_facet_engine = null;
",
	],

	// 2. La résolution du moteur, juste avant getFacetContent().
	[
		'ancre' => "	public function getFacetContent(\$ps_facet_name, \$pa_options=null) {\n		global \$AUTH_CURRENT_USER_ID;",
		'avant' => "	/**
	 * Return the configured search engine plugin when it can compute browse facet content
	 * (implements getBrowseFacetContent()), null otherwise. Resolved once per request.
	 *
	 * @return WLPlugSearchEngine|null
	 */
	static private function getFacetEngine() {
		if (BrowseEngine::\$s_facet_engine === null) {
			require_once(__CA_LIB_DIR__.'/Search/SearchBase.php');
			try {
				\$o_engine = SearchBase::newSearchEngine();
			} catch (Exception \$e) {
				\$o_engine = null;
			}
			BrowseEngine::\$s_facet_engine = (\$o_engine && method_exists(\$o_engine, 'getBrowseFacetContent')) ? \$o_engine : false;
		}
		return BrowseEngine::\$s_facet_engine ? BrowseEngine::\$s_facet_engine : null;
	}
	# ------------------------------------------------------
",
	],

	// 3. La délégation elle-même, en tête du calcul, avant que le SQL ne s'engage.
	[
		'ancre' => "		// Values to exclude from list attributes and authorities; can be idnos or ids\n		\$va_exclude_values = caGetOption('exclude_values', \$va_facet_info, array(), array('castTo' => 'array'));",
		'avant' => "		// Delegate facet computation to the configured search engine when it supports it
		// (eg. Meilisearch computes facet counts with facetDistribution). The delegate returns
		// null to decline — untranslatable facet type, option or browse state — in which case
		// the SQL-based computation below runs unchanged. returnFullFacet is not delegated:
		// the delegate always applies current browse criteria.
		if ((\$o_facet_engine = BrowseEngine::getFacetEngine()) && !caGetOption('returnFullFacet', \$pa_options, false)) {
			\$vm_delegated_facet = \$o_facet_engine->getBrowseFacetContent(\$this, \$ps_facet_name, \$va_facet_info, \$pa_options);
			if (!is_null(\$vm_delegated_facet)) { return \$vm_delegated_facet; }
		}

",
	],
];

foreach ($insertions as $n => $insertion) {
	$ancre = $insertion['ancre'];
	$vues  = substr_count($source, $ancre);

	if ($vues !== 1) {
		fwrite(STDERR, sprintf(
			"Ancre %d introuvable ou ambiguë (%d occurrence(s)) — ce BrowseEngine n'est pas celui\n" .
			"qu'attend le patch. Rien n'a été écrit ; poser les trente-six lignes à la main, ou\n" .
			"comparer avec la branche feature/meilisearch-browse-facets du socle.\n",
			$n + 1, $vues
		));
		exit(1);
	}

	$remplacement = isset($insertion['avant'])
		? $insertion['avant'] . $ancre
		: $ancre . $insertion['apres'];

	$source = str_replace($ancre, $remplacement, $source);
}

// On n'écrit qu'après avoir vérifié que le résultat tient debout : un socle à la syntaxe
// cassée, c'est le site entier qui tombe, et le Browse est chargé partout.
$temporaire = $chemin . '.patch-en-cours';
file_put_contents($temporaire, $source);

exec(sprintf('php -l %s 2>&1', escapeshellarg($temporaire)), $sortie, $code);
if ($code !== 0) {
	unlink($temporaire);
	fwrite(STDERR, "Le fichier patché ne compile pas — rien n'a été écrit :\n" . join("\n", $sortie) . "\n");
	exit(1);
}

// Les droits du fichier d'origine, sinon le serveur web perd la main dessus.
$stat = stat($chemin);
chmod($temporaire, $stat['mode'] & 0777);
@chown($temporaire, $stat['uid']);
@chgrp($temporaire, $stat['gid']);

rename($temporaire, $chemin);

echo "Patch posé (36 lignes) et vérifié.\n";
exit(0);
