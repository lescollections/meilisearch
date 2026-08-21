# Meilisearch en remplacement de SqlSearch2 — ce que la mesure a établi

Campagne des 20 et 21 août 2026, sur le fonds du **Centre Interdiocésain du Patrimoine et des
Arts Religieux** (Namur), rapatrié dans le harnais de développement.

Ce document rassemble les résultats destinés à être présentés. Les notes de travail qui les ont
produits sont dans `CLAUDE.md` ; les sorties brutes des outils de mesure dans
`mesures/cipar-2026-08/`.

---

## L'objectif, et pourquoi il est restrictif

**Ce qu'on cherche est le comportement de SqlSearch2 servi par Meilisearch** — pas un meilleur
moteur de recherche. Un résultat que Meilisearch trouve et que SqlSearch2 ne trouve pas est un
écart à réduire, pas une amélioration à défendre.

La raison est de déploiement : un remplacement dont on ne peut pas prédire les écarts n'inspire
pas confiance, et sans confiance il ne se déploie pas là où les fonds sont gros — c'est-à-dire là
où le gain existe. Le bénéfice attendu est ailleurs : **empreinte MariaDB plus légère**, et **fin
des effondrements sur les recherches à plusieurs mots**.

Cette règle vaut **y compris quand SqlSearch2 a tort**. Le connecteur reproduit ses défauts connus
plutôt que de les corriger pour lui seul ; les corrections doivent être portées au socle
CollectiveAccess, où elles profitent à tous.

## Le terrain d'essai

| | |
|---|---|
| objets | **213 258** (210 892 vivants) |
| documents indexés | 519 206 |
| représentations, entités | 279 921 · 7 088 |
| historique de recherche | **807 536 recherches réelles**, 71 607 expressions distinctes, 2017 → 2026 |
| socle d'origine | CollectiveAccess 1.7, migré en 2.0 pour l'essai |

L'historique est ce qui donne sa valeur au terrain : les comparaisons portent sur des expressions
que des catalogueurs ont réellement tapées, avec le nombre de résultats que SqlSearch2 leur avait
rendu à l'époque, et sur un fonds figé — c'est un dump, il ne dérive pas pendant qu'on mesure.

---

## 1. Fidélité — 98 %

Sur **45 recherches plein texte** tirées de l'historique, liste figée avant les essais :

| | à ±5 % de SqlSearch2 |
|---|---|
| première approche (texte brut) | 34 sur 45 — 76 % |
| **mode `tokenize_like_sqlsearch`** | **44 sur 45 — 98 %** |

Le mode consiste à indexer, non pas le texte, mais **les mots que SqlSearch2 lui-même en
tirerait** : le connecteur appelle le tokeniseur du socle. L'équivalence est alors obtenue par
construction plutôt que par réglages successifs. Il est activé par défaut depuis le 20 août ; en
changer impose une réindexation.

Les recherches de phrase, où l'approche par texte brut se trompait d'un facteur trois à quatre
cents, tombent justes :

| | texte brut | mode tokens | SqlSearch2 |
|---|---|---|---|
| `"chemin de croix"` | 2 243 | **2 241** | 2 241 |
| `"eglise saint-roch"` | 1 105 | **591** | 591 |
| `"vierge à l'enfant"` | 5 | **2 132** | 2 123 |
| `"saint joseph"` | 5 061 | **1 776** | 1 769 |

**Un seul écart subsiste, et il est documenté comme inexpliqué** : « jauche » rend 86 fiches
contre 30. Les 56 de trop portent le mot dans un numéro composite que les deux moteurs indexent
à l'identique — vérifié — et où Meilisearch le retrouve tout de même. 56 fiches sur 213 258.

Coût du mode : **+50 % de temps d'indexation**, à comparer aux durées ci-dessous.

## 2. Performance — un compromis, pas une victoire

### Le fait structurant : le coût par résultat s'inverse

| pour mille résultats rendus | recherches à petit volume | recherches à gros volume |
|---|---|---|
| Meilisearch | 10,9 ms | 22,7 ms — **×2,1** |
| SqlSearch2 | **5,1 ms** | 68,9 ms — **×13,5** |

