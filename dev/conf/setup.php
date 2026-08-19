<?php
/* ----------------------------------------------------------------------
 * setup.php — configuration de l'instance Providence du harnais.
 *
 * Déposé en /var/www/html/setup.php par le Dockerfile. Les identifiants et l'adresse du moteur
 * viennent de l'environnement (compose.yml / dev/.env) : rien de secret n'est écrit en dur.
 * ---------------------------------------------------------------------- */

// ── Base de données ───────────────────────────────────────────────────────────────────────
define('__CA_DB_HOST__',     getenv('CA_DB_HOST')     ?: 'db');
define('__CA_DB_PORT__',     getenv('CA_DB_PORT')     ?: '3306');
define('__CA_DB_USER__',     getenv('CA_DB_USER')     ?: 'collectiveaccess');
define('__CA_DB_PASSWORD__', getenv('CA_DB_PASSWORD') ?: 'collectiveaccess');
define('__CA_DB_DATABASE__', getenv('CA_DB_DATABASE') ?: 'collectiveaccess');

// ── Identité de l'instance ────────────────────────────────────────────────────────────────
define('__CA_APP_DISPLAY_NAME__', 'Meilisearch — développement');
define('__CA_ADMIN_EMAIL__',      getenv('CA_ADMIN_EMAIL') ?: 'dev@meilisearch.test');
define('__CA_AUTH_ADAPTER__',     'CaUsers');
// Le nom de l'instance porte celui de la base, et ce n'est pas cosmétique : le cache de
// CollectiveAccess vit dans <tmp>/<__CA_APP_NAME__>Cache (ExternalCache.php:241), sans rien qui
// distingue les bases. Deux bases servies par la même arborescence — le corpus CMA et une base
// cliente rapatriée — partageaient donc leurs listes en cache, et une facette rendait les
// libellés de l'autre. Symptôme trompeur : des divergences qui apparaissent et disparaissent
// selon l'ordre des exécutions.
define('__CA_APP_NAME__',         'meilisearch' . ((getenv('CA_DB_DATABASE') && getenv('CA_DB_DATABASE') !== 'collectiveaccess') ? '_' . preg_replace('![^A-Za-z0-9_]!', '_', getenv('CA_DB_DATABASE')) : ''));

date_default_timezone_set('Europe/Paris');
define('__CA_DEFAULT_LOCALE__', 'fr_FR');

// ── Meilisearch ───────────────────────────────────────────────────────────────────────────
// Le connecteur lit d'abord ces constantes, puis l'environnement, puis meilisearch.conf du
// greffon applicatif. On les pose ici pour que le comportement soit le même en HTTP et en
// ligne de commande — caUtils et les scripts de test ne reçoivent pas forcément l'environnement
// du conteneur.
define('__CA_MEILISEARCH_URL__',          getenv('CA_MEILISEARCH_URL')          ?: 'http://meilisearch:7700');
define('__CA_MEILISEARCH_API_KEY__',      getenv('CA_MEILISEARCH_API_KEY')      ?: '');
define('__CA_MEILISEARCH_INDEX_PREFIX__', getenv('CA_MEILISEARCH_INDEX_PREFIX') ?: 'ca');

// ── URL ───────────────────────────────────────────────────────────────────────────────────
define('__CA_URL_ROOT__',       '/gestion');
define('__CA_SITE_HOSTNAME__',  getenv('CA_SITE_HOSTNAME') ?: 'localhost:8089');
define('__CA_SITE_PROTOCOL__',  'http');
define('__CA_USE_CLEAN_URLS__', 0);

// ── Mode développement ────────────────────────────────────────────────────────────────────
// LE réglage qui fait la boucle courte : sans lui, CollectiveAccess met en cache les fichiers
// de configuration analysés, et une modification de meilisearch.conf resterait invisible
// jusqu'au vidage du cache.
define('__CA_DISABLE_CONFIG_CACHING__', true);

define('__CA_STACKTRACE_ON_EXCEPTION__', true);

// Nécessaire à `dev-install --force`. Base jetable, jamais exposée ; à ne pas reprendre
// en production.
define('__CA_ALLOW_INSTALLER_TO_OVERWRITE_EXISTING_INSTALLS__', true);

// Pas de file d'attente : les traitements longs se font en direct, on veut voir les erreurs.
define('__CA_QUEUE_ENABLED__', 0);

define('__CA_CACHE_BACKEND__', 'file');

require(__DIR__ . '/app/helpers/post-setup.php');
