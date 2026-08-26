<?php
/* ---------------------------------------------------------------------------------------------
 * msearch_BaseBrowse.php — le point d'accroche du connecteur dans le Browse, **sans patcher
 * le cœur de CollectiveAccess**.
 *
 * Ce fichier remplace `app/lib/Browse/BaseBrowse.php` par lien symbolique (voir `installer.sh`).
 * L'original est conservé à côté sous `BaseBrowse.php.avant-meilisearch` ; `./installer.sh
 * <racine> --retirer` le remet en place.
 *
 * ── Pourquoi ici, et pas dans BrowseEngine ─────────────────────────────────────────────────────
 *
 * La chaîne du Browse est : `ObjectBrowse` (51 lignes) → `BaseBrowse` (50 lignes) →
 * `BrowseEngine` (8 516 lignes). Toutes les classes de browse — objets, entités, lieux… —
 * passent par `BaseBrowse`, qui ne fait rien d'autre que déléguer à son parent : c'est une
 * classe-crochet, manifestement prévue pour être étendue.
 *
 * La première version du connecteur insérait ses 36 lignes **dans** `BrowseEngine::getFacetContent()`,
 * ce qui imposait un fork du socle et un outil (`patcher-browse.php`) pour poser et retirer le
 * patch. Un patch mêlé à 8 516 lignes devient invisible à la première montée de version. Ici,
 * tout ce qui appartient au connecteur est dans un fichier qui porte son nom, et le cœur reste
 * intact.
 *
 * ── La règle, la même qu'ailleurs : traduire exactement ou décliner ─────────────────────────────
 *
 * `getFacetContent()` du socle exécute une série de contrôles avant de calculer quoi que ce soit :
 * ACL, existence de la facette, restrictions de type et de source propres à la facette, accès
 * forcé. Le patch d'origine s'insérait *après* eux et en héritait donc gratuitement. Une
 * surcharge, elle, intercepte *avant*.
 *
 * On ne les reproduit pas — les reproduire, c'est risquer de contourner un contrôle d'accès au
 * premier changement du socle. On **décline** dès que l'un d'eux s'applique, et le calcul SQL
 * du socle reprend la main, contrôles compris. Les options en cause (`type_restrictions`,
 * `source_restrictions`, `force_access`, `returnFullFacet`) sont rares ; le coût est nul dans le
 * cas courant et la sûreté est acquise dans tous les autres.
 *
 * ── Étape suivante, prévue ─────────────────────────────────────────────────────────────────────
 *
 * `execute()` est surchargée ici à l'identique, prête à recevoir la délégation du **jeu de
 * résultats** — la « couche 3 » du brief. Mesure faite le 21 août sur le fonds CIPAR : la
 * machinerie du browse ne coûte que 27 à 349 ms, tout le reste étant la recherche elle-même.
 * Déléguer `execute()` tel quel ne gagnerait donc presque rien. Ce qui vaudrait le coup est
 * ailleurs : **ne plus rapatrier tous les identifiants**. Un browse affiche vingt fiches et nous
 * en ramenons 185 253 ; cette liste ne servait qu'aux facettes — déléguées désormais — et au tri
 * avec la pagination. C'est ce dernier point qui reste, et il suppose de changer le contrat entre
 * le socle et son moteur.
 * --------------------------------------------------------------------------------------------- */

include_once(__CA_LIB_DIR__ . "/Browse/BrowseEngine.php");

class BaseBrowse extends BrowseEngine {
	/**
	 * Le moteur configuré, s'il sait calculer des facettes — `false` s'il ne sait pas, pour ne
	 * pas le redemander à chaque facette d'une même page.
	 *
	 * @var \WLPlugSearchEngine|false|null
	 */
	static private $msearch_engine = null;

	# -------------------------------------------------------
	public function __construct($subject_table_name_or_num, ?string $browse_id=null, ?string $context=null) {
		parent::__construct($subject_table_name_or_num, $browse_id, $context);
	}