SqlSearch2 est le plus rapide tant que le fonds répond peu, et **se dégrade treize fois plus vite
quand le volume monte**. C'est tout le compromis, et il explique les deux chiffres qui suivent.

### En médiane, sur des recherches courantes : SqlSearch2 devant

**1,82× plus rapide** à volume de résultats égal (261 ms contre 144 ms, 32 expressions
comparables sur 40). L'écart est structurel : SqlSearch2 garde ses identifiants dans la même base
que les données, là où le connecteur les rapatrie par HTTP avant de les réinjecter en SQL.
Court-circuiter le classement par pertinence de Meilisearch ne change rien — mesuré.

### Aux extrêmes : Meilisearch, d'un facteur vingt à trente

| | Meilisearch | SqlSearch2 |
|---|---|---|
| `chaises 2*` | **2,2 s** | 45,1 s |
| `il y en a 2*` | **2,1 s** | 43,4 s (jusqu'à 100 s en browse) |
| total sur 20 expressions | **9,4 s** | 90,4 s |

Ce sont les recherches à **plusieurs mots dont l'un est tronqué** — la forme la plus banale quand
on cherche sans savoir exactement. Vingt millisecondes de plus sur une recherche courante ne se
perçoivent pas ; **quarante-cinq secondes, c'est une recherche qui a échoué.**

### Le browse complet, à 210 000 objets

| | Meilisearch | SqlSearch2 |
|---|---|---|
| `vierge` | 10 119 fiches en 299 ms | 10 076 en 86 ms |
| `chaises 2*` | 1 021 fiches en **2,7 s** | 1 011 en **66,7 s** |
| `il y en a 2*` | 1 778 fiches en **2,6 s** | 1 337 en **66,1 s** |

Même profil : 2,3 à 3,5× plus lent en ordinaire, **25× plus rapide sur les cas qui font tomber
l'autre**.

## 3. Empreinte et exploitation

| | Meilisearch | SqlSearch2 |
|---|---|---|
| index sur disque | **~3,0 Go de fichiers** | **21,4 Go dans MariaDB** |
| reconstruction complète de l'index | **14 min** | 1 h 12 |

Les 21,4 Go de SqlSearch2 sont dans la base : ils pèsent sur sa mémoire, ses sauvegardes et ses
restaurations. Les 3 Go de Meilisearch sont des fichiers d'un service séparé. Sur la
reconstruction, 25 des 72 minutes de SqlSearch2 sont une simple purge de 49 millions de lignes,
MariaDB à 100 % de CPU pendant tout ce temps.

**La réindexation incrémentale tient** : modifier une fiche dépose ses entrées dans la file du
socle, et le traitement de la file met l'index à jour. Mais 25 secondes pour 11 entrées, et la
file reste mono-processus par construction — **c'est le point à surveiller sur une instance où
l'on catalogue en continu**, et il appartient au socle, pas au connecteur.

## 4. Les facettes du browse — déléguées au moteur

Le connecteur calcule les facettes du browse à la place du SQL du socle, selon une règle stricte :
**traduire exactement ou décliner**. Une facette qu'il ne sait pas rendre à l'identique rend la
main au socle, facette par facette, sans réglage à faire.

Sur les 40 facettes du profil CIPAR :

| | |
|---|---|
| **conformes** | 28 |
| rendues au SQL (déclins assumés) | 8 |
| écarts où **c'est le socle qui a tort** | 47 |
| divergentes | **4** — une seule cause, une fiche datée 1874, inexpliquée |

Les 8 déclins sont définitifs et compris : `label` ne rend pas une distribution mais une jointure,
et `violations`/`checkouts`/`dupeidno` portent sur des tables d'état inutilisées (0, 1 et
11 postes).

**Le gain est net sous critère** — c'est-à-dire dans l'usage réel du browse :

| | Meilisearch | SqlSearch2 |
|---|---|---|
| facette de types, fonds entier | 6-8 ms | 394 ms |
| facette de titres, sous un critère à 10 333 fiches | 160 ms | 1 914 ms |

### Les 47 « écarts assumés » : décision du 19 août

