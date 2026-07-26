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
- `accounting.success.json` ;
- `managed-references.success.json` ;
- `expenses.success.json` ;
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
| GET | `/api/v1/configuration` | identité, modules, paiements et audit |
| POST | `/api/v1/configuration/identity` | identité légale et devise de base |
| POST | `/api/v1/configuration/modules` | activation d’un module du dossier |
| POST | `/api/v1/configuration/payment-terms` | nouvelle condition de paiement datée |
| POST | `/api/v1/configuration/payment-defaults` | nouveau défaut client ou fournisseur |
| GET | `/api/v1/configuration/references` | contacts, codes/taux TVA et taux salariaux du dossier |
| POST | `/api/v1/configuration/references/contacts` | création ou édition optimiste d’un contact multi-rôles |
| POST | `/api/v1/configuration/references/vat-codes` | nouveau code TVA daté |
| POST | `/api/v1/configuration/references/payroll-rates` | taux sociaux annuels en ppm |
| POST | `/api/v1/configuration/references/treasury-accounts` | création ou édition optimiste d’un compte de trésorerie |
| POST | `/api/v1/configuration/references/journals` | création ou édition optimiste d’un journal |
| POST | `/api/v1/configuration/references/exercises` | création ou changement de statut d’un exercice |
| POST | `/api/v1/configuration/references/periods` | création ou changement de statut d’une période |
| POST | `/api/v1/configuration/access` | rôles directs d’un utilisateur sur le dossier |
| GET | `/api/v1/accounting` | exercice, journal, extrait et plan issus du moteur comptable |
| POST | `/api/v1/accounting/entries` | création et éventuelle validation d’une écriture |
| POST | `/api/v1/accounting/chart/types` | libellés des types de comptes |
| POST | `/api/v1/accounting/chart/sense-rules` | préfixes de fonctionnement créditeur |
| POST | `/api/v1/accounting/chart/rubrics` | création, édition, ordre ou retrait d’une rubrique |
| POST | `/api/v1/accounting/chart/accounts` | création, édition, ordre ou désactivation d’un compte |
| POST | `/api/v1/accounting/opening` | enregistrement ou validation des soldes d’ouverture |
| GET | `/api/v1/liquidites` | dépenses, récurrences, pièces et catalogues du dossier |
| POST | `/api/v1/liquidites/depenses` | création d’une dépense en brouillon |
| POST | `/api/v1/liquidites/depenses/soumettre` | soumission à approbation |
| POST | `/api/v1/liquidites/depenses/approuver` | approbation explicite |
| POST | `/api/v1/liquidites/depenses/comptabiliser` | comptabilisation via `EntryService` |
| POST | `/api/v1/liquidites/depenses/annuler` | annulation et contre-passation si nécessaire |
| POST | `/api/v1/liquidites/recurrences` | création d’un modèle récurrent |
| POST | `/api/v1/liquidites/recurrences/pause` | suspension ou reprise optimiste |
| POST | `/api/v1/liquidites/recurrences/generer` | génération idempotente des échéances |

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

Les référentiels gérés ajoutent les permissions `facturation.manage`,
`tva.setup` ou `salaires.manage` selon le domaine. Ils appellent directement
les services métier existants. Les valeurs salariales sont transmises en ppm
entiers et les taux TVA en points de base ; Vue ne fait aucun calcul avec des
flottants. Les valeurs proposées pour 2026 proviennent de `TAUX_DEFAUT` de
Lasso et restent explicitement à vérifier auprès des organismes concernés.

La lecture comptable exige `exercise_id` et accepte `account_id` pour demander
un extrait. Les mutations utilisent uniquement le scope de session et les
services `EntryService` et `ChartOfAccountsService`. Elles exigent
respectivement `compta.edit`, `compta.setup` ou `compta.validate`. Tous les
montants transmis sont des centimes entiers.

Les dépenses utilisent le même document fournisseur, les mêmes contacts, codes
TVA, comptes, pièces jointes, paiements et allocations que Facturation. Elles
ajoutent un workflow explicite `brouillon → à approuver → approuvé →
comptabilisé`. La création et la génération récurrente ne comptabilisent
jamais. `depenses.approve` et `depenses.post` sont deux permissions distinctes.

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

La base de développement est reconstruite depuis `001_initial.sql`. Après le
gel de production, tout retour arrière de schéma se fera par restauration de la
sauvegarde SQLite créée avant migration. Voir
[`../../configuration.md`](../../configuration.md).
