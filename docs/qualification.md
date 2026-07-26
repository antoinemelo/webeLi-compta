# Qualification et archive mutualisée

## Porte unique

Avant chaque livraison :

```bash
php bin/console qualify
```

La commande vérifie successivement :

1. l’empreinte de la base initiale canonique ;
2. la syntaxe de tous les fichiers PHP applicatifs ;
3. les suites `quick` puis `integration` ;
4. une installation SQLite vierge ;
5. le diagnostic et l'intégrité de cette base ;
6. les préconditions de l'archive de production.

La suite d’intégration vérifie qu’une base vide atteint directement le schéma
courant avec la seule version `001`, que le rejeu est idempotent et que toute
altération de son checksum est refusée. Les migrations additives postérieures
au gel de production devront compléter cette preuve.

Les tests ne sont pas dupliqués. `quick` contient les contrôles purs de
configuration et la parité de calcul OCAS ; `integration` contient SQLite,
HTTP et tous les modules. `all` reste la valeur par défaut.

## Environnement d'exécution

Le socle obligatoire est PHP 8.2 ou supérieur avec `PDO`, `pdo_sqlite`,
`mbstring`, `openssl`, `session`, `json` et `fileinfo`. Composer 2 est requis
pour construire une livraison, mais pas sur l'hébergement mutualisé lorsque le
répertoire `vendor/` est déjà inclus dans l'archive.

Les extensions `intl` et `gd` sont recommandées pour couvrir sans dégradation
les formats internationaux et la génération d'images. Les extensions XML
(`dom`, `xmlreader`, `xmlwriter`, `simplexml`) et `zip` sont recommandées pour
les contrôles XSD et les archives en processus PHP. Le validateur eCH existant
conserve son repli Java lorsque les extensions XML ne sont pas disponibles.
`app:doctor` rend visibles ces capacités et leurs éventuels replis.

Le shell Vue est construit avec Node 22 et npm 10 uniquement sur le poste de
développement ou d’intégration :

```bash
npm --prefix frontend/admin-vue ci
npm --prefix frontend/admin-vue run build
```

La production ne nécessite ni Node ni `node_modules` : PHP lit
`public/app/.vite/manifest.json` et sert les fichiers construits sous
`public/app/assets/`.

La suite d’intégration mesure aussi la projection du tableau de bord sur
500 écritures, 200 factures et 100 lignes bancaires. Elle refuse une réponse
supérieure à 500 ms, contrôle les plans SQLite et compare les indicateurs aux
rapports, allocations et soldes bancaires de référence.

Elle couvre également une facture EUR réglée en deux fois à des taux distincts,
les gains et pertes de change réalisés, la réévaluation latente contre-passable,
la traçabilité des taux et l’absence de régression du parcours mono-CHF.

Les données publiques BNS/OFDF sont testées avec des réponses locales
déterministes : aucune qualification ne dépend du réseau. Le test vérifie le
cache global entre deux organisations, les deux conventions mensuelles, le
taux quotidien, les taux d’intérêt négatifs et le stockage entier à échelle
fixe.

## Construire l'archive

Construire depuis un commit qualifié et un arbre Git propre. Installer les
dépendances avec la plateforme PHP fixée par `composer.json`, sans dépendances
de développement :

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php bin/console qualify
git archive --format=tar --prefix=compta/ HEAD > /tmp/compta-source.tar
```

Extraire l'archive source dans un répertoire temporaire, y recopier le
répertoire `vendor/` produit par Composer, puis créer le ZIP final. Ne jamais
inclure :

- `config/local.php` ;
- base SQLite, fichiers `-wal`/`-shm`, sauvegardes, journaux ou uploads ;
- secrets, fichiers d'environnement ou données réelles ;
- `.git/`, caches de tests ou dépendances Node de développement.

Le ZIP doit contenir `vendor/autoload.php`, le manifest, les assets Vue
construits, les assets locaux et tout le code hors webroot. Il ne contient
jamais `frontend/admin-vue/node_modules/` ni les résultats Playwright. En
production, seul `public/` est exposé par le serveur HTTP.

## Restaurer la référence

Le commit qualifié et sa sauvegarde sont les points de restauration du socle.
Le fichier `docs/baseline/migrations.sha256` protège la base initiale canonique et
`tests/fixtures/baseline-reports.json` fixe les résultats comptables minimaux.
Une restauration de base s'effectue uniquement depuis une sauvegarde validée
par `db:integrity`.
