# Multi-entités et consolidation

Les organisations existantes restent les entités légales du système et
conservent leurs identifiants. Une nouvelle identité juridique est enregistrée
comme une version datée, sourcée et immuable ; la version précédente est fermée
à la veille sans réécrire son contenu.

## Isolation et droits

Un groupe de consolidation référence des dossiers membres, mais ne donne aucun
droit sur eux. L’API masque entièrement un groupe tant que l’utilisateur ne
possède pas `compta.view` sur chacun de ses membres. Les mutations exigent de
même `compta.setup`, `compta.validate` ou `compta.export` sur chaque dossier.
L’ajout d’un membre contrôle aussi le droit sur la cible avant toute écriture.

Les journaux, comptes et écritures restent dans leur organisation d’origine.
Aucune ligne statutaire n’est copiée ou déplacée par la consolidation.

## Périodes, mappings et change

Chaque période fige un ratio entier `numérateur / dénominateur`, sa date et sa
source pour chaque membre. Le ratio convertit les unités mineures de la devise
fonctionnelle du dossier vers celles de la devise du groupe. Une devise
identique impose `1 / 1`; aucun `float` n’intervient.

**Politique à valider :** le moteur applique actuellement ce ratio sourcé
unique par membre et période. Il ne déduit pas de taux moyen ou historique
différent selon la classe de comptes ; ce choix doit être arrêté avec la
politique comptable du groupe avant une utilisation de production.

Chaque compte mouvementé doit être relié explicitement à un compte cible. La
clôture est refusée si un compte source utilisé reste sans mapping. Une ligne de
balance consolidée expose toujours les balances sources, leur dossier, leur
compte et le taux figé :

`balance source convertie + éliminations = montant consolidé`.

## Inter-entités et éliminations

Une paire inter-entités relie deux comptes appartenant à deux membres
différents. Leur somme, après conversion, constitue l’écart de réconciliation.
Cet écart reste visible jusqu’à sa résolution ou à une élimination documentée.

Les éliminations vivent exclusivement dans
`eliminations_consolidation` et `lignes_elimination_consolidation`. Elles sont
équilibrées au centime, validées immédiatement avec une justification, auditées
et ensuite immuables. Elles ne référencent pas `ecritures` et n’apparaissent
donc jamais dans un grand livre statutaire.

## Export et retour arrière

L’onglet `Comptabilité > Consolidation` exporte une piste JSON autonome :
groupe, période, membres, identités juridiques, mappings, balances sources,
taux, réconciliation et éliminations. Une empreinte SHA-256 couvre le contenu.

Avant le gel de production, le retour arrière du schéma consiste à restaurer la
sauvegarde de confort prise avant la reconstruction canonique. Après gel, ces
tables devront être introduites par une migration additive ; une période
clôturée ou une élimination validée ne se corrige jamais par modification
directe.
