# Meilisearch pour CollectiveAccess

Un moteur de recherche pour Providence, à côté de `SqlSearch2` et `ElasticSearch`.

Le greffon a deux moitiés, qui ne s'installent pas l'une sans l'autre :

| Répertoire | Installé en | Rôle |
|---|---|---|
| `MeilisearchSearchEngine/` | `app/lib/Plugins/SearchEngine/Meilisearch.php` et `…/Meilisearch/` | le connecteur : indexation, recherche, résultats |
| `MeilisearchAppPlugin/` | `app/plugins/Meilisearch/` | les réglages, l'état de santé, les points d'accroche sur le cycle d'enregistrement |

Le connecteur vit dans l'arborescence du socle — c'est là que `SearchBase::newSearchEngine()`
va le chercher, sans autre indirection — mais il n'y a pas de configuration à lui : il lit
`app/plugins/Meilisearch/conf/meilisearch.conf`. Sans le greffon applicatif, il refuse de
s'initialiser avec un message qui dit quoi faire. Sans le connecteur, le greffon applicatif se
déclare indisponible. C'est le couplage voulu.

## État

La recherche simple fonctionne et se vérifie : retrouver un objet par son titre ou par son
numéro d'inventaire, avec les filtres de résultat de CollectiveAccess (supprimé, accès, type)
et la mise à jour de l'index quand une fiche change.

Ce qui n'est pas encore fait est listé plus bas, sans faux-semblant.

## Installer

Trois liens symboliques et un réglage. C'est ce que fait [`dev/bin/dev-link-plugin`](dev/bin/dev-link-plugin),
qui sert aussi de documentation exécutable :

```sh
DEPOT=/chemin/vers/ce/depot
CA=/var/www/html          # racine de Providence

ln -sfn "$DEPOT/MeilisearchSearchEngine/Meilisearch.php"                      "$CA/app/lib/Plugins/SearchEngine/Meilisearch.php"
ln -sfn "$DEPOT/MeilisearchSearchEngine"                                      "$CA/app/lib/Plugins/SearchEngine/Meilisearch"
ln -sfn "$DEPOT/MeilisearchSearchEngine/MeilisearchConfigurationSettings.php" "$CA/app/lib/Plugins/SearchEngine/MeilisearchConfigurationSettings.php"
ln -sfn "$DEPOT/MeilisearchAppPlugin"                                         "$CA/app/plugins/Meilisearch"
```

Puis, dans **`app/conf/local/app.conf`** :

```
search_engine_plugin = Meilisearch
```

> **Pas dans `app/conf/local/app_<__CA_APP_NAME__>.conf`.** Cette couche est composée sur le
> papier par `Configuration.php`, mais `Configuration::load()` — la voie qu'emprunte tout
> CollectiveAccess — ne la charge pas. Le symptôme est un réglage qui « ne prend pas », sans
> le moindre message.

Enfin, l'adresse du service, dans `MeilisearchAppPlugin/conf/meilisearch.conf` ou, mieux en
conteneur, par l'environnement (`CA_MEILISEARCH_URL`) ou par une constante de `setup.php`
(`__CA_MEILISEARCH_URL__`). Puis :

```sh
caUtils rebuild-search-index
```

Apache doit avoir `+FollowSymLinks` sur la racine de Providence — c'est le cas par défaut.

## Le harnais de développement

Une instance Providence complète, le corpus de test et le moteur, en une commande :

```sh
cd dev && docker compose up
```

→ http://localhost:8089/gestion/ — **`administrator` / `collections`**.

