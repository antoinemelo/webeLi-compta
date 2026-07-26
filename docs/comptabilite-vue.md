# Comptabilité dans Vue

Les parcours quotidiens de comptabilité sont servis sous `/app/compta` :

- `journalisation` : saisie composée et écritures récentes ;
- `extraits` : grand livre et représentation en compte en T ;
- `plan` : types, règles de sens, rubriques, comptes et ouverture.

## Architecture

`AccountingApiController` valide HTTP, session, CSRF et permissions.
`AccountingWorkspaceService` orchestre ensuite les services métier existants :

- `EntryService` pour les écritures et ouvertures ;
- `ChartOfAccountsService` pour le plan ;
- `ReportingService` pour journal et grand livre.

Vue ne contient ni SQL, ni calcul de solde, ni règle de validation comptable.
Les montants de l'API sont des centimes entiers. L'organisation et le dossier
proviennent exclusivement de la session ; leur injection dans une charge utile
est rejetée.

## Fin des doubles parcours

Les anciennes routes `/compta`, `/compta/saisie`, `/compta/compte` et
`/compta/plan` redirigent vers leur équivalent Vue. Leurs gabarits et le script
historique du plan ont été retirés. Les rapports PHP non encore repris par Vue
restent disponibles jusqu'au lot des documents financiers.

## Schéma de développement

Le modèle courant est contenu dans `database/migrations/001_initial.sql`.
L'ancien empilement 001 à 012 n'est plus nécessaire pour une installation
neuve. Tant que le produit n'est pas gelé pour la production, la procédure
normale est :

1. sauvegarder la base locale si son contenu peut être utile ;
2. reconstruire une base vide depuis `001_initial.sql` ;
3. recréer les données de développement ;
4. lancer `db:integrity`, les tests et le build Vue.

Après le gel de production, `001_initial.sql` devient immuable et toute
évolution reçoit une migration additive.

## Retour arrière

Le code revient par le commit Git précédent. Une base reconstruite revient
uniquement par restauration de sa sauvegarde ; aucune tentative de suppression
manuelle de tables ou colonnes n'est supportée.
