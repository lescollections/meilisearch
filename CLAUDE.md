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
| CIPAR (base cliente, Namur) | **213 258** | base `cipar` du harnais, migrée 1.7 → 2.0 ; voir le point d'étape du 20 août |

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

## Point d'étape — 20 août 2026 : le fonds CIPAR, l'échelle réelle sous la main

Le Centre Interdiocésain du Patrimoine et des Arts Religieux (Namur) fournit enfin ce que le
Rijksmuseum devait apporter : **213 258 objets**, rapatriés dans le harnais, sous notre entière
maîtrise. Le semeur Rijksmuseum n'a toujours pas tourné et n'a plus d'urgence.

| | |
|---|---|
| base `cipar` | 213 258 objets (210 892 vivants), 279 921 représentations, 7 088 entités |
| index | 519 206 documents, préfixe `cipar` |
| `ca_search_log` | **807 536 recherches réelles**, 71 607 expressions distinctes, 2017 → 2026 |
| socle d'origine | CollectiveAccess **1.7**, schéma 158, `search_engine_plugin = SqlSearch` |

### Rapatrier une base cliente : ce que ça coûte vraiment

Le dump complet fait **21,85 Gio**, et le catalogue n'en est qu'une petite part :

| | volume | |
|---|---|---|
| `ca_change_log` + `_snapshots` + `_subjects` | 37,2 Go | écarté |
| `ca_guids` | 3,9 Go — **52,6 millions de lignes** | écarté |
| `ca_sql_search_word_index` | 2,7 Go | écarté |
| le reste | **2,1 Go** | gardé |

`dev/bin/degraisser-dump` filtre en flux, sans jamais poser le dump sur le disque, et conserve la
structure des tables vidées : la base restaurée reste complète et tout se reconstruit sur place.
**Restaurée en 2 min 38** une fois `ca_guids` écartée, contre plus de trente minutes avec elle —
cette table enfle sans rapport avec le fonds et rien de la recherche ne la lit.

Mieux vaut dégraisser **à la source** : `mysqldump --no-data` pour la structure de toute la base,
puis `--no-create-info` avec les `--ignore-table` pour les données. Le transfert tombe de vingt
mille mégaoctets à quelques centaines. `--ignore-table` accepte silencieusement une table
absente ; c'est le dump `--no-data` d'une *liste* de tables qui échoue sur la première manquante.

### Migrer 1.7 → 2.0 sans payer l'index qu'on ne veut pas

`caUtils update-from-1-7` réindexe SqlSearch2 en dur à son étape 9/9, et
`update_from_1_7ParamList()` rend `[]` : aucune option pour l'éviter. Les huit premières étapes
existent toutes comme commandes `caUtils` autonomes — les rejouer une à une, puis réindexer avec
`reindexer.php`, économise des heures. **49 min** ici : 11 de schéma, 6 d'attributs, 32 de
valeurs de tri. `update-database-schema` demande une confirmation interactive (`yes y |`).

### L'objectif : égaler SqlSearch2, pas le surpasser — décidé le 20 août 2026

**Ce qu'on cherche, c'est le comportement de SqlSearch2 servi par Meilisearch.** Le gain visé est
ailleurs : une empreinte mémoire de MariaDB plus légère, et la fin des effondrements sur les
recherches à plusieurs mots. Un résultat que Meilisearch trouve et que SqlSearch2 ne trouve pas
est donc **un écart à réduire**, pas une amélioration à défendre.

L'exemple qui a fixé la règle : `roch` rend 5 294 fiches au moteur contre 966 à SqlSearch2, et
l'analyse montrait que Meilisearch retrouvait en plus les objets dont le numéro composite porte
le nom de l'église (`211467819_Saint-Roch[Ham-sur-Heure]_1`). C'est de la meilleure couverture —
mais au CIPAR, le lien entre un objet et son édifice se pose par un critère d'emplacement ajouté
dans l'interface, pas par la recherche plein texte. La bonne réponse n'est donc pas de s'en
féliciter, mais de s'aligner.

**Y compris quand SqlSearch2 a tort.** `parent_id:6` et `code_eglise:05R1000` y rendent zéro,
faute de rattacher un champ tapé sans sa table ; `ca_objects.parent_id:6` rend 26. Rattacher
automatiquement à la table sujet a été implémenté, mesuré juste — et **retiré**. La raison tient
en une phrase : un remplacement dont on ne peut pas prédire les écarts n'inspire pas confiance,
et sans confiance il ne se déploie pas là où les fonds sont gros. C'est bien une régression du
socle entre 1.7 et 2.0 — l'historique du CIPAR montre 1 347 fiches pour `parent_id:220749036` —
mais c'est au socle qu'il faut la porter, pas au connecteur de la masquer pour lui seul.

