# Prompt maître

Tu interviens sur le dépôt COMPTA existant. Lis d'abord tout ce dossier de
conception, puis `README.md`, les migrations, les services concernés et les
tests. Inspecte aussi les conventions Vue du CMS voisin
`/home/amelo/Documents/DEV/Ecol_WebeLi/web/mod/frontend/admin-vue`, sans copier
ses fonctions métier. Pour les charges salariales, traite
`/home/amelo/Documents/DEV/Ecol_WebeLi/web/lasso` comme source de reprise.

## Objectif intangible

Faire évoluer COMPTA par petits incréments. Conserver son moteur, ses données,
ses résultats et ses comportements éprouvés. Adopter Vue et les bons principes
de Gäld, pas sa pile Laravel/PostgreSQL/Redis.

## Garde-fous

- PHP 8.2+, PDO SQLite et hébergement mutualisé restent obligatoires.
- Ne modifie jamais les migrations 001 à 010 ni une migration déjà appliquée.
- Sauvegarde vérifiée avant migration ; intégrité et clés étrangères après.
- Ne recrée pas la base, ne vide aucune table et ne renumérote aucun identifiant.
- Tous les montants sont des entiers en unité mineure ; aucun `float`.
- Toute écriture validée est équilibrée, immuable et corrigée par
  contre-passation.
- Toute mutation est transactionnelle, scopée et idempotente quand rejouable.
- Une transaction bancaire ou un document n'est jamais le grand livre.
- Vue appelle une API JSON interne versionnée ; aucun SQL dans les contrôleurs.
- Session, RBAC, CSRF et audit existants s'appliquent aux routes JSON.
- Pas de CDN, worker, Redis, Docker ou service externe obligatoire.
- Ne copie aucun code Gäld AGPL sans décision explicite.
- Préserve les changements utilisateur et produis des commits/lots ciblés.

## Méthode exigée

1. Établis l'état initial et les fichiers touchés.
2. Écris les tests qui fixent le comportement à conserver.
3. Implémente le plus petit changement complet.
4. Exécute lint, tests ciblés, suite COMPTA, migration sur copie et build Vue.
5. Rapporte les preuves, limites et procédure de retour arrière.

Si une décision métier manque, n'invente pas une règle légale. Rends-la
configurable et marque-la « à valider ». Ne déclare pas terminé si une
acceptation n'est pas prouvée.
