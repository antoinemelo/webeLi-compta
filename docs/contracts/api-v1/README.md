# Contrat HTTP interne COMPTA API v1

Version de contrat : `compta-api-v1`.

L’API est servie sous `/api/v1` avec le cookie de session COMPTA existant. Elle
reste interne au monolithe et n’est ni une API publique ni une seconde source
de vérité métier.

## Enveloppe

Chaque réponse JSON contient exactement les trois clés de premier niveau :

```json
{
  "data": {},
  "meta": {
    "contract_version": "compta-api-v1",
    "correlation_id": "4b5d2fa95e374f2896b7eb9c4d8afb62"
  },
  "errors": []
}
```

En erreur, `data` vaut `null` et `errors` contient au moins `code`, `message`
et `correlation_id`. Une erreur de validation peut ajouter `fields`. Le même
identifiant de corrélation est renvoyé dans `X-Correlation-ID`.

Les exemples versionnés sont :

- `context.success.json` ;
- `collection.success.json` ;
- `dashboard.success.json` ;
- `configuration.success.json` ;
- `error.validation.json`.

## Routes

| Méthode | Route | Portée |
|---|---|---|
| GET | `/api/v1/context` | utilisateur, sélection, CSRF et capacités du shell |
| POST | `/api/v1/context/dossier` | sélection d’un dossier autorisé |
| GET | `/api/v1/dossiers` | dossiers visibles, paginés |
| GET | `/api/v1/navigation` | navigation calculée depuis les permissions |
| GET | `/api/v1/permissions` | permissions effectives du dossier courant |
| GET | `/api/v1/exercises` | exercices du dossier courant, paginés |
| GET | `/api/v1/references` | types, statuts et devise de base |
| GET | `/api/v1/dashboard` | projection comptable à une date et pour un exercice |
| GET | `/api/v1/configuration` | identité, modules, paiements et liens vers les référentiels |
| POST | `/api/v1/configuration/identity` | identité légale et devise de base |
| POST | `/api/v1/configuration/modules` | activation d’un module du dossier |
| POST | `/api/v1/configuration/payment-terms` | nouvelle condition de paiement datée |
| POST | `/api/v1/configuration/payment-defaults` | nouveau défaut client ou fournisseur |

Les mutations exigent `X-CSRF-Token`. Un client peut envoyer
`X-Contract-Version: compta-api-v1`; une autre version est refusée avec
`409 CONTRACT_VERSION_UNSUPPORTED`.

## Listes

Les paramètres communs sont `page`, `per_page` (maximum 100), `sort` et
`order=asc|desc`. Chaque route définit ses tris et filtres autorisés :

- dossiers : tris `id`, `name`, `organization_name`, `type`; filtres `type`,
  `search` ;
- exercices : tris `id`, `label`, `start_date`, `end_date`, `status`; filtres
  `status`, `search`.

Tout paramètre inconnu ou valeur hors liste blanche produit une erreur 422.
La pagination est renvoyée sous `meta.pagination`.

Le tableau de bord exige exactement `exercise_id` et `as_of_date=AAAA-MM-JJ`.
L’exercice doit appartenir au dossier de session et la date doit être comprise
dans ses bornes. Cette route est strictement en lecture et exige
`compta.view`.

La configuration exige `dossier.manage`. Toutes ses mutations sont limitées au
scope de session : les identifiants d’organisation ou de dossier sont refusés
dans la charge utile. Les versions optimistes protègent l’identité et les
modules contre les écrasements concurrents.

## Erreurs

- `401 AUTHENTICATION_REQUIRED` : session absente ou utilisateur inactif ;
- `403 ACCESS_FORBIDDEN` : scope ou permission refusé ;
- `403 CSRF_INVALID` : mutation sans jeton valide ;
- `404 RESOURCE_NOT_FOUND` : route ou ressource absente ;
- `409 CONTEXT_REQUIRED` : aucun dossier sélectionné ;
- `409 CONTRACT_VERSION_UNSUPPORTED` : contrat client incompatible ;
- `422 VALIDATION_FAILED` : entrée, filtre, tri ou pagination invalide.

Un identifiant d’organisation ou de dossier non autorisé renvoie un refus
générique sans nom ni métadonnée de la ressource visée.

## Retour arrière

Les routes initiales restent compatibles. Les migrations de configuration
étant additives, revenir avant leur introduction nécessite de restaurer la
sauvegarde SQLite créée avant migration. Voir
[`../../configuration.md`](../../configuration.md).
