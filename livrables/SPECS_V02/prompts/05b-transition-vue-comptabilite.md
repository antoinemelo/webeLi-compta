# Lot 05b — Comptabilité Vue et source métier unique

Applique le prompt maître.

Objectif : terminer la transition du journal, des extraits et du plan comptable
vers Vue sans réécrire le moteur comptable.

## Travaux

- exposer une projection comptable unique sous `/api/v1/accounting` ;
- faire passer toute mutation par `EntryService` ou
  `ChartOfAccountsService` ;
- fournir dans Vue la saisie débit/crédit, les écritures récentes, l'extrait
  avec compte en T, les types, règles de sens, rubriques, comptes et soldes
  d'ouverture ;
- conserver les contrôles de centimes, équilibre, période, scope, permissions,
  CSRF, idempotence et immutabilité côté PHP ;
- rediriger les anciennes pages PHP vers `/app/compta` et supprimer leurs
  gabarits et scripts devenus doubles ;
- consolider le schéma de développement dans `001_initial.sql`.

## Acceptation

- une installation vierge applique une seule base initiale et passe les
  contrôles SQLite ;
- journal, extrait et plan proviennent des mêmes services et tables ;
- une écriture simple ou composée est exacte au centime et un déséquilibre est
  atomiquement refusé ;
- un identifiant de scope injecté est refusé ;
- les anciens chemins comptables redirigent vers Vue ;
- aucun ancien écran ne reste capable de modifier le plan en parallèle ;
- lint PHP, build Vue, tests rapides, intégration et E2E sont verts.

Ne développe pas encore les états financiers, la TVA et les immobilisations :
leurs parcours Vue appartiennent aux lots suivants, mais leurs services et
rapports existants restent intacts.
