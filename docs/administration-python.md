# Administration Python

Le point d’entrée est `tools/python/compta.py`. Lancé sans argument, il affiche
un menu qui donne accès au diagnostic des extensions PHP, à la qualification,
à la création d’une base, à la publication Git et au déploiement :

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
`Comptabilité > Clôture > TVA` lorsque l’organisation est assujettie.

L’initialisation propose par défaut une seconde organisation pédagogique avec
son dossier de démonstration et les sept parcours WebeLi, sans question
supplémentaire. Le mot de passe est demandé sans être affiché ni placé dans la
ligne de commande.

La sous-commande équivalente utilise la variable d’environnement
`COMPTA_ADMIN_PASSWORD` :

```bash
COMPTA_ADMIN_PASSWORD='mot-de-passe-long' \
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
le remplacement et son chemin est affiché.

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
templates. Les sources Vue, tests, livrables, bases, caches et secrets sont
exclus.

Les fichiers envoyés proviennent directement des objets Git du commit, jamais
du répertoire de travail. Les suppressions distantes restent désactivées par
défaut et exigent en plus `--delete`. Le marqueur distant, écrit en dernier,
conserve le commit précédent, le nouveau commit, la version, les empreintes et
la liste du delta. Une copie immuable est aussi écrite sous
`storage/deployments/releases/<commit>.json`.

Pour une cible montée localement, le même protocole se teste avec :

```json
{
  "transport": "local",
  "target": "/chemin/vers/la/cible"
}
```