### Ce que l'alignement a donné, mesuré sur le CIPAR

Trois corrections, dans l'ordre où elles ont compté :

1. **poser le `search.conf` du client** — sans quoi on compare à un SqlSearch2 qui n'est pas le
   sien (voir le piège plus bas) ;
2. **un terme sans astérisque se cherche exactement** (`Query::clause`, `exact => !$wildcard`).
   Meilisearch complète sinon le dernier mot en préfixe, toujours : `roch` y rendait exactement
   ce que rend `roch*`. Le même réglage annule sa tolérance orthographique, et c'est voulu —
   SqlSearch2 n'en a pas. Le pont des facettes met de même chaque mot du critère `_search` entre
   guillemets, sans quoi la facette compterait sur un autre ensemble que le browse n'affiche ;
3. **ne plus rattacher un champ à sa table**, ci-dessus.

| expression | Meilisearch | SqlSearch2 |
|---|---|---|
| `waremme`, `peinture murale` | identiques | identiques |
| `burette` | 1 060 | 1 049 |
| `parent_id:6`, `code_eglise:…` | 0 | 0 |
| `roch` | 2 999 | 965 |

**17 expressions réelles sur 40 sont à ±5 %**, et le diagnostic historique passe de 35 à 68 sur
200. Ce qui restait tenait aux **valeurs composites** : SqlSearch2 garde
`211467819_Saint-Roch[Ham-sur-Heure]_1` en un seul token, nous le découpions. D'où le mode
ci-dessous.

### `tokenize_like_sqlsearch` — l'équivalence par construction

Le connecteur peut indexer **les mots que SqlSearch2 tirerait de la valeur**, au lieu du texte
brut. Réglage `tokenize_like_sqlsearch` (0 par défaut), lisible aussi dans l'environnement ;
**en changer impose une réindexation**.

Trois pièces, et il les faut toutes :

1. `Document::normalizeValues()` passe chaque valeur texte par le tokeniseur du socle. Les
   numéros d'inventaire n'y passent pas : ils ont déjà leurs variantes ;
2. `nonSeparatorTokens = [". _ - /"]` sur l'index — les caractères que SqlSearch2 garde à
   l'intérieur d'un mot alors qu'il ne les retire qu'en tête et en queue. Sans cette
   déclaration, Meilisearch redécoupe les tokens et tout le bénéfice est perdu ;
3. **la requête passe par le même tokeniseur**, sinon l'asymétrie fait des trous : SqlSearch2
   retire le point final d'un mot, si bien que « Autel majeur. » est indexé « majeur » et
   cherché « majeur. ». La correspondance exacte échouait et la recherche rendait **zéro** là où
   le SQL rendait 2 582 fiches.

Mesure sur 45 recherches plein texte du CIPAR, liste figée :

**Les mots sont recollés par des espaces, non rangés un par un dans un tableau.** La distinction
décide de tout : Meilisearch traite les éléments d'un tableau comme autant de valeurs séparées,
jamais contiguës, et **toute recherche de phrase y rend zéro** — mesuré, « "chemin de croix" »
passait de 2 243 fiches à une seule. Recollés, les mots restent voisins ; le moteur les redécoupe
sur les espaces, ce qui est sans effet puisqu'un token n'en contient pas.

| | à ±5 % de SqlSearch2 |
|---|---|
| texte brut | 34 sur 45 — 76 % |
| **tokens du socle** | **43 sur 45 — 96 %** |

`roch` passe de 2 999 à 968 pour 965 attendus ; `Henri` et `waremme` tombent exactement juste.
Il ne reste que deux cas, marqués par un caractère de tête : `#Eglise` et « ␣jauche ».

**Le mode l'emporte aussi sur les phrases**, où le texte brut se trompait d'un facteur trois à
quatre cents :

| | brut | tokens | SqlSearch2 |
|---|---|---|---|
| `"chemin de croix"` | 2 243 | **2 241** | 2 241 |
| `"eglise saint-roch"` | 1 105 | **591** | 591 |
| `"vierge à l'enfant"` | 5 | **2 132** | 2 123 |
| `"saint joseph"` | 5 061 | **1 776** | 1 769 |

Le coût est de **+50 % de temps d'indexation** (13 min 15 contre 8 min 50), à comparer aux
1 h 12 de SqlSearch2 sur le même fonds.

> **Mesurer la fidélité demande une liste d'expressions *figée*.** `ObjectSearch` écrit chaque
> recherche dans `ca_search_log` : trois exécutions qui relisent l'historique à tour de rôle ne
> portent pas sur les mêmes expressions, et les colonnes ne se correspondent plus. Deux séries de
> chiffres ont été produites et jetées pour cette raison. La liste se tire une fois, se borne aux
> entrées antérieures aux essais, et le comparateur vérifie ligne à ligne que les trois colonnes
> parlent bien de la même expression. Trier l'échantillon par `num_hits DESC` est un second piège :
> il ne sélectionne que des troncatures d'une ou deux lettres, atypiques de l'usage réel.

