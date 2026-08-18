<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/Config.php — d'où viennent les réglages.
 *
 * Le connecteur ne se configure pas dans `app/conf/search.conf` : ce fichier appartient au
 * socle Providence, que ce dépôt ne modifie pas. Les réglages vivent dans le greffon
 * applicatif, `app/plugins/Meilisearch/conf/meilisearch.conf` — c'est ce qui rend les deux
 * moitiés indissociables, comme voulu : sans le greffon, le connecteur n'a pas d'adresse à
 * appeler et le dit.
 *
 * Ordre de résolution, du plus fort au plus faible :
 *
 *   1. constante PHP définie dans setup.php  (__CA_MEILISEARCH_URL__, …)
 *   2. variable d'environnement              (CA_MEILISEARCH_URL, …)
 *   3. meilisearch.conf du greffon applicatif
 *   4. valeur par défaut
 *
 * Les deux premiers niveaux servent le déploiement en conteneur, où l'adresse du service et sa
 * clé viennent de l'orchestrateur et n'ont rien à faire dans un fichier versionné.
 * ---------------------------------------------------------------------- */

namespace Meilisearch;

class ConfigurationException extends \Exception {}

class Config {
	/** Chemin du greffon applicatif dont ce connecteur dépend. */
	const APP_PLUGIN_DIRECTORY = 'Meilisearch';

	/** @var array réglages résolus */
	private $settings = [];

	/** @var \Configuration|null conf du greffon applicatif */
	private $plugin_config;

	private static $defaults = [
		'url'                   => 'http://meilisearch:7700',
		'api_key'               => '',
		'index_prefix'          => 'ca',
		'timeout'               => 10,
		'task_timeout'          => 300,
		'indexing_buffer_size'  => 500,
		'search_limit'          => 2000000,
		'debug'                 => 0,
	];

	private static $constants = [
		'url'          => '__CA_MEILISEARCH_URL__',
		'api_key'      => '__CA_MEILISEARCH_API_KEY__',
		'index_prefix' => '__CA_MEILISEARCH_INDEX_PREFIX__',
	];

	private static $env_vars = [
		'url'          => 'CA_MEILISEARCH_URL',
		'api_key'      => 'CA_MEILISEARCH_API_KEY',
		'index_prefix' => 'CA_MEILISEARCH_INDEX_PREFIX',
		'debug'        => 'CA_MEILISEARCH_DEBUG',
	];

	public function __construct() {
		$this->plugin_config = $this->loadPluginConfig();

		foreach (array_keys(self::$defaults) as $key) {
			$this->settings[$key] = $this->resolve($key);
		}
	}

	public function get(string $key) {
		return $this->settings[$key] ?? null;
	}

	public function url(): string          { return rtrim((string)$this->get('url'), '/'); }
	public function apiKey(): ?string      { $k = (string)$this->get('api_key'); return strlen($k) ? $k : null; }
	public function indexPrefix(): string  { return (string)$this->get('index_prefix'); }
	public function timeout(): int         { return max(1, (int)$this->get('timeout')); }
	public function taskTimeout(): int     { return max(1, (int)$this->get('task_timeout')); }
	public function indexingBufferSize(): int { return max(1, (int)$this->get('indexing_buffer_size')); }
	public function searchLimit(): int     { return max(1, (int)$this->get('search_limit')); }
	public function debug(): bool          { return (bool)(int)$this->get('debug'); }

	/**
	 * Le greffon applicatif est-il installé ? Le connecteur seul ne suffit pas : les deux
	 * moitiés se répondent (réglages ici, réindexation à l'enregistrement là-bas).
	 */
	public function appPluginIsInstalled(): bool {
		return $this->plugin_config !== null;
	}

	public function appPluginPath(): string {
		return __CA_APP_DIR__ . '/plugins/' . self::APP_PLUGIN_DIRECTORY;
	}

	/**
	 * @throws ConfigurationException si le greffon applicatif manque.
	 */
	public function requireAppPlugin(): void {
		if ($this->appPluginIsInstalled()) { return; }

		throw new ConfigurationException(
			"Le connecteur Meilisearch exige le greffon applicatif Meilisearch, introuvable en "
			. $this->appPluginPath() . ". Créer le lien symbolique vers MeilisearchAppPlugin/ du dépôt du connecteur."
		);
	}

	# -------------------------------------------------------

	private function resolve(string $key) {
		if (isset(self::$constants[$key]) && defined(self::$constants[$key])) {
			$v = constant(self::$constants[$key]);
			if (is_string($v) ? strlen($v) : ($v !== null)) { return $v; }
		}

		if (isset(self::$env_vars[$key])) {
			$v = getenv(self::$env_vars[$key]);
			if ($v !== false && strlen($v)) { return $v; }
		}

		if ($this->plugin_config) {
			$v = $this->plugin_config->get('meilisearch_' . $key);
			if ($v !== null && $v !== '' && $v !== false) { return $v; }
		}

		return self::$defaults[$key];
	}

	private function loadPluginConfig(): ?\Configuration {
		$path = __CA_APP_DIR__ . '/plugins/' . self::APP_PLUGIN_DIRECTORY . '/conf/meilisearch.conf';
		if (!file_exists($path)) { return null; }

		$conf = \Configuration::load($path);
		return $conf ?: null;
	}
}
