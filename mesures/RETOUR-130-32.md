# Retour de terrain — première réindexation complète en production réelle (instance 130.32)

25 août 2026. Rédigé pendant la réindexation, chiffres de fin de course à compléter.
L'instance est désignée par les deux derniers octets de son adresse.

---

## Le terrain, et ce qui le distingue de tout ce qu'on avait mesuré

| | |
|---|---|
| objets vivants | **253 658** (fiches très riches en attributs) |
| corpus attendu | ≈ 660 000 documents |
| particularité | **base MariaDB distante** — 1,9 ms de réseau, **2,2 ms par requête** (contre ~0,05 ms en local) |
| contexte de lancement | en journée, utilisateurs actifs — sans gêne constatée (SqlSearch2 continue de servir, charge 2 sur 12 cœurs) |

C'est la première réindexation complète du connecteur sur une production réelle. Les deux
terrains précédents — le fonds d'essai (CIPAR) et la préproduction de cette même instance —
avaient chacun leur base **locale**.

## Les mesures

| table | volume | débit |
|---|---|---|
| ca_set_items | 272 728 lignes en 232 s | **1 174 lignes/s** |
| ca_objects | 253 658 lignes | **14 à 18 lignes/s** |

Le rapport entre les deux lignes est le fait central : **un facteur 70 entre la table la plus
légère et la plus lourde du même fonds**. Les set_items se lisent directement ; chaque objet,
lui, paie l'hydratation complète de ses attributs, et chacune de ces requêtes paie la latence
réseau. La préproduction (base locale, code identique) donnait 13 à 26 lignes/s sur la même
table : la latence distante n'est donc pas le facteur dominant — **c'est le coût d'hydratation
par fiche qui fait la durée, la latence le multiplie**.

Durée de bout en bout : lancée à 17 h 05, fin projetée entre 23 h 30 et 1 h — **7 à 8 heures**,
là où le fonds CIPAR (519 206 documents, fiches plus simples, base locale) tenait en 14 minutes.
À corpus comparable, un facteur 30.

## Ce que cette course apprend

1. **On n'extrapole jamais une durée d'un fonds à l'autre.** Le nombre de documents ne prédit
   rien ; le débit mesuré sur la table dominante du fonds cible, si. L'estimation annoncée ici
   (fondée sur CIPAR) était fausse d'un facteur 30 — la mesure préproduction, déjà consignée
   dans l'en-tête du réindexeur, donnait pourtant le bon ordre de grandeur.

2. **Deux ouvriers sur huit sont morts en silence** — ni OOM, ni stderr, ni trace : des zombies.
   La file commune a fait son travail : pas de trous, les six survivants ont absorbé la charge,
   perte bornée à deux lots (200 identifiants), vérifiable par comptage final. Ce qui manque au
   réindexeur : un journal de sortie par ouvrier, un battement de cœur, une relance automatique.

3. **Le plafond de huit processus est inadapté aux bases distantes.** Le travail est borné par
   la latence, pas le calcul : 21 % de CPU par ouvrier. Seize à vingt-quatre processus seraient
   quasi linéairement plus rapides. À paramétrer plutôt qu'à plafonner.

4. **L'option « réindexer sans purge » change de statut.** Limite connue et tolérable sur un
   fonds qui se réindexe en 15 minutes ; sur 8 heures de production, c'est une fenêtre entière
   sans index complet et l'impossibilité d'interrompre sans tout reperdre. Elle passe de
   « vaut cher » à nécessaire.

5. **Réindexer à chaud est sans douleur pour les utilisateurs** — le moteur en place continue de
   servir, la charge reste marginale. C'est le point à dire aux exploitants : la contrainte de
   fenêtre ne porte que sur la bascule, pas sur l'indexation.

## Chiffres de fin de course (26 août)

**Durée totale : 370 minutes** (17 h 05 → 23 h 16). 661 162 lignes, quinze tables, six vides.

**Sept ouvriers sont morts en cours de route**, sur quatre tables (objets ×2, représentations ×1,
entités ×3, occurrences ×1) — et la cause réelle n'est pas celle qu'on croyait. Ce n'est pas
« une valeur nulle » : c'est **une valeur au codage UTF-8 invalide**. `preg_replace` en mode `/u`
retourne alors null, que `caIdentifyAlphabet()` — non nullable — transforme en TypeError fatale.
Un premier garde-fou posé à l'entrée de `tokenize()` (nul → rien) n'a rien changé : le null naît
*au milieu* de la fonction. Le correctif qui tient : réparer le codage
(`mb_convert_encoding` UTF-8 → UTF-8) et retenter. Validé unitairement, puis à l'échelle —
la reprise des trois tables s'est faite **sans une seule mort**.

**Le rattrapage a coûté 8 minutes** là où la course avait coûté six heures :
les trois tables légères refaites entièrement (purge par table, 247 s), les 72 objets perdus
— deux plages contiguës, la signature exacte des lots en vol des deux ouvriers morts —
réindexés chirurgicalement sans purge, et la dérive accumulée depuis le lancement (2 lignes)
rejouée depuis le journal des modifications.

**Bilan final : quinze tables sur quinze, zéro écart, identifiant par identifiant sur les
quatre tables touchées.** L'outillage qui reste : un script de rattrapage de dérive à rejouer
juste avant la bascule, pour un index exact à la seconde.

La leçon qui manquait à la liste : **le tokeniseur partagé est un point de défaillance commun**
— le même TypeError guette l'indexation en ligne de SqlSearch2 à chaque sauvegarde d'une fiche
mal encodée. Le garde-fou protège donc aussi le moteur en place ; la correction doit remonter
au socle en PR.
