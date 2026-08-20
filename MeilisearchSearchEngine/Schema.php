<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/Schema.php — nommage des index et des champs.
 *
 * Un index Meilisearch par table sujet, comme le fait le connecteur ElasticSearch : les
 * résultats de CollectiveAccess sont toujours des identifiants d'une table donnée, et un index
 * par table évite d'avoir à distinguer les documents à la lecture.
 *
 * Les noms de champs de CollectiveAccess (`ca_objects/idno`, `ca_object_labels/name`) portent
 * des barres obliques. Meilisearch les accepte dans un document JSON, mais son langage de
 * filtre et ses listes d'attributs (`searchableAttributes`, `filterableAttributes`) réclament
 * des noms en [A-Za-z0-9_-]. On normalise donc une fois pour toutes, dans un seul sens :
 *
 *     ca_objects/idno        →  ca_objects__idno
 *     ca_objects.access      →  ca_objects__access
 *     ca_objects/idno|part_of →  ca_objects__idno__part_of
 *
 * La transformation n'est pas réversible (on ne peut pas savoir où était la barre), et n'a pas
 * besoin de l'être : rien ne relit un nom de champ depuis l'index.
 * ---------------------------------------------------------------------- */

namespace Meilisearch;

class Schema {
	/** Champ portant l'identifiant de l'enregistrement — clé primaire des documents. */
	const PK = 'id';

	/** Champ technique : nom de la table sujet, pratique au diagnostic. */
	const TABLE = 'ca_table';

	/**
	 * Préfixe des attributs de facette : identifiants des enregistrements liés, rangés par
	 * table (`facet__ca_entities`) et, quand le lien est typé, par type de relation
	 * (`facet__ca_entities__artist`). Ce sont ces attributs que le Browse filtre et compte —
	 * les attributs texte servent à chercher, ceux-ci servent à naviguer.
	 */
	const FACET = 'facet__';

	/**
	 * Préfixe des attributs de facette *directs* : les seuls enregistrements effectivement
	 * liés, sans leurs ancêtres. N'existe que pour les tables hiérarchiques, où le Browse
	 * traite différemment un lien direct et un nœud présent par simple ascendance — un
	 * ancêtre sans type ni accès est écarté, un lien direct est conservé. Préfixe distinct
	 * de FACET : un type de relation nommé « direct » ne doit pas entrer en collision.
	 */
	const FACET_DIRECT = 'facet_direct__';

	/**
	 * Préfixe des tranches de dates : `facet_date__creation_date__years` porte toutes les
	 * années qu'un intervalle recouvre, `…__decades` toutes ses décennies.
	 *
	 * Le socle éclate chaque intervalle en ses tranches puis dédoublonne par fiche ; une
	 * distribution sur cet attribut rend donc exactement le même compte, la déduplication
	 * étant acquise — Meilisearch compte des documents. Et comme un critère de date s'applique
	 * en recouvrement d'intervalles, l'égalité sur une tranche sélectionne les mêmes fiches.
	 */
	const FACET_DATE = 'facet_date__';

	/**
	 * Préfixe des valeurs d'attribut à facetter : `facet_attr__materiau` porte les valeurs
	 * telles qu'elles sont en base, et rien d'autre.
	 *
	 * L'attribut texte ordinaire ne pouvait pas servir. Il porte bien les valeurs, mais aussi
	 * les variantes que l'indexeur fabrique pour la recherche : au CIPAR, « béton » y voisine
	 * avec « beton », « Bois et skaï » avec « bois et skai ». Ces fantômes s'ajoutent à la
	 * facette comme autant de postes que le SQL n'a pas, et faussent les comptes de ceux qu'il a.
	 * Chercher et naviguer demandent deux matières différentes — c'est déjà la raison d'être de
	 * FACET pour les enregistrements liés.
	 *
	 * Seuls les éléments qu'une facette `attribute` de browse.conf désigne sont écrits : rien ne
	 * justifie de doubler dans l'index des attributs que personne ne facette.
	 */
	const FACET_ATTR = 'facet_attr__';

