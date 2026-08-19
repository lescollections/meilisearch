<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/Client.php — le dialogue HTTP avec Meilisearch.
 *
 * Un client minimal, en cURL, sans dépendance Composer : le connecteur s'installe par lien
 * symbolique dans l'arborescence de Providence, il ne peut pas y apporter son vendor/.
 *
 * Meilisearch travaille en différé : toute écriture (documents, réglages, création d'index)
 * renvoie une tâche et rend la main aussitôt. Rien n'est visible en recherche tant que la
 * tâche n'a pas le statut `succeeded`. D'où waitForTask(), que l'indexation appelle à chaque
 * lot — sans quoi un `rebuild-search-index` rendrait la main sur un index encore vide.
 * ---------------------------------------------------------------------- */

namespace Meilisearch;

/**
 * Panne du moteur : injoignable, délai dépassé, réponse illisible, tâche en échec.
 * Le connecteur la laisse remonter à l'indexation et l'attrape à la recherche.
 */
class ClientException extends \Exception {}

class Client {
	/** @var string URL de base, sans barre oblique finale */
	private $base_url;

	/** @var string|null clé d'API envoyée en Authorization: Bearer */
	private $api_key;

	/** @var int délai d'attente d'une requête, en secondes */
	private $timeout;

	/** @var int délai maximal d'attente d'une tâche, en secondes */
	private $task_timeout;

	/** @var array dernières requêtes émises, pour le diagnostic */
	private $trace = [];

	/** @var bool trace activée */
	private $trace_enabled = false;

	/**
	 * Compteurs cumulés, toujours tenus : ils coûtent deux additions et répondent à la seule
	 * question qui vaille quand une réindexation traîne — le temps part-il dans le moteur ou
	 * dans CollectiveAccess ?
	 *
	 * `wait` isole l'attente des tâches : Meilisearch écrit en différé, et cette attente n'est
	 * pas du travail, c'est du temps mort qu'on peut recouvrir. `wait_request_seconds` en
	 * détache la part de sondage, qui est bien du HTTP et compte aussi dans `seconds` : sans
	 * elle, on attribue à l'écriture du temps qui n'est que de la surveillance.
	 */
	private static $stats = ['requests' => 0, 'seconds' => 0.0, 'wait_polls' => 0, 'wait_seconds' => 0.0, 'wait_request_seconds' => 0.0];

	public static function stats(): array { return self::$stats; }

	public static function resetStats(): void {
		self::$stats = ['requests' => 0, 'seconds' => 0.0, 'wait_polls' => 0, 'wait_seconds' => 0.0, 'wait_request_seconds' => 0.0];
	}

	public function __construct(string $base_url, ?string $api_key = null, int $timeout = 10, int $task_timeout = 120) {
		$this->base_url     = rtrim($base_url, '/');
		$this->api_key      = ($api_key !== null && strlen($api_key)) ? $api_key : null;
		$this->timeout      = $timeout;
		$this->task_timeout = $task_timeout;
	}

	# -------------------------------------------------------
	# Santé
	# -------------------------------------------------------

	/**
	 * Le moteur répond-il ? Ne lève pas : c'est la question qu'on pose justement quand on
	 * soupçonne qu'il est éteint.
	 */
	public function isAvailable(): bool {
		try {
			$this->request('GET', '/health');
			return true;
		} catch (ClientException $e) {
			return false;
		}
	}

	public function version(): array {
		return $this->request('GET', '/version');
	}

	public function getBaseUrl(): string {
		return $this->base_url;
	}

	# -------------------------------------------------------
	# Index
	# -------------------------------------------------------

	public function indexExists(string $uid): bool {
		try {
			$this->request('GET', '/indexes/' . rawurlencode($uid));
			return true;
		} catch (ClientException $e) {
			if ($e->getCode() === 404) { return false; }
			throw $e;
		}
	}

	/**
	 * Crée l'index s'il n'existe pas. Idempotent : Meilisearch répond `index_already_exists`,
	 * qu'on absorbe.
	 */
	public function createIndex(string $uid, string $primary_key = 'id'): void {
		try {
			$task = $this->request('POST', '/indexes', ['uid' => $uid, 'primaryKey' => $primary_key]);
			$this->waitForTask($task);
		} catch (ClientException $e) {
			if (strpos($e->getMessage(), 'index_already_exists') !== false) { return; }
			throw $e;
		}
	}

	/**
	 * Supprime un index. Idempotent : un index absent n'est pas une erreur.
	 *
	 * Le cas se signale de deux façons différentes, et il faut couvrir les deux : la requête
	 * peut répondre 404, mais elle peut aussi répondre 200 en enregistrant une tâche qui
	 * échouera ensuite en `index_not_found`. C'est la seconde qui se produit en pratique, et
	 * elle faisait mourir une réindexation complète sur la première table jamais indexée.
	 */
	public function deleteIndex(string $uid): void {
		try {
			$task = $this->request('DELETE', '/indexes/' . rawurlencode($uid));
			$this->waitForTask($task);
		} catch (ClientException $e) {
			if ($e->getCode() === 404) { return; }
			if (strpos($e->getMessage(), 'index_not_found') !== false) { return; }
			throw $e;
		}
	}

	public function listIndexes(): array {
		$r = $this->request('GET', '/indexes?limit=1000');
		return $r['results'] ?? [];
	}

	public function getSettings(string $uid): array {
		return $this->request('GET', '/indexes/' . rawurlencode($uid) . '/settings');
	}

	public function updateSettings(string $uid, array $settings, bool $wait = true): array {
		$task = $this->request('PATCH', '/indexes/' . rawurlencode($uid) . '/settings', $settings);
		if ($wait) { $this->waitForTask($task); }
		return $task;
	}

	# -------------------------------------------------------
	# Documents
	# -------------------------------------------------------

	/**
	 * Ajoute ou fusionne des documents (PUT = add or update : les champs absents du document
	 * envoyé sont conservés). C'est ce qui permet l'indexation champ par champ sans relire le
	 * document existant, là où ElasticSearch impose un aller-retour.
	 */
	public function updateDocuments(string $uid, array $documents, bool $wait = true): array {
		$task = $this->request('PUT', '/indexes/' . rawurlencode($uid) . '/documents', $documents);
		if ($wait) { $this->waitForTask($task); }
		return $task;
	}

	/**
	 * Ajoute ou remplace des documents (POST = add or replace : le document est écrasé).
	 */
	public function replaceDocuments(string $uid, array $documents, bool $wait = true): array {
		$task = $this->request('POST', '/indexes/' . rawurlencode($uid) . '/documents', $documents);
		if ($wait) { $this->waitForTask($task); }
		return $task;
	}

	public function deleteDocuments(string $uid, array $ids, bool $wait = true): array {
		$task = $this->request('POST', '/indexes/' . rawurlencode($uid) . '/documents/delete-batch', array_values($ids));
		if ($wait) { $this->waitForTask($task); }
		return $task;
	}

	public function deleteAllDocuments(string $uid, bool $wait = true): array {
		$task = $this->request('DELETE', '/indexes/' . rawurlencode($uid) . '/documents');
		if ($wait) { $this->waitForTask($task); }
		return $task;
	}

	/**
	 * Page de documents. Ne rend que les attributs déclarés dans `displayedAttributes` de
	 * l'index — restreint à l'identifiant en usage normal.
	 */
	/**
	 * Statistiques d'un index — dont `fieldDistribution`, qui dit quels attributs y sont
	 * réellement présents et dans combien de documents.
	 */
	public function indexStats(string $uid): array {
		return $this->request('GET', '/indexes/' . rawurlencode($uid) . '/stats');
	}

	public function getDocuments(string $uid, int $limit = 1000, int $offset = 0): array {
		return $this->request('GET', '/indexes/' . rawurlencode($uid) . "/documents?limit={$limit}&offset={$offset}");
	}

	public function getDocument(string $uid, $id): ?array {
		try {
			return $this->request('GET', '/indexes/' . rawurlencode($uid) . '/documents/' . rawurlencode((string)$id));
		} catch (ClientException $e) {
			if ($e->getCode() === 404) { return null; }
			throw $e;
		}
	}

	public function documentCount(string $uid): int {
		try {
			$stats = $this->request('GET', '/indexes/' . rawurlencode($uid) . '/stats');
			return (int)($stats['numberOfDocuments'] ?? 0);
		} catch (ClientException $e) {
			if ($e->getCode() === 404) { return 0; }
			throw $e;
		}
	}

	# -------------------------------------------------------
	# Recherche
	# -------------------------------------------------------

	public function search(string $uid, array $params): array {
		return $this->request('POST', '/indexes/' . rawurlencode($uid) . '/search', $params);
	}

	/**
	 * Plusieurs recherches en un aller-retour. Le connecteur s'en sert pour les requêtes
	 * booléennes, qu'il résout en intersectant / unissant les jeux d'identifiants.
	 */
	public function multiSearch(array $queries): array {
		$r = $this->request('POST', '/multi-search', ['queries' => $queries]);
		return $r['results'] ?? [];
	}

	# -------------------------------------------------------
	# Tâches
	# -------------------------------------------------------

	public function getTask(int $task_uid): array {
		return $this->request('GET', '/tasks/' . $task_uid);
	}

	/**
	 * Attend qu'une tâche aboutisse. Lève si elle échoue ou si le délai est dépassé : une
	 * indexation qui échoue en silence est pire qu'une indexation qui casse.
	 *
	 * @param array|int $task La tâche renvoyée par une écriture, ou son taskUid.
	 */
	public function waitForTask($task): array {
		$task_uid = is_array($task) ? ($task['taskUid'] ?? $task['uid'] ?? null) : (int)$task;
		if ($task_uid === null) { return is_array($task) ? $task : []; }

		$started  = microtime(true);
		$deadline = $started + $this->task_timeout;
		$sleep_us = 20000;   // 20 ms, puis on ralentit progressivement jusqu'à 250 ms

		while (true) {
			self::$stats['wait_polls']++;
			$avant_sondage = microtime(true);
			$r = $this->getTask((int)$task_uid);
			// Le sondage est du HTTP comme le reste, mais ce n'est pas de l'écriture : on le
			// tient à part pour que « écriture » ne l'absorbe pas.
			self::$stats['wait_request_seconds'] += microtime(true) - $avant_sondage;
			self::$stats['wait_seconds'] += microtime(true) - $started;
			$started = microtime(true);
			$status = $r['status'] ?? 'unknown';

			if ($status === 'succeeded') { return $r; }
			if ($status === 'failed' || $status === 'canceled') {
				$err = $r['error']['message'] ?? $status;
				$code = $r['error']['code'] ?? '';
				throw new ClientException("Tâche Meilisearch #{$task_uid} en échec ({$code}) : {$err}");
			}
			if (microtime(true) > $deadline) {
				throw new ClientException("Tâche Meilisearch #{$task_uid} toujours « {$status} » après {$this->task_timeout} s");
			}

			usleep($sleep_us);
			$sleep_us = min(250000, (int)($sleep_us * 1.5));
		}
	}

	/**
	 * Attend l'aboutissement d'un ensemble de tâches enfilées sans attente.
	 *
	 * Meilisearch traite ses tâches dans l'ordre où elles sont enfilées : quand la dernière a
	 * abouti, les précédentes sont terminées. Terminées, mais pas forcément réussies — on
	 * interroge donc la file sur celles qui ont échoué, par tranches, plutôt que de sonder
	 * chaque tâche une par une. C'est là tout l'intérêt : une attente au lieu de N.
	 *
	 * @throws ClientException si l'une des tâches a échoué ou a été annulée.
	 */
	public function waitForTasks(array $task_uids): void {
		$uids = array_values(array_unique(array_map('intval', $task_uids)));
		if (!sizeof($uids)) { return; }

		sort($uids);
		$this->waitForTask(end($uids));       // lève si c'est la dernière qui a échoué

		if (sizeof($uids) === 1) { return; }

		$echecs = [];
		foreach (array_chunk($uids, 100) as $tranche) {
			$r = $this->request('GET', '/tasks?limit=' . sizeof($tranche)
				. '&statuses=failed,canceled&uids=' . join(',', $tranche));
			foreach (($r['results'] ?? []) as $tache) {
				$echecs[] = '#' . ($tache['uid'] ?? '?') . ' ' . ($tache['error']['code'] ?? $tache['status'] ?? 'inconnu');
			}
		}

		if (sizeof($echecs)) {
			throw new ClientException(sprintf('%d tâche(s) Meilisearch en échec sur %d : %s',
				sizeof($echecs), sizeof($uids), join(', ', array_slice($echecs, 0, 5))));
		}
	}

	/**
	 * Attend que toutes les tâches en cours de l'index soient traitées. Utile après un lot
	 * d'écritures lancées sans attente.
	 */
	public function waitForIndex(string $uid): void {
		$deadline = microtime(true) + $this->task_timeout;
		while (true) {
			$r = $this->request('GET', '/tasks?indexUids=' . rawurlencode($uid) . '&statuses=enqueued,processing&limit=1');
			if (empty($r['results'])) { return; }
			if (microtime(true) > $deadline) {
				throw new ClientException("Des tâches Meilisearch sont encore en attente sur « {$uid} » après {$this->task_timeout} s");
			}
			usleep(50000);
		}
	}

	# -------------------------------------------------------
	# Trace (diagnostic)
	# -------------------------------------------------------

	public function enableTrace(bool $on = true): void { $this->trace_enabled = $on; }
	public function getTrace(): array { return $this->trace; }
	public function clearTrace(): void { $this->trace = []; }

	# -------------------------------------------------------
	# Transport
	# -------------------------------------------------------

	/**
	 * @throws ClientException moteur injoignable, délai dépassé, réponse illisible, ou statut
	 *                         HTTP ≥ 400 (le code de l'exception porte alors le statut HTTP).
	 */
	private function request(string $method, string $path, $body = null): array {
		$url = $this->base_url . $path;
		$ch  = curl_init($url);

		$headers = ['Content-Type: application/json', 'Accept: application/json'];
		if ($this->api_key !== null) { $headers[] = 'Authorization: Bearer ' . $this->api_key; }

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
		]);

		if ($body !== null) {
			// JSON_PRESERVE_ZERO_FRACTION : sans lui, un 1.0 part en `1` et Meilisearch fige
			// le type du champ sur un entier, ce qui casse les filtres de plage ultérieurs.
			// JSON_INVALID_UTF8_SUBSTITUTE : une seule valeur mal encodée dans le fonds faisait
			// échouer json_encode(), donc tout le lot, donc l'ouvrier — 1 500 entités sur 8 719
			// manquaient à l'index sur l'instance INRAP. Le socle assemble ces documents à partir
			// de champs qui ne sont pas garantis en UTF-8 valide ; plutôt que de perdre le lot, on
			// remplace les séquences fautives par U+FFFD. Le champ reste cherchable, seule la
			// séquence illisible est neutralisée.
			$payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
			if ($payload === false) {
				throw new ClientException('Corps de requête non encodable en JSON : ' . json_last_error_msg());
			}
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		}

		$started  = microtime(true);
		$response = curl_exec($ch);
		$errno    = curl_errno($ch);
		$error    = curl_error($ch);
		$status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		self::$stats['requests']++;
		self::$stats['seconds'] += microtime(true) - $started;

		if ($this->trace_enabled) {
			$this->trace[] = [
				'method'   => $method,
				'path'     => $path,
				'status'   => $status,
				'ms'       => round((microtime(true) - $started) * 1000, 1),
				'body'     => $body,
			];
		}

		if ($errno !== 0) {
			throw new ClientException("Meilisearch injoignable sur {$this->base_url} ({$error})", 0);
		}

		$decoded = ($response === '' || $response === false) ? [] : json_decode($response, true);
		if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
			throw new ClientException(
				"Réponse Meilisearch illisible ({$method} {$path}, HTTP {$status}) : " . substr((string)$response, 0, 200),
				$status
			);
		}
		if (!is_array($decoded)) { $decoded = ['value' => $decoded]; }

		if ($status >= 400) {
			$code = $decoded['code'] ?? '';
			$msg  = $decoded['message'] ?? substr((string)$response, 0, 200);
			throw new ClientException("Meilisearch a répondu HTTP {$status} sur {$method} {$path} [{$code}] : {$msg}", $status);
		}

		return $decoded;
	}
}
