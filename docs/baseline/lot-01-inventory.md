# Inventaire de référence — lot 01

État observé le 26 juillet 2026, avant toute évolution fonctionnelle.

## Socle

- PHP 8.2, SQLite et rendu serveur PHP.
- 10 migrations historiques immuables : `001` à `010`.
- 72 fichiers PHP sous `src/`, 13 gabarits et 29 routes HTTP.
- Une suite historique représentant 342 assertions avant le découpage.
- Aucun frontal Vue présent dans COMPTA au démarrage du lot.

## Commandes historiques

`app:doctor`, `db:migrate`, `db:integrity`, `user:create-admin`,
`organisation:create`, `dossier:create`, `exercice:create`, `role:grant`,
`compta:plan-seed`, `compta:plan-install`, `compta:periode-create`,
`compta:journal-create`, `tresorerie:compte-create`,
`tresorerie:import-preview`, `tresorerie:import-confirm`,
`tresorerie:etat`, `tva:periode-create`, `tva:decompte-prepare`,
`tva:decompte-control`, `tva:ech-export`, `tva:decompte-declare` et `test`.

## Routes HTTP

19 routes `GET` et 10 routes `POST` couvrent l'installation, la connexion, le
tableau de bord, les pièces, les écritures, la trésorerie, la TVA, la
facturation, les salaires, la pédagogie et l'administration.

## Permissions

Les familles de permissions en place sont : `installation`, `organisation`,
`dossier`, `exercice`, `audit`, `compta`, `tresorerie`, `tva`, `facturation`,
`salaires` et `pedagogie`. Les contrôleurs restent la source d'autorité pour
les permissions détaillées.

## Rapports comptables figés

Le scénario de référence crée une ouverture, une vente et une charge. Il fige
dans `tests/fixtures/baseline-reports.json` :

- le journal et la balance ;
- le compte de résultat et le bilan ;
- le grand livre du compte bancaire.

Ces valeurs, les empreintes SHA-256 des migrations et le commit Git du lot
forment ensemble le point de restauration vérifiable.