### Le gain, lui, est acquis : la queue de distribution

| expression | Meilisearch | SqlSearch2 |
|---|---|---|
| `il y en a 2*` | 3,0 s | **100,3 s** |
| `chaises 2*` | 2,5 s | **96,3 s** |
| `cathédrale de Tournai` | 42 ms | 942 ms |
| total, 40 expressions | **28,1 s** | **205,8 s** |

Meilisearch reste **4× plus lent en médiane** sur les requêtes simples (30 ms contre 7) et
**7× plus rapide au total**. C'est le compromis : vingt millisecondes de plus ne se perçoivent
pas, cent secondes sont une recherche qui a échoué.

### La configuration cliente s'isole par base, sans toucher au socle

`Configuration.php:153` cherche, en plus de `local/<fichier>.conf`, un
**`local/<fichier>_<__CA_APP_NAME__>.conf`**. Comme `__CA_APP_NAME__` porte déjà le nom de la
base (piège du cache, plus bas), il suffit de poser `browse_meilisearch_cipar.conf` pour que la
configuration du client ne s'applique qu'à lui. Vérifié : `technique_facet` n'apparaît que sous
`cipar`. Les confs rapatriées vivent dans `dev/instances/<base>/`, hors dépôt.

> **Comparer les facettes sans le `browse.conf` du client ne prouve rien** : on compare les
> facettes du profil livré, pas celles que ses catalogueurs utilisent. Le CIPAR a trois facettes
> `attribute` (`materiau`, `technique`, `objet_present`) que le profil par défaut n'a pas.
>
> **Et comparer les recherches sans son `search.conf` ne prouve pas davantage.** Piège payé le
> 20 août : le harnais a `search_sql_search_do_stemming = 1`, le CIPAR `= 0`. Toutes les mesures
> de fidélité d'avant opposaient donc Meilisearch à un SqlSearch2 **désuffixant**, que le client
> n'utilise pas — d'où `burette` rendant 2 670 fiches chez lui contre 1 051 pour le mot exact :
> il trouvait « burettes » par la racine.
>
> **Inutile en revanche de reconstruire son index** : SqlSearch2 désuffixe à la *recherche*, pas
> à l'indexation — ses mots stockés sont identiques dans les deux cas. Reconstruire a coûté
> 1 h 12 pour rien, dont 25 minutes de `DELETE FROM ca_sql_search_word_index WHERE table_num=57`
> sur 49 millions de lignes, MariaDB à 100 % de CPU pendant tout ce temps.

> **`SqlSearch2::tokenize()` ne nettoie rien si le moteur n'a jamais été instancié.** Elle
> initialise `whitespace_tokenizer_regex` quand elle est nulle, mais pas celles de ponctuation et
> de séparation, posées seulement par son *constructeur*. Appelée statiquement — ce qui est le
> cas dès que Meilisearch est le moteur en service — elle découpe sur les espaces sans plus :
> « …Saint-Roch[Ham-sur-Heure]… » garde ses crochets. Les tokens de `caTokenizeString()`
> dépendaient donc du moteur configuré. `WLPlugSearchEngineMeilisearch::tokenize()` amorce
> désormais une instance, une fois. Même famille que le défaut de dispatch trouvé par la session
> INRAP, et à remonter en amont avec lui.

### Le coût d'indexation suit la latence SQL, pas le nombre de fiches

**519 206 documents en 11 min 13 s**, soit 445 lignes/s sur `ca_objects` (2,4 ms par fiche) —
contre 19 lignes/s et 52 ms par fiche à comodo-preprod, à code identique. **Un facteur 22.** La
seule différence structurelle est que la base y était distante et qu'elle est locale ici. Deux
conséquences : l'élagage de `search_indexing.conf`, jugé marginal, devient le levier principal
sur une instance à base distante, puisqu'il supprime des requêtes ; et **une durée de
réindexation ne se transporte pas d'une instance à l'autre** sans connaître la topologie.

### Facettes : 22 conformes, 18 au SQL, 21 assumés, 2 divergentes

`entity_facet` rend **6 816 postes conformes** — la plus grosse facette d'autorité jamais
comparée. `storage_location_facet` : 35 postes dont 7 branches hiérarchiques, conformes sur un
fonds réel et non plus sur de la matière fabriquée. Les 21 écarts assumés sont ceux déjà connus.

