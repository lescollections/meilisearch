# Brief — environnement de test pour un connecteur de moteur de recherche CollectiveAccess

Destinataire : instance de développement connectée à un clone local de Providence.
Rédigé le 2026-08-17, machine `darwin` de Gautier Michelin.

## 1. La règle, d'abord

**Le développement se valide sur une instance réelle de CollectiveAccess.** Pas de stubs, pas de faux `Configuration`, pas de faux `Db`, pas de modèles rejoués. C'est une décision prise le 2026-08-17 après sept audits d'un autre greffon menés hors instance : la boucle était rapide, mais elle ne prouvait rien du comportement réel dans Providence.

Le socle de test est le harnais Docker décrit en §2, semé avec le corpus `cleveland`.

## 2. Le harnais Docker

Emplacement : `/Users/gautier/git/lescollections/lescollectionsPlugin/dev/`. Il tourne déjà sur la machine (`lescollections-dev-app-1`, `127.0.0.1:8088`). Il a été écrit pour le greffon `lescollections` ; **lire son `README.md` en entier** avant d'en dériver une variante, il documente plusieurs pièges payés cher.

### Ce qu'il monte

| Service | Image | Rôle |
|---|---|---|
| `db` | `mariadb:11.4` | `utf8mb4`, `max_allowed_packet=256M`, `innodb_buffer_pool_size=512M` |
| `app` | `php:8.4-apache` construite sur place | Providence servi sous `/gestion` |

Extensions PHP : `gd` (jpeg/webp/freetype), `mysqli`, `zip`, `exif`, `bcmath`, `intl`. Composer refuse de résoudre les dépendances de CollectiveAccess sans `gd`, `zip` et `intl`.

Providence est cloné **à un SHA vérifié au build** : si le tag ne pointe pas sur l'octet attendu, le build échoue plutôt que de laisser développer contre autre chose. Discipline à conserver telle quelle.

### La boucle de développement

Les caches sont coupés pour que « éditer, rafraîchir, voir » soit vrai : `__CA_DISABLE_CONFIG_CACHING__` (conf de CollectiveAccess), opcache PHP, cache de la barre de menus. Une exception documentée : la liste des permissions de greffon est en cache trente jours, il faut `caUtils clear-caches` après y avoir touché.

### Commandes

```sh
cd dev && docker compose up                      # démarrage (build au premier lancement)
docker compose exec app dev-install --force      # base neuve, tout est perdu
docker compose exec app dev-seed                 # six objets de démonstration, idempotent
docker compose exec app dev-seed-cleveland 250   # le corpus cleveland
docker compose exec app caUtils <commande>
docker compose logs -f app
docker compose down -v && docker compose up --build   # repartir de zéro, images comprises
```

Réglages dans `dev/.env` (copier depuis `.env.example`) : `CA_HOST_PORT`, `CA_PROFILE`, identifiants MariaDB, `CA_SEED_CLEVELAND`, `CA_SEED_CLEVELAND_COUNT`.

Interface : `http://localhost:8088/gestion/`, `administrator` / `collections`.

> **8088 et pas 8080.** 8080 est souvent pris par un Apache ou un MAMP local, et la collision est sournoise : `localhost` résout `::1` en premier, on tombe sur l'autre serveur sans que Docker ne se plaigne.

### Deux écarts assumés, à connaître

`policy.advisories.block false` au `composer install` : CollectiveAccess épingle `dompdf ~2.0.2`, dont toutes les versions portent des avis de sécurité, et Composer 2.9 refuse donc de l'installer. La contrainte vient du fork ; la lever reviendrait à ne plus être aligné.

`allow_fetching_of_media_from_remote_urls = 1` : sans quoi le corpus `cleveland` arrive sans images. À ne pas reprendre hors harnais local.

### Le piège de configuration à ne pas retomber dedans

**Ne pas écrire dans `app/conf/local/app_<__CA_APP_NAME__>.conf`.** Cette couche est composée sur le papier par `Configuration.php`, mais `Configuration::load()` — la voie qu'emprunte tout CollectiveAccess — ne la charge pas : le chargement de n'importe quel autre `.conf` construit `app.conf` avec cette couche désactivée puis mémorise l'instance. Écrire dans `app/conf/local/app.conf`. Deux sessions s'y sont laissé prendre, et le symptôme est un réglage qui « ne prend pas » sans aucun message.

C'est directement votre affaire : la directive `search_engine_plugin` vit dans `app.conf`.

## 3. Le corpus cleveland

`dev/bin/seed-cleveland.php` charge des œuvres CC0 du Cleveland Museum of Art depuis le jeu d'exemple de `rochambeau` — 2500 disponibles, 250 par défaut, avec leurs images téléchargées et leurs dérivés fabriqués. Comptez trois minutes au premier semis, et il faut le réseau.

La correspondance vers le profil `default` est volontairement grossière, c'est une amorce de test et pas une modélisation :

