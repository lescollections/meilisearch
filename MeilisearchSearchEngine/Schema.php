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
		return array_values(array_unique($attributes));
	}

	/**
	 * Attribut portant les tranches d'un élément de date, pour une normalisation donnée.
	 */
	public function dateFacetAttribute(string $element_code, string $normalisation): string {
		return self::FACET_DATE . $this->sanitize($element_code) . '__' . $this->sanitize($normalisation);
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
	public function indexSettings(?array $searchable_attributes = null, array $filterable_attributes = []): array {
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
