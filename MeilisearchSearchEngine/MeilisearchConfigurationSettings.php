<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/MeilisearchConfigurationSettings.php — l'écran de vérification.
 *
 * Alimente « Gérer › Vérification de la configuration ». CollectiveAccess cherche ce fichier
 * sous le nom du connecteur suffixé de `ConfigurationSettings`, à côté de Meilisearch.php.
 *
 * Quatre questions, dans l'ordre où on se les pose quand une recherche ne rend rien :
 * le greffon applicatif est-il là, le moteur répond-il, les index existent-ils, contiennent-ils
 * quelque chose.
 * ---------------------------------------------------------------------- */

require_once(__CA_LIB_DIR__ . '/Datamodel.php');
require_once(__CA_LIB_DIR__ . '/Configuration.php');
require_once(__CA_LIB_DIR__ . '/Search/ASearchConfigurationSettings.php');
require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch.php');

define('__CA_MEILISEARCH_SETTING_APP_PLUGIN__',  5101);
define('__CA_MEILISEARCH_SETTING_RUNNING__',     5102);
define('__CA_MEILISEARCH_SETTING_INDEX_EXISTS__', 5103);
define('__CA_MEILISEARCH_SETTING_INDEX_FILLED__', 5104);

class MeilisearchConfigurationSettings extends ASearchConfigurationSettings {
	private $names        = [];
	private $descriptions = [];
	private $hints        = [];

	/** @var Meilisearch\Config */
	private $ms_config;

	/** @var Meilisearch\Client|null */
	private $client;

	/** @var Meilisearch\Schema|null */
	private $schema;

	public function __construct() {
		$this->ms_config = new Meilisearch\Config();

		if ($this->ms_config->appPluginIsInstalled()) {
			$this->schema = new Meilisearch\Schema($this->ms_config->indexPrefix());
			$this->client = new Meilisearch\Client(
				$this->ms_config->url(),
				$this->ms_config->apiKey(),
				$this->ms_config->timeout(),
				$this->ms_config->taskTimeout()
			);
		}

		$this->initMessages();
		parent::__construct();
	}

	public function getEngineName() {
		return 'Meilisearch';
	}

	public function setSettings() {
		$this->opa_possible_errors = array_keys($this->names);
	}

	public function checkSetting($setting_num) {
		switch ($setting_num) {
			case __CA_MEILISEARCH_SETTING_APP_PLUGIN__:
				return $this->ms_config->appPluginIsInstalled() ? __CA_SEARCH_CONFIG_OK__ : __CA_SEARCH_CONFIG_ERROR__;

			case __CA_MEILISEARCH_SETTING_RUNNING__:
				return ($this->client && $this->client->isAvailable()) ? __CA_SEARCH_CONFIG_OK__ : __CA_SEARCH_CONFIG_ERROR__;

			case __CA_MEILISEARCH_SETTING_INDEX_EXISTS__:
				return $this->checkIndexes(false);

			case __CA_MEILISEARCH_SETTING_INDEX_FILLED__:
				return $this->checkIndexes(true);

			default:
				return false;
		}
	}

	public function getSettingName($n)        { return $this->names[$n] ?? ''; }
	public function getSettingDescription($n) { return $this->descriptions[$n] ?? ''; }
	public function getSettingHint($n)        { return $this->hints[$n] ?? ''; }

	# -------------------------------------------------------

	/**
	 * L'index des objets sert de témoin : c'est la table qu'on indexe toujours, et la première
	 * qu'on interroge.
	 */
	private function checkIndexes(bool $must_have_documents) {
		if (!$this->client || !$this->schema) { return __CA_SEARCH_CONFIG_ERROR__; }

		try {
			$index = $this->schema->indexName('ca_objects');
			if (!$this->client->indexExists($index)) { return __CA_SEARCH_CONFIG_ERROR__; }
			if ($must_have_documents && $this->client->documentCount($index) < 1) { return __CA_SEARCH_CONFIG_ERROR__; }
			return __CA_SEARCH_CONFIG_OK__;
		} catch (\Exception $e) {
			return __CA_SEARCH_CONFIG_ERROR__;
		}
	}

	private function initMessages() {
		$url    = $this->ms_config->url();
		$prefix = $this->ms_config->indexPrefix();

		$this->names[__CA_MEILISEARCH_SETTING_APP_PLUGIN__]   = _t('Greffon applicatif Meilisearch installé');
		$this->names[__CA_MEILISEARCH_SETTING_RUNNING__]      = _t('Service Meilisearch joignable');
		$this->names[__CA_MEILISEARCH_SETTING_INDEX_EXISTS__] = _t('Index de recherche créé');
		$this->names[__CA_MEILISEARCH_SETTING_INDEX_FILLED__] = _t('Index de recherche peuplé');

		$this->descriptions[__CA_MEILISEARCH_SETTING_APP_PLUGIN__] = _t(
			'Le connecteur de recherche et le greffon applicatif forment un tout : les réglages du connecteur sont lus dans %1.',
			$this->ms_config->appPluginPath() . '/conf/meilisearch.conf'
		);
		$this->descriptions[__CA_MEILISEARCH_SETTING_RUNNING__] = _t(
			'Le service Meilisearch doit répondre sur %1.', $url
		);
		$this->descriptions[__CA_MEILISEARCH_SETTING_INDEX_EXISTS__] = _t(
			'Un index par table, préfixé « %1 ». L\'index des objets est %2.', $prefix, $prefix . '_ca_objects'
		);
		$this->descriptions[__CA_MEILISEARCH_SETTING_INDEX_FILLED__] = _t(
			'Un index créé mais vide donne une recherche qui ne renvoie jamais rien, sans erreur.'
		);

		$this->hints[__CA_MEILISEARCH_SETTING_APP_PLUGIN__] = _t(
			'Créer le lien symbolique de MeilisearchAppPlugin/ vers %1.', $this->ms_config->appPluginPath()
		);
		$this->hints[__CA_MEILISEARCH_SETTING_RUNNING__] = _t(
			'Démarrer le service, puis vérifier l\'adresse configurée (meilisearch_url, ou la constante __CA_MEILISEARCH_URL__, ou la variable d\'environnement CA_MEILISEARCH_URL).'
		);
		$this->hints[__CA_MEILISEARCH_SETTING_INDEX_EXISTS__] = _t(
			'Lancer « caUtils rebuild-search-index » : les index sont créés à la première écriture.'
		);
		$this->hints[__CA_MEILISEARCH_SETTING_INDEX_FILLED__] = _t(
			'Lancer « caUtils rebuild-search-index », puis lire app/log/meilisearch.log en cas d\'échec.'
		);
	}
}