| source | CollectiveAccess |
|---|---|
| `id` | `idno`, préfixé `CLE.` |
| `titre` | étiquette préférée |
| `denomination` | type d'objet (valeur d'origine conservée dans `notes`) |
| `auteur`, `datation` | `description` |
| `technique` / `materiaux` | `work_medium` |
| `provenance` | `credit_line` |
| `domaine`, `culture`, `dimensions`, `localisation` | `notes` |
| `images[0].plein` | représentation d'objet |

Pour un moteur de recherche c'est un corpus honnête : des titres, des auteurs, des techniques, des matériaux, du texte libre dans `notes`, et assez de volume pour que la pertinence veuille dire quelque chose. Les objets arrivent en `access` = 0 ; à vérifier si votre connecteur filtre là-dessus.

Charger davantage : `dev-seed-cleveland 2500`.

## 4. Le contrat d'un connecteur de moteur de recherche

Points vérifiés dans `/Users/gautier/git/ideesculture/providence` (le clone à lire par défaut, **jamais modifié** — le connecteur vit dans son propre dépôt).

### Emplacement et nommage

`SearchBase::newSearchEngine()` — `app/lib/Search/SearchBase.php:75-83` — fait, sans autre indirection :

```php
if (!file_exists(__CA_LIB_DIR__.'/Plugins/SearchEngine/'.$plugin_name.'.php')) { return null; }
require_once(__CA_LIB_DIR__.'/Plugins/SearchEngine/'.$plugin_name.'.php');
$classname = 'WLPlugSearchEngine'.$plugin_name;
```

Donc : fichier `app/lib/Plugins/SearchEngine/<Nom>.php`, classe `WLPlugSearchEngine<Nom>`.

**Conséquence directe sur le harnais** : un connecteur de recherche n'est pas un greffon d'application. Il atterrit dans l'arborescence du socle, pas dans `app/plugins/`. Le bind-mount de `compose.yml` doit viser `app/lib/Plugins/SearchEngine/` — soit le fichier seul, soit un sous-répertoire pour les classes d'appui, sur le modèle d'ElasticSearch. À décider tôt : c'est ce qui détermine comment le dépôt du connecteur cohabite avec le clone Providence du conteneur.

### Sélection

Directive `search_engine_plugin` dans `app.conf` — le nom **du fichier** (`ElasticSearch`), pas celui de la classe. Un plugin introuvable fait `die()` dans `SearchBase::__construct()` avec un message explicite.

### L'interface à implémenter

`app/lib/Plugins/IWLPlugSearchEngine.php`, trois blocs :

- **cycle de vie et options** — `init()`, `can($capability)`, `engineName()`, `setOption()`, `getOption()`, `getAvailableOptions()`, `isValidOption()` ;
- **indexation** — `startRowIndexing()`, `indexField()`, `commitRowIndexing()`, `removeRowIndexing()`, `truncateIndex()`, `optimizeIndex()` ;
- **recherche** — `search(int $subject_tablenum, string $search_expression, array $filters, $rewritten_query)`, `addFilter()`, `clearFilters()`, `quickSearch()`.

C'est le bloc d'indexation qu'on sous-estime. Les résultats passent par `IWLPlugSearchEngineResult` (`app/lib/Plugins/IWLPlugSearchEngineResult.php`).

### Ce qu'il faut lire avant d'écrire une ligne

`ElasticSearch.php`, le répertoire `ElasticSearch/` (`Mapping.php`, `Query.php`, `Field.php`, `FieldTypes/`), `ElasticSearchResult.php` et `ElasticSearchConfigurationSettings.php`. C'est le seul connecteur vers un service externe livré avec CollectiveAccess : la référence pour la construction du mapping, la traduction des requêtes et le cycle de réindexation. `SqlSearch2.php` montre l'autre extrémité du spectre, tout-en-base.

### Commandes de réindexation

Vérifiées dans `app/lib/Utils/CLIUtils/Search.php` ; `caUtils` convertit les tirets en soulignés, les deux graphies marchent :

```sh
docker compose exec app caUtils rebuild-search-index
docker compose exec app caUtils process-indexing-queue
```

Piloter la réindexation par là plutôt que par l'interface, et lire `app/log/`.

## 5. Méthode

**Ajouter le service du moteur au `compose.yml`.** Il rejoint `db` et `app` dans le même réseau ; le connecteur l'atteint par son nom de service. C'est ce que fait le `docker-compose` d'exemple d'ElasticSearch chez CollectiveAccess.

**Ne conclure que sur une exécution.** Un constat s'adosse à une commande, sa sortie, et l'état observé dans l'instance. Un défaut lu dans le code mais non reproduit se signale comme tel, explicitement distingué d'un défaut confirmé.

**Éprouver les chemins d'échec autant que le chemin heureux.** Un connecteur réseau se juge là-dessus : moteur éteint, moteur lent au point de déclencher le délai d'attente, moteur qui répond une erreur ou un corps malformé, index absent, index à un mapping périmé. Le service se coupe et se ralentit facilement depuis `compose`.

**Mesurer la réindexation sur le corpus complet.** 250 objets ne disent rien du comportement à 2500. Le semis va jusque-là, s'en servir avant de déclarer le connecteur utilisable.
