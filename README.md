# Compta

Socle PHP 8.2 / SQLite du projet défini dans `SPECS/`.

## Installation locale

```bash
cp config/local.php.example config/local.php
php bin/console db:migrate --apply --backup
COMPTA_ADMIN_PASSWORD='une-longue-phrase-secrete' \
  php bin/console user:create-admin --email=admin@example.test
php -S 127.0.0.1:8080 -t public
```

En production, le webroot doit être `public/`. `storage/`, `config/`, `src/`,
`database/`, `templates/` et `SPECS/` ne doivent pas être servis par le serveur web.

## Contrôles

```bash
php bin/console app:doctor
php bin/console db:migrate --plan
php bin/console db:integrity
php bin/console test --suite=quick
php bin/console test --suite=integration
php bin/console qualify
```

Une application installée dans un sous-répertoire doit définir un
`APP_INSTANCE_ID`, un `APP_BASE_URL`, un stockage et une base propres.

`qualify` est la porte unique avant livraison : garde des migrations 001–010,
lint PHP, deux suites de tests, migration vierge avec sauvegarde, diagnostic,
intégrité SQLite et vérification du contenu de l'archive mutualisée. Voir
[`docs/qualification.md`](docs/qualification.md).

## API interne

Le shell Vue utilise le contrat JSON versionné `compta-api-v1` sous
`/api/v1`. La session, les permissions, les scopes, le CSRF et les en-têtes de
sécurité sont ceux du moteur existant. Les exemples et les listes blanches de
pagination, tri et filtres sont documentés dans
[`docs/contracts/api-v1/README.md`](docs/contracts/api-v1/README.md).

## Shell Vue

Le shell progressif est disponible sous `/app`. Il est actif par défaut hors
production ; en production, l’activer avec `APP_VUE_SHELL_ENABLED=1`.
`APP_VUE_SHELL_ENABLED=0` permet de revenir explicitement à l’interface
classique. Les bundles hachés sont construits dans `public/app/` et ne
nécessitent pas Node en production. L’interface PHP reste
disponible via `/?legacy=1`. Voir [le guide du shell Vue](docs/vue-shell.md).

Le premier écran utile expose trésorerie, chiffre d’affaires, charges,
échéancier, opérations à traiter et écritures récentes depuis une projection
SQLite strictement en lecture. Ses définitions et preuves de concordance sont
documentées dans [le guide du tableau de bord](docs/dashboard.md).

La page `/app/configuration` centralise l’identité légale, la devise, les
modules par dossier, les conditions de paiement datées et les liens vers les
référentiels existants. Une désactivation est appliquée dans la navigation et
côté serveur sans supprimer les données. Voir
[le guide de configuration](docs/configuration.md).

L’espace `/app/configuration/structures` fournit le registre paginé des
organisations, leurs identités juridiques datées et une arborescence de
dossiers. L’assistant Vue crée un dossier complet (modules, plan, exercice,
période, journal et références) dans une seule transaction, puis rafraîchit le
sélecteur sans reconnexion. L’archivage, la réactivation et la suppression
protégée des seules structures vides y sont également disponibles. Voir
[le guide Organisations et dossiers](docs/organisations-dossiers.md).
La section **Accès** distingue les rôles d’installation, hérités et directs,
prévisualise toute modification, protège le dernier administrateur et permet
de recopier explicitement la matrice directe d’un dossier frère lors de sa
création.

## Initialiser la comptabilité

Après création de l’organisation, du dossier et de l’exercice :

```bash
php bin/console compta:plan-install \
  --organisation=1 --dossier=1 --variante=personne_morale \
  --association --projets --fonds-affectes
php bin/console compta:periode-create \
  --organisation=1 --dossier=1 --exercice=1 \
  --libelle=2026 --debut=2026-01-01 --fin=2026-12-31
php bin/console compta:journal-create \
  --organisation=1 --dossier=1 --code=OD \
  --libelle="Opérations diverses" --type=general
```

