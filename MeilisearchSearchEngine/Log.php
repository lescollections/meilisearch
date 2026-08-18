<?php
/* ----------------------------------------------------------------------
 * MeilisearchSearchEngine/Log.php — journal du connecteur.
 *
 * Un fichier à part, `app/log/meilisearch.log`, plutôt qu'une ligne noyée dans le journal
 * applicatif : quand une recherche ne renvoie rien, la première question est « le moteur
 * a-t-il répondu ? », et on veut y répondre en une commande.
 *
 * Écrire dans app/log/ peut échouer (droits, disque plein) ; le journal ne doit jamais faire
 * tomber une recherche, donc aucune erreur d'écriture n'est propagée — mais elle n'est pas
 * perdue pour autant : on se replie sur `error_log()`.
 *
 * Le cas se produit pour de bon. Un administrateur lance `caUtils` ou l'un des outils du
 * greffon en root : le fichier est créé root:root en 0644, et le serveur web ne peut plus y
 * écrire. Sans repli, le connecteur devient muet exactement là où il doit parler — sur les
 * recherches des usagers. Le fichier est donc créé en 0666, et l'échec bascule vers le journal
 * de PHP plutôt que dans le vide.
 * ---------------------------------------------------------------------- */

namespace Meilisearch;

class Log {
	const ERROR = 'ERREUR';
	const WARN  = 'ALERTE';
	const INFO  = 'INFO';
	const DEBUG = 'DEBUG';

	/** @var bool journaliser les messages de niveau DEBUG */
	private static $debug = false;

	public static function setDebug(bool $on): void { self::$debug = $on; }

	public static function error(string $message): void { self::write(self::ERROR, $message); }
	public static function warn(string $message): void  { self::write(self::WARN, $message); }
	public static function info(string $message): void  { self::write(self::INFO, $message); }
	public static function debug(string $message): void {
		if (self::$debug) { self::write(self::DEBUG, $message); }
	}

	public static function path(): string {
		return (defined('__CA_APP_DIR__') ? __CA_APP_DIR__ : sys_get_temp_dir()) . '/log/meilisearch.log';
	}

	private static function write(string $level, string $message): void {
		$line = sprintf(
			"[%s] %-6s %s %s\n",
			date('Y-m-d H:i:s'),
			$level,
			(php_sapi_name() === 'cli') ? '(cli)' : '(web)',
			str_replace(["\n", "\r"], ' ', $message)
		);

		$path = self::path();
		$dir  = dirname($path);
		if (!is_dir($dir)) { error_log('meilisearch: ' . rtrim($line)); return; }

		$nouveau = !file_exists($path);

		if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
			// Droits, disque plein, fichier tenu par un autre compte : la ligne part au journal
			// de PHP. Perdre la trace d'une panne du moteur coûte plus cher qu'une ligne au
			// mauvais endroit.
			error_log('meilisearch: ' . rtrim($line));
			return;
		}

		// Le journal est lu et écrit par les deux comptes de l'instance — le serveur web et
		// celui qui lance les outils en ligne de commande. Ni l'un ni l'autre ne doit pouvoir
		// faire taire le connecteur en créant le fichier le premier.
		if ($nouveau) { @chmod($path, 0666); }
	}
}
