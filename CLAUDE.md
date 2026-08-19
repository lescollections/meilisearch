# Notes de travail — connecteur Meilisearch pour CollectiveAccess

Contexte pour reprendre le travail. Le README documente le greffon ; ce fichier note ce qui
oriente les décisions et ne se lit pas dans le code.

## L'échelle réelle

**Les bases visées vont jusqu'à environ 300 000 fiches.** Ce n'est pas un cas limite, c'est le
cas courant. Tout ce qui est mesuré sur 2 500 fiches ne dit rien : la mémoire, les caches, la
taille de l'index et le débit d'écriture de Meilisearch n'entrent en jeu que bien plus loin.

Conséquences déjà identifiées, à vérifier par la mesure et non par le raisonnement :

- le filtrage des résultats se fait en SQL, avec un `WHERE object_id IN (…)` construit sur les
  identifiants rendus par le moteur. À 300 000 résultats, la clause devient énorme ;
- le connecteur attend l'aboutissement de chaque lot d'écriture (500 documents). À 300 000
  fiches, cela fait 600 attentes ; il faudra sans doute n'attendre qu'à la fin ;
- `MeilisearchResult` garde tous les identifiants en mémoire ;
- l'élagage de `search_indexing.conf`, marginal à 2 500 fiches, change de statut à cette échelle :
  chaque requête SQL évitée est multipliée par le nombre de fiches.

## Corpus de test

