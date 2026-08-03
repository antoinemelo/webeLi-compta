# Administration Python

Le point d’entrée est `tools/python/compta.py`. Lancé sans argument, il affiche
un menu qui donne accès au diagnostic des extensions PHP, à la qualification,
à la création ou restauration d’une base, à la création directe d’une
photographie SQLite autonome, à la publication Git et au déploiement :

```bash
python3 tools/python/compta.py
```

Les sous-commandes restent disponibles pour l’automatisation. Toutes celles
qui modifient un état commencent par une simulation et exigent `--apply`.

## Créer ou restaurer une base

Dans le menu, l’option recommandée crée une instance immédiatement utilisable :
administrateur, organisation, dossier, exercice, période, journal, plan
comptable, modules, codes TVA et régime TVA initial. Ce régime prudent est
configuré comme **non assujetti**, sans inventer de numéro TVA, et prend effet
au premier jour de l’exercice. Il doit être remplacé dans
`Configuration > Référentiels > TVA` lorsque l’organisation est assujettie.

L’initialisation propose par défaut une seconde organisation pédagogique avec
son dossier de démonstration et les sept parcours WebeLi, sans question
supplémentaire. Le mot de passe est demandé sans être affiché ni placé dans la
ligne de commande. Il doit contenir au moins 12 caractères et ne peut pas être
une combinaison prévisible telle que `ChangeMe123!`. Cette validation a lieu
avant la création, les migrations et les sauvegardes ; en mode interactif, une
saisie refusée est simplement redemandée.

La sous-commande équivalente utilise la variable d’environnement
`COMPTA_ADMIN_PASSWORD` :

```bash
COMPTA_ADMIN_PASSWORD='<phrase-secrète-unique-de-12-caractères-ou-plus>' \
python3 tools/python/compta.py db-create \
  --path storage/database/nouvelle.sqlite \
  --initialize \
  --admin-email admin@example.test \
  --organisation "Mon organisation" \
  --dossier "Comptabilité" \
  --apply
```

`--initialize` installe toujours les parcours pédagogiques par défaut. L’option
experte `--without-pedagogy` permet seulement de les omettre volontairement.

Sans `--initialize`, `db-create` produit volontairement une base technique
vierge : schéma, référentiels globaux et catalogue des plans, mais aucun
utilisateur, organisation ou dossier.

Une base existante n’est jamais écrasée implicitement. Avec `--replace`, le
script crée d’abord une copie SQLite cohérente dans `storage/backups/`, incluant
les données encore présentes dans un éventuel WAL. La copie est contrôlée avant
le remplacement et son chemin est affiché. La sauvegarde est normalisée en mode
`DELETE` : au repos, elle est autonome et peut être copiée comme un fichier
`.sqlite` unique, sans fichier `-wal` ou `-shm` associé. Lors de sa prochaine
ouverture par COMPTA, l’application réactive automatiquement le mode WAL.

Pour figer à tout moment une base en cours d’utilisation dans un fichier
portable, y compris lorsque ses dernières écritures se trouvent encore dans le
WAL, utiliser `db-backup`. Le premier appel simule l’opération ; `--apply` crée
et contrôle réellement le fichier :

```bash
python3 tools/python/compta.py db-backup \
  --source storage/database/app.sqlite \
  --output storage/backups/compta-configuree.sqlite
python3 tools/python/compta.py db-backup \
  --source storage/database/app.sqlite \
  --output storage/backups/compta-configuree.sqlite \
  --apply
```

Sans `--output`, un nom horodaté est généré dans `storage/backups/`. La commande
refuse d’écraser un fichier existant, compare les empreintes du contenu métier
et produit une destination en mode `DELETE`, sans dépendance `-wal` ou `-shm`.
La base source n’est ni arrêtée ni modifiée.

Pour restaurer une sauvegarde, appliquer ensuite automatiquement les migrations
manquantes et contrôler l’intégrité :

```bash
python3 tools/python/compta.py db-restore \
  --source storage/backups/app-before-init-AAAAMMJJ-HHMMSS.sqlite \
  --path storage/database/app.sqlite
python3 tools/python/compta.py db-restore \
  --source storage/backups/app-before-init-AAAAMMJJ-HHMMSS.sqlite \
  --path storage/database/app.sqlite \
  --apply
```

La restauration sauvegarde elle aussi la base cible avant de la remplacer. En
cas d’échec de création, d’initialisation, de migration ou de restauration, la
base précédente est remise en place automatiquement. Les volumes et les
empreintes du contenu métier et pédagogique sont comparés avant et après
l’opération ; toute différence annule la restauration.

Pour contrôler une base ou une ancienne version sans la modifier, notamment le
nombre de parcours, versions, étapes, indices et assignations pédagogiques :

```bash
python3 tools/python/compta.py db-inspect \
  --path storage/database/app_v0.sqlite
```

Le rapport distingue la taille physique de l’espace réellement utilisé. Une
copie SQLite cohérente peut donc être sensiblement plus petite que sa source
lorsque celle-ci conserve des pages libres, sans perte de données.

## Commit et push

```bash
python3 tools/python/compta.py git-publish \
  --message="Décrire précisément la livraison"
python3 tools/python/compta.py git-publish \
  --message="Décrire précisément la livraison" \
  --apply
```

La prévisualisation énumère les fichiers. Les bases, journaux, secrets et
configurations locales sont refusés. La commande indexe ensuite le périmètre,
vérifie le diff, crée le commit et pousse `HEAD` vers `origin/main`.

Pour que les commandes Git usuelles affichent ensuite l’écart avec le dépôt
distant, configurer une fois la branche de suivi :