Les **dates normalisées fonctionnent**, y compris les cas que l'on craignait : `-1932` devient
« 1932 av. J.-C. », une borne ouverte (`avant 1945`, `1831-`) retient la seule borne connue.
Étendue moyenne d'une datation : **32,1 ans** ; 27 datations sur 133 414 dépassent 501 ans.
L'énumération année par année reste donc viable, y compris sur un fonds archéologique.

> **Ne pas juger une datation large de « bruit ».** 900 postes d'année sous l'an 1000 viennent de
> datations comme `100-299` — parfaitement valides sur un sarcophage de pierre, et plus encore en
> archéologie où les dates négatives sont ordinaires. Le socle produit exactement les mêmes.

### Le `search.conf` du client explique une part des écarts de recherche

    search_sql_search_do_stemming = 0
    indexing_tokenizer_regex = ^\pL\pN\pNd/_#\@\&\-

**Le tiret, la barre oblique, `_ # @ &` ne sont pas des séparateurs chez eux** : `saint-denis` est
un seul terme dans leur index, là où Meilisearch coupe et cherche les deux mots. Les chiffres
concordent — `saint-denis` 1 779 → 4 947, `Saint-Léger` 1 321 → 3 291. `nonSeparatorTokens` de
Meilisearch reproduit ce découpage sans toucher au connecteur. Ils n'ont par ailleurs **aucune
désuffixation**, donc leurs usagers attendent une recherche littérale.

### Mesurer deux moteurs : quatre pièges, tous payés

`tools/comparer-latence.php` oppose Meilisearch et SqlSearch2 sur la même base, la même machine
et dans le même processus. Il a fallu quatre corrections pour qu'il dise quelque chose de vrai.

- **`BaseSearch::__construct()` ignore le moteur qu'on lui passe.** `SearchBase` l'accepte en
  second paramètre, mais `BaseSearch` — dont héritent `ObjectSearch`, `EntitySearch` et les
  autres — ne prend aucun paramètre et appelle son parent sans rien. `new ObjectSearch(null,
  'SqlSearch2')` rend donc une recherche sur le moteur *configuré*, sans un mot. La première
  version de l'outil a ainsi comparé Meilisearch à lui-même et l'a trouvé, sans surprise,
  exactement aussi rapide que son adversaire — des rapports de 0,98× à 1,00× partout, seul
  indice. Le remède : remplacer `SearchEngine::$opo_engine` après construction, par réflexion.
- **Le premier tir ment.** `burette` a donné 1 594 ms puis 36, 34 et 34 ms — un facteur 46 entre
  le tir à froid et le régime. L'outil répète et ne retient que la médiane des tirs suivants.
- **Ne jamais mesurer pendant une réindexation.** L'index est alors partiel : `chêne` a rendu
  5 198 résultats à un moment et 11 951 dix minutes plus tard. J'ai failli conclure à un défaut
  grave du connecteur. `comparer-latence.php` et `comparer-facettes.php` refusent désormais de
  démarrer quand `isIndexing` est vrai. **Ce garde-fou est dans les outils, jamais dans le
  connecteur** : pendant une réindexation qui dure une journée, les services publics doivent
  pouvoir chercher dans ce qui est déjà indexé.
- **Une latence ne se compare qu'à volume de résultats égal.** Les deux moteurs ne rendent pas
  les mêmes ensembles ; rapatrier trois fois plus d'identifiants coûte trois fois plus cher. Le
  bilan sépare les expressions dont les comptes s'accordent à 5 % près.

**Le verdict, sur 20 expressions réelles.** À volume comparable, **SqlSearch2 est 5,2× plus
rapide** (médiane 4,8 ms contre 24,8 ms ; 8,6 ms contre 38,7 ms pour mille résultats). Cela
confirme, en plus marqué, le facteur 1,89 obtenu indirectement à l'INRAP, et donc le diagnostic
du §9.3 du brief : le coût est dans le chemin, pas dans le moteur.

**Mais le profil est inverse aux extrêmes**, et c'est ce qui compte pour un catalogueur :

| | Meilisearch | SqlSearch2 |
|---|---|---|
| `chaises 2*` | 2,2 s | **45,1 s** |
| `il y en a 2*` | 2,1 s | **43,4 s** |
| total, 20 expressions | **9,4 s** | 90,4 s |
| réindexation de 210 892 objets | **8 min** | 21 min |
| index sur disque | **~3,0 Go** | **21,4 Go** |

Vingt millisecondes de plus sur une recherche courante ne se voient pas ; quarante-cinq secondes,
c'est une recherche qui a échoué. Les expressions que le diagnostic signalait comme lentes chez
Meilisearch — troncature à plusieurs mots — sont précisément celles où il fait vingt fois mieux.

### Deux moteurs, une même normalisation