	/**
	 * Tables primaires dont les enregistrements liés sont rangés en facettes. La liste est
	 * fermée : tout ce qui n'y figure pas (tables de labels, de relations…) passe par
	 * l'indexation texte ordinaire.
	 */
	const FACET_TABLES = [
		'ca_objects', 'ca_object_lots', 'ca_entities', 'ca_places', 'ca_occurrences',
		'ca_collections', 'ca_storage_locations', 'ca_loans', 'ca_movements',
		'ca_list_items', 'ca_object_representations',
	];

	/**
	 * Intrinsèques de la table sujet dont le Browse a besoin comme filtres transversaux
	 * (accès, type, source, aliénation). Rendus filtrables d'office : un filtre de facette
	 * dont le champ n'est pas filtrable échoue, et un browse aux comptes faux est pire
	 * qu'un browse lent.
	 */
	const FILTER_FIELDS = ['access', 'type_id', 'source_id', 'is_deaccessioned', 'deleted', 'parent_id'];

	/** @var string préfixe des noms d'index */
	private $prefix;

	public function __construct(string $prefix) {
		$this->prefix = $this->sanitize($prefix);
	}

	/**
	 * Nom de l'index d'une table sujet, désignée par son numéro ou son nom.
	 */
	public function indexName($table): string {
		if (is_numeric($table)) { $table = \Datamodel::getTableName((int)$table); }
		if (!$table) { throw new \InvalidArgumentException('Table sujet inconnue'); }
		return $this->prefix . '_' . $this->sanitize($table);
	}

	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Normalise un nom de champ pour Meilisearch.
	 */
	public function fieldName(string $table, string $field): string {
		return $this->sanitize($table) . '__' . $this->sanitize($field);
	}

	/**
	 * Normalise un access point de CollectiveAccess (`ca_objects.idno`, `ca_object_labels/name`)
	 * en nom d'attribut Meilisearch.
	 */
	public function accessPointToFieldName(string $access_point): string {
		return $this->sanitize($access_point);
	}

	/**
	 * Attribut de facette d'une table liée, éventuellement restreint à un type de relation.
	 */
	public function facetAttribute(string $table, ?string $rel_type_code = null): string {
		$attribute = self::FACET . $this->sanitize($table);
		if ($rel_type_code !== null && strlen($rel_type_code)) {
			$attribute .= '__' . $this->sanitize($rel_type_code);
		}
		return $attribute;
	}

	/**
	 * Attribut des liens directs d'une table hiérarchique — mêmes règles de nommage.
	 */
	public function directFacetAttribute(string $table, ?string $rel_type_code = null): string {
		$attribute = self::FACET_DIRECT . $this->sanitize($table);
		if ($rel_type_code !== null && strlen($rel_type_code)) {
			$attribute .= '__' . $this->sanitize($rel_type_code);
		}
		return $attribute;
	}

	/**
	 * Attributs filtrables connus d'avance pour un index : les facettes de chaque table
	 * primaire et les intrinsèques transversaux de la table sujet. Les variantes par type de
	 * relation, imprévisibles, sont ajoutées au fil des versements (voir le connecteur) —
	 * Meilisearch 1.13 ne connaît pas encore les motifs `facet__*`.
	 */
	public function baseFilterableAttributes(string $subject_table): array {
		$attributes = [self::PK, self::TABLE];
		foreach (self::FACET_TABLES as $table) {
			$attributes[] = $this->facetAttribute($table);
			$attributes[] = $this->directFacetAttribute($table);
		}
		foreach (self::FILTER_FIELDS as $field) {
			$attributes[] = $this->fieldName($subject_table, $field);
		}
		foreach ($this->facetedFields($subject_table) as $field) {
			$attributes[] = $this->fieldName($subject_table, $field);
		}
		foreach ($this->dateFacets($subject_table) as $code => $normalisations) {
			foreach ($normalisations as $normalisation) {
				$attributes[] = $this->dateFacetAttribute($code, $normalisation);
			}
		}
		foreach ($this->attributeFacetElements($subject_table) as $code) {
			$attributes[] = $this->attributeFacetAttribute($code);
		}
		return array_values(array_unique($attributes));
	}

