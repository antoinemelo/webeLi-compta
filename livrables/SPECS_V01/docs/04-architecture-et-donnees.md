# Architecture et données

## 1. Architecture recommandée

Monolithe modulaire, contrôleurs minces et HTML rendu côté serveur :

```text
app/
├── public/
│   ├── index.php
│   └── assets/
├── config/
│   ├── app.php
│   ├── modules.php
│   └── routes.php
├── src/
│   ├── Core/
│   │   ├── Auth/ Http/ Database/ Audit/ Files/ Migration/
│   │   └── Support/
│   └── Modules/
│       ├── Dossiers/
│       ├── Compta/
│       ├── Tresorerie/
│       ├── Facturation/
│       ├── Tva/
│       ├── Salaires/
│       ├── Analytique/
│       └── Pedagogie/
├── templates/
├── database/
│   ├── migrations/
│   └── seeds/
├── storage/
│   ├── database/ documents/ backups/ logs/ cache/
│   └── .htaccess
├── tools/
├── tests/
├── bin/console
├── composer.json
└── VERSION
```

`public/` est le seul webroot. `storage/` et la configuration locale ne sont
jamais servis directement.

## 2. Règles de dépendance

- `Core` ne dépend d'aucun module.
- Un module expose des services/ports explicites ; pas d'inclusion de fichier
  arbitraire entre modules.
- `Compta` fournit l'API interne de comptabilisation.
- Facturation, salaires et trésorerie dépendent de cette API, jamais de ses tables
  via SQL dispersé.
- Les contrôleurs valident l'entrée et appellent un cas d'usage.
- Les règles métier résident dans des services testables sans HTTP.
- Les repositories concentrent SQL et filtrage `dossier_id`.

## 3. Tables principales

### Noyau

- `utilisateurs`, `roles`, `permissions`, `utilisateur_dossiers`
- `organisations`, `dossiers`, `parametres_organisation`, `parametres_dossier`
- `schema_migrations`, `audit_events`, `documents`

### Comptabilité

- `exercices`, `periodes`, `journaux`
- `comptes`, `ecritures`, `lignes_ecriture`
- `axes`, `valeurs_axes`, `ventilations_analytiques`

### Trésorerie

- `comptes_tresorerie`, `imports_bancaires`, `lignes_bancaires`
- `rapprochements`, `rapprochement_lignes_bancaires`,
  `rapprochement_lignes_comptables`

### Tiers et documents

- `contacts`, `contact_roles`, `adresses`
- `documents_financiers`, `lignes_document`, `echeances`
- `paiements`, `allocations`

### TVA

- `regimes_tva`, `taux_tva`, `codes_tva`, `configurations_tva`
- `periodes_tva`, `decomptes_tva`, `lignes_decompte_tva`
- `corrections_tva`, `exports_tva`

### Salaires

- `employes`, `taux_salaires_annuels`, `tarifs`, `unites`
- `fiches_salaires`, `lignes_prestation`, `composants_fiche`
- `certificats_salaires`

### Pédagogie

- `modeles_exercice`, `etapes_exercice`, `regles_validation`
- `groupes_pedagogiques`, `membres_groupes`, `assignations`
- `progressions`, `tentatives`

## 4. Colonnes transversales

Toute table métier racine contient au minimum :

```text
id INTEGER PRIMARY KEY
organisation_id INTEGER NOT NULL
dossier_id INTEGER NOT NULL
cree_le TEXT NOT NULL
cree_par INTEGER
modifie_le TEXT
version INTEGER NOT NULL DEFAULT 1
```

Les tables enfants héritent l'isolation via une relation dont l'organisation et
le dossier sont contrôlés par le repository/service. Les identifiants exposés
dans les URL ne dispensent jamais de ces contrôles.

## 5. Contraintes importantes

- Unicité compte : `(dossier_id, numero)`.
- Unicité exercice : `(dossier_id, date_debut, date_fin)`.
- Numéro de facture unique seulement après émission :
  index partiel sur `(dossier_id, numero)` où `numero <> ''`.
- Doublon fournisseur : `(dossier_id, contact_id, numero_externe)`.
- Une écriture générée : clé unique `(dossier_id, source_type, source_id, action)`.
- `CHECK` des statuts et montants.
- Suppressions en cascade uniquement pour brouillons/enfants non comptabilisés.
- `ON DELETE RESTRICT` sur l'historique validé.

## 6. Transactions et concurrence

- `PRAGMA foreign_keys = ON`, `journal_mode = WAL`, `busy_timeout`.
- Transactions courtes avec `BEGIN IMMEDIATE` pour numérotation/validation.
- Verrou optimiste par colonne `version` sur les brouillons.
- En cas de conflit de version, réponse HTTP 409 et écran de comparaison/rechargement ;
  jamais de « dernier enregistrement gagne » silencieux.
- Plusieurs utilisateurs peuvent créer des écritures distinctes simultanément.
  Un verrou/lease court peut compléter le verrou optimiste sur un même brouillon,
  sans dépendre de WebSocket.
- Numérotation attribuée dans la même transaction que l'émission.
- Les opérations longues (PDF, e-mail) se font après commit ; leur résultat est
  journalisé séparément.

## 7. Interface

- Navigation latérale par modules, dossier et exercice toujours visibles.
- Formulaires avec validation serveur ; amélioration JavaScript facultative.
- Tables filtrables, pagination serveur, états vides et messages d'erreur utiles.
- Responsive dès 360 px, navigation clavier et contraste WCAG AA.
- Impression CSS pour rapports simples ; bibliothèque PDF uniquement lorsque
  la conformité du document l'exige.
- Aucun CDN, aucune police ou dépendance distante obligatoire.

## 8. Déploiement en sous-répertoire

Une même release est installable indépendamment dans `edu/`, `entreprise-1/`,
`maison-a/` ou tout autre répertoire :

- URL générées depuis `APP_BASE_URL`/base path détecté et validé ;
- aucun lien absolu supposant `/` ;
- cookie de session avec nom, chemin et préfixe propres à l'instance ;
- `APP_INSTANCE_ID` unique affiché dans le diagnostic et les sauvegardes ;
- chemins de stockage/configuration absolus ou relatifs à l'instance, jamais au CWD ;
- cron et CLI ciblent explicitement l'instance ;
- mise à jour et sauvegarde d'une instance n'affectent aucune voisine.

## 9. Commande unifiée

`bin/console` doit proposer au minimum :

```text
app:doctor
db:migrate --plan|--apply --backup
db:integrity
backup:create
backup:verify
backup:restore --target ...
test
qualify
release:package
```

Les commandes destructives exigent cible explicite, sauvegarde et confirmation.
