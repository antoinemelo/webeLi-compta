# Multi-entités, agrégation et consolidation

Les organisations restent les entités légales du système ; leurs dossiers
restent des périmètres comptables distincts. Une nouvelle identité juridique
est enregistrée comme une version datée, sourcée et immuable. La version
précédente est fermée à la veille, sans réécriture.

## Choisir le bon mode

- **Agrégation interne** : réunit au moins deux dossiers d’une même
  organisation pilote. Le rapport produit est une vue de gestion et ne
  constitue pas une consolidation légale.
- **Consolidation légale** : réunit des dossiers appartenant à au moins deux
  organisations juridiques distinctes dès la première période.
- **Livres statutaires** : journaux, comptes et écritures propres à chaque
  dossier. Ils ne sont jamais copiés, déplacés ou modifiés par un groupe.

Le mode et la devise sont obligatoires à la création et deviennent immuables
dès la première période.

## Assistant et cycle de vie

Dans `Comptabilité > Consolidation > Groupe et mappings`, l’assistant conduit
l’opérateur en quatre étapes :

1. choisir le mode, le dossier pilote, la devise et le début d’appartenance ;
2. sélectionner les dossiers membres visibles ;
3. figer la période et ses ratios, puis compléter les mappings ;
4. contrôler la formule, les anomalies et confirmer l’activation.

Un groupe est créé en `brouillon`, puis devient `actif` après prévisualisation.
Il peut être archivé et réactivé sans perdre son historique. Son libellé reste
modifiable avec verrou optimiste. Un membre sans donnée peut être supprimé
physiquement ; dès qu’une période l’utilise, seule une sortie datée est admise.

## Isolation et droits

Un groupe ne donne aucun droit sur ses membres. L’API le masque entièrement
tant que l’utilisateur ne possède pas `compta.view` sur chacun des dossiers.
Les mutations exigent de même `compta.setup`, `compta.validate` ou
`compta.export` sur chaque membre. L’ajout d’un dossier contrôle son droit
propre avant toute écriture.

Toutes les listes, balances, rapprochements et éliminations affichent le couple
`organisation — dossier` pour éviter toute ambiguïté.

## Périodes, mappings et change

Chaque période fige un ratio entier `numérateur / dénominateur`, sa date et sa
source pour chaque membre. Il convertit les unités mineures de la devise
fonctionnelle du dossier vers celles du groupe. Une devise identique impose
`1 / 1` ; aucun `float` n’intervient.

**Politique à valider :** le moteur applique ce ratio sourcé unique par membre
et période. Il ne déduit pas de taux moyen ou historique selon la classe de
comptes. Cette politique doit être arrêtée avant une utilisation de production.

Chaque compte mouvementé doit être relié explicitement à un compte cible. La
clôture et l’activation sont refusées tant qu’un mapping manque. La piste
expose toujours les balances sources, leur dossier, leur compte et le taux
figé :

`balances sources converties + éliminations = résultat du groupe`.

Après une clôture, un mapping ou une paire inter-entités n’est jamais réécrit :
sa version est fermée et une nouvelle version datée prend le relais. Les
résultats d’une période clôturée restent donc reproductibles.

## Inter-entités et éliminations

Une paire inter-entités relie deux comptes de deux membres différents. Leur
somme après conversion constitue l’écart de réconciliation, visible jusqu’à sa
résolution ou à une élimination documentée.

Les éliminations vivent exclusivement dans
`eliminations_consolidation` et `lignes_elimination_consolidation`. Elles sont
équilibrées au centime, justifiées, auditées puis immuables. Elles ne
référencent pas `ecritures` et n’apparaissent jamais dans un livre statutaire.

## Export, migration et retour arrière

L’export JSON autonome précise s’il s’agit d’une agrégation interne ou d’une
consolidation légale. Il contient groupe, période, membres, identités
juridiques, versions de mappings, balances sources, taux, réconciliation et
éliminations ; une empreinte SHA-256 couvre le contenu.

La gouvernance 14e est installée par la migration additive
`002_consolidation_governance.sql`. Avant application sur une instance
existante, exécuter :

```bash
php bin/console db:migrate --plan
php bin/console db:migrate --apply --backup
```

Le retour arrière consiste à arrêter les écritures et restaurer la sauvegarde
SQLite créée juste avant la migration. Une période clôturée ou une élimination
validée ne se corrige jamais directement en base.