Les deux normalisent déjà les accents, chacun de son côté : `béton` et `beton` rendent le même
nombre de résultats sous Meilisearch (232) comme sous SqlSearch2 (128). **Nettoyer le texte
cherchable en amont ne rapprocherait donc rien** et ferait perdre l'original — la règle de
classement `exactness` de Meilisearch distingue le mot tapé exactement du mot normalisé. C'est
seulement pour les *facettes* qu'il faut aplatir, et là c'est indispensable (voir plus bas).

### Facettes `attribute` : la matière de la recherche ne sert pas à naviguer

Le CIPAR en déclare trois — `materiau`, `technique`, `objet_present` — et c'est le type que les
profils métier emploient le plus. La traduction a demandé un attribut d'index à part,
`facet_attr__<code>`, et trois découvertes :

- **l'attribut texte ne peut pas servir.** Il porte les valeurs, mais aussi les variantes que
  l'indexeur fabrique pour la recherche : « beton » à côté de « béton », « bois et skai » à côté
  de « Bois et skaï ». Ces fantômes ajoutaient à la facette 126 postes que le SQL n'a pas ;
- **la collation de la base fusionne casse *et* accents.** `utf8mb4_general_ci` tient pour égaux
  « béton »/« beton », « skaï »/« skai », « chêne »/« chene » — vérifié. Le `GROUP BY` du socle
  en hérite. On écrit donc dans l'index la **clé** et non la graphie, via `Schema::valueKey()`,
  qui s'appuie sur `caRemoveAccents()` du socle plutôt que sur une décomposition Unicode à nous ;
- **le piège du `row_id`, dans sa variante symétrique.** On savait que les appels `COUNT` portent
  la table liée et le `row_id` du sujet. L'inverse existe : pour une relation objet-objet, la
  table du contenu *est* la table sujet, mais le `row_id` est celui de l'objet **lié**. Comparer
  les seules tables ne suffit donc pas, et l'on écrivait les matériaux d'un objet dans le
  document d'un autre — une technique portée par une fiche en comptait quatre.

**Un élément adossé à une liste** (`objet_present`) se traite à part : `value_longtext1` y porte
l'identifiant de l'item — « 210 », non « Non » —, et c'est lui que la facette du socle rend comme
identité de poste. On l'écrit tel quel, **avec les ancêtres de l'item**, comme pour les
enregistrements liés. C'est ce qui rend un thésaurus utilisable : chercher « percussion » doit
ramener les djembés, faute de quoi il faut déjà savoir classer l'objet qu'on cherche à
identifier. Le socle fait de même en étendant son critère à la descendance
(`getHierarchy(includeSelf)`) ; chez nous l'égalité sur un ancêtre suffit, et le comparateur
reconnaît qu'une telle facette est hiérarchique — elle ne déclare pas de `table` dans
`browse.conf`, c'est le type de son élément qui la désigne.

**La descendance est éprouvée par `tests/browse-facettes.php`**, qui fabrique le thésaurus qu'aucun
corpus ne porte : `Instruments > Percussion > { Djembé, Cajón }`, trois objets sous l'un, deux sous
l'autre. Les feuilles portent leurs comptes exacts, l'ancêtre en compte cinq, et un critère posé
sur lui rend toute sa descendance. Sur données *réelles*, en revanche, elle attend toujours un
fonds : la seule liste facettée du CIPAR est plate.

> **La couche locale *remplace* un bloc de configuration, elle ne le fusionne pas.** Un
> `local/browse.conf` qui ne déclarerait qu'une facette **efface les vingt autres** — mesuré. Il
> faut donc recopier le fichier entier, ce que fait le harnais (`dev/conf/browse-local.conf`,
> posé sous `browse_meilisearch.conf` pour ne valoir que pour la base par défaut) comme ce qu'on
> a fait du `browse.conf` du CIPAR.

**Résultat : les trois facettes sont conformes** — `materiau` 1 719 postes, `technique` 990,
`objet_present` 2. Le bilan du CIPAR passe de 22 conformes / 18 au SQL / 2 divergentes à
**28 conformes / 8 au SQL / 47 assumés / 4 divergentes**, ces quatre étant les deux facettes de
dates comptées dans deux contextes — la même divergence d'une fiche sur 1874.

Deux écarts assumés sont apparus au passage, et ils tiennent tous deux à des **espaces parasites
dans les valeurs saisies** — plus fréquents qu'on ne croit dans un fonds réel :

- **une valeur entourée d'espaces sort du socle sans identifiant.** Sa facette affiche
  `trim(getDisplayValue())` puis redemande l'identifiant de cette valeur à `getValueIDFor()`,
  dont le `=` SQL ignore les espaces de fin mais pas de tête. « ␣base est un socle en pierre
  calcaire » n'est jamais retrouvée : le poste sort avec `id` à null. Nous avons l'identifiant ;
