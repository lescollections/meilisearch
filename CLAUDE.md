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

1. **Lancer `tools/diagnostiquer-recherches.php --historique` sur comodo-preprod** (INRAP,
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
