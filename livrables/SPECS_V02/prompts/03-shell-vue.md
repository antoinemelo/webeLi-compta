# Lot 03 — Shell Vue progressif

Applique le prompt maître.

Objectif : introduire Vue 3 + TypeScript + Vite selon les conventions du CMS,
sans Inertia et sans exiger Node en production.

Implémente sous `frontend/admin-vue` :

- shell authentifié, router, store de contexte et client API typé ;
- navigation principale définie dans la cible fonctionnelle ;
- sous-navigation compacte, fil d'Ariane, bandeaux réel/démo/exercice ;
- composants accessibles : table, formulaire, onglets, dialogue de
  confirmation, erreurs, état vide, squelette et notification ;
- gestion 401/403/409/422, CSRF, focus et formulaires non enregistrés ;
- build vers des assets versionnés servis par PHP.

Conserve l'HTML actuel derrière un feature flag de repli. N'embarque aucun
secret de configuration dans le bundle.

Acceptation :

- build reproductible, sans CDN et sans Node côté serveur ;
- navigation clavier et vue 360 px testées ;
- rafraîchissement d'une route profonde fonctionne en sous-répertoire ;
- E2E connexion, changement de dossier, refus d'accès et déconnexion.
