# Interface Vue

Le shell est servi sous `/app` et ses routes profondes. Il utilise Vue 3,
TypeScript, Pinia et Vue Router ; Vite n’intervient qu’au build. Toutes les
données proviennent de l’API interne `/api/v1`.

## Accès

Après connexion, `/` redirige toujours vers `/app`. Les anciennes adresses
métier encore référencées redirigent vers leur écran Vue correspondant.

La connexion se déroule en deux écrans : adresse e-mail, puis mot de passe.
Cette séparation ne révèle pas l’existence d’un compte. Lorsqu’un second
facteur est actif, le mot de passe est suivi du code TOTP, du code envoyé par
e-mail ou d’un code de récupération. Le menu personnel donne ensuite accès à
**Sécurité du compte** et au changement de mot de passe.

## Navigation

Sur ordinateur et tablette, les modules sont alignés dans l’en-tête et leurs
sous-menus s’ouvrent au survol, au focus ou au clic. Le contexte
organisation/dossier et la configuration restent regroupés dans l’action
**Contexte de travail**. Sur mobile, le menu burger contient seulement les
modules principaux.

La recherche globale utilise `/terme` pour les destinations commençant par le
terme et `terme` pour les destinations ou panneaux qui le contiennent. Les
barres d’onglets peuvent recevoir une action contextuelle à droite : export de
la vue en Facturation, exercice consulté en Comptabilité et année en Salaires.

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
- boîte de confirmation applicative pour la déconnexion ;
- alerte avant abandon de formulaires marqués non enregistrés ;
- contexte organisation, dossier, exercice et devise toujours visible dans
  l’en-tête.

Les messages métier sont rendus par la région de notifications temporaire,
sans multiplier les alertes persistantes dans les panneaux. Les actions de
ligne nombreuses sont regroupées sous un bouton vertical « ⋮ » nommé pour
l’objet concerné.

## Configuration initiale

Un dossier réel non terminé affiche un guide en bas à gauche. Le compteur suit
la position dans le parcours (`étape/total`), même lorsqu’une étape facultative
n’est pas renseignée. L’icône « — » réduit le guide en bouton compact sans
modifier son état. **Annuler** enregistre au contraire l’abandon pour ce
dossier ; **Contexte de travail > Reprendre la configuration initiale**
n’apparaît que dans ce cas. La dernière étape propose une action unique
**Terminer et ouvrir la comptabilité**.

Session, CSRF, permissions, scopes et corrélation restent appliqués par PHP.
Une route Vue masquée par la navigation contrôle également la permission avant
d’afficher son espace.

## Test navigateur

```bash
npm --prefix frontend/admin-vue run test:e2e
```

La recette crée une base SQLite temporaire sous `test-results/`, lance PHP dans
le sous-répertoire `/e2e`, puis vérifie notamment la connexion en deux étapes,
la récupération du mot de passe, la configuration initiale, le changement de
dossier, les refus UI/API, les routes profondes, la recherche, la navigation
clavier, la largeur 360 px et la déconnexion.
