<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/Document.php — du champ indexé au fragment de document.
 *
 * L'indexeur de CollectiveAccess appelle indexField() avec un nom de champ codé : `I5` pour
 * l'intrinsèque numéro 5 de la table de contenu, `A12` pour l'élément de métadonnée 12,
 * `COUNT` pour un décompte de relations. On le traduit ici en nom lisible, puis en attribut
 * Meilisearch normalisé.
 *
 * Le cas qui compte pour la recherche par numéro d'inventaire est `INDEX_AS_IDNO` : « CLE.42 »
 * est alors indexé avec les variantes que produit le greffon de numérotation de la table
 * (« CLE », « 42 », « CLE.42 »…), comme le fait le connecteur ElasticSearch. Sans cela, une
 * recherche sur le seul numéro ne trouve rien.
 * ---------------------------------------------------------------------- */

namespace Meilisearch;

require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Schema.php');

class Document {
	/** @var Schema */
	private $schema;

	/** @var array cache des noms de champs résolus, indexé par "tablenum/fieldname" */
	private static $field_name_cache = [];

	public function __construct(Schema $schema) {
		$this->schema = $schema;
	}

	/**
	 * Construit le fragment à fusionner dans le document, pour un appel d'indexField().
	 *
	 * @param int    $content_tablenum Table d'où vient le contenu (pas forcément la table sujet).
	 * @param string $content_fieldname Nom de champ codé par l'indexeur (I5, A12, COUNT…).
	 * @param mixed  $content Valeur ou tableau de valeurs.
	 * @param array|null $options Options d'indexation (INDEX_AS_IDNO, DONT_TOKENIZE, relationship_type_id…).
	 *
	 * @return array [attribut Meilisearch => valeur(s)] — tableau vide si le champ est inconnu.
	 */
	public function fragment(int $content_tablenum, string $content_fieldname, $content, ?array $options = null): array {
		$resolved = $this->resolveFieldName($content_tablenum, $content_fieldname);
		if (!$resolved) { return []; }

		list($table_name, $field_name, $kind) = $resolved;
		$attribute = $this->schema->fieldName($table_name, $field_name);

		$values = is_array($content) ? $content : [$content];
		$values = $this->normalizeValues($values, $table_name, $field_name, $kind, $options);

		if (!sizeof($values)) { return []; }

		$fragment = [$attribute => $values];

		// Un champ indexé au titre d'une relation typée est aussi indexé sous un attribut
		// suffixé du code de type, pour permettre les recherches restreintes à un type de lien.
		if ($rel_type_id = ($options['relationship_type_id'] ?? null)) {
			if (function_exists('caGetRelationshipTypeCode') && ($code = caGetRelationshipTypeCode($rel_type_id))) {
				$fragment[$this->schema->fieldName($table_name, $field_name . '__' . $code)] = $values;
			}
		}

		return $fragment;
	}

	/**
	 * Attribut Meilisearch correspondant à un champ, sans passer par une valeur. Sert à
	 * l'effacement ciblé d'une portion d'indexation.
	 */
	public function attributeFor(int $content_tablenum, string $content_fieldname): ?string {
		$resolved = $this->resolveFieldName($content_tablenum, $content_fieldname);
		if (!$resolved) { return null; }
		return $this->schema->fieldName($resolved[0], $resolved[1]);
	}

	# -------------------------------------------------------
	# Facettes
	# -------------------------------------------------------

	/** @var array cache des ancêtres hiérarchiques : [table][row_id] => [ids] */
	private static $ancestor_cache = [];

	/** @var \Db|null connexion paresseuse, pour écarter les racines techniques */
	private static $db = null;

	private function db(): \Db {
		if (self::$db === null) { self::$db = new \Db(); }
		return self::$db;
	}

	/** @var array tables dont on sait déjà si elles sont hiérarchiques : [table] => bool */
	private static $is_hierarchical = [];

