<?php
/* ----------------------------------------------------------------------
 * MeilisearchAppPlugin/MeilisearchPlugin.php — la moitié applicative du greffon.
 *
 * Installé par lien symbolique en app/plugins/Meilisearch : le gestionnaire de greffons de
 * Providence cherche, pour chaque dossier <X> de app/plugins/, le fichier <X>Plugin.php et y
 * instancie la classe <X>Plugin.
 *
 * Un connecteur de recherche ne peut pas tout faire : l'interface IWLPlugSearchEngine ne
 * connaît ni les enregistrements, ni les requêtes, ni le cycle de vie de l'application. Ce
 * greffon tient la partie que le connecteur ne peut pas atteindre :
 *
 *   • les réglages (conf/meilisearch.conf), que le connecteur relit — c'est ce qui rend les
 *     deux moitiés indissociables : sans ce fichier, le connecteur n'a pas d'adresse à
 *     appeler et refuse de s'initialiser ;
 *   • l'état de santé, remonté à l'écran « Vérification de la configuration » ;
 *   • les points d'accroche sur le cycle d'enregistrement, où viendront les traitements qui
 *     débordent du contrat d'indexation.
 * ---------------------------------------------------------------------- */

require_once(__CA_LIB_DIR__ . '/BaseApplicationPlugin.php');
require_once(__CA_LIB_DIR__ . '/Configuration.php');

class MeilisearchPlugin extends BaseApplicationPlugin {
	/** @var Configuration */
	private $config;

	/** @var string chemin d'installation du greffon */
	private $plugin_path;

	# -------------------------------------------------------
	public function __construct($plugin_path) {
		$this->plugin_path = $plugin_path;
		$this->description = _t('Recherche Meilisearch');
		$this->config      = Configuration::load($plugin_path . '/conf/meilisearch.conf');

		parent::__construct();
	}
	# -------------------------------------------------------
	/**
	 * Le greffon ne s'annonce disponible que si le connecteur est là : les deux moitiés vont
	 * ensemble, et un greffon actif sans connecteur donnerait l'illusion d'une installation
	 * complète.
	 *
	 * Les autres constats — moteur sélectionné, moteur joignable — sont des avertissements :
	 * ils n'empêchent pas le greffon de fonctionner, ils expliquent pourquoi la recherche ne
	 * rend rien.
	 */
	public function checkStatus() {
		$errors   = [];
		$warnings = [];

		if (!$this->searchEngineIsInstalled()) {
			$errors[] = _t(
				'Le connecteur de recherche Meilisearch est absent (%1 introuvable). Créer le lien symbolique depuis MeilisearchSearchEngine/ du dépôt.',
				__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch.php'
			);
		} else {
			$selected = Configuration::load()->get('search_engine_plugin');
			if ($selected !== 'Meilisearch') {
				$warnings[] = _t(
					'Le moteur de recherche actif est « %1 ». Poser « search_engine_plugin = Meilisearch » dans app/conf/local/app.conf — et non dans app_<nom>.conf, qui n\'est pas chargé.',
					$selected
				);
			}

			if (!$this->engineIsReachable()) {
				$warnings[] = _t(
					'Le service Meilisearch ne répond pas sur %1. La recherche renverra zéro résultat ; voir app/log/meilisearch.log.',
					$this->engineUrl()
				);
			}
		}

		return [
			'description' => $this->getDescription(),
			'errors'      => $errors,
			'warnings'    => $warnings,
			'available'   => ((bool)$this->config->get('enabled')) && !sizeof($errors),
		];
	}
	# -------------------------------------------------------
	# Cycle d'enregistrement
	# -------------------------------------------------------
	/**
	 * Après enregistrement d'un élément dans l'éditeur.
	 *
	 * Meilisearch écrit en différé : le connecteur attend déjà que chaque lot soit absorbé
	 * (option `waitForIndexing`), pour qu'un enregistrement soit retrouvable dès la page
	 * suivante. On journalise ici de quoi rapprocher un enregistrement d'une écriture d'index
	 * quand ce n'est pas le cas.
	 *
	 * C'est aussi le point d'accroche prévu pour les traitements complémentaires à venir.
	 *
	 * @param array $params id, table_num, table_name, instance, is_insert
	 * @return bool true pour laisser tourner les greffons suivants
	 */
	public function hookSaveItem($params) {
		if (!$this->config->get('log_saves')) { return $params; }

		$this->log(sprintf(
			'Enregistrement %s#%s (%s)',
			$params['table_name'] ?? '?',
			$params['id'] ?? '?',
			!empty($params['is_insert']) ? 'création' : 'mise à jour'
		));

		return $params;
	}
	# -------------------------------------------------------
	/**
	 * Tâche périodique : on vérifie que le moteur répond, pour que la panne se voie dans le
	 * journal avant de se voir dans les résultats.
	 */
	public function hookPeriodicTask(?array $options = null) {
		if ($tasks = caGetOption('limit-to-tasks', $options, null)) {
			if (!in_array('Meilisearch', $tasks)) { return true; }
		}

		if (!$this->engineIsReachable()) {
			$this->log('Service injoignable sur ' . $this->engineUrl(), 'ERREUR');
		}

		return true;
	}
	# -------------------------------------------------------
	# Utilitaires
	# -------------------------------------------------------
	public function getConfig() {
		return $this->config;
	}

	private function searchEngineIsInstalled(): bool {
		return file_exists(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch.php')
			&& file_exists(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Client.php');
	}

	private function engineUrl(): string {
		if (defined('__CA_MEILISEARCH_URL__') && strlen(__CA_MEILISEARCH_URL__)) { return __CA_MEILISEARCH_URL__; }
		if (($env = getenv('CA_MEILISEARCH_URL')) && strlen($env)) { return $env; }
		return (string)($this->config->get('meilisearch_url') ?: 'http://meilisearch:7700');
	}

	/**
	 * Interrogation directe : on ne veut pas instancier le connecteur ici, il lèverait si la
	 * configuration est incomplète — or c'est précisément ce qu'on est en train de vérifier.
	 */
	private function engineIsReachable(): bool {
		if (!$this->searchEngineIsInstalled()) { return false; }

		require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Client.php');

		$key = null;
		if (defined('__CA_MEILISEARCH_API_KEY__') && strlen(__CA_MEILISEARCH_API_KEY__)) {
			$key = __CA_MEILISEARCH_API_KEY__;
		} elseif (($env = getenv('CA_MEILISEARCH_API_KEY')) && strlen($env)) {
			$key = $env;
		} elseif ($v = $this->config->get('meilisearch_api_key')) {
			$key = (string)$v;
		}

		$client = new Meilisearch\Client($this->engineUrl(), $key, 3, 5);
		return $client->isAvailable();
	}

	private function log(string $message, string $level = 'INFO'): void {
		if (file_exists(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Log.php')) {
			require_once(__CA_LIB_DIR__ . '/Plugins/SearchEngine/Meilisearch/Log.php');
			($level === 'ERREUR') ? Meilisearch\Log::error('[greffon] ' . $message)
			                      : Meilisearch\Log::info('[greffon] ' . $message);
		}
	}
	# -------------------------------------------------------
}