Le premier démarrage construit l'image (clone du fork Providence à un SHA vérifié, `composer
install`), installe la base et charge **2500 œuvres du Cleveland Museum of Art**. Les images ne
sont pas téléchargées par défaut : elles pèsent l'essentiel du temps de chargement et
n'apprennent rien sur un moteur de recherche (`CA_SEED_CLEVELAND_MEDIA=1` pour les avoir).

Le corpus arrive en `access = 1`. À `0` — sa valeur d'origine — les filtres d'accès de
CollectiveAccess l'écarteraient entièrement des résultats, et il n'y aurait rien à mesurer.

| Commande | |
|---|---|
| `docker compose exec app dev-test` | tous les tests |
| `docker compose exec app dev-test recherche-simple` | un test |
| `docker compose exec app dev-reindex --processus=4` | réindexation parallèle |
| `docker compose exec app caUtils rebuild-search-index` | réindexation par le socle, séquentielle |
| `docker compose exec app caUtils process-indexing-queue` | traite la file de réindexation |
| `docker compose exec app dev-elaguer --simuler` | ce que l'élagage retirerait de l'index |
| `docker compose exec app dev-seed-cleveland 2500` | corpus court (2 500), idempotent |
| `docker compose exec app dev-seed-cma` | corpus du Cleveland Museum of Art (68 953) |
| `docker compose exec app dev-seed-rijksmuseum --colonnes` | inspecte le CSV du Rijksmuseum sans importer |
| `docker compose exec app dev-seed-rijksmuseum` | collection du Rijksmuseum (plafonnée à 300 000) |
| `docker compose exec app dev-install --force` | base neuve, tout est perdu |
| `curl -s localhost:7700/indexes` | l'état des index, depuis la machine hôte |
| `docker compose exec app tail -f app/log/meilisearch.log` | le journal du connecteur |

Le dépôt est monté sous `/opt/meilisearch/plugin` et lié dans l'arborescence de Providence au
démarrage. Éditer un fichier du connecteur et rafraîchir suffit : le cache de configuration de
CollectiveAccess et l'opcache de PHP sont coupés. Modifier un fichier de `dev/` demande en
revanche `docker compose up -d --build`.

Port **8089**, pas 8088 (pris par le harnais du greffon `lescollections`) ni 8080 (souvent pris
par un Apache ou un MAMP local, avec une collision sournoise : `localhost` résout `::1` en
premier et on tombe sur l'autre serveur sans que Docker ne se plaigne).

## Les tests

Pas de PHPUnit : le connecteur s'installe dans l'arborescence de Providence et ne peut pas y
apporter son `vendor/`. Chaque test fait un `require` de `setup.php` et parle à l'instance
réelle — la boucle est courte sans rien simuler.

Les recherches passent par `ObjectSearch`, la voie qu'emprunte l'interface : parseur Lucene,
réécriture des points d'accès, filtres de résultat, cache. Vérifier le connecteur seul dirait
qu'il sait parler à Meilisearch, pas qu'il s'insère dans la chaîne.

```
tests/_cadre.php                    assertions, déroulé, raccourcis
tests/recherche-simple.php          titre, numéro d'inventaire, filtres, mise à jour
tests/recherche-booleens.php        les opérateurs rendent-ils ce qu'ils promettent ?
tests/compatibilite-socle.php       les outils tiennent-ils hors du fork lescollections ?
tests/reindexation-parallele.php    le réindexeur parallèle rend-il le même index ?
```

Les outils livrés avec le greffon, eux, vivent dans `MeilisearchAppPlugin/tools/` — installés
avec lui, donc disponibles en production :

```
tools/reindexer.php               réindexation complète, en parallèle
tools/elaguer-indexation.php      réduit ce qui est indexé, et dit ce qu'on perd
tools/mesurer-indexation.php      où passe le temps d'une réindexation
tools/mesurer-recherche.php       où passe le temps d'une recherche
tools/diagnostiquer-recherches.php  quelles recherches le connecteur rend-il mal ?
tools/verifier-socle.php          cette instance peut-elle recevoir le connecteur ?
```

**`tools/verifier-socle.php`** se lance sur l'instance visée **avant** de basculer
`search_engine_plugin` : liens en place, connecteur instanciable, méthodes hors interface
présentes, moteur joignable, journal écrivable, historique de recherches exploitable. Le
connecteur a été écrit contre le fork `lescollections/providence` et touche des points qu'aucune
interface ne garantit — mieux vaut trente secondes de vérification que la découverte au milieu
d'une réindexation de plusieurs centaines de milliers de fiches.

**`tools/diagnostiquer-recherches.php`** vérifie ce qui se vérifie sans référence : les identités
booléennes (`a AND b` doit rendre l'intersection de `a` et de `b`, `a OR b` leur union), le
comptage SQL, l'inclusion d'une recherche restreinte dans la recherche libre. Les termes sont
tirés du fonds lui-même, l'outil se transporte donc d'une instance à l'autre. Deux autres modes :
`--historique[=N]` rejoue les recherches réellement faites par les usagers (`ca_search_log`) et
compare le nombre de résultats d'alors à celui d'aujourd'hui — sur une instance qui tournait sous
SqlSearch2, c'est la seule comparaison qui vaille ; `--plan "expression"` montre ce que le
connecteur a compris d'une expression, et par où elle s'est perdue.

## Réindexer vite

Une réindexation complète du corpus de 2500 objets prend **11 s** par la commande du socle. Le
connecteur n'y est pour presque rien : **94 % du temps est passé dans `SearchIndexer::indexRow()`**,
3 % à parler à Meilisearch. Le coût vient de `search_indexing.conf`, qui déclare 24 tables liées
à verser dans l'index de `ca_objects` — soit une quarantaine de requêtes SQL par objet, y compris
pour des relations vides.

Deux outils, livrés avec le greffon donc disponibles en production :

**`tools/reindexer.php`** fait le même travail que `caUtils rebuild-search-index` en le
répartissant sur plusieurs processus. En mode réindexation, `indexRow()` est indépendant d'une
ligne à l'autre ; on découpe les identifiants par modulo, on tronque une fois avant de lancer, et
chaque processus prend sa part. `tests/reindexation-parallele.php` vérifie que l'index produit est
identique **document par document** à celui du socle.

**`tools/elaguer-indexation.php`** dérive `app/conf/local/search_indexing.conf` de celui du socle
en retirant des tables liées. Ce n'est pas gratuit : ce qui n'est plus indexé n'est plus
cherchable — d'où `--simuler`, qui énumère précisément ce qu'on abandonne, champ par champ, sans
rien écrire. `--defaire` revient à l'état livré.

Mesuré sur `ca_objects`, 2500 objets, huit cœurs :

| | 1 processus | 4 processus |
|---|---|---|
| configuration livrée | 8,3 s | **3,0 s** |
| élagage par défaut (prudent) | 7,3 s | 2,9 s |
| élagage étendu (lieux, occurrences, collections, lots…) | 5,7 s | **2,5 s** |

La parallélisation est le levier qui compte : ×2,8. L'élagage prudent ne rapporte que 12 %, et
presque rien une fois le travail réparti — la liste par défaut est volontairement conservatrice.
Un élagage étendu ajoute ×1,2. Utile à grande échelle, marginal ici.

Trois points à connaître avant d'y toucher :

- **la file d'attente n'est pas parallélisable de cette façon** : `ca_search_indexing_queue::process()`
  prend un verrou de fichier exclusif ([`LockingTrait.php:52`](https://github.com/collectiveaccess/providence/blob/master/app/lib/Utils/LockingTrait.php)),
  un seul processus à la fois. Il faudrait toucher au socle ;
- **le gain plafonne à quatre processus** sur huit cœurs : au-delà, MariaDB et le CPU sont saturés,
  pas Meilisearch, qui encaisse les écritures concurrentes sans broncher ;
- **la surcharge `app/conf/local/search_indexing.conf` est bien chargée** par `Configuration::load()`,
  contrairement à `app/conf/local/app_<nom>.conf`. La fusion se fait par table sujet : le fichier
  ne contient que les blocs modifiés.

## Comment c'est fait

**Un index par table sujet** (`ca_ca_objects`, `ca_ca_entities`…), comme le connecteur
ElasticSearch. Les documents ne contiennent que des identifiants et du texte cherchable : rien
d'affichable, CollectiveAccess relit toujours la base pour l'affichage.

**Les noms de champs sont normalisés** : `ca_objects/idno` devient `ca_objects__idno`.
Meilisearch accepte n'importe quelle clé JSON dans un document, mais son langage de filtre et
ses listes d'attributs réclament des noms en `[A-Za-z0-9_-]`.

**Les filtres de résultat sont appliqués en SQL**, sur les identifiants rendus par le moteur —
comme le fait `SqlSearch2`, et non traduits en filtres Meilisearch. Un filtre porte souvent sur
un champ qu'on n'a aucune raison d'indexer, et un filtre qui ne s'applique pas silencieusement,
c'est un enregistrement supprimé qui réapparaît dans les résultats.

**L'indexation est incrémentale sans relecture.** Meilisearch fusionne les documents partiels
(`PUT /documents`) : on envoie les champs modifiés, les autres sont conservés. ElasticSearch
impose au connecteur livré avec CollectiveAccess un aller-retour de lecture pour chaque
fragment ; on s'en passe.

**On attend les tâches, mais une seule fois.** Meilisearch écrit en différé : toute écriture rend
la main aussitôt et n'est visible qu'une fois la tâche `succeeded`. Le connecteur enfile les lots
sans les attendre, puis attend leur aboutissement au versement final — et vérifie qu'aucun n'a
échoué. La garantie reste entière (`waitForIndexing`) : un `rebuild-search-index` qui se termine
signifie « l'index est prêt », et les tests restent reproductibles. Attendre chaque lot coûtait
10 % du temps d'une réindexation, en pur temps mort : le moteur absorbe un lot dix fois plus vite
que CollectiveAccess n'en fabrique un. `maxPendingTasks` plafonne le nombre de lots enfilés, par
sécurité et non par régulation.

**Les booléens suivent l'arbre, pas une liste de clauses.** L'expression réécrite par le socle
arrive sous forme d'arbre Lucene ; le connecteur en fait un plan de même forme, dont les
feuilles sont des recherches Meilisearch et les nœuds des combinaisons ensemblistes. Toutes les
feuilles partent en un seul `/multi-search` : le coût d'une requête booléenne est d'un
aller-retour, pas d'un par opérateur. Deux points méritent d'être connus :

- des termes *exigés* de même portée sont mis en commun dans un seul `q` avec
  `matchingStrategy: all` — c'est exact, et le classement par pertinence tient alors compte de
  tous les mots. Des termes *facultatifs* ne le sont jamais : ils rendraient l'intersection à la
  place de l'union ;
- une recherche qui n'est qu'une exclusion (`-bronze`) part du fonds entier et retranche.
  Répondre « rien » à « tout sauf ceci » serait une réponse fausse.

**Une recherche sur un champ non indexé est signalée.** Chercher dans un attribut qu'aucun
document ne porte ne lève rien et ne rend rien : zéro résultat se lit « il n'y a rien de tel »
alors qu'il faut lire « ce champ n'est pas indexé ». Le connecteur relève les attributs présents
dans l'index (une requête par index et par processus, seulement si la recherche vise un champ
précis) et écrit une alerte dans `app/log/meilisearch.log`.

**Les identifiants se cherchent exactement.** Meilisearch complète le dernier mot d'une requête
en préfixe ; sans précaution, chercher `CLE.1928.8` ramène aussi `CLE.1928.856`. Un terme visant
un champ de numérotation, ou qui mêle chiffres et séparateurs, est donc entouré de guillemets.
Cela ne rend pas un numéro univoque pour autant : CollectiveAccess indexe les préfixes
hiérarchiques d'un numéro, si bien que `CLE.1938.388.a` contient littéralement `CLE.1938.388`.
Chercher un numéro ramène donc ses sous-numéros — c'est le socle qui le veut, et ce n'est pas au
connecteur de le défaire.
Le texte libre, lui, garde la complétion et la tolérance aux fautes : c'est ce qu'on vient
chercher chez Meilisearch, et c'est un écart assumé par rapport à `SqlSearch2`.

**En cas de panne, deux comportements opposés, et c'est voulu :**

- à la **recherche**, le connecteur journalise et rend zéro résultat — l'application reste
  utilisable, et `app/log/meilisearch.log` dit pourquoi ;
- à l'**indexation**, il lève. Un index silencieusement incomplet est pire qu'un échec franc.

### Le contrat, tel qu'il est vraiment

La dépendance joue dans les deux sens, et les deux sens ont mordu.

**Ce que les outils du greffon demandaient au socle** : `SearchIndexer::getFieldDataForReindex()`
n'existe que dans le fork. Sur une autre instance, `tools/reindexer.php` mourait à la première
tranche (« Call to undefined method »). Les outils passent maintenant par
`tools/_socle.php`, qui lit la table lui-même quand la méthode manque ;
`tests/compatibilite-socle.php` vérifie que ce repli écrit **le même index**, document par
document, et non pas seulement qu'il s'exécute.

**Ce que le fork demande au connecteur.** `IWLPlugSearchEngine` ne suffit pas : le fork
`lescollections/providence` appelle en plus, sans `method_exists()` :

- `flushContentBuffer()` — `SearchIndexer.php:322`, en fin de réindexation complète. Son absence
  fait mourir `rebuild-search-index` sur une erreur fatale *après* avoir tout indexé, ce qui
  rend le symptôme trompeur ;
- `setOption('useAsync', …)` — `SearchIndexer.php:82`. `setOption()` refuse en silence ce qu'il
  ne connaît pas, donc l'option doit exister ;
- `clearCaches()` et `setDb()` sont appelées sous `method_exists()`, mais les fournir évite des
  surprises.

`getRawResultDesc()` est attendue sur l'objet résultat dès que
`return_search_result_description_data` passe à 1 dans `search.conf`.

### Le socle n'indexe pas toujours tout de suite

Modifier une étiquette dépose une entrée dans `ca_search_indexing_queue` au lieu d'écrire
l'index. Il faut `caUtils process-indexing-queue`. Rien de propre à ce connecteur — c'est
CollectiveAccess — mais il faut le savoir, sinon on accuse le moteur.

## Ce qui n'est pas fait

- **Les recherches par intervalle** (dates, dimensions, mesures) sont ignorées et signalées dans
  le journal. Elles demandent des attributs filtrables typés et une traduction des bornes ;
  `ElasticSearch/FieldTypes/` montre l'ampleur du travail.
- **`[BLANK]` et `[SET]`** (champ vide / champ renseigné) ne sont pas traduits.
- **`restrictSearchToFields` / `excludeFieldsFromSearch`** : la capacité `restrict_to_fields` est
  déclarée fausse. Meilisearch sait le faire (`attributesToSearchOn`), il reste à convertir les
  points d'accès en attributs.
- **`updateIndexingInPlace()` fusionne au niveau de l'attribut.** Pour un champ à valeurs
  multiples, la valeur envoyée remplace l'ensemble des valeurs du champ au lieu de n'en
  remplacer qu'une. `ElasticSearch` tient pour cela une liste parallèle d'identifiants de
  contenu ; il faudra faire de même, ou replanifier une réindexation complète de la ligne
  depuis le greffon applicatif.
- **La description des correspondances** (`getRawResultDesc`) rend un tableau vide :
  `return_search_result_description_data` doit rester à 0.
- **Le classement d'une requête à plusieurs groupes** vient du premier groupe positif ; les
  autres ne font que restreindre. C'est défendable, ce n'est pas une fusion de pertinence.
- **Pawtucket** n'a pas été essayé.

## Ce que le harnais assume et qui n'a rien à faire ailleurs

- `policy.advisories.block false` au `composer install` : CollectiveAccess épingle
  `dompdf ~2.0.2`, dont toutes les versions portent des avis de sécurité, et Composer 2.9 refuse
  donc de l'installer. La contrainte vient du fork ; la lever, ce serait ne plus être aligné.
- `__CA_ALLOW_INSTALLER_TO_OVERWRITE_EXISTING_INSTALLS__`, nécessaire à `dev-install --force`.
- `allow_fetching_of_media_from_remote_urls = 1`, sans quoi le corpus arrive sans images.
- Meilisearch tourne **sans clé** (`MEILI_ENV=development`). Poser `CA_MEILISEARCH_API_KEY` dans
  `dev/.env` pour éprouver le chemin authentifié : la clé est passée au service *et* au
  connecteur.

Le harnais n'écoute que sur `127.0.0.1` et ses données sont jetables.
# meilisearch