	/**
	 * Fragment de facette pour un enregistrement lié : l'identifiant de l'enregistrement — et
	 * ceux de ses ancêtres si la table est hiérarchique — rangés sous `facet__<table>`, plus la
	 * variante par type de relation quand le lien est typé.
	 *
	 * Les ancêtres sont la clef des facettes hiérarchiques : un objet lié à « Addis-Abeba »
	 * doit compter pour « Abyssinie » ; le moteur ne connaissant pas les hiérarchies, on les
	 * aplatit ici, à l'indexation. Contrepartie assumée : déplacer un nœud de hiérarchie exige
	 * la réindexation des fiches liées — contrainte que CollectiveAccess a déjà pour sa
	 * recherche hiérarchique, via la file d'indexation.
	 *
	 * @return array [attribut => [row_ids]] — vide si la table ne se facette pas.
	 */
	public function facetFragment(int $content_tablenum, int $content_row_id, int $subject_tablenum, ?array $options = null): array {
		if ($content_row_id <= 0 || $content_tablenum === $subject_tablenum) { return []; }

		$table = \Datamodel::getTableName($content_tablenum);
		if (!$table || !in_array($table, Schema::FACET_TABLES, true)) { return []; }

		$ids = array_merge([$content_row_id], $this->hierarchyAncestors($table, $content_row_id));

		$fragment = [$this->schema->facetAttribute($table) => $ids];

		// Pour une table hiérarchique, le lien direct est aussi rangé à part : le Browse ne
		// traite pas de la même façon un enregistrement lié et un nœud présent par simple
		// ascendance (un ancêtre sans type ni accès est écarté, un lien direct est conservé).
		$hierarchical = !empty(self::$is_hierarchical[$table]);
		if ($hierarchical) {
			$fragment[$this->schema->directFacetAttribute($table)] = [$content_row_id];
		}

		if ($rel_type_id = ($options['relationship_type_id'] ?? null)) {
			if (function_exists('caGetRelationshipTypeCode') && ($code = caGetRelationshipTypeCode($rel_type_id))) {
				$fragment[$this->schema->facetAttribute($table, $code)] = $ids;
				if ($hierarchical) {
					$fragment[$this->schema->directFacetAttribute($table, $code)] = [$content_row_id];
				}
			}
		}

		return $fragment;
	}

	/**
	 * Ancêtres hiérarchiques d'un enregistrement, racine technique exclue — la racine d'une
	 * liste ou d'une hiérarchie de lieux est un nœud de service, pas une valeur de facette.
	 *
	 * Le cache est la condition du coût nul : 300 000 objets se rattachent à quelques milliers
	 * de lieux ou de termes, chaque chaîne d'ancêtres n'est résolue qu'une fois par processus.
	 */
	private function hierarchyAncestors(string $table, int $row_id): array {
		if (isset(self::$ancestor_cache[$table][$row_id])) { return self::$ancestor_cache[$table][$row_id]; }

		if (!isset(self::$is_hierarchical[$table])) {
			$t = \Datamodel::getInstanceByTableName($table, true);
			self::$is_hierarchical[$table] = ($t && $t->isHierarchical());
		}
		if (!self::$is_hierarchical[$table]) { return self::$ancestor_cache[$table][$row_id] = []; }

		$ancestors = [];
		try {
			$t = \Datamodel::getInstanceByTableName($table, true);
			$ids = $t->getHierarchyAncestors($row_id, ['idsOnly' => true, 'includeSelf' => false]);
			$ids = is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];

			// La chaîne remonte jusqu'à la racine technique — le nœud de service d'une liste
			// ou d'une hiérarchie de lieux, que le Browse écarte aussi. Elle se reconnaît à
			// son absence de parent, relue en base : le relevé d'ancêtres ne la donne pas.
			if (sizeof($ids)) {
				$qr = $this->db()->query(
					'SELECT ' . $t->primaryKey() . ' FROM ' . $table . ' WHERE ' . $t->primaryKey() . ' IN (?) AND parent_id IS NOT NULL',
					[$ids]
				);
				$ancestors = array_map('intval', $qr->getAllFieldValues($t->primaryKey()));
			}
		} catch (\Exception $e) {
			// Un arbre incohérent ne doit pas faire échouer l'indexation : la facette perd ses
			// ancêtres pour cette valeur, c'est tout.
			Log::warn("Ancêtres de {$table}#{$row_id} introuvables : " . $e->getMessage());
		}

