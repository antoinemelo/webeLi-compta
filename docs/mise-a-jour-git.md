# Mise à jour Git de l’instance live

Le flux comporte toujours deux actions séparées :

1. `/erp/dev` construit et pousse une publication vers la branche `main` de
   `git@github.com:antoinemelo/webeLi-compta.git` avec `compta.py` ;
2. un administrateur de l’installation ouvre
   `/erp/main/app/organisations-dossiers`, vérifie la version disponible et
   choisit de l’installer.

Il n’existe aucune copie directe de `dev` vers l’instance live. GitHub est
l’intermédiaire et la source de vérité de chaque synchronisation.

## Publier depuis `/erp/dev`

Construire d’abord le frontend et exécuter la qualification adaptée à la
livraison, puis simuler la publication :

```bash
cd /home/amelo/Documents/DEV/WebeLi/web/erp/dev
npm --prefix frontend/admin-vue run build
php tests/run.php
python3 -m unittest tools/python/tests/test_compta.py
python3 tools/python/compta.py git-publish \
  --message="Décrire précisément la publication"
```

La simulation affiche les fichiers, le dépôt, la branche et l’incrément de
version sans modifier Git. Après contrôle :

```bash
python3 tools/python/compta.py git-publish \
  --message="Décrire précisément la publication" \
  --apply
```

Une version peut être fixée explicitement avec
`--release-version=0.7.0`. Sans cette option, le dernier segment sémantique est
incrémenté automatiquement. La commande génère `RELEASE.json` avant le commit
et le push.

## Installer depuis `/erp/main`

La carte **Maintenance de l’installation** n’est visible qu’avec la permission
`installation.admin`. Elle propose :

- **Vérifier maintenant**, qui relit `RELEASE.json` sur GitHub ;
- **Installer**, uniquement lorsqu’une version plus récente et cohérente est
  disponible et que le runtime et le stockage sont inscriptibles.

L’installation effectue dans cet ordre :

1. nouvelle lecture du manifeste pour empêcher une course entre vérification
   et installation ;
2. téléchargement HTTPS de l’archive de la branche `main` depuis GitHub ;
3. comparaison du manifeste inclus et contrôle SHA-256 de chaque fichier
   autorisé ;
4. validation préalable de toute la chaîne de migrations ;
5. verrou exclusif et passage temporaire de l’application en HTTP 503 ;
6. photographie SQLite autonome et sauvegarde des fichiers remplacés ;
7. remplacement atomique du runtime et retrait des anciens fichiers gérés ;
8. application transactionnelle des migrations et contrôle d’intégrité ;
9. retrait du mode maintenance, invalidation du cache et rechargement de
   l’interface.

Les données de `storage/`, `config/local.php` et `vendor/` sont préservées. Les
sources Vue, tests, outils Python, fichiers locaux et secrets présents dans le
dépôt ne sont jamais installés. Les archives de sécurité restent sous
`storage/updates/backup-*` pour permettre une intervention manuelle.

## Amorçage initial

Une instance créée avant l’existence de ce mécanisme ne peut évidemment pas
s’auto-installer son premier client de mise à jour. Après la première
publication Git contenant ce dispositif, déployer une seule fois ce runtime
par le parcours administratif existant (`deploy` ou une copie locale
`runtime-copy --replace` contrôlée). Cette livraison initiale doit inclure
`RELEASE.json`.

Dès que la carte de maintenance apparaît dans
`/app/organisations-dossiers`, les publications suivantes sont consommées
directement depuis GitHub sur demande et le transfert manuel n’est plus requis.

## Garde-fous d’exploitation

- La source est figée dans le code sur `antoinemelo/webeLi-compta`, branche
  `main`; une redirection vers un autre dépôt est refusée.
- Les mutations exigent session authentifiée, permission
  `installation.admin`, CSRF et confirmation de l’empreinte de publication.
- Une seule mise à jour peut s’exécuter à la fois.
- Une migration modifiée ou absente bloque l’opération avant le remplacement.
- Les migrations d’une publication sont exécutées dans une transaction SQLite
  unique ; en cas d’échec, le code précédent est restauré.
- Une modification de `composer.lock` exige que le `vendor` préservé reste
  compatible. Toute évolution des dépendances doit donc être préparée et
  validée séparément avant publication.
