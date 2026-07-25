# Prompt maître

Tu construis une application web suisse de comptabilité, trésorerie, facturation,
salaires et enseignement. Le dossier de spécifications fourni avec ce prompt est
la source de vérité fonctionnelle. Lis-le entièrement avant toute modification.

## Contraintes non négociables

- PHP 8.2+ et SQLite, monolithe modulaire, HTML rendu serveur.
- Pas de framework lourd, SPA, Node en production, CDN ni service obligatoire.
- `public/` seul webroot ; configuration et données hors webroot.
- Montants monétaires en centimes entiers.
- Comptabilité en partie double ; toute écriture validée est équilibrée et immuable.
- Correction par contre-passation, jamais modification de l'historique.
- Factures, paiements, imports bancaires et paies sont des objets distincts reliés
  à la comptabilité par services, allocations et rapprochements.
- Hiérarchie installation → organisation → dossier/mandat → exercice.
- Plusieurs organisations par installation, sans consolidation.
- Isolation de chaque accès par organisation, `dossier_id` et permissions.
- Dossiers réels non réinitialisables ; exercices pédagogiques individuels ou
  partagés par un groupe multi-postes.
- TVA suisse opérationnelle : méthode effective/TDFN, taux datés, décompte et eCH-0217.
- Plan PME suisse VEB avec source citée et overlay association compatible.
- CSV et CAMT `camt.053`/`camt.054`.
- Salaires genevois, sans transmission Swissdec.
- Aucun workflow d'approbation des factures fournisseurs.
- Support d'instances autonomes installées dans des sous-répertoires différents.
- Requêtes préparées, CSRF, échappement, sessions sûres, audit.
- Migration incrémentale après sauvegarde vérifiée ; jamais de rebuild d'une base réelle.
- Dépendances verrouillées et réellement compatibles PHP 8.2.
- Interface, messages, documentation et commentaires utiles en français.

## Méthode de travail

Avant de coder :

1. inspecte le dépôt, ses instructions et l'état Git ;
2. reformule le lot demandé et ses critères d'acceptation ;
3. signale toute contradiction avec les spécifications ;
4. propose un plan court limité au lot.

Pendant le lot :

- ne mélange pas de refonte sans rapport ;
- mets la logique métier dans des services testables ;
- centralise SQL dans des repositories ;
- enveloppe toute mutation multi-table dans une transaction ;
- ajoute d'abord les tests des invariants critiques ;
- n'utilise jamais de données personnelles réelles dans tests/logs.

Avant de conclure :

1. exécute lint, tests ciblés puis suite complète ;
2. contrôle clés étrangères et intégrité SQLite ;
3. teste installation/migration si le schéma ou le packaging change ;
4. résume fichiers changés, décisions, preuves et risques restants.

Ne prétends jamais qu'un test a réussi sans l'avoir exécuté. Si une information
métier manque, respecte `07-decisions-confirmees.md`, puis documente
l'hypothèse résiduelle et garde le paramètre modifiable.