- **le socle éparpille puis perd des graphies.** « fer forgé\n » et « Fer forgé » forment pour
  MySQL deux groupes ; le socle en fait deux postes, puis les réduit à un en les indexant par
  `strToLower(trim(...))` — le dernier écrasant le premier, avec son seul compte. Sur « fer
  forgé », il affiche 6 fiches là où il y en a 19. Notre `trim()` à l'indexation les rassemble,
  et notre compte est celui que le clic rend.

> **`TRIM()` de MySQL ne retire pas les retours à la ligne**, là où `trim()` de PHP le fait. Les
> deux critères de reconnaissance du comparateur, écrits d'abord en SQL, échouaient donc en
> silence sur « Plâtre coulé\n ». Le regroupement se fait maintenant en PHP, avec la même
> fonction que l'indexation. Corollaire : deux regroupements distincts ne choisissent pas le même
> représentant dans un groupe — retrouver un poste par *appartenance* et non par identité.

Coût mesuré : **une requête SQL par fiche, soit ~17 % du temps d'indexation** (478 → 397
lignes/s). Consenti, et nul si la table sujet ne déclare aucune facette de ce type — seuls les
éléments qu'une facette `attribute` de `browse.conf` désigne *et* que le pont sait rendre
reçoivent un attribut.

> **Une réindexation interrompue laisse l'index sans clé primaire.** `truncateIndex()` supprime
> l'index ; si le processus meurt avant que `prepareIndex()` ne le recrée, le premier versement
> de documents le recrée **implicitement** — et Meilisearch, faute de `primaryKey` déclarée,
> tente de l'inférer. Elle échoue dès que plusieurs attributs finissent par « id », ce qui est
> notre cas général : `index_primary_key_multiple_candidates_found`, lot après lot. Le symptôme
> est opaque, l'index paraissant normal. `createIndex()` vérifie désormais la clé de l'index
> existant et la repose — l'opération est acceptée tant qu'il est vide, ce qui est précisément
> l'état d'après purge.

### À reprendre là (CIPAR)

1. **La réindexation vide l'index avant de commencer** (`truncateIndex()`), comme le
   `rebuild-search-index` du socle vide `ca_sql_search_word_index`. Le service part donc de zéro
   et remonte sur toute la durée. Meilisearch écrasant les documents par clé primaire, une option
   « sans purge » le laisserait à 100 % du début à la fin — chaque fiche remplacée au fil de
   l'eau, avec un ménage final par filtre pour les fiches supprimées entre-temps. **Décidé le
   20 août : la purge reste la règle, l'option est une amélioration à venir**, et elle vaut cher
   pour les instances dont la réindexation occupe une journée ouvrée.
2. **Éprouver la descendance d'un thésaurus** : le mécanisme est écrit et les tests passent, mais
   aucune liste hiérarchique n'a servi à le vérifier. `tests/browse-facettes.php` fabrique déjà
   sa matière (vocabulaire « Abyssinie » marqué TEST-FAC) ; y ajouter un élément `attribute`
   adossé à une liste à trois niveaux fermerait le trou en attendant un fonds réel.
3. La divergence d'une fiche sur `year_facet`/`decade_facet` (poste 1874, SQL 10 010 contre
   10 009) **reste inexpliquée**. Borner l'intervalle au lieu de le rejeter a été essayé et
   mesuré faux : l'objet daté « 1851 - 19000 » peuplait alors chaque année jusqu'à 2076, dont 54
   que le socle ne connaît pas. Le rejet est bien ce que fait le socle ; la cause est ailleurs.

### Diagnostic historique — 200 expressions réelles, fonds figé

    0 identique · 35 à 5 % près · 156 plus larges · 3 plus étroites · 6 vidées · 0 en erreur

Moins bon qu'à l'INRAP, mais la référence n'est pas la même : cet historique a été écrit par le
**SqlSearch de 1.7**, pas SqlSearch2. Le fonds, lui, est figé — c'est un dump —, ce qui élimine la
dérive qui brouillait la comparaison là-bas. Trois défauts en sortent :

- **les champs qualifiés muets.** `parent_id:220749036` rendait 1 347 fiches, rend 0. **Ce n'est
  pas un cas d'école** : c'est ainsi que le CIPAR liste le mobilier d'un édifice, par parenté.
  Idem `code_eglise`. Il n'y a ici **aucun élagage** de `search_indexing.conf` : c'est bien une
  différence de couverture du connecteur ;
- **la latence des troncatures à plusieurs mots.** `chaises*` répond en 45 ms, `chaises 2*` en
  **1 920 ms** — mêmes 1 822 résultats. Une douzaine d'expressions dépassent 1,7 s, toutes de
  cette forme. Le coût ne suit ni le volume ni la troncature seule, mais leur combinaison ;