Le socle a des défauts de comptage que nous avons choisi de **ne pas reproduire**, après les
avoir chiffrés. Les deux principaux :

- **il surcompte les autorités liées par plusieurs types de relation** — une entité affiche 108
  là où le clic rend 54 fiches ;
- **il éparpille puis perd des graphies** lorsqu'une valeur saisie porte des espaces parasites —
  sur « fer forgé », il affiche 6 fiches là où il y en a 19.

Dans les deux cas, **notre compte est celui que le clic rend vraiment**. L'outil de comparaison
les reconnaît et les compte à part, pour qu'ils cessent de crier au loup.

## 5. Ce que le moteur n'apportera pas de plus

Trois optimisations ont été envisagées, chiffrées, et **écartées par la mesure** :

| piste | ce que la mesure a dit |
|---|---|
| pousser les filtres dans le moteur | le filtrage SQL ne pèse que 9 à 11 % du temps |
| déléguer le jeu de résultats du browse | sa machinerie ne coûte que 27 à 349 ms |
| paginer dans le moteur | **98,6 %** des recherches réelles rendent moins de mille fiches |

La dernière est la plus nette. Sur 649 585 recherches du journal : 52,4 % rendent moins de
100 fiches, 46,2 % de 100 à 999, et **0,2 % dépassent 50 000**. Le cas que la pagination déléguée
optimiserait est celui de deux recherches sur mille.

**L'apport de Meilisearch est donc acquis et borné.** Il ne s'agit pas d'un travail à poursuivre
jusqu'à dépasser SqlSearch2 partout : cela n'arrivera pas, et ce n'était pas le but.

## 6. Intégration — le cœur de CollectiveAccess n'est plus modifié

La première version insérait 36 lignes **dans** `BrowseEngine::getFacetContent()`, un fichier de
8 516 lignes : un patch invisible à la première montée de version, et un fork du socle à
maintenir.

Désormais, tout ce qui appartient au connecteur vit dans des fichiers qui portent son nom.
La délégation se pose en substituant `BaseBrowse.php`, une classe-crochet de cinquante lignes que
toutes les classes de browse traversent et qui ne fait rien d'autre que déléguer. L'original est
conservé à côté ; `./installer.sh <racine> --retirer` le remet en place.

**Une seule correction reste à porter au socle**, et elle est indépendante de Meilisearch : un
test de dispatch fautif dans `caTokenizeString()`, qui fait prendre le repli SqlSearch2 pour tous
les greffons.

---

## Où en est le déploiement

| instance | état |
|---|---|
| **floutier** | déployé, comparé, **pas basculé** — la décision reste à prendre |
| **CIPAR** | fonds rapatrié pour l'essai ; rien de déployé chez le client |
| **INRAP** (comodo-preprod) | première mise à l'échelle réelle (661 025 documents) ; essai à reprendre |
| **Mayenne** | hors de portée — le connecteur ne se charge pas sur CollectiveAccess 1.7 |

**Ce que la bascule de floutier emporterait**, et qui n'est pas décidé : elle change la
**recherche**, pas seulement les facettes. Les manques connus du connecteur deviendraient visibles
des catalogueurs. Et comme Pawtucket a sa propre configuration, il resterait sur SqlSearch2 —
dont l'index cesserait d'être tenu à jour, le site public dérivant doucement. Réversible, mais à
décider en connaissance de cause.

## Ce qui n'est pas résolu

À dire tel quel, plutôt que de le découvrir en production :

1. **La divergence d'une fiche sur les facettes de dates** (poste 1874) reste inexpliquée. Une
   correction a été essayée, mesurée fausse, et retirée.
2. **L'écart « jauche »** — 56 fiches sur 213 258 — reste inexpliqué.
3. **La file de réindexation est mono-processus**, et lente rapportée à son volume.
4. **La réindexation vide l'index avant de commencer**, donc le service part de zéro et remonte
   sur toute la durée. Une option « sans purge » est possible et vaut cher pour les instances
   dont la réindexation occupe une journée ouvrée.
5. **La descendance d'un thésaurus n'est éprouvée que sur matière fabriquée** : aucun fonds
   disponible ne porte de liste hiérarchique facettée.