		return self::$ancestor_cache[$table][$row_id] = $ancestors;
	}

	# -------------------------------------------------------

	/**
	 * @return array|null [nom de table, nom de champ, nature ('intrinsic'|'attribute'|'count')]
	 */
	private function resolveFieldName(int $content_tablenum, string $content_fieldname): ?array {
		$cache_key = $content_tablenum . '/' . $content_fieldname;
		if (array_key_exists($cache_key, self::$field_name_cache)) { return self::$field_name_cache[$cache_key]; }

		$table_name = \Datamodel::getTableName($content_tablenum);
		$resolved   = null;

		if ($table_name) {
			if ($content_fieldname === 'COUNT') {
				$resolved = [$table_name, 'count', 'count'];
			} elseif (preg_match('!^A([0-9]+)$!', $content_fieldname, $m)) {
				$code = \ca_metadata_elements::getElementCodeForId((int)$m[1]);
				if ($code) { $resolved = [$table_name, $code, 'attribute']; }
			} elseif (preg_match('!^I([0-9]+)$!', $content_fieldname, $m)) {
				$field = \Datamodel::getFieldName($table_name, (int)$m[1]);
				if ($field) { $resolved = [$table_name, $field, 'intrinsic']; }
			} else {
				// `created`, `modified` et les noms passés tels quels.
				$resolved = [$table_name, $content_fieldname, 'intrinsic'];
			}
		}

		self::$field_name_cache[$cache_key] = $resolved;
		return $resolved;
	}

	/**
	 * Met les valeurs en forme : chaînes vides écartées, tableaux sérialisés, numériques typés,
	 * numéros d'inventaire éclatés en leurs variantes indexables.
	 */
	private function normalizeValues(array $values, string $table_name, string $field_name, string $kind, ?array $options): array {
		$out = [];

		$as_idno = $this->indexAsIdno($table_name, $field_name, $options);

		foreach ($values as $value) {
			if (is_array($value)) { $value = join(' ', array_filter(array_map('strval', $value), 'strlen')); }
			if ($value === null) { continue; }

			if ($as_idno) {
				foreach ($this->idnoVariants($table_name, (string)$value) as $variant) {
					if (strlen($variant)) { $out[] = $variant; }
				}
				continue;
			}

			if ($kind === 'count') { $out[] = (int)$value; continue; }

			if (is_bool($value)) { $out[] = $value ? 1 : 0; continue; }

			$value = (string)$value;
			if (!strlen(trim($value))) { continue; }

			if ($kind === 'intrinsic' && $this->isNumericIntrinsic($table_name, $field_name)) {
				$out[] = (float)$value;
			} else {
				$out[] = $value;
			}
		}

		// Les doublons ne servent qu'à gonfler l'index : Meilisearch ne pondère pas sur la
		// répétition d'une valeur dans un tableau.
		$out = array_values(array_unique($out, SORT_REGULAR));
		return $out;
	}

	private function indexAsIdno(string $table_name, string $field_name, ?array $options): bool {
		if (is_array($options) && in_array('INDEX_AS_IDNO', $options, true)) { return true; }
		if (is_array($options) && !empty($options['INDEX_AS_IDNO'])) { return true; }

		$t = \Datamodel::getInstanceByTableName($table_name, true);
		return ($t && $t->getProperty('ID_NUMBERING_ID_FIELD') === $field_name);
	}

	/**
	 * Variantes indexables d'un numéro d'inventaire. On garde toujours la valeur entière en
	 * plus des fragments : c'est elle qu'on cherche le plus souvent.
	 */
	private function idnoVariants(string $table_name, string $value): array {
		$variants = [$value];

		$t = \Datamodel::getInstanceByTableName($table_name, true);
		if ($t && method_exists($t, 'getIDNoPlugInInstance') && ($idno = $t->getIDNoPlugInInstance())) {
			$variants = array_merge($variants, array_values($idno->getIndexValues($value)));
		} else {
			$variants = array_merge($variants, preg_split('![\s._/-]+!', $value) ?: []);
		}

		return array_values(array_unique(array_filter(array_map('strval', $variants), 'strlen')));
	}

	private function isNumericIntrinsic(string $table_name, string $field_name): bool {
		$info = \Datamodel::getFieldInfo($table_name, $field_name);
		if (!is_array($info)) { return false; }
		// Un champ adossé à une liste garde sa valeur telle quelle : c'est un identifiant
		// d'élément de liste, pas une grandeur.
		if (isset($info['LIST_CODE']) || isset($info['LIST'])) { return false; }
		return in_array($info['FIELD_TYPE'] ?? null, [FT_NUMBER, FT_TIME, FT_TIMERANGE, FT_TIMECODE, FT_BIT], true);
	}
}