	/**
	 * Attribut portant les tranches d'un élément de date, pour une normalisation donnée.
	 */
	public function dateFacetAttribute(string $element_code, string $normalisation): string {
		return self::FACET_DATE . $this->sanitize($element_code) . '__' . $this->sanitize($normalisation);
	}

	/**
	 * Attribut portant les valeurs d'un élément à facetter.
	 */
	public function attributeFacetAttribute(string $element_code): string {
		return self::FACET_ATTR . $this->sanitize($element_code);
	}

	/**
	 * La clé sous laquelle deux graphies d'une même valeur n'en font qu'une.
	 *
	 * Elle imite `utf8mb4_general_ci`, la collation sous laquelle le socle groupe : casse
	 * ignorée, diacritiques retirés. Vérifié sur la base — elle y tient pour égaux « béton » et
	 * « beton », « skaï » et « skai », « chêne » et « chene ».
	 *
	 * C'est cette clé, et non la graphie, qui est écrite dans l'index. Sans quoi une fiche
	 * portant deux graphies du même groupe compterait deux fois dans la facette, là où le
	 * `COUNT(DISTINCT row_id)` du socle ne la compte qu'une. Le libellé à afficher se retrouve
	 * ensuite en base, à la lecture.
	 */
	public function valueKey(string $valeur): string {
		$valeur = trim($valeur);

		// `caRemoveAccents()` d'abord : c'est la translittération du socle, avec sa table
		// explicite. S'aligner sur elle vaut mieux qu'une décomposition Unicode à nous — deux
		// façons de retirer les accents finiraient par diverger sur un caractère de bord.
		if (function_exists('caRemoveAccents')) {
			$valeur = (string)caRemoveAccents($valeur);
		} elseif (class_exists('\Normalizer')) {
			$decompose = \Normalizer::normalize($valeur, \Normalizer::FORM_D);
			if (is_string($decompose)) { $valeur = preg_replace('!\p{Mn}+!u', '', $decompose); }
		}
		return function_exists('mb_strtolower') ? mb_strtolower($valeur, 'UTF-8') : strtolower($valeur);
	}

	/**
	 * Éléments qu'une facette `attribute` de `browse.conf` demande de compter.
	 *
	 * La liste sort de la configuration du client et d'elle seule : un élément que personne ne
	 * facette n'a aucune raison d'être doublé dans l'index. Les facettes que le pont ne saurait
	 * de toute façon pas rendre sont écartées d'emblée, pour la même raison qu'aux dates —
	 * inutile de payer à l'indexation ce qui repartira au SQL.
	 *
	 * @return array codes d'élément
	 */
	public function attributeFacetElements(string $subject_table): array {
		static $cache = [];
		if (isset($cache[$subject_table])) { return $cache[$subject_table]; }

		$codes = [];
		foreach ($this->facetsOf($subject_table) as $facet) {
			if (($facet['type'] ?? null) !== 'attribute') { continue; }
			if (!empty($facet['relative_to']) || !empty($facet['filter'])) { continue; }
			if (empty($facet['element_code'])) { continue; }

			$parts   = explode('.', (string)$facet['element_code']);
			$codes[] = (string)array_pop($parts);
		}
		$codes = array_values(array_unique($codes));
		if (!sizeof($codes) || !class_exists('\Db')) { return $cache[$subject_table] = $codes; }

		// Ne garder que les types que le pont sait rendre (voir Facets::facetElement) : valeur
		// textuelle ou liste. Un élément adossé à une table liée relève d'authority, une date de
		// normalizedDates. Les écarter ici plutôt qu'à la lecture évite de doubler dans l'index des
		// valeurs dont la facette repartira de toute façon au SQL.
		try {
			$qr = (new \Db())->query(
				"SELECT element_code FROM ca_metadata_elements WHERE element_code IN (?) AND datatype IN (1, 3)", [$codes]
			);
			$textuels = [];
			while ($qr->nextRow()) { $textuels[] = (string)$qr->get('element_code'); }
			$codes = array_values(array_intersect($codes, $textuels));
		} catch (\Exception $e) {
			// Base injoignable au moment où l'on prépare l'index : mieux vaut tout écrire que
			// rien, la facette triera à la lecture.
		}
		return $cache[$subject_table] = $codes;
	}

