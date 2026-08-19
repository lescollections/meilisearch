# Brief — faire converger Search et Browse sur la question des identifiants

Destinataire : les deux sessions qui travaillent en parallèle sur le connecteur Meilisearch
(recherche) et sur `BrowseEngine` (navigation à facettes). Rédigé le 2026-08-19 depuis
`comodo2026-preprod`, après la première mise à l'échelle réelle du connecteur.

Ce brief ne refait pas l'état du connecteur : il est dans le `CLAUDE.md`, point d'étape du
19 août. Il traite d'**un seul sujet**, celui qui oblige les deux chantiers à se parler.

## 1. Le constat qui motive la convergence

Sur les 253 392 objets de l'instance INRAP, à volume de résultats égal, **Meilisearch répond
1,89× plus lentement que SqlSearch2** — médiane 236 ms contre 134 ms, mesuré contre les
`execution_time` que SqlSearch2 avait lui-même enregistrés dans `ca_search_log`.

Ce n'est pas le moteur. Interrogé en direct, Meilisearch rend une réponse en **4 ms de médiane**.
C'est le chemin : SqlSearch2 garde ses identifiants **dans la même base MariaDB que les données**,
l'ensemble ne quitte jamais SQL. Tout moteur externe doit rapatrier ses identifiants, puis les
réinjecter dans la base pour filtrer, trier, compter et paginer.

`BrowseEngine` construit **167 clauses `IN (…)`**. La navigation à facettes intersecte des
ensembles successifs : le problème y est de même nature, et probablement plus aigu.

## 2. Trois couches, trois réponses différentes

La question « le `WHERE object_id IN (…)` est-il contournable ? » n'a pas une réponse mais trois.

### Couche 1 — le filtrage fait en SQL sur l'ensemble rendu : **supprimable**

`MeilisearchSearchEngine/Meilisearch.php`, méthode de filtrage :

```php
SELECT {$pk} FROM {$table_name} {$sql_joins}
WHERE {$pk} IN (?) AND ...filtres...
```

Le connecteur rapatrie les identifiants, les renvoie à MariaDB avec les filtres (accès, type,
supprimés), et garde ce qui survit. **Ces filtres portent sur des attributs du document.**
`MeilisearchSearchEngine/Schema.php` déclare déjà des `filterableAttributes` : passés en `filter=`
à Meilisearch, le moteur rend un ensemble **déjà filtré**. Plus d'aller-retour, plus de `IN`, et
l'ensemble transféré est plus petit dès le départ.

C'est le seul des trois niveaux où l'on **supprime** du travail au lieu de le déplacer. À traiter
en premier, et il ne demande aucune modification du socle.

Même raisonnement côté Browse pour tout critère qui est un attribut du document.

### Couche 2 — la matérialisation des résultats : **le `IN` est remplaçable**

Le socle fournit déjà l'outil, et les deux moteurs y ont accès par héritage :

- `BaseFindEngine::loadListIntoTemporaryResultTable($hits, $key, $options)` — **publique** ;
- `SearchEngine` s'en sert (`INNER JOIN {$tmp} ON {$tmp}.row_id = …`) ;
- `BrowseEngine extends BaseFindEngine` : la méthode lui est disponible telle quelle.

Ce qu'on y gagne : les identifiants sont expédiés **une fois par requête** au lieu d'une fois par
instruction SQL qui en a besoin, et MariaDB fait une jointure indexée sur une table `MEMORY` au
lieu d'analyser une liste `IN` de plusieurs dizaines de milliers d'éléments.

**Décision prise le 19/08 : accorder le privilège `FILE` à l'utilisateur MariaDB.** Au-delà de
500 résultats, `loadListIntoTemporaryResultTable()` bascule sur `LOAD DATA INFILE`, nettement plus
rapide que les `INSERT` — mais seulement si l'utilisateur a le privilège global `FILE`. Sur
`comodo2026-preprod`, l'utilisateur `comodo` ne l'a pas (`USAGE` sur `*.*`), donc le repli par
`INSERT` s'applique toujours et le gain de la table temporaire est amputé. C'est peu coûteux à
accorder et cela conditionne l'intérêt de toute la couche 2 :

```sql
GRANT FILE ON *.* TO 'comodo'@'%';
FLUSH PRIVILEGES;
```

Réserve à vérifier avant de conclure : `LOAD DATA INFILE` **sans** `LOCAL` lit un fichier sur le
serveur de base. Ici la base est **distante** (`51.91.72.220`) et le fichier temporaire est écrit
sur le serveur web. Il faudra donc soit `LOAD DATA LOCAL INFILE` — qui demande aussi
`local_infile=1` côté serveur et côté client PHP — soit accepter que le gain ne vaille que pour
les instances où la base est locale. **À mesurer, pas à supposer.**

### Couche 3 — exiger l'ensemble complet : **non contournable sans changer le socle**

Tant que `SearchResult` réclame **tous** les identifiants, on transfère O(N) quoi qu'on fasse :
253 365 entiers pour une requête sur `1`, mesurés à 7,3 s. Ni la table temporaire ni le filtrage
dans le moteur n'y changent quoi que ce soit.

