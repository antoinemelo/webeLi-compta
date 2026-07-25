# Shell Vue progressif

Le shell est servi sous `/app` et ses routes profondes. Il utilise Vue 3,
TypeScript, Pinia et Vue Router ; Vite n’intervient qu’au build. Toutes les
données proviennent de l’API interne `/api/v1`.

## Activation

La valeur par défaut reste prudente :

```text
APP_VUE_SHELL_ENABLED=0
```

Définir `APP_VUE_SHELL_ENABLED=1` active la redirection de `/` vers `/app`
après connexion. L’interface PHP historique reste accessible avec
`/?legacy=1` et par ses routes métier. Désactiver le flag rend `/app`
indisponible et restaure immédiatement l’accueil PHP, sans changement de base.

## Build et livraison

```bash
npm --prefix frontend/admin-vue ci
npm --prefix frontend/admin-vue run build
```

Le build déterministe écrit les bundles hachés et le manifest sous
`public/app/`. Ces fichiers sont livrés avec PHP. Aucun CDN, secret, variable
d’environnement privée ou runtime Node n’est embarqué.

## Accessibilité et sécurité

- lien d’évitement, focus visible, titres et régions nommées ;
- navigation mobile utilisable à 360 px et tableaux défilables ;
- réduction des animations selon `prefers-reduced-motion` ;
- erreurs annoncées et focalisées, notifications `aria-live` ;
- boîte de confirmation native pour la déconnexion ;
- alerte avant abandon de formulaires marqués non enregistrés ;
- bandeau textuel permanent pour les dossiers réel, démonstration et exercice.

Session, CSRF, permissions, scopes et corrélation restent appliqués par PHP.
Une route Vue masquée par la navigation contrôle également la permission avant
d’afficher son espace.

## Test navigateur

```bash
npm --prefix frontend/admin-vue run test:e2e
```

La recette crée une base SQLite temporaire sous `test-results/`, lance PHP dans
le sous-répertoire `/e2e`, puis vérifie connexion, changement de dossier,
refus UI/API, route profonde, navigation clavier, largeur 360 px et
déconnexion.
