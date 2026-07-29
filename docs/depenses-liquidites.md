# Dépenses et utilisation des liquidités

## Source de vérité

Une dépense est une `facture_fournisseur` de `documents_financiers` avec le
workflow `depense`. Elle réutilise les contacts, lignes et snapshots TVA,
comptes, justificatifs, paiements et allocations existants. Il n’existe donc
aucun second moteur d’achats.

Les montants sont calculés en centimes par `VatLineService`. La
comptabilisation appelle exclusivement `BillingService`, puis `EntryService`.
Une création ou une génération récurrente ne produit jamais d’écriture.

Le dossier doit posséder un régime TVA couvrant la date de la dépense. Une
base ou un dossier créé par l’initialisation standard reçoit automatiquement
un régime `non_assujetti` daté du début de l’exercice. Si l’organisation est
assujettie, ce régime doit être remplacé par sa configuration réelle dans
`Configuration > Référentiels > TVA`.

## Cycle

1. `brouillon` : lignes modifiables et justificatif numérique facultatif ;
   lorsqu’il existe, il est archivé dans SQLite hors du webroot ;
2. `a_approuver` : numéro interne attribué, document figé ;
3. `approuve` : décision tracée avec opérateur et horodatage ;
4. `comptabilise` : écriture fournisseur équilibrée et idempotente ;
5. `annule` : aucune allocation ouverte ; si le document était comptabilisé,
   l’écriture est contre-passée, jamais supprimée.

Les permissions `depenses.manage`, `depenses.approve` et `depenses.post`
séparent préparation, approbation et grand livre. Leur attribution à des
personnes différentes reste un choix organisationnel configurable.

## Récurrences

Un modèle conserve fournisseur, périodicité, fin facultative, jours
d’échéance, compte collectif et lignes. Chaque couple modèle/date porte une
unicité persistante et le document généré reçoit une clé de génération unique.
Un rejeu de la commande ou de l’endpoint ne crée donc pas de doublon.

```bash
php bin/console depenses:recurrences-generer \
  --organisation=1 --dossier=1 --jusqu-au=2026-07-31
```

La commande convient à cron sur hébergement mutualisé. Chaque échéance devient
un brouillon sans justificatif qui peut être soumis, approuvé et comptabilisé.
Une preuve numérique peut être ajoutée, mais elle n’est jamais obligatoire.

## Paiements

Le paiement demeure un objet séparé dans `paiements`. Le lettrage reste une
allocation explicite dans `allocations`. La comptabilisation d’une dépense ne
crée jamais de décaissement et l’annulation est refusée tant qu’une allocation
valide subsiste. L’émission et le rapprochement sont traités au lot 07.

## Retour arrière en développement

Le schéma n’étant pas gelé, sauvegarder la base de confort, reconstruire depuis
`database/migrations/001_initial.sql`, puis exécuter :

```bash
php bin/console db:integrity
php bin/console qualify
```

Après le gel de production, cette évolution devra être portée par une migration
additive et une restauration de sauvegarde constituera le retour arrière.
