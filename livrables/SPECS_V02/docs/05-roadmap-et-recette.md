# Roadmap et recette

## Séquence recommandée

### Palier A — sécuriser et rendre visible

Versionner l'état actuel, ajouter les contrats HTTP, le shell Vue, les
projections du tableau de bord et la configuration des modules. Aucun changement
du calcul comptable.

### Palier B — parcours quotidiens

Dépenses/récurrences, écran bancaire, rapprochement, lettrage, paiements
sortants, factures récurrentes, contacts et aging.

### Palier C — clôture et administration

Flux de trésorerie, TVA dans Vue, dossier fiscal, immobilisations,
amortissements, paie mensuelle, décomptes annuels et archives.

### Palier D — fonctions avancées

Multi-devise, multi-entités, réconciliation inter-entités et consolidation.
Chaque fonction est activable et ne change pas le comportement mono-entité CHF.

### Palier E — pédagogie et finition

Parcours pédagogiques ciblés dans la nouvelle interface, accessibilité,
performance, restauration et audit final contradictoire.

## Portes obligatoires à chaque lot

- état Git propre et portée documentée ;
- migrations anciennes inchangées ;
- tests existants toujours verts ;
- nouveaux tests unité + intégration SQLite + contrat HTTP ;
- E2E Vue pour le parcours heureux et au moins un refus ;
- preuve d'isolation inter-dossiers et inter-organisations ;
- aucune somme en flottant ;
- sauvegarde/restauration testées si le schéma change ;
- build Vue reproductible, assets locaux et archive mutualisée vérifiée ;
- documentation opérateur mise à jour.

## Scénarios de recette transversaux

1. Facture client partiellement payée : aging, tableau de bord, lettrage, TVA et
   grand livre concordent.
2. Facture fournisseur récurrente : approbation, comptabilisation, pain.001,
   import CAMT et rapprochement sans doublon.
3. Salaire horaire et salaire mensuel : dettes, paiement, fiche et annuel
   concordent.
4. Clôture : dotations, TVA, flux, bilan et résultat sont reproductibles ; une
   période fermée refuse les écritures.
5. Devise étrangère : facture, paiement partiel et gain/perte de change sont
   équilibrés au centime.
6. Deux entités : aucune fuite de données ; la consolidation est réconciliable
   avec leurs balances et ses éliminations.
7. Exercice pédagogique : aucune donnée réelle n'est visible ou réinitialisée.

## Budgets techniques

- première réponse API usuelle sous 500 ms sur un jeu représentatif ;
- pagination obligatoire au-delà de 100 lignes ;
- requêtes du tableau de bord bornées et indexées ;
- opérations d'écriture courtes sous verrou SQLite ;
- tests de concurrence sur idempotence et numérotation ;
- WCAG 2.2 AA visé pour clavier, focus, libellés, contrastes et erreurs.
