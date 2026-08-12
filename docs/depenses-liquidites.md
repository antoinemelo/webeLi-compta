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
2. `a_approuver` : numéro interne attribué, document figé, puis explicitement
   approuvé ou refusé ;
3. `approuve` : décision tracée avec opérateur et horodatage ;
4. `comptabilise` : écriture fournisseur équilibrée et idempotente ;
5. `annule` : brouillon ou dépense approuvée abandonnée sans écriture.

Une dépense comptabilisée reste immuable et ne peut plus être annulée depuis
ce parcours. Une soumission refusée est distinguée visuellement d’une
annulation. Le clic sur l’identifiant ouvre le brouillon en modification et
les autres états en consultation dans une fenêtre modale. Les actions de
ligne sont regroupées sous le menu vertical « ⋮ ».

La référence fournisseur est unique pour un même fournisseur dans tout le
dossier, aussi bien entre **Achats** et **Dépenses** qu’après un refus ou une
annulation. Cette conservation empêche la double saisie d’une même pièce. En
cas de doublon, le message indique désormais le document, son numéro et son
statut au lieu de masquer la contrainte SQLite derrière une erreur générique.

Les permissions `depenses.manage`, `depenses.approve` et `depenses.post`
séparent préparation, approbation et grand livre. Leur attribution à des
personnes différentes reste un choix organisationnel configurable.

## Récurrences

Un modèle conserve fournisseur, périodicité, fin facultative, jours
d’échéance, compte de paiement et lignes. Chaque couple modèle/date porte une
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
valide subsiste. Elle est aussi définitivement refusée après comptabilisation.
La saisie d’un paiement se fait depuis
**Liquidités > Paiements > Saisir un paiement** dans une fenêtre modale ; le
contact indicatif y est facultativement recherchable par entreprise, prénom ou
nom. Chaque allocation reprend le contact de la facture et un même paiement
peut donc couvrir plusieurs contacts. Le rapprochement et le lettrage restent
des actions distinctes, mais partagent le même paiement et la même écriture
comptable idempotente.

## Retour arrière

Le schéma 0.6.1 couvre les migrations immuables `001` à `008`. Il ne doit pas
être reconstruit en modifiant `001_initial.sql`. Avant une mise à niveau,
créer la sauvegarde prévue par le moteur de migration, puis exécuter :

```bash
php bin/console db:integrity
php bin/console qualify
```

Toute évolution suivante est portée par une migration additive à partir de
`009`. Une restauration de sauvegarde constitue le retour arrière. Voir
[`migrations.md`](migrations.md).
