<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/MeilisearchResult.php — le jeu de résultats.
 *
 * Un curseur sur une liste d'identifiants, rien de plus. CollectiveAccess ne demande à l'index
 * que les clés primaires : tout le reste — étiquettes, attributs, médias — il le relit en base
 * via `SearchResult::get()`. C'est aussi ce que fait le connecteur ElasticSearch, et c'est ce
 * qui permet de ne rien stocker d'affichable dans le moteur.
 * ---------------------------------------------------------------------- */

require_once(__CA_LIB_DIR__ . '/Plugins/WLPlug.php');
require_once(__CA_LIB_DIR__ . '/Plugins/IWLPlugSearchEngineResult.php');

class WLPlugSearchEngineMeilisearchResult extends WLPlug implements IWLPlugSearchEngineResult {
	/** @var array identifiants, dans l'ordre de pertinence rendu par Meilisearch */
	private $hits = [];

	/** @var array description des correspondances, par identifiant */
	private $result_desc = [];

	private $current_row = -1;
	private $subject_tablenum;
	private $subject_primary_key;
	private $subject_table_name;

	/**
	 * @param array $hits        Identifiants (entiers).
	 * @param array $result_desc Description des correspondances, indexée par identifiant.
	 * @param int   $table_num   Table sujet.
	 */
	public function __construct($hits, $result_desc, $table_num) {
		parent::__construct();

		$this->subject_tablenum = $table_num;
		$this->result_desc      = is_array($result_desc) ? $result_desc : [];
		$this->setHits(is_array($hits) ? $hits : []);
	}

	public function setHits($hits) {
		$this->hits        = array_values($hits);
		$this->current_row = -1;

		if (sizeof($this->hits)) {
			$instance = Datamodel::getInstanceByTableNum($this->subject_tablenum, true);
			if ($instance) {
				$this->subject_primary_key = $instance->primaryKey();
				$this->subject_table_name  = $instance->tableName();
			}
		}
	}

	public function getHits() {
		return $this->hits;
	}

	public function numHits() {
		return sizeof($this->hits);
	}

	public function nextHit() {
		if ($this->current_row < sizeof($this->hits) - 1) {
			$this->current_row++;
			return true;
		}
		return false;
	}

	public function currentRow() {
		return $this->current_row;
	}

	public function get($field, $options = null) {
		// Seule la clé primaire vient de l'index ; le reste passe par la base.
		if (
			$field === $this->subject_primary_key
			|| $field === $this->subject_table_name . '.' . $this->subject_primary_key
		) {
			return $this->hits[$this->current_row] ?? false;
		}
		return false;
	}

	public function seek($index) {
		if (($index >= 0) && ($index < sizeof($this->hits))) {
			$this->current_row = $index - 1;
			return true;
		}
		return false;
	}

	/**
	 * @param int|null $limit
	 */
	public function getPrimaryKeyValues($limit = null) {
		if (!$limit) { return $this->hits; }
		return array_slice($this->hits, 0, (int)$limit);
	}

	/**
	 * Description des correspondances, lue par SearchEngine quand
	 * `return_search_result_description_data` est activé dans search.conf.
	 */
	public function getRawResultDesc() {
		return $this->result_desc;
	}
}