- **l'élargissement**, dont le tokeniseur ci-dessus explique une bonne part.

## Point d'étape — 19 août 2026, instance INRAP comodo2026-preprod

Première mise à l'échelle réelle : **661 025 documents**, dont 253 392 objets, sur un socle
**`collectiveaccess/providence` non forké** (branche `dev/php8`, schéma 191, PHP 8.4.24). Base
MariaDB **distante**. `verifier-socle.php` : 12 conformes, 1 à savoir, 0 bloquant — seul
`getFieldDataForReindex` manque ; `clearCaches` et `flushContentBuffer`, que le brief redoutait,
sont présents.

**Réindexation complète : 4 h 14**, dont 3 h 40 pour les seuls objets (19 lignes/s), 13 min pour
les 40 014 collections. Index de **2 619 Mo utilisés**, 4,4 Go de fichier — LMDB ne rend jamais
les pages libérées. Durabilité vérifiée : 661 025 documents avant `systemctl restart`, autant
après.

**Le coût d'indexation suit le nombre de fiches, pas le volume de métadonnées.** Prédiction faite
puis démentie : les collections portent 31,1 attributs par fiche contre 6,9 pour les objets, d'où
une estimation de 2 h 30 — réel 13 min, soit 19 ms par fiche contre 52 ms pour un objet. Le poste
dominant est l'aller-retour SQL par fiche, pas l'attribut.

**Découpage dynamique.** Le découpage statique par modulo équilibrait le nombre de lignes, jamais
leur coût : le débit tombait de 25,9 à 13,6 objets/s à mesure que les parts légères s'achevaient,
la fin étant dictée par la part la plus lourde restée seule. Remplacé par une file où chaque
ouvrier puise un lot (`--lot`, 100 par défaut) sous verrou de fichier. Gain mesuré sur les
objets : **3 h 40 contre 4 h 31**, et la charge reste à 8,3 sur 8 cœurs au lieu de retomber à 5,8.

**Diagnostic historique** — 100 expressions de moins de 180 jours, chacune rendant plus de 1 700
résultats à l'époque, sélectionnées par `--historique=100 --jours=180 --tri=resultats` (options
ajoutées ici : la sélection d'origine prenait les N *lignes* les plus récentes puis dédoublonnait).

    3 identiques · 34 à 5 % près · 58 plus larges · 3 plus étroites · 2 vidées · 0 en erreur

Le fonds n'a pas dérivé sur la fenêtre — `ceramique` 34 627 → 34 627, `palette` 16 297 → 16 297,
`F010*` 2 175 → 2 175 — donc les écarts sont imputables au moteur, pas à la croissance de la base.

Toute la divergence tient dans la **longueur du terme** :

| Famille | n | à ±5 % | Écart médian |
|---|---|---|---|
| 1-2 caractères | 15 | 1/15 | **+172 %** |
| 3 caractères | 41 | 18/41 | +6 % |
| 4-5 caractères | 24 | 11/24 | +6 % |
| 6 et plus | 7 | 3/7 | +12 % |
| Champ qualifié | 13 | 4/13 | +23 % |

`12` passe de 63 603 à 222 765, `M` de 8 036 à 242 217. Au-delà de quatre caractères les
troncatures sont fidèles au chiffre près : `F12*` −2, `F010*` exact, `2214*` +15.

**Deux régressions à traiter :**

- `[BLANK]` et `[SET]` ne sont pas implémentés — `statut_collection:"[BLANK]"` rendait 244 572
  fiches, rend 0 ;
- la valeur d'un champ qualifié ne restreint pas — `(ca_objects.type_id:"mobilier")` rendait
  20 373, rend 218 173.

**Latence : Meilisearch est 1,89× plus lent que SqlSearch2** sur cette instance. Comparé à
volume de résultats égal (34 expressions à ±5 %) contre les `execution_time` enregistrés dans
`ca_search_log` : médiane **134 ms contre 236 ms**, coût pour mille résultats 21,7 ms contre
34,8 ms, 7 requêtes sur 34 plus rapides seulement. Ce n'est pas le moteur — interrogé en direct il
répond en 4 ms — c'est le chemin : SqlSearch2 garde ses identifiants dans la même base que les
données, là où le connecteur les rapatrie par HTTP avant de les réinjecter en `WHERE … IN (…)`.
Le §9.3 du brief le pressentait ; il se chiffre à un facteur 2.

**Le 0 silencieux est le défaut le plus dangereux.** Service arrêté, délai dépassé
(`meilisearch_timeout = 10`, atteint par `M 3*`) : dans les deux cas le connecteur rend zéro
résultat sans lever. Une panne devient indiscernable d'un fonds vide. À faire lever.