Le plan fourni est le Plan comptable suisse PME VEB, version officielle du
12 août 2024 (© veb.ch, Zürich), avec variantes de forme juridique et overlay
association versionné. Voir `database/seeds/README.md`.

Le module `Compta` expose `EntryService::postGenerated()` aux futurs modules.
La clé d’idempotence, le scope organisation/dossier et l’empreinte de commande
empêchent les doubles comptabilisations.

Les parcours quotidiens reprennent la logique éprouvée du programme Python
`journal/` : espace comptable central, journalisation simple, extrait en liste
ou compte en T, grand livre, soldes initiaux et états. Les différences et les
garanties supplémentaires sont décrites dans la
[correspondance avec Journal](docs/journal-correspondance.md).

Les onglets Vue couvrent aussi le grand livre, la balance, le bilan, le compte
de résultat comparatif, le flux de trésorerie réconcilié, le décompte TVA, la
checklist de clôture et le dossier fiscal préparatoire. Les anciennes routes de
rapports ne rendent plus d’écran parallèle et redirigent vers ce parcours
unique. Voir [le guide rapports, clôture et fiscal](docs/rapports-cloture-fiscal.md).

L’onglet **Amortissements** fournit le registre des immobilisations, leur plan
linéaire journalier en centimes, les dotations idempotentes, cessions, mises au
rebut, contre-passations et la réconciliation avec le grand livre. Voir
[le guide des immobilisations](docs/immobilisations.md).

L’écran **Plan comptable et ouvertures** permet de configurer les préfixes
moins / plus, les rubriques de regroupement, les numéros/libellés/types des
comptes et les soldes initiaux. Les exceptions VEB restent explicites et une
ouverture validée demeure immuable. Voir
[`docs/plan-comptable.md`](docs/plan-comptable.md).

## Trésorerie

Le module `Tresorerie` gère les comptes banque, poste, caisse et carte associés
au grand livre. Il importe les CSV PostFinance et les messages ISO 20022
`camt.053` / `camt.054`, avec prévisualisation, conservation de la source,
détection des doublons et confirmation explicite.

Les services internes couvrent les rapprochements 1–1, 1–N et N–1, les
suggestions de comptabilisation à accepter explicitement, l’état comparatif
banque/comptabilité et les virements internes. Les lignes et soldes bancaires
confirmés sont immuables.

Les trois onglets Vue dédiés couvrent aussi le rapprochement réversible, le
lettrage N–N et les paiements sortants. Un lot pain.001 est préparé puis
téléchargé sans être déclaré transmis ; les dettes ne sont soldées qu’après
confirmation du débit par un relevé bancaire. Les coordonnées IBAN/BIC des
créanciers sont gérées dans le référentiel unique des contacts. Voir
[le guide banque, lettrage et paiements](docs/banque-lettrage-paiements.md).

L’écran Vue `/app/liquidites` gère aussi les dépenses ponctuelles et
récurrentes. Une dépense reste un document fournisseur unique : brouillon avec
justificatif, soumission, approbation, comptabilisation explicite, puis
contre-passation en cas d’annulation. La génération cron est rejouable :

```bash
php bin/console depenses:recurrences-generer \
  --organisation=1 --dossier=1 --jusqu-au=2026-12-31
```

Cette commande ne crée que des brouillons et ne paie ni ne comptabilise rien.

```bash
php bin/console tresorerie:compte-create \
  --organisation=1 --dossier=1 --compte=42 --libelle=PostFinance \
  --type=poste --iban=CH9300762011623852957 --monnaie=CHF
php bin/console tresorerie:import-preview \
  --organisation=1 --dossier=1 --tresorerie=1 --fichier=/chemin/releve.xml
php bin/console tresorerie:import-confirm \
  --organisation=1 --dossier=1 --import=1
php bin/console tresorerie:etat \
  --organisation=1 --dossier=1 --tresorerie=1 --date=2026-12-31
```

## TVA suisse

Le module `Tva` fournit les régimes datés (effective/TDFN,
convenues/reçues), les taux et codes versionnés, le calcul net/brut en centimes,
les snapshots par ligne, les paiements partiels, les décomptes traçables et les
rectificatifs immuables.

