# Administration Python

Le point d’entrée est `tools/python/compta.py`. Lancé sans argument, il affiche
un menu qui donne accès au diagnostic des extensions PHP, à la qualification,
à la création d’une base, à la publication Git et au déploiement :

```bash
python3 tools/python/compta.py
```

Les sous-commandes restent disponibles pour l’automatisation. Toutes celles
qui modifient un état commencent par une simulation et exigent `--apply`.

## Créer une base neuve

```bash
python3 tools/python/compta.py db-create \
  --path storage/database/nouvelle.sqlite
python3 tools/python/compta.py db-create \
  --path storage/database/nouvelle.sqlite \
  --apply
```

La commande applique les migrations, charge le catalogue versionné de
`database/seeds/`, puis exécute le contrôle d’intégrité. Une base existante
n’est jamais écrasée implicitement. Avec `--replace`, elle est d’abord déplacée
vers une sauvegarde horodatée.

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