```bash
git branch --set-upstream-to=origin/main main
```

Après cette opération, `git status`, `git pull` et `git push` utilisent
automatiquement `origin/main`. Cette configuration appartient au dépôt local ;
elle ne crée aucun commit et ne se pousse pas.

## Déployer uniquement le delta applicatif

Copier `ops/compta.deploy.example.json` vers `ops/compta.deploy.json`, puis
renseigner les paramètres FTPS. Ce dernier fichier est ignoré par Git, car il
contient les identifiants.

```bash
python3 tools/python/compta.py deploy
python3 tools/python/compta.py deploy --apply
```

Le script lit le marqueur `storage/deployments/current.json` du site, calcule le
delta entre le commit déjà déployé et `HEAD`, puis ne transfère que les fichiers
utiles au runtime : PHP, build public, migrations, seeds, ressources et
templates. Lors d’une première installation ou d’un changement de
`composer.lock`, les dépendances PHP installées sont également livrées après
vérification de leurs versions. Les sources Vue, tests, livrables, bases, caches
et secrets sont exclus. Sans marqueur complet et récent — notamment après un
déploiement produit par une ancienne version du script — il réexpédie
automatiquement l’inventaire applicatif complet afin de réparer une installation
partielle.

Une installation applicative initiale ne crée volontairement aucune base SQLite
et ne transfère aucun secret. Le script le rappelle avant confirmation : la
base et la configuration persistantes doivent être provisionnées séparément,
sans jamais être écrasées par un déploiement de code.

Les fichiers envoyés proviennent directement des objets Git du commit, jamais
du répertoire de travail. Les suppressions distantes restent désactivées par
défaut et exigent en plus `--delete`. Chaque fichier envoyé est relu et comparé
à son contenu Git avant que le déploiement soit déclaré terminé. Le marqueur
distant, lui aussi relu après écriture, est écrit en dernier et conserve le
commit précédent, le nouveau commit, la version, l’inventaire complet avec ses
empreintes ainsi que la liste du delta. Une copie immuable est aussi écrite sous
`storage/deployments/releases/<commit>.json`.

Pour une cible montée localement, le même protocole se teste avec :

```json
{
  "transport": "local",
  "target": "/chemin/vers/la/cible"
}
```

## Installer directement un nouveau site par FTP/FTPS

Le menu interactif propose également **Installer un nouveau site depuis un
dossier par FTP/FTPS**. Il demande explicitement :

1. le répertoire local de départ ;
2. le fichier contenant la connexion FTP/FTPS ;
3. le répertoire FTP absolu d’arrivée ;
4. une confirmation après présentation du volume à envoyer.

La même opération est automatisable :

```bash
python3 tools/python/compta.py ftp-install \
  --source /chemin/vers/compta \
  --remote-root /www/nouveau-site/compta

python3 tools/python/compta.py ftp-install \
  --source /chemin/vers/compta \
  --remote-root /www/nouveau-site/compta \
  --vendor-mode auto \
  --list-files \
  --apply
```

Cette installation ne dépend pas de Git : elle inspecte directement le dossier
choisi. Elle exige une livraison complète comprenant le code PHP, les
migrations, les templates, `vendor/autoload.php` et un build Vue cohérent. Les
assets référencés par le manifeste Vite doivent tous exister.

Le répertoire Composer est recherché d’abord sous `./vendor`, puis
automatiquement sous `../vendor`. Le choix `--vendor-mode` détermine son
traitement :

- `auto` reproduit la disposition locale : `./vendor` reste propre à
  l’instance, tandis qu’un `../vendor` détecté est mutualisé dans le parent
  distant ;
- `local` transfère les dépendances dans le `vendor` de l’instance ;
- `shared` les transfère dans le `vendor` du répertoire parent ;
- `skip` n’envoie aucune dépendance et exige qu’un `./vendor` ou `../vendor`
  compatible soit déjà présent sur le serveur.

Le menu interactif présente les mêmes choix sous forme de questions « avec ou
sans vendor ». Lorsqu’un vendor mutualisé compatible existe déjà, son transfert
est automatiquement évité. Un vendor partagé utilisant d’autres versions est
protégé ; `--replace-shared-vendor` est nécessaire pour l’écraser explicitement.
Le choix peut aussi être conservé dans le fichier de connexion avec
`"vendor_mode": "shared"` ou `"vendor_mode": "skip"`.

Seuls les fichiers nécessaires à l’exécution sont envoyés. Sont notamment
exclus `frontend/`, `tests/`, `tools/`, `.git/`, `node_modules/`,
`config/local.php`, les bases SQLite, les journaux et tout `storage/`. Aucun
fichier distant n’est supprimé. Après l’envoi, chaque fichier est relu par FTP
et comparé à son empreinte SHA-256 ; le marqueur de livraison est écrit en
dernier.

Une destination contenant déjà `index.php` ou un marqueur Compta est refusée
par défaut. Une mise à jour normale doit passer par `deploy`. L’option experte
`--replace-runtime` permet uniquement de confirmer le remplacement des fichiers
applicatifs existants ; elle ne supprime toujours aucune donnée distante.

Le transfert installe le moteur d’un nouveau site, sans copier les secrets ni
les données d’une autre instance. La configuration locale de production et la
base initiale doivent ensuite être provisionnées pour ce nouveau site. Une
instance utilisant un vendor mutualisé peut être rafraîchie sans le retransférer
avec `ftp-install --replace-runtime --vendor-mode skip`. Le déploiement
incrémental `deploy` reste disponible pour les installations dont le vendor
demeure propre à chaque instance.