| Source | Volume | État |
|---|---|---|
| `rochambeau/example/cleveland` | 2 500 | chargé par `dev-seed-cleveland`, boucle courte |
| [ClevelandMuseumArt/openaccess](https://github.com/ClevelandMuseumArt/openaccess) | 68 953 | chargé par `dev-seed-cma`, CSV 131 Mo via Git LFS |
| [Rijksmuseum, CSV](https://github.com/Rijksmuseum/rijksmuseum.github.io/releases/download/1.0.0/202001-rma-csv-collection.zip) | ~800 000 | `dev-seed-rijksmuseum` écrit, **jamais exécuté** |
| [Rijksmuseum, LIDO](https://github.com/Rijksmuseum/rijksmuseum.github.io/releases/download/1.0.0/202001-rma-lido-collection.zip) | ~800 000 | variante XML, plus riche ; pas de semeur |

Le Rijksmuseum est la source qui permet d'atteindre l'échelle réelle. **Plafonner à 300 000, pas
besoin des 800 000** — le semeur applique ce plafond de lui-même. Ne rien télécharger sans que
ce soit demandé : ces archives sont lourdes.

> **Le semeur Rijksmuseum n'a jamais tourné.** L'archive n'a pas été téléchargée, donc les noms
> exacts des colonnes du CSV n'ont pas été vérifiés — la documentation du musée annonce le
> contenu (numéro d'objet, identifiant pérenne, titre, type, créateur, date, image) mais pas
> l'en-tête. Le semeur cherche les colonnes parmi des variantes plausibles au lieu de les
> supposer, et `dev-seed-rijksmuseum --colonnes` affiche l'en-tête réel sans rien importer.
> **Commencer par là**, et compléter la table `ALIAS` du script si une colonne n'est pas
> reconnue.

Le CSV du Rijksmuseum est bien plus maigre que celui du CMA : peu de texte libre, mais le volume
recherché. Pour éprouver la recherche plein texte à grande échelle, le LIDO serait plus riche —
au prix d'un analyseur XML en flux.

Le corpus CMA arrive en `access = 1` et sans images (`CA_SEED_CMA_MEDIA=1` pour les avoir).
L'indexation de recherche est coupée pendant le chargement, pour que la réindexation se mesure
comme une opération à part.

## Point d'étape — 19 août 2026 : les facettes du Browse

**Le Browse délègue ses facettes au moteur.** Deux moitiés : le connecteur indexe la matière et
répond (`Facets.php`, le pont), le socle porte un point de délégation de 36 lignes dans
`BrowseEngine::getFacetContent()` — branche `feature/meilisearch-browse-facets` dans les clones
locaux `../providence` et `../pawtucket2` (BrowseEngine y est du code partagé : 88 lignes d'écart
sur 8 480, ailleurs que dans notre zone ; le patch est octet pour octet le même).

Doctrine du pont : **traduire exactement ou décliner** — un `null` rend la main au SQL du socle,
facette par facette, sans réglage. Couvert : `fieldList`, `has`, `authority` (avec hiérarchies),
critère `_search` en texte nu, checkAccess, restrictions de type/source, `is_deaccessioned`.
Décliné d'office : `relative_to`, `filter`, ACL par enregistrement (sorti du périmètre — un seul
client s'en sert pour la visibilité), `exclude_relationship_types`, plusieurs
`restrict_to_relationship_types` (double compte), relations réflexives, syntaxe Lucene dans
`_search`, et tout type non couvert. `tests/browse-facettes.php` : 18 vérifications d'équivalence
contre l'instance, qui fabriquent leur matière authority (entités + vocabulaire hiérarchique
« Abyssinie » marqué TEST-FAC) — le harnais n'a aucun lien objet-entité naturel.

À 71 277 fiches : `type_facet` 211 → 2,8 ms à vide, 233 → **1,3 ms** sous un critère à 70 964
résultats (le `IN (…)` littéral du SQL contre un `filter`). Réindexation : 892 fiches/s, pas de
régression. `maxValuesPerFacet` posé à 500 000 : les facettes sont exhaustives (50 000 valeurs
distinctes rendues en 42 ms) — le top-N n'est pas un plafond du moteur, juste son défaut.

### Pièges payés en route

- **Les appels `COUNT` de l'indexeur portent la table liée mais le `row_id` du sujet**
  (`SearchIndexer.php:1237`) : sans les écarter, chaque objet se croit lié à l'entité homonyme
  de son propre identifiant. Vu parce que le harnais n'a aucune vraie relation.
- **Le SQL du socle écarte un *ancêtre* sans `type_id`, sans accès ou hors restriction, mais
  garde un lien *direct* dans les mêmes conditions** (`BrowseEngine.php:7262-7269`). D'où le
  second attribut `facet_direct__<table>` sur les tables hiérarchiques : les deux distributions
  s'obtiennent dans la même requête, et le pont applique la règle du socle à l'identique.
- **Le filtre d'accès du Browse porte aussi sur les items de liste eux-mêmes** (`li.access`) :
  dans le profil livré, tous les types d'objet ont `access = 0` — filtrer par accès vide la
  facette des types, en SQL comme au moteur. C'est le socle qui le veut.
- **`attributePatterns` (motifs `facet__*` dans `filterableAttributes`) n'existe qu'en
  Meilisearch ≥ 1.14** ; le harnais est en 1.13. D'où l'énumération : base connue posée à la
  création de l'index, variantes par type de relation déclarées au fil des versements — et
  `prepareIndex()` **fusionne** avec l'existant au lieu de remplacer, sinon chaque nouveau
  processus effacerait les variantes et paierait une reconstruction des bases de facettes.
- Écart assumé, documenté dans le test : le compte d'un ancêtre est le nombre de **documents
  distincts** de sa branche ; le SQL additionne les branches et compte deux fois l'objet lié à
  deux descendants. Notre compte est le bon.

### floutier — déployé, comparé, **pas basculé**

Première instance réelle. Tout est en place et éprouvé ; `search_engine_plugin` vaut **encore
SqlSearch2**, et la bascule reste à décider (voir plus bas ce qu'elle emporte).

| | |
|---|---|
| dépôt | `/opt/lescollections/meilisearch` — hors racines web, prévu pour servir les 17 vhosts |
| moteur | service systemd, **127.0.0.1:7700 seulement**, mode production avec clé dans `/etc/meilisearch.env` |
| version | **1.53.1** — et non la 1.13.3 du harnais ; à savoir, rien n'a divergé |
| réglages | constantes dans `setup.php` (non suivi par git) : URL, clé, préfixe `floutier` |
| index | 1 629 documents, 23 Mo de fichier, 12 Mo utiles |
| socle | `collectiveaccess/providence` amont — mais `BrowseEngine.php` **identique au fork, md5 pour md5** |
| comparaison | `comparer-facettes.php` : **22 conformes, 28 au SQL, 0 divergente** |
| gains | `type_facet` ×5 (17,9 → 3,9 ms), `has_media_facet` ×7 (27,1 → 3,7 ms) |

**Le fonds est maigre en relations** : 0 lien objet-entité, lieu, vocabulaire, collection. Seules
`type_facet`, `has_media_facet` et `access_facet` y ont du contenu. Les autorités et les
hiérarchies n'y sont donc **pas éprouvées** — elles ne le sont que par la matière fabriquée du
test sur le harnais. Pour les valider sur des données réelles, il faut une instance qui lie ses
objets : préprod-130.32.

**Ce que la bascule emporterait, et qui n'est pas décidé :**

- elle change la **recherche**, pas seulement les facettes : les manques connus du connecteur
  (`oil NOT canvas` rend tout le fonds, `||` traité comme AND, intervalles ignorés, préfixe de
  deux lettres qui ramène tout) deviendraient visibles des catalogueurs ;
- **`quickSearch()` n'applique aucun filtre** — floutier porte 23 fiches `access = 0`. En
  arrière-guichet le catalogueur voit de toute façon tout, donc pas de fuite ici ; mais c'est la
  raison de plus de ne pas basculer Pawtucket ;
- **Pawtucket a sa propre `app/conf`** (pas un lien), il resterait donc sur SqlSearch2 — dont
  l'index cesserait d'être tenu à jour, puisque Providence écrirait ailleurs. Le site public
  dériverait doucement. Réversible : remettre la ligne et `rebuild-search-index`.

Retour en arrière du déploiement entier : `./installer.sh <racine> --retirer`.

### Mayenne — le connecteur ne tourne pas sur CollectiveAccess 1.7

Écarté comme terrain d'essai, pour une raison de fond qu'il faut connaître avant de proposer
une instance : **le connecteur ne peut pas même se charger sur un socle 1.7.**

`IWLPlugSearchEngine` y déclare ses paramètres sans types
(`indexField($pn_content_tablenum, $ps_content_fieldname, …)`) là où le connecteur les type.
Typer un paramètre que l'interface laisse libre viole la contravariance : PHP refuse la classe
à l'analyse, pas à l'exécution. Vérifié par reproduction minimale plutôt que supposé —
« *Declaration of Connecteur::indexField(int $a, …) must be compatible with
ISocle17::indexField($a, …)* ». Le remède, si le besoin revient : ôter les types des méthodes
qui implémentent l'interface. Une signature *sans* type est compatible avec les deux socles
(elle est plus large), donc un seul connecteur pourrait servir 1.7 et 2.0 — au prix de la
sûreté de typage. Le `BrowseEngine` de 1.7 (7 551 lignes contre 8 480) est par ailleurs trop
différent : `patcher-browse.php` refuse de s'y poser, ce qui est exactement son rôle.

**Et la machine ne peut pas passer en 2.0 sans frais.** Debian 11, PHP 7.4 seul, pas de dépôt
Sury. Y installer PHP 8.4 en FPM à côté de mod_php 7.4 est possible, mais entraîne la montée
de `libpcre2` (5 paquets), `libgd3` — la chaîne média — et `php-common`, tous partagés avec la
production hébergée sur la même machine. Écarté pour cette raison. À savoir si l'on y revient :
`unattended-upgrades` y est actif mais n'accepte que les origines Debian, donc un dépôt Sury
ajouté ne peut rien déclencher de lui-même ; l'épinglage à priorité −1 sur `php7.4*`,
`php8.5*`, `php`, `php-cli`, `php-redis` et `php-igbinary` suffit à contenir le reste.

**Ce qu'on fait à la place** : la base de recette est rapatriée dans le harnais local. Le dump
tombe de 5,5 Go à 60 Mo en écartant les données de `ca_sql_search_word_index` (3,4 Go à lui
seul), des trois tables de `ca_change_log`, de `ca_eventlog` et de `ca_task_queue` — tout se
reconstruit, et la structure est conservée. Le socle porte une commande dédiée,
`caUtils update-from-1-7` (schéma, valeurs de tri, réindexation complète).

> **Le harnais accueille une seconde base sans toucher à la première.** `conf/setup.php` lit
> `CA_DB_DATABASE` dans l'environnement, et le préfixe d'index vient de
> `CA_MEILISEARCH_INDEX_PREFIX` : `docker compose exec -e CA_DB_DATABASE=mayenne
> -e CA_MEILISEARCH_INDEX_PREFIX=mayenne …` travaille sur Mayenne pendant que les 71 277 fiches
> du CMA et leur index restent intacts. **Ne pas oublier le préfixe** — sans lui, la
> réindexation de la migration écrirait dans l'index du corpus CMA.

### Mayenne dans le harnais — le premier fonds réellement relationnel

La base de recette (CollectiveAccess 1.7) est rapatriée, restaurée à côté du corpus CMA et
migrée en 2.0 par `caUtils update-from-1-7` (schéma 158 → 212, 20 min d'indexation). C'est le
premier jeu de données qui éprouve vraiment les autorités : **24 709 liens objets-entités**,
4 339 vers des lieux hiérarchiques, 2 786 vers des occurrences, 9 609 représentations, avec des
types de relation de métier (`graveur`, `dessinateur`, `origine_decouvreur`, `decouverte`…).

`comparer-facettes.php --criteres` : **30 conformes, 20 au SQL, 4 divergentes.**

**Deux divergences trouvées et corrigées de notre côté :**

- **une autorité sans étiquette préférée doit disparaître de la facette.** Le socle ne la rend
  pas (sa requête de libellés est séparée, et l'entrée vide est écartée) ; le pont la gardait
  sous « ??? », ajoutant à la facette une valeur que le SQL n'y met pas. Un seul cas dans tout
  le fonds — la racine des lieux de rangement — et il suffisait à faire diverger la facette ;
- **la tolérance du comparateur sur les nœuds hiérarchiques était trop étroite** : elle jugeait
  « branche » d'après les enfants *présents dans la facette*, ce qui rate un ancêtre dont les
  descendants comptés sont des petits-enfants. Elle interroge maintenant la hiérarchie réelle.

**Deux divergences qui restent, et où c'est le socle qui a tort :**

- **le SQL surcompte les autorités liées par plusieurs types de relation.** Sa requête groupe
  par autorité *et* par `rel_type_id`, puis additionne les lignes (`BrowseEngine.php:7232`).
  L'entité 1043 de Mayenne a 8 liens vers 4 objets distincts — chacun lié deux fois — et le SQL
  affiche 4 là où cliquer en rend 2. Notre compte est celui des fiches, c'est-à-dire de ce que
  le clic rend vraiment ;
- **une facette `fieldList` perd les valeurs dont l'item de liste est supprimé.** 17 objets de
  Mayenne portent un `type_id` dont l'item est `deleted = 1` ; l'indexeur du socle n'écrit rien
  pour eux (il indexe les *libellés* de l'item, et un item supprimé n'en a plus), tandis que sa
  facette SQL, qui lit `ca_objects.type_id` directement, les affiche encore. **C'est le
  corollaire de fond de la délégation : tout ce que l'indexeur saute devient invisible aux
  facettes**, alors que le SQL du socle atteint des données que l'index n'a pas. Même famille
  que les « recherches muettes » notées plus haut.

> **Piège coûteux : le cache de CollectiveAccess ignore la base.** Il vit dans
> `<tmp>/<__CA_APP_NAME__>Cache` (`ExternalCache.php:241`) ; deux bases servies par la même
> arborescence partagent donc leurs listes en cache. Symptôme observé : une facette de types du
> corpus CMA rendant les libellés de Mayenne (« Ethnologie », « Histoire naturelle ») et perdant
> deux valeurs — des divergences qui apparaissent et disparaissent selon l'ordre des exécutions.
> `dev/conf/setup.php` fait désormais porter le nom de la base à `__CA_APP_NAME__`. **Sur une
> instance réelle, ne jamais servir deux bases depuis la même arborescence sans le faire.**

### À reprendre là (facettes)

1. **Décider la bascule de floutier** (une ligne dans `app/conf/local/app.conf`), au vu de ce qui
   précède. L'index est déjà chaud : `reindexer.php --moteur=Meilisearch` l'a rempli pendant que
   SqlSearch2 servait. Vérifier ensuite l'écran de browse réel, pas seulement l'API.
2. Types de facette suivants par valeur décroissante : `attribute` (élément de liste),
   `normalizedDates` (via `facetStats`/intervalles — indexer la valeur normalisée), `label`.
3. `execute()` lui-même (le jeu de résultats du browse) reste en SQL : le déléguer quand tous
   les critères se traduisent ferait disparaître la dernière clause `IN` géante.
4. La suppression d'une relation vide l'attribut de facette en entier (même grain que les
   attributs texte) ; c'est la réindexation replanifiée par le greffon qui remet les liens
   restants — à éprouver sur floutier avec de vraies éditions.

## Point d'étape — 18 août 2026

Le harnais contient **71 277 fiches** (2 500 rochambeau + 68 777 CMA), index à jour, volumes
Docker conservés : rien à recharger à la reprise. Le projet `meilisearch-dev` tourne encore ;
`docker compose stop` depuis `dev/` si besoin de libérer la machine.

Les quatre points laissés en suspens la veille sont traités, sauf le Rijksmuseum.

### Ce que la mesure a dit

**Le filtrage SQL n'était pas le coupable.** Sur une recherche qui ramène tout le fonds
(71 277 résultats, 401 ms) : 330 ms dans Meilisearch, **52 ms** dans le `WHERE object_id IN (…)`,
21 ms dans le reste de CollectiveAccess. Le coût dominant est la matérialisation des hits par le
moteur — 352 ms de `processingTimeMs` pour rendre 71 277 identifiants, le transfert étant
négligeable en local. L'endpoint `/documents` n'est pas une échappatoire : 666 ms, plus lent.
Une recherche ordinaire (1 000 à 2 000 résultats) coûte 11 à 15 ms au total.

> L'ancien `mesurer-recherche.php` soustrayait deux mesures indépendantes et affichait 0 ms
> partout. Il décompose maintenant la chaîne réelle, par les compteurs du client
> (`Client::stats()`) et un compteur de filtrage ajouté au connecteur
> (`WLPlugSearchEngineMeilisearch::searchStats()`).

**L'attente des tâches pesait 10 % de l'indexation, et était invisible.** Le tampon se vide de
lui-même tous les 500 documents, *pendant* `indexRow()` : l'attente était donc comptée dans
« fabrication des données » et faisait croire que CollectiveAccess était plus lent qu'il n'est.

### Ce qui a été corrigé

**1. On n'attend plus chaque lot.** Les versements de mi-parcours enfilent sans attendre ; le
versement final attend d'un coup et vérifie qu'aucun lot n'a échoué (`Client::waitForTasks()` :
une attente au lieu de N, puis une requête sur les tâches en échec). La garantie est intacte —
quand `flush()` rend la main, l'index est prêt et rien n'a échoué en silence. Sur 10 000 objets :

| | avant | après |
|---|---|---|
| attente des tâches | 3,46 s (10,3 %) | 0,22 s (0,7 %) |
| requêtes HTTP | 124 | 32 |
| débit | 297 objets/s | **331 objets/s** |

`maxPendingTasks` (32) plafonne les lots enfilés, par sécurité : le producteur est de toute
façon dix fois plus lent que le moteur, la file ne s'accumule pas.

**2. Les booléens suivaient une liste de clauses au lieu de l'arbre.** Trois défauts d'un coup,
tous réels et tous corrigés en donnant au plan la forme de l'arbre Lucene :

| | avant | après |
|---|---|---|
| `folio OR gospel` (A∪B = 2 927) | 1 679 — l'intersection ! | 2 927 |
| `(folio OR gospel) AND fragment` (3) | 0 | 3 |
| `-canvas` (complémentaire, 70 155) | 0 | 70 155 |

La cause du premier : les clauses de même portée étaient fusionnées dans un seul `q` avec
`matchingStrategy: all`, ce qui exige tous les mots. Cette mise en commun ne vaut que pour les
termes *exigés* ; elle est conservée pour eux (elle est exacte, et le classement tient alors
compte de tous les mots).

> **Piège rencontré en corrigeant** : une feuille ne doit pas porter sa propre négation, sinon
> elle est niée deux fois — une fois par elle, une fois par le nœud qui la range — et
> `oil -canvas` rend `oil ∩ canvas`. Vu et corrigé (`bucketFor()` est le seul endroit où la
> négation compte).

**3. Une recherche sur un champ non indexé ne se taisait plus.** Elle ne lève rien et ne rend
rien : zéro résultat se lit « il n'y a rien de tel » alors qu'il faut lire « ce champ n'est pas
indexé ». Le connecteur relève les attributs présents dans l'index (`fieldDistribution`, une
requête par index et par processus, seulement si la recherche vise un champ précis) et écrit
une alerte. **C'est le corollaire direct de l'élagage de `search_indexing.conf` : chaque table
retirée fabrique des recherches muettes.**

### Ce qui reste faux, et pourquoi

**Défauts du socle, pas du connecteur** — vérifiés en instrumentant l'arbre reçu :

| syntaxe | résultat | |
|---|---|---|
| `oil -canvas`, `oil AND NOT canvas` | 712 | exact |
| `oil NOT canvas`, `oil !canvas` | 71 277 | **tout le fonds** |
| `oil \|\| canvas` | 1 001 | traité comme AND |

Dans les cas fautifs, le connecteur reçoit une **expression vide et un arbre sans sous-requête** :
le parseur de CollectiveAccess a jeté la requête avant lui. Même chose pour `AND`, `(`, `"` ou
une seule lettre, qui rendent le fonds entier. L'usager croit restreindre et reçoit tout.

**Limites de Meilisearch**, à documenter plutôt qu'à corriger :

- troncature initiale (`*ospel`) impossible — le moteur ne cherche que par préfixe ;
- joker interne (`g*spel`) : l'astérisque est retiré, résultat approximatif ;
- `foli*` rend **moins** que `folio` (2 736 contre 2 858) : le terme complet bénéficie de la
  correction orthographique, le préfixe non ;
- intervalles `[x TO y]` non traduits — et le parseur les a déjà découpés en trois termes texte
  qui polluent la recherche ; le connecteur le signale au journal ;
- un terme de deux lettres (`fo`) ramène tout le fonds, par complétion de préfixe. À surveiller
  sur l'autocomplétion, d'autant que **`quickSearch()` n'applique aucun filtre** — ni accès, ni
  supprimés (SqlSearch2 a au moins `omitPrivateIndexing`).

**4. Le journal ne se tait plus.** Voir les pièges plus bas : `Log` se replie sur `error_log()`
et pose le fichier en 0666, faute de quoi une instance dont le journal a été créé en root perd
silencieusement toutes les alertes du connecteur côté web.

`tests/recherche-booleens.php` protège ces corrections : identités booléennes, structure des
parenthèses, exclusion seule, `OR` sur champ qualifié, et l'alerte de champ non indexé. Les
termes de référence y sont tirés du fonds (`mots_de_reference()` dans le cadre), le test se
transporte donc d'une instance à l'autre.

### À reprendre là

1. **Lancer `tools/diagnostiquer-recherches.php --historique` sur préprod-130.32** (130.32,
   plusieurs centaines de milliers de fiches). C'est la seule comparaison qui vaille : `num_hits`
   y a été écrit par SqlSearch2, sur des requêtes que des usagers ont réellement tapées. L'outil
   classe en identique / à 5 % près / plus large / plus étroite / **vidée**, et imprime la
   commande `--plan` pour chaque recherche vidée. Deux limites : il ne travaille que sur
   `ca_objects`, et compte ~10 à 500 ms par expression distincte.
2. `quickSearch()` sans filtre d'accès : décider si c'est acceptable.
3. Puis le Rijksmuseum pour atteindre 300 000 (commencer par `--colonnes`).

### Empreinte à 71 277 fiches

| | |
|---|---|
| fichier LMDB (`databaseSize`) | 649 Mo |
| pages réellement utiles (`usedDatabaseSize`) | **344 Mo**, soit 4,8 Ko/fiche |
| documents bruts | 74 Mo (1 091 o/doc) |

**LMDB ne rend jamais au système les pages qu'il libère** : cet index traîne 305 Mo de pages
libres après une dizaine de réindexations. Le remède est un dump/restore, ou la suppression puis
reconstruction de l'index. À 300 000 fiches, compter ~1,4 Go utile et jusqu'à ~2,8 Go de fichier.

RAM du moteur, selon ce qu'il vient de faire : 25 Mo à froid, ~285 Mo après des recherches
(dont 219 Mo de pages de fichier, réclamables par le noyau), **683 Mo juste après une
réindexation complète** — presque tout anonyme, mémoire de travail non rendue à l'OS. Le pic
vient de l'indexation, pas de la recherche. Les clients visés ont 32 Go, quelques-uns 16 Go :
sans objet à cette échelle.

## Mesures acquises

Corpus de 2 500 objets, conteneur à huit cœurs. Conservées comme point de comparaison ; les
chiffres à 71 000 fiches sont ci-dessus.

| | 1 processus | 4 processus |
|---|---|---|
| réindexation de `ca_objects`, configuration livrée | 8,3 s | 3,0 s |
| élagage prudent (défaut de l'outil) | 7,3 s | 2,9 s |
| élagage étendu | 5,7 s | 2,5 s |

- 94 % du temps de réindexation est passé dans `SearchIndexer::indexRow()`, 3 % à parler à
  Meilisearch. Le connecteur n'est pas le goulot.
- ~44 requêtes SQL par objet indexé, dues aux 24 tables liées déclarées pour `ca_objects` dans
  `search_indexing.conf`.
- Insertion du corpus CMA : 67 objets/s, indexation coupée.
- Le gain de parallélisation plafonne à quatre processus : MariaDB et le CPU saturent, pas
  Meilisearch.

## Décisions structurantes

- **Validation sur instance réelle uniquement.** Pas de stub, pas de faux `Configuration`, pas
  de faux `Db`. Les tests font un `require` de `setup.php` et parlent à l'instance du harnais.
- **Les recherches des tests passent par `ObjectSearch`**, la voie qu'emprunte l'interface —
  parseur Lucene, réécriture, filtres, cache. Vérifier le connecteur seul ne prouverait pas
  qu'il s'insère dans la chaîne.
- **Harnais autonome** dans `dev/`, dérivé de celui du greffon `lescollections` mais indépendant.
  Port 8089 (8088 est pris par l'autre harnais).
- **Les filtres de résultat sont appliqués en SQL**, pas traduits en filtres Meilisearch. Un
  filtre porte souvent sur un champ qu'on n'indexe pas, et un filtre qui ne s'applique pas en
  silence, c'est un enregistrement supprimé qui réapparaît.
- **Panne du moteur** : à la recherche, on journalise et on rend zéro résultat ; à l'indexation,
  on lève. Un index silencieusement incomplet est pire qu'un échec franc.
- **Pas de Pawtucket** pour l'instant.

## Pièges rencontrés, à ne pas repayer

- **`app/conf/local/app_<nom>.conf` n'est pas chargé** par `Configuration::load()`. Écrire dans
  `app/conf/local/app.conf`. En revanche `app/conf/local/search_indexing.conf` *est* bien chargé —
  la couche locale fonctionne pour tous les fichiers sauf `app.conf`.
- **Le fork attend des méthodes hors interface** : `flushContentBuffer()` (appelée sans
  `method_exists()` en fin de réindexation, son absence tue `rebuild-search-index` *après* avoir
  tout indexé) et l'option `useAsync`.
- **Et nos outils attendaient une méthode du fork.** `SearchIndexer::getFieldDataForReindex()`
  n'existe que dans `lescollections/providence` : sur préprod-130.32, `tools/reindexer.php`
  mourait dès la première tranche. Le repli vit dans `tools/_socle.php` (lecture directe de la
  table) et `CA_SANS_FIELD_DATA_FORK=1` le force là où la méthode existe, sans quoi il ne serait
  jamais éprouvé. `tests/compatibilite-socle.php` compare les index produits par les deux
  chemins. **Lancer `tools/verifier-socle.php` avant toute bascule sur une instance inconnue.**
- **Modifier une étiquette ne réindexe pas tout de suite** : l'entrée part dans
  `ca_search_indexing_queue`, qu'il faut traiter. Rien de propre au connecteur.
- **La file de réindexation n'est pas parallélisable** en l'état : verrou de fichier exclusif
  dans `LockingTrait`.
- **Meilisearch complète le dernier mot en préfixe.** Sans précaution, `CLE.1928.8` ramène aussi
  `CLE.1928.856`. Les identifiants sont donc cherchés entre guillemets. Cela ne rend pas un
  numéro univoque pour autant : le socle indexe les préfixes hiérarchiques d'un numéro
  (`getIDNoPlugInInstance()->getIndexValues()`), si bien que `CLE.1938.388.a` contient
  littéralement `CLE.1938.388`. Chercher un numéro ramène donc ses sous-numéros — c'est voulu
  (chercher `CLE.1938` doit ramener la série), et ce n'est pas au connecteur de le défaire.
- **`getSigns()` rend `null` pour un terme facultatif** — ce qui n'est pas la même chose qu'un
  signe absent. Un `?? true` confond les deux et transforme tous les `OR` en `AND`. Le même
  piège a faussé un script de diagnostic avant de fausser le connecteur : `$signes[$i] ?? '…'`
  affichait « absent » là où il y avait `null`, et faisait accuser le socle à tort.
- **Supprimer un index Meilisearch absent répond HTTP 200** puis enregistre une tâche qui échoue
  en `index_not_found`. Il faut absorber les deux formes.
- **Le journal du connecteur se tait quand il change de propriétaire.** `app/log/meilisearch.log`
  créé par un outil lancé en root passe en root:root 0644 ; le serveur web ne peut plus y écrire,
  et `Log` absorbait l'échec — le connecteur devenait muet côté web, là précisément où ses
  alertes servent. Il se replie maintenant sur `error_log()` et crée le fichier en 0666. Le
  symptôme trompeur : un test lancé par `dev-test` (qui passe en `www-data`) ne voyait rien au
  journal, alors que le même code en ligne de commande écrivait bien.
- **`filesize()` sert une valeur de cache.** Relire la taille d'un fichier qu'on vient d'écrire
  rend l'ancienne : sans `clearstatcache()`, le journal paraît n'avoir rien reçu. Deux fois le
  même faux diagnostic — dans l'outil et dans le test.
- **`getenv()` rend la chaîne `"0"`, que PHP tient pour vide.** Un `getenv(…) ?: '1'` ignore
  silencieusement une désactivation. Vu sur `CA_SEED_CLEVELAND_MEDIA`, qui téléchargeait les
  images malgré la consigne inverse et faisait mourir le semis, mémoire épuisée.