L'export eCH-0217 2.0.0 est validé avant enregistrement et reste explicitement
« non transmis » jusqu'à la confirmation manuelle de l'opérateur. Les règles et
taux ont été revérifiés auprès de l'AFC et d'eCH le 25 juillet 2026.

Voir [le guide opérateur TVA](docs/tva-operateur.md).

## Débiteurs et créanciers

Le module `Facturation` gère les contacts multi-rôles, factures clients et
fournisseurs, avoirs, justificatifs, rappels, paiements indépendants et
allocations N–N. L’émission numérote par dossier/année, la comptabilisation est
idempotente et les documents émis conservent leurs snapshots d’adresse et de
TVA.

L’espace Vue `/app/facturation` sépare ventes, achats, récurrences, contacts
360° et échéancier. Les tranches 0–30, 31–60, 61–90 et plus de 90 jours sont
calculées à une date de référence visible ; avoirs, paiements partiels et
acomptes non alloués restent réconciliables au centime. Les factures clients
peuvent être archivées en PDF avec QR-facture suisse et référence SCOR. Voir
[le guide de facturation](docs/facturation.md).

```bash
php bin/console facturation:recurrences-generer \
  --organisation=1 --dossier=1 --jusqu-au=2026-12-31
```

Cette commande rejouable crée uniquement les brouillons arrivés à échéance.

## Salaires genevois

Le module `Salaires` conserve les calculs de référence de l’OCAS en centimes et
milli-heures. Il gère des paramètres annuels explicites par dossier, les
employés, contrats horaires ou mensuels datés, variables de période, les
snapshots de fiches, la validation immuable, les écritures détaillées, les
dettes sociales, les paiements et allocations, l’import JSON simulable,
l’import contrôlé des taux annuels de l’OCAS et le certificat annuel préparé,
contrôlé puis exporté sans transmission.

Le périmètre actuel est Genève uniquement. Voir
[le guide des salaires genevois](docs/salaires-geneve.md) et la
[correspondance OCAS](docs/ocas-salaires-correspondance.md).

## Multi-entités

Les organisations sont les entités légales et peuvent conserver des attributs
juridiques datés. L’onglet **Comptabilité > Consolidation** distingue
l’agrégation interne de plusieurs dossiers d’une même organisation de la
consolidation légale de plusieurs organisations. Son assistant gère groupes,
membres, périodes, ratios entiers, mappings versionnés, réconciliation et
éliminations séparées des grands livres. Un groupe n’accorde aucun droit sur
ses membres et l’export JSON autonome qualifie et conserve toute la piste. Voir
[le guide multi-entités et consolidation](docs/multientites-consolidation.md).

## Enseignement

Le module `Pedagogie` versionne des modèles avec plan, données initiales,
consignes, étapes, indices, règles et solution protégée. Les assignations
individuelles et de groupe sont des clones isolés ; la collaboration utilise le
verrou optimiste du moteur comptable, attribue chaque contribution et retourne
un conflit HTTP 409 sans écrasement.

Voir [le guide de l’enseignement](docs/enseignement.md).

## Interface

L’interface utilise Bootstrap 5.3.8, distribué localement sous
`public/assets/vendor/bootstrap/`, et applique `SPECS/CharteGraphique.pdf` :
couleur principale `#20214e`, palette complémentaire de la charte, Raleway pour
les titres et Montserrat pour le corps de texte. Les polices sont également
hébergées localement sous `public/assets/fonts/`.

Aucun CDN n’est nécessaire à l’exécution. La licence MIT de Bootstrap et les
licences SIL Open Font License des polices sont conservées avec leurs fichiers.

Toutes les pages authentifiées conservent l’instance, l’organisation, le
dossier, l’exercice et le module visibles. Les dossiers réel, démonstration et
exercice ont des bandeaux textuels distincts. La navigation, la saisie
comptable, le réordonnancement clavier, les vues 360 px et l’impression sont
documentés dans le [guide interface et accessibilité](docs/interface-accessibilite.md).
