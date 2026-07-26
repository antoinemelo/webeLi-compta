# Vision et périmètre

## Vision

Fournir un outil francophone simple qui permette :

1. d'enseigner la logique de la partie double sur des cas guidés ;
2. de tenir réellement la comptabilité d'une petite structure ;
3. de suivre la trésorerie, les créances clients et les dettes fournisseurs ;
4. de calculer et documenter les salaires suisses ;
5. de relier les documents opérationnels à leur traduction comptable.

La simplicité signifie ici : peu de dépendances, installation prévisible,
écrans cohérents et maintenance explicite. Elle ne signifie pas sacrifier les
invariants comptables.

## Utilisateurs

- **Administrateur** : installation, comptes, rôles, dossiers, sauvegardes.
- **Comptable** : paramétrage, saisie, validation, rapprochement, clôture.
- **Gestionnaire paie** : employés, taux, fiches, certificats, écritures de paie.
- **Formateur** : scénarios, consignes, modèles et suivi des exercices.
- **Apprenant** : accès limité à ses dossiers d'exercice.
- **Lecteur/auditeur** : consultation et exports sans mutation.

Une personne peut cumuler plusieurs rôles. Les autorisations sont accordées par
dossier, pas seulement globalement.

## Hiérarchie centrale

```text
installation
└── organisation
    └── dossier / mandat
        └── exercice comptable
```

- Une **installation** est un déploiement autonome du logiciel et sa base SQLite.
- Une **organisation** est une entité gérée, réelle ou pédagogique. Plusieurs
  organisations coexistent sans consolidation ni écritures inter-organisations.
- Un **dossier/mandat** est un espace comptable isolé appartenant à une organisation.
- Un **exercice** est une période comptable du dossier, généralement annuelle.

Une organisation peut posséder plusieurs mandats et chaque mandat plusieurs
exercices. Un utilisateur reçoit des droits par organisation et par dossier.
La nature de l'organisation (`reelle` ou `pedagogique`) et le type du dossier
forment deux contrôles distincts ; aucun simple paramètre visuel ne change l'un
ou l'autre.

## Types de dossier

Un dossier représente une entité comptable ou un exercice :

- `reel` : données de production, pas de réinitialisation ;
- `demo` : données fictives partageables ;
- `exercice` : copie isolée d'un modèle pédagogique, réinitialisable.

Chaque dossier possède sa monnaie (CHF au MVP), son plan comptable, ses exercices,
ses journaux, ses contacts et ses paramètres. Toutes les requêtes métier doivent
filtrer explicitement sur `organisation_id` et `dossier_id`, directement ou par
une relation contrôlée.

Une installation scolaire ne doit pas être physiquement séparée d'une installation
réelle. La coexistence est supportée, mais un apprenant n'obtient jamais de droit
sur un dossier réel. Le type de dossier, l'organisation et l'exercice restent
visibles sur chaque écran.

## MVP

- Connexion, rôles et permissions par dossier.
- Création/archivage d'organisations, dossiers et exercices.
- Plan comptable PME suisse VEB, variante association et import personnalisé.
- Exercices comptables, soldes d'ouverture, journaux et périodes.
- Écritures simples ou composées, brouillon, validation et contre-passation.
- Journal, grand livre, balance, bilan et compte de résultat.
- Comptes banque/caisse, imports CSV et rapprochement.
- Contacts clients/fournisseurs.
- Factures clients et fournisseurs, notes de crédit, échéances et paiements partiels.
- TVA suisse complète au niveau nécessaire à une tenue réelle et à son décompte :
  méthode effective et TDFN, codes/taux datés, impôt préalable et export eCH-0217.
- Paie OCAS : employés, taux annuels, prestations, fiches, certificats et exports.
- Génération contrôlée des écritures de paie et de factures.
- Axes analytiques facultatifs.
- Modèles d'exercices, travail individuel ou en groupe depuis plusieurs postes,
  consignes, progression et validation automatique simple.
- Exports CSV/PDF, sauvegarde/restauration et journal d'audit.

## Après le MVP

- Rapprochement bancaire avancé et initiation de paiements.
- Déclarations sociales électroniques officielles.
- Budgets et prévisions de trésorerie avancées.
- Relances automatiques planifiées.
- API publique, intégrations et application mobile.
- Événements/SUISA repris de l’OCAS.

## Explicitement hors périmètre initial

- ERP généraliste, stock, e-commerce ou CRM complet.
- Multi-devises avec gains/pertes de change.
- Consolidation de groupes.
- Édition collaborative temps réel.
- Effacement physique arbitraire des données comptables validées.

## Indicateurs de succès

- Une installation neuve fonctionnelle en moins de 15 minutes.
- Une écriture équilibrée créée en moins d'une minute.
- Aucun écart entre balance débit/crédit, à tout moment.
- Une facture partiellement payée affiche le solde exact et ses allocations.
- Une fiche de salaire reste reproductible après changement des taux.
- Un décompte TVA est traçable jusqu'aux écritures et exportable en eCH-0217.
- Deux étudiants peuvent travailler simultanément dans un dossier de groupe sans
  perte de saisie ni confusion d'auteur.
- Une base réelle survit à sauvegarde, migration et restauration testée.
- Un apprenant ne peut jamais voir un dossier réel ou la solution d'un exercice.
