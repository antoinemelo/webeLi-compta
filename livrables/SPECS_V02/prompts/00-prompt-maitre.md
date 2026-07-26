# Prompt maître

Tu interviens sur le dépôt COMPTA existant. Lis d'abord tout ce dossier de
conception, puis `README.md`, les migrations, les services concernés et les
tests. Inspecte aussi les conventions Vue du CMS voisin
`/home/amelo/Documents/DEV/Ecol_WebeLi/web/mod/frontend/admin-vue`, sans copier
ses fonctions métier. Pour les charges salariales, traite
`/home/amelo/Documents/DEV/Ecol_WebeLi/web/lasso` comme source de reprise.

## Objectif intangible

Conserver les règles, résultats et comportements éprouvés du moteur COMPTA,
mais faire converger toute l'application vers une architecture unique :
services PHP, API JSON interne, Vue et SQLite. Le projet est encore en
développement : le schéma et les données de développement peuvent être
reconstruits lorsque cela supprime une dette ou une double source. Adopter les
bons principes de Gäld, pas sa pile Laravel/PostgreSQL/Redis.

## Garde-fous

- PHP 8.2+, PDO SQLite et hébergement mutualisé restent obligatoires.
- `001_initial.sql` est la base canonique de développement. Tant que le schéma
  n'est pas gelé pour la production, préfère une reconstruction propre à une
  chaîne de migrations héritée artificiellement.
- Après gel de production, toute évolution devient une migration additive et
  toute base réelle est sauvegardée et contrôlée avant application.
- Une reconstruction de développement est explicite, précédée d'une sauvegarde
  de confort et suivie des contrôles d'intégrité.
- Tous les montants sont des entiers en unité mineure ; aucun `float`.
- Toute écriture validée est équilibrée, immuable et corrigée par
  contre-passation.
- Toute mutation est transactionnelle, scopée et idempotente quand rejouable.
- Une transaction bancaire ou un document n'est jamais le grand livre.
- Vue est l'interface applicative unique. Elle appelle une API JSON interne
  versionnée ; aucun SQL ni règle comptable dans les contrôleurs.
- Les services PHP existants sont l'unique moteur métier : aucun traitement
  parallèle dans Vue, les contrôleurs ou d'anciens gabarits PHP.
- Session, RBAC, CSRF et audit existants s'appliquent aux routes JSON.
- Pas de CDN, worker, Redis, Docker ou service externe obligatoire.
- Ne copie aucun code Gäld AGPL sans décision explicite.
- Préserve les changements utilisateur et produis des commits/lots ciblés.

## Méthode exigée

1. Établis l'état initial et les fichiers touchés.
2. Écris les tests qui fixent le comportement à conserver.
3. Implémente un parcours vertical complet et retire les doublons qu'il remplace.
4. Exécute lint, tests ciblés, suite COMPTA, installation vierge et build Vue.
5. Rapporte les preuves, limites et procédure de retour arrière.

Si une décision métier manque, n'invente pas une règle légale. Rends-la
configurable et marque-la « à valider ». Ne déclare pas terminé si une
acceptation n'est pas prouvée.