Le seul remède est la pagination, et elle suppose deux choses simultanément :

1. que `SearchEngine` / `SearchResult` acceptent de ne demander qu'une page ;
2. que **le tri passe au moteur** (`sortableAttributes` dans Meilisearch), faute de quoi il faut
   de toute façon tout rapatrier pour trier.

**C'est ici que les deux chantiers doivent converger.** Changer le contrat de `SearchResult` ne
peut pas se décider d'un seul côté : `BrowseEngine` et `SearchEngine` partagent `BaseFindEngine`
et la même classe de résultat. Une pagination conçue pour la recherche seule casserait la
navigation, et réciproquement.

## 3. Ce qu'il faut trancher ensemble

1. **Le contrat.** `SearchResult` continue-t-il d'exiger la liste complète, ou devient-il capable
   de demander une fenêtre ? Si oui, qui garde l'état entre deux pages — le moteur, la session,
   une table temporaire persistante ?
2. **Le tri.** Peut-il descendre dans le moteur pour les cas courants (pertinence, date, libellé),
   avec repli SQL pour les tris que le moteur ne sait pas faire ? Sans cela la pagination
   n'apporte rien.
3. **Le partage de la table temporaire.** Une recherche puis une navigation dans ses résultats
   pourraient-elles réutiliser la même table plutôt que de recharger l'ensemble ? `$ps_key` est
   déjà prévu pour ça (`caResultTmp{clé}`), et `loadListIntoTemporaryResultTable()` rend la table
   existante si la clé est inchangée.
4. **Le seuil.** En dessous de quelques centaines d'identifiants, le `IN` est parfaitement
   approprié et la table temporaire coûte plus qu'elle ne rapporte. La plupart des 167 `IN` de
   `BrowseEngine` portent sur des listes courtes — types de relations, `checkAccess`, listes de
   vocabulaire — et **ne doivent pas être touchés**. Seuls les `IN` portant sur des ensembles de
   résultats méritent l'examen.

## 4. Un défaut à corriger de toute façon, indépendamment de la convergence

**Le connecteur rend 0 en silence quand quelque chose échoue.** Service arrêté : 0 résultat.
Délai dépassé (`meilisearch_timeout = 10`, atteint par la requête `M 3*` en 10 083 ms) : 0
résultat. Dans les deux cas, aucune exception. Une panne devient indiscernable d'un fonds vide,
et c'est le défaut le plus dangereux relevé sur cette instance. Il doit lever.

## 5. État de la machine à la mise en pause

- Meilisearch **1.53.1**, unité `systemd` activée au démarrage, mode production avec clé maîtresse
  dans `/etc/meilisearch/meilisearch.env` (0600 root), écoute sur `127.0.0.1:7700` seulement. Clé
  transmise à PHP par `env[]` du pool php-fpm et par `/etc/meilisearch/ca-meilisearch.env` pour le
  CLI — **rien dans `setup.php` ni dans le dépôt versionné** ;
- index complet : **661 025 documents**, 2 619 Mo utilisés, 4,4 Go de fichier. Durabilité vérifiée
  par un `systemctl restart` : même compte avant et après ;
- `search_engine_plugin = Meilisearch` dans `app/conf/local/app.conf` du socle. **Retour arrière :
  commenter cette ligne** — `app.conf:843` rend alors `SqlSearch2`, dont l'index MariaDB est
  intact (113 984 071 lignes, 46,4 Go) ;
- **une modification du socle n'est pas committée et ne peut pas l'être ici** :
  `providence.bak/app/helpers/searchHelpers.php`, correction du dispatch de `caTokenizeString()`.
  Ce clone est `collectiveaccess/providence` avec une centaine de fichiers personnalisés par
  l'INRAP de longue date ; la correction doit repartir en PR en amont, pas être committée dans le
  clone d'exploitation. Sauvegarde : `searchHelpers.php.avant-meilisearch` ;
- `MeilisearchAppPlugin/conf/meilisearch.conf` porte `meilisearch_url = http://127.0.0.1:7700`,
  valeur propre à cette instance. **Volontairement non committée** : la valeur du dépôt vise le
  harnais Docker (`http://meilisearch:7700`) et la committer le casserait. Cette adresse doit
  venir de l'environnement ou d'une constante, pas du fichier versionné.

## 6. Pièges relevés ici, à ne pas repayer

- une recherche lancée **à la main** sur l'instance est enregistrée dans `ca_search_log` en
  `PROVIDENCE`, sans la marque `meilisearch-diagnostic`, et devient de l'« historique client »
  pour les exécutions suivantes de `--historique`. Borner la référence, ou marquer aussi les
  sondages manuels ;
- le dépôt cloné en `drwxr-Sr--` empêche `www-data` de traverser : le greffon ne se charge pas
  côté web et toute recherche échoue sur « Couldn't load configured search engine plugin ». Le
  CLI, lui, fonctionne — d'où un diagnostic trompeur ;
- `app/log/meilisearch.log` créé par un outil lancé en `root` passe en `root:root` et rend le
  connecteur muet côté web. Vérifier les droits après chaque commande.