	/**
	 * Les facettes déclarées pour une table dans `browse.conf`, ou un tableau vide.
	 */
	private function facetsOf(string $subject_table): array {
		if (!class_exists('\Configuration')) { return []; }
		try {
			$config   = \Configuration::load(__CA_CONF_DIR__ . '/browse.conf');
			$settings = $config ? $config->getAssoc($subject_table) : null;
		} catch (\Exception $e) {
			return [];
		}
		if (!is_array($settings) || !is_array($settings['facets'] ?? null)) { return []; }
		return array_filter($settings['facets'], 'is_array');
	}

	/**
	 * Éléments de date que `browse.conf` demande de facetter, et sous quelles normalisations.
	 *
	 * Un même élément sert souvent deux facettes — par année et par décennie — d'où la liste.
	 * Les facettes que le pont ne traduit pas de toute façon sont écartées ici : inutile de
	 * payer des tranches à l'indexation pour une facette qui repartira au SQL.
	 *
	 * @return array [code d'élément => [normalisations]]
	 */
	public function dateFacets(string $subject_table): array {
		static $cache = [];
		if (isset($cache[$subject_table])) { return $cache[$subject_table]; }

		if (!class_exists('\Configuration')) { return $cache[$subject_table] = []; }

		try {
			$config = \Configuration::load(__CA_CONF_DIR__ . '/browse.conf');
			$settings = $config ? $config->getAssoc($subject_table) : null;
		} catch (\Exception $e) {
			return $cache[$subject_table] = [];
		}
		if (!is_array($settings) || !is_array($settings['facets'] ?? null)) { return $cache[$subject_table] = []; }

		$out = [];
		foreach ($settings['facets'] as $facet) {
			if (!is_array($facet) || ($facet['type'] ?? null) !== 'normalizedDates') { continue; }
			if (empty($facet['element_code']) || empty($facet['normalization'])) { continue; }

			// Options que le pont ne sait pas traduire : la facette repartira au SQL, ses
			// tranches ne serviraient à rien.
			foreach (['relative_to', 'include_unknown', 'minimum_date', 'maximum_date',
				'treat_before_dates_as_circa', 'treat_after_dates_as_circa', 'single_value'] as $key) {
				if (!empty($facet[$key])) { continue 2; }
			}

			// `creation_date` ou `conteneur.creation_date` : c'est le dernier segment qui
			// nomme l'élément, comme le fait le socle.
			$parts = explode('.', (string)$facet['element_code']);
			$code  = (string)array_pop($parts);
			if (sizeof($parts) > 1) { continue; }   // élément dans un conteneur : au SQL

			$out[$code][] = (string)$facet['normalization'];
		}

		foreach ($out as $code => $normalisations) {
			$out[$code] = array_values(array_unique($normalisations));
		}
		return $cache[$subject_table] = $out;
	}

