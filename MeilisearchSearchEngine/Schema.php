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
