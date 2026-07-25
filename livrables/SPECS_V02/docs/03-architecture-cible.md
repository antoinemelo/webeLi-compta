# Architecture cible

## Forme

Monolithe modulaire PHP 8.2+ / SQLite avec une application Vue 3 compilée lors
de la livraison. Node n'est pas requis en production. Apache/PHP sert
`public/`, les assets compilés et les endpoints JSON.

Structure recommandée :

```text
frontend/admin-vue/       Vue 3 + TypeScript + Vite, comme le CMS
public/admin/             assets compilés, jamais source de vérité
src/Core/Http/            routeur, requête/réponse, JSON, erreurs
src/Modules/<Module>/
  Http/                   contrôleurs minces et validation d'entrée
  Application/            cas d'usage et DTO simples
  Domain/                 règles/invariants quand ils existent réellement
  Infrastructure/         PDO, fichiers, PDF, parseurs
database/migrations/      001–010 immuables, nouvelles versions additives
tests/                    unitaires, intégration SQLite, contrats HTTP, E2E
```

Cette structure est une direction, pas une réorganisation massive préalable.
Chaque lot extrait seulement le code qu'il touche.

## Contrats internes

- Endpoints sous `/api/v1/`, cookie de session existant et CSRF obligatoire
  pour toute mutation.
- Réponse uniforme : `data`, `meta`, `errors`; identifiant de corrélation dans
  les erreurs et journaux.
- Entrées validées par objets/validateurs simples avant appel métier.
- Les contrôleurs ne contiennent ni SQL ni calcul comptable.
- Les services métier ne connaissent ni Vue ni les formes HTTP.
- Les listes sont paginées côté serveur avec tri et filtres en liste blanche.
- Un contrat JSON versionné et des tests empêchent les ruptures silencieuses.

## Commandes et lectures

Les mutations continuent de passer par les services actuels :
`EntryService`, facturation, paiements, TVA, paie et pédagogie. Les tableaux de
bord et rapports utilisent de nouvelles projections de lecture PDO. Une
projection ne modifie jamais le grand livre.

Le moteur conserve :

- centimes entiers, taux en ppm et quantités en millièmes ;
- transactions SQLite et idempotence ;
- écritures validées immuables et correction par contre-passation ;
- scopes organisation/dossier/exercice à chaque requête ;
- snapshots pour tout paramètre daté.

## Vue progressive

Le shell Vue apporte navigation, onglets compacts, composants de formulaire,
tables, graphiques accessibles, gestion des erreurs et changements non
enregistrés. Les routes PHP historiques restent disponibles derrière un
feature flag jusqu'à parité. Une page est basculée seulement après tests E2E et
comparaison des résultats avec l'écran historique.

## Hébergement mutualisé

- aucun worker, Redis ou processus permanent requis ;
- tâches planifiées idempotentes via cron/CLI avec verrous SQLite courts ;
- traitements lourds découpés et reprenables ;
- aucune dépendance CDN ;
- archive de livraison contenant `vendor/` compatible PHP 8.2 et les assets ;
- CSP, tailles d'upload, sauvegarde, restauration et diagnostic documentés.