	/**
	 * Champs sur lesquels `browse.conf` demande de facetter, pour les types que le pont
	 * traduit (`fieldList`, `has` sur élément de métadonnée).
	 *
	 * On les lit plutôt que de les énumérer : chaque instance a ses facettes, et une liste
	 * figée ici ne couvrirait pas les configurations des clients. Le symptôme d'un champ
	 * oublié est discret — le moteur refuse la distribution (HTTP 400), le pont décline, le
	 * SQL rend la bonne réponse, et personne ne voit qu'un gain dormait là.
	 *
	 * Ne sont retenues que les facettes portant sur la table sujet elle-même : `relative_to`
	 * désigne un champ d'une autre table, que ce document ne porte pas.
	 *
	 * @return array noms de champs, sans doublon
	 */
	private function facetedFields(string $subject_table): array {
		if (!class_exists('\Configuration')) { return []; }

		try {
			$config = \Configuration::load(__CA_CONF_DIR__ . '/browse.conf');
			$settings = $config ? $config->getAssoc($subject_table) : null;
		} catch (\Exception $e) {
			return [];
		}
		if (!is_array($settings) || !is_array($settings['facets'] ?? null)) { return []; }

		$fields = [];
		foreach ($settings['facets'] as $facet) {
			if (!is_array($facet) || !empty($facet['relative_to'])) { continue; }

			switch ($facet['type'] ?? null) {
				case 'fieldList':
					// Une facette fieldList adossée à une table liée se compte par la facette
					// de cette table, pas par un intrinsèque.
					if (empty($facet['table']) && !empty($facet['field'])) { $fields[] = (string)$facet['field']; }
					break;

				case 'field':
					// Un gabarit compose son libellé par jointure : le pont décline, inutile
					// de payer un attribut filtrable pour rien.
					if (empty($facet['template']) && !empty($facet['field'])) { $fields[] = (string)$facet['field']; }
					break;

				case 'has':
					if (!empty($facet['element_code'])) {
						$parts = explode('.', (string)$facet['element_code']);
						$fields[] = (string)array_pop($parts);
					}
					break;

				// `attribute` n'est pas ici : ses valeurs vont dans un attribut à part
				// (FACET_ATTR), voir attributeFacetElements(). L'attribut texte ordinaire
				// n'a donc pas à devenir filtrable pour autant.
			}
		}

		return array_values(array_unique($fields));
	}

	/**
	 * Réglages appliqués à chaque index.
	 *
	 * `pagination.maxTotalHits` est le réglage à ne pas oublier : Meilisearch plafonne à
	 * 1000 résultats par défaut, en silence. CollectiveAccess pagine lui-même à partir du jeu
	 * complet d'identifiants ; un plafond bas se lit comme « la recherche ne trouve que les
	 * mille premiers », sans aucun message.
	 */
	public function indexSettings(?array $searchable_attributes = null, array $filterable_attributes = [], bool $tokenize_like_sqlsearch = false): array {
		$settings = [
			'pagination'          => ['maxTotalHits' => 2000000],
			'filterableAttributes' => array_values(array_unique(array_merge([self::PK, self::TABLE], $filterable_attributes))),
			'displayedAttributes' => [self::PK],
			// Les facettes du Browse sont exhaustives : un spécialiste veut la liste entière,
			// pas les cent valeurs les plus fréquentes. Mesuré à 71 000 fiches : 42 ms pour
			// rendre 50 000 valeurs distinctes — le plafond haut ne coûte que si on s'en sert.
			'faceting'            => [
				'maxValuesPerFacet' => 500000,
				'sortFacetValuesBy' => ['*' => 'count'],
			],
			// La recherche par identifiant d'inventaire est un cas majeur : on ne veut pas que
			// « CLE.1234 » soit rapproché de « CLE.1235 » par tolérance orthographique.
			'typoTolerance'       => [
				'enabled'    => true,
				'minWordSizeForTypos' => ['oneTypo' => 5, 'twoTypos' => 9],
			],
			'searchCutoffMs'      => 5000,
		];

		// Quand on indexe les mots du socle, Meilisearch ne doit pas les redécouper : SqlSearch2
		// garde « . _ - / » à l'intérieur d'un mot (il ne les retire qu'en tête et en queue),
		// alors que Meilisearch y coupe. Sans cette déclaration, « 211467819_saint-roch… »
		// redeviendrait plusieurs termes à l'indexation et tout le bénéfice serait perdu.
		if ($tokenize_like_sqlsearch) {
			$settings['nonSeparatorTokens'] = ['.', '_', '-', '/'];
		}
		if ($searchable_attributes !== null) {
			$settings['searchableAttributes'] = array_values($searchable_attributes);
		}
		return $settings;
	}

	/**
	 * Remplace tout ce qui n'est pas [A-Za-z0-9_-] par un souligné, et replie les suites de
	 * soulignés issues d'un même séparateur.
	 */
	private function sanitize(string $s): string {
		$s = preg_replace('![./|]+!', '__', $s);
		$s = preg_replace('![^A-Za-z0-9_-]+!', '_', $s);
		return trim($s, '_') === '' ? '_' : $s;
	}
}