	# -------------------------------------------------------
	/**
	 * Le jeu de résultats du browse. Inchangé pour l'instant — voir l'entête.
	 */
	public function execute($options=null) {
		return parent::execute($options);
	}

	# -------------------------------------------------------
	/**
	 * Contenu d'une facette : au moteur quand il sait la rendre, au SQL du socle sinon.
	 */
	public function getFacetContent($ps_facet_name, $pa_options=null) {
		$delegue = $this->msearchFacetContent($ps_facet_name, $pa_options);
		if ($delegue !== null) { return $delegue; }

		return parent::getFacetContent($ps_facet_name, $pa_options);
	}

	# -------------------------------------------------------
	/**
	 * Tente la délégation. Rend `null` pour laisser le socle faire — c'est le cas par défaut, et
	 * le seul comportement sûr dès qu'un doute existe.
	 *
	 * @return array|bool|null
	 */
	private function msearchFacetContent(string $facet_name, $options) {
		// Opt-in explicite : tant que la délégation des facettes n'est pas validée sur le
		// profil de l'instance (voir mesures/RETOUR-130-32.md — 21 facettes divergentes au
		// premier rejeu), elle ne s'active que si CA_MSEARCH_FACETS=1 est posée dans
		// l'environnement. Sans elle, le SQL du socle garde la main partout.
		if (getenv('CA_MSEARCH_FACETS') !== '1') { return null; }
		$moteur = self::msearchEngine();
		if (!$moteur) { return null; }

		if (!is_array($options)) { $options = []; }

		// `returnFullFacet` demande la facette *hors* critères courants ; le pont applique
		// toujours l'état du browse. Non traduisible.
		if (caGetOption('returnFullFacet', $options, false)) { return null; }

		if (!is_array($this->opa_browse_settings)) { return null; }
		$facettes = $this->getInfoForFacets();
		if (!isset($facettes[$facet_name])) { return null; }

		$info = $this->getInfoForFacet($facet_name);
		if (!is_array($info)) { return null; }

		// Les contrôles que le socle fait avant son calcul, et que nous n'entreprenons pas de
		// refaire — voir l'entête. Chacun peut vider la facette ou en changer l'accès ; les
		// reproduire ici serait les dupliquer, donc les laisser diverger un jour.
		if (!empty($info['type_restrictions']) && $this->getTypeRestrictionList())     { return null; }
		if (!empty($info['source_restrictions']) && $this->getSourceRestrictionList()) { return null; }
		if (isset($info['force_access']) && is_array($info['force_access']))           { return null; }

		// ACL par enregistrement : hors périmètre du pont, et le socle retire lui-même
		// `checkAccess` dans ce cas — deux raisons de lui rendre la main.
		if (function_exists('caACLIsEnabled')) {
			$t_subject = $this->getSubjectInstance();
			if ($t_subject && caACLIsEnabled($t_subject, ['forPawtucket' => true])) { return null; }
		}

		return $moteur->getBrowseFacetContent($this, $facet_name, $info, $options);
	}

	# -------------------------------------------------------
	/**
	 * Le moteur de recherche configuré, s'il sait rendre des facettes.
	 *
	 * Résolu une fois par processus : une page de browse demande une dizaine de facettes, et
	 * instancier le moteur à chaque fois coûterait plus que la délégation ne rapporte.
	 */
	static private function msearchEngine() {
		if (self::$msearch_engine === null) {
			self::$msearch_engine = false;
			try {
				require_once(__CA_LIB_DIR__ . '/Search/SearchBase.php');
				$moteur = SearchBase::newSearchEngine();
				if ($moteur && method_exists($moteur, 'getBrowseFacetContent')) {
					self::$msearch_engine = $moteur;
				}
			} catch (Exception $e) {
				// Moteur injoignable ou mal configuré : le Browse doit rester utilisable.
				self::$msearch_engine = false;
			}
		}
		return self::$msearch_engine ?: null;
	}
	# -------------------------------------------------------
}