**Trois défauts corrigés pour que la réindexation aboutisse**, tous liés à de l'UTF-8 mal formé ou
à un dispatch fautif du socle :

1. `searchHelpers.php` — `caTokenizeString()` testait `method_exists('SearchBase', <nom de
   classe>)`, faux par construction : le repli SqlSearch2 était pris pour **tous** les greffons. La
   correction doit charger le fichier du greffon, qui n'est pas autochargeable, sans quoi elle ne
   change rien. À remonter en amont, indépendamment de Meilisearch.
   **Porté le 19/08 dans les deux branches `feature/meilisearch-browse-facets`** (providence et
   pawtucket2) et vérifié sur le harnais : `caTokenizeString()` survit désormais à une séquence
   UTF-8 mal formée, là où `WLPlugSearchEngineSqlSearch2::tokenize()` appelé directement lève
   toujours `caIdentifyAlphabet(): Argument #1 ($text) must be of type string, null`. **Sur une
   instance dont le socle n'est pas patché, le `tokenize()` du connecteur reste donc inerte et
   la réindexation reste exposée** ;
2. `Meilisearch.php` — `tokenize()` propre, qui purge l'encodage avant de déléguer au socle. Sans
   lui, `preg_replace` en `/u` rend `null` sur une séquence mal formée et `caIdentifyAlphabet()`
   lève une `TypeError` fatale au milieu de la réindexation ;
3. `Client.php` — `JSON_INVALID_UTF8_SUBSTITUTE`. Une seule valeur mal encodée faisait échouer
   `json_encode()`, donc tout le lot : **1 500 entités sur 8 719 manquaient**. 8 719 sur 8 719 après.

**Piège à ne pas repayer** : une recherche lancée à la main sur l'instance est enregistrée dans
`ca_search_log` en `PROVIDENCE`, sans la marque `meilisearch-diagnostic`, et devient de
l'« historique client » pour les exécutions suivantes. Borner la référence à l'ère SqlSearch2, ou
marquer aussi les sondages manuels.

## Point d'étape — 19 août 2026 : les facettes du Browse

**Le Browse délègue ses facettes au moteur.** Deux moitiés : le connecteur indexe la matière et
répond (`Facets.php`, le pont), le socle porte un point de délégation de 36 lignes dans
`BrowseEngine::getFacetContent()` — branche `feature/meilisearch-browse-facets` dans les clones
locaux `../providence` et `../pawtucket2` (BrowseEngine y est du code partagé : 88 lignes d'écart
sur 8 480, ailleurs que dans notre zone ; le patch est octet pour octet le même).

Doctrine du pont : **traduire exactement ou décliner** — un `null` rend la main au SQL du socle,
facette par facette, sans réglage. Couvert : `fieldList`, `has`, `authority` (avec hiérarchies),
`field` (booléens et valeurs libres ; déclinés : gabarit, champ adossé à une liste — c'est
`fieldList` —, et numéro d'inventaire, dont l'indexation éclate en variantes),
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
objets : comodo-preprod.

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

**Le type `field` a été ajouté ensuite** (19 août, après-midi) : harnais **28 conformes /
18 au SQL**, Mayenne **36 / 18**, zéro divergence de part et d'autre. Il apporte un troisième
écart assumé — **le socle ne rend aucun compte pour un booléen** : sa branche `field` recopie le
gabarit `['id', 'label']` sans `content_count` (`BrowseEngine.php:5618`), si bien que l'interface
affiche « Oui / Non » sans nombre. Nous en fournissons un.

**Deux écarts assumés, où c'est le socle qui a tort** — décision prise le 19 août 2026 : on ne
reproduit pas ses défauts. `comparer-facettes.php` les reconnaît et les compte à part, pour
qu'ils cessent de crier au loup ; le bilan de Mayenne est donc **34 conformes, 20 au SQL,
58 assumés, 0 divergente**.

- **le SQL surcompte les autorités liées par plusieurs types de relation.** Sa requête groupe
  par autorité *et* par `rel_type_id`, puis additionne les lignes (`BrowseEngine.php:7232`).
  Ce n'est pas anecdotique : **27 entités de Mayenne** sont dans ce cas, dont une qui affiche
  **108 là où le clic rend 54 fiches**. Notre compte est celui des fiches, c'est-à-dire de ce
  que le clic rend vraiment. L'outil le reconnaît à l'existence d'un doublon dans la table de
  liens ;
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
- **Et nos outils attendaient une méthode du fork.** `SearchIndexer::getFieldDataForReindex()`
  n'existe que dans `lescollections/providence` : sur comodo-preprod, `tools/reindexer.php`
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
