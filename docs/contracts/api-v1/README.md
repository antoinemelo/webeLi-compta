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
- `security.success.json` ;
- `configuration.success.json` ;
- `setup-guide.success.json` ;
- `accounting.success.json` ;
- `managed-references.success.json` ;
- `expenses.success.json` ;
- `treasury.success.json` ;
- `billing.success.json` ;
- `payroll.success.json` ;
- `pedagogy.success.json` ;
- `organisations.success.json` ;
- `dossiers.success.json` ;
- `structure-access.success.json` ;
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
| GET | `/api/v1/security` | mode de connexion et disponibilité des seconds facteurs du compte |
| POST | `/api/v1/security/totp/prepare` | préparation TOTP après revalidation du mot de passe |
| POST | `/api/v1/security/totp/confirm` | confirmation TOTP et émission unique des codes de récupération |
| POST | `/api/v1/security/email/prepare` | envoi du code d’activation après revalidation du mot de passe |
| POST | `/api/v1/security/email/confirm` | confirmation du second facteur par e-mail |
| POST | `/api/v1/security/disable` | retour au mot de passe seul et révocation des sessions |
| POST | `/api/v1/security/password` | changement de phrase secrète et révocation des sessions |
| GET | `/api/v1/configuration` | identité, modules, paiements et audit |
| GET | `/api/v1/configuration/setup-guide` | état métier ordonné du parcours initial du dossier |
| POST | `/api/v1/configuration/setup-guide/confirm` | confirmation contrôlée d’une étape prête |
| POST | `/api/v1/configuration/setup-guide/status` | annulation persistante ou reprise explicite du parcours |
| GET | `/api/v1/structures/organisations` | registre paginé limité aux organisations autorisées |
| GET | `/api/v1/structures/organisations/detail` | organisation, historique juridique et dépendances |
| POST | `/api/v1/structures/organisations` | création par `installation.admin`, sans rôle implicite |
| POST | `/api/v1/structures/organisations/update` | modification optimiste du nom usuel |
| POST | `/api/v1/structures/organisations/legal-identities` | nouvelle identité datée et sourcée ; `expected_legal_identity_id` protège uniquement l’historique juridique |
| POST | `/api/v1/structures/organisations/archive` | archivage après les dossiers actifs |
| POST | `/api/v1/structures/organisations/reactivate` | réactivation sans attribution de droit |
| POST | `/api/v1/structures/organisations/delete` | suppression par `installation.admin` si aucune dépendance |
| GET | `/api/v1/structures/dossiers` | dossiers actifs et archivés d’une organisation administrée |
| GET | `/api/v1/structures/dossiers/detail` | dossier, résumé d’initialisation et dépendances |
| POST | `/api/v1/structures/dossiers` | création et initialisation atomiques par le gestionnaire de l’organisation |
| POST | `/api/v1/structures/dossiers/update` | nom et paramètres encore mutables, avec version optimiste |
| POST | `/api/v1/structures/dossiers/archive` | archivage sans suppression de l’historique |
| POST | `/api/v1/structures/dossiers/reactivate` | réactivation si l’organisation est active |
| POST | `/api/v1/structures/dossiers/delete` | suppression d’un dossier sans donnée métier |
| GET | `/api/v1/structures/users/export` | export administrateur de `utilisateurs.csv`, sans mot de passe |
| GET | `/api/v1/structures/access/export` | export administrateur de `roles_acces.csv` |
| POST | `/api/v1/structures/access/csv-preview` | validation coordonnée des deux CSV et aperçu signé |
| POST | `/api/v1/structures/access/csv-import` | import atomique confirmé des utilisateurs et affectations |
| GET | `/api/v1/structures/access` | matrice versionnée et rôles effectifs par source |
| POST | `/api/v1/structures/access/preview` | aperçu signé des permissions avant/après |
| POST | `/api/v1/structures/access/apply` | confirmation optimiste et auditée de l’aperçu |
| POST | `/api/v1/structures/access/copy-preview` | aperçu des rôles directs à recopier depuis un dossier frère |
| POST | `/api/v1/configuration/identity` | identité légale et devise de base |
| POST | `/api/v1/configuration/modules` | activation d’un module du dossier |
| POST | `/api/v1/configuration/payment-terms` | nouvelle condition de paiement datée |
| POST | `/api/v1/configuration/payment-defaults` | nouveau défaut client ou fournisseur |
| POST | `/api/v1/configuration/payment-accounting` | choix du premier lettrage ou du lettrage complet comme déclencheur comptable |
| POST | `/api/v1/configuration/audit/clear` | effacement explicite du journal d’audit du dossier |
| GET | `/api/v1/configuration/references` | contacts, codes/taux TVA et taux salariaux du dossier |
| POST | `/api/v1/configuration/references/contacts` | création ou édition optimiste d’un contact multi-rôles |
| POST | `/api/v1/configuration/references/contacts/delete` | suppression optimiste si aucun document, paiement ou élément salarial n’est lié |
| POST | `/api/v1/configuration/references/contacts/restore` | réactivation optimiste d’un contact archivé |
| POST | `/api/v1/configuration/references/currencies` | activation ou désactivation d’une devise autorisée |
| POST | `/api/v1/configuration/references/exchange-rates` | création ou correction d’un taux de change daté |
| POST | `/api/v1/configuration/references/exchange-mapping` | correspondance des comptes pour les écarts de change |
| POST | `/api/v1/configuration/references/vat-regimes` | nouveau régime TVA daté du dossier |
| POST | `/api/v1/configuration/references/vat-codes` | nouveau code TVA daté |
| POST | `/api/v1/configuration/references/vat-codes/delete` | suppression d’un code TVA sans dépendance |
| POST | `/api/v1/configuration/references/vat/clear` | effacement coordonné de toute la configuration TVA |
| POST | `/api/v1/configuration/references/payroll-rates` | taux sociaux annuels en ppm |
| POST | `/api/v1/configuration/payroll/employer` | heures de référence ; identité employeur reprise de l’entité |
| POST | `/api/v1/configuration/payroll/mapping` | mapping des comptes de salaires |
| POST | `/api/v1/configuration/references/treasury-accounts` | création ou édition optimiste d’un compte de trésorerie |
| POST | `/api/v1/configuration/references/treasury-accounts/remove` | archivage ou suppression d’un compte de trésorerie selon ses dépendances |
| POST | `/api/v1/configuration/references/journals` | création ou édition optimiste d’un journal |
| POST | `/api/v1/configuration/references/exercises` | création ou changement de statut d’un exercice |
| POST | `/api/v1/configuration/references/periods` | création ou changement de statut d’une période |
| GET | `/api/v1/salaires` | employés, contrats, fiches, dettes, paiements et récapitulatifs annuels |
| POST | `/api/v1/salaires/employeur` | paramètres employeur utilisés par les calculs salariaux |
| POST | `/api/v1/salaires/mapping` | comptes de comptabilisation des salaires |
| POST | `/api/v1/salaires/employes` | création ou modification optimiste d’un employé genevois |
| POST | `/api/v1/salaires/employes/supprimer` | suppression d’un employé sans historique salarial |
| POST | `/api/v1/salaires/contrats` | création ou modification optimiste d’un contrat daté |
| POST | `/api/v1/salaires/contrats/supprimer` | suppression d’un contrat jamais utilisé |
| POST | `/api/v1/salaires/fiches` | création ou recalcul optimiste d’un brouillon |
| POST | `/api/v1/salaires/fiches/brouillon/supprimer` | suppression d’un brouillon non validé |
| POST | `/api/v1/salaires/fiches/valider` | validation et gel des snapshots |
| POST | `/api/v1/salaires/fiches/comptabiliser` | écriture salariale dans le grand livre |
| POST | `/api/v1/salaires/fiches/annuler` | correction par contre-passation |
| POST | `/api/v1/salaires/paiements` | saisie d’un paiement salarial indépendant de la dette |
| POST | `/api/v1/salaires/allocations` | allocation d’un paiement à une ou plusieurs dettes salariales |
| POST | `/api/v1/salaires/paiements/comptabiliser` | comptabilisation idempotente du paiement salarial |
| POST | `/api/v1/salaires/taux-ocas/previsualiser` | lecture sans écriture de `taux_par_annee` |
| POST | `/api/v1/salaires/taux-ocas/confirmer` | import contrôlé, audité et idempotent |
| POST | `/api/v1/salaires/certificats/preparer` | préparation d’un certificat interne |
| POST | `/api/v1/salaires/certificats/controler` | contrôle opérateur avant export |
| GET | `/api/v1/salaires/certificats/exporter` | export nominatif audité, non transmis |
| GET | `/api/v1/accounting` | exercice, journal, extrait et plan issus du moteur comptable |
| POST | `/api/v1/accounting/entries` | création et éventuelle validation d’une écriture |
| GET | `/api/v1/accounting/entries/draft` | lecture détaillée d’une écriture encore en brouillon |
| POST | `/api/v1/accounting/entries/delete` | suppression contrôlée d’une écriture en brouillon |
| POST | `/api/v1/accounting/exchange-revaluations` | comptabilisation d’une réévaluation de change |
| POST | `/api/v1/accounting/exchange-revaluations/reverse` | contre-passation d’une réévaluation de change |
| POST | `/api/v1/accounting/chart/types` | libellés des types de comptes |
| POST | `/api/v1/accounting/chart/sense-rules` | préfixes de fonctionnement créditeur |
| POST | `/api/v1/accounting/chart/rubrics` | création, édition, ordre ou retrait d’une rubrique |
| POST | `/api/v1/accounting/chart/accounts` | création, édition, ordre ou désactivation d’un compte |
| GET | `/api/v1/accounting/chart/export` | export CSV du plan comptable courant |
| POST | `/api/v1/accounting/chart/import/preview` | contrôle d’un plan comptable CSV sans mutation |
| POST | `/api/v1/accounting/chart/import` | import confirmé du plan comptable |
| POST | `/api/v1/accounting/chart/reset/preview` | aperçu des effets d’une réinitialisation du plan |
| POST | `/api/v1/accounting/chart/reset` | réinitialisation confirmée du plan comptable |
| POST | `/api/v1/accounting/opening` | enregistrement ou validation des soldes d’ouverture |
| GET | `/api/v1/accounting/opening/export` | export CSV propre aux soldes d’ouverture |
| POST | `/api/v1/accounting/opening/import/preview` | contrôle d’un CSV d’ouverture sans mutation |
| POST | `/api/v1/accounting/opening/import` | remplacement atomique du brouillon d’ouverture |
| GET | `/api/v1/accounting/journal/details` | toutes les lignes détaillées du journal de l’exercice |
| GET | `/api/v1/accounting/journal/export` | export CSV détaillé et réimportable du journal |
| POST | `/api/v1/accounting/journal/import/preview` | contrôle d’écritures CSV groupées et équilibrées |
| POST | `/api/v1/accounting/journal/import` | import atomique et anti-rejeu des écritures du journal |
| GET | `/api/v1/accounting/reports/export` | export paramétré CSV ou PDF (`format=csv|pdf`) des états financiers |
| POST | `/api/v1/accounting/vat/periods` | création d’une période TVA |
| POST | `/api/v1/accounting/vat/statements/prepare` | préparation ou rectification depuis les sources |
| POST | `/api/v1/accounting/vat/statements/control` | contrôle et rapprochement avec le grand livre TVA |
| POST | `/api/v1/accounting/vat/statements/export` | génération et validation eCH-0217, sans transmission |
| POST | `/api/v1/accounting/vat/statements/declare` | confirmation manuelle de la déclaration |
| GET | `/api/v1/accounting/vat/exports/download` | téléchargement XML avec empreinte |
| POST | `/api/v1/accounting/closing/controls` | contrôle manuel versionné de la checklist |
| POST | `/api/v1/accounting/closing/periods` | fermeture ou réouverture contrôlée d’une période |
| POST | `/api/v1/accounting/tax-file/adjustments` | ajustement fiscal préparatoire idempotent |
| POST | `/api/v1/accounting/tax-file/adjustments/status` | validation ou mise à l’écart d’un ajustement |
| POST | `/api/v1/accounting/archives` | archive financière immuable et vérifiable |
| POST | `/api/v1/accounting/archives/delete` | suppression d’une archive en double si l’exercice reste ouvert |
| GET | `/api/v1/accounting/archives/download` | téléchargement JSON avec empreinte |
| GET | `/api/v1/accounting/assets` | registre, échéancier et réconciliation des immobilisations |
| POST | `/api/v1/accounting/assets/categories` | création ou modification d’une catégorie et de ses comptes |
| POST | `/api/v1/accounting/assets/records` | création ou correction d’une fiche avant comptabilisation |
| POST | `/api/v1/accounting/assets/depreciations` | dotations d’une période groupée, atomiques et idempotentes via le grand livre |
| POST | `/api/v1/accounting/assets/depreciations/reverse` | contre-passation atomique des dotations d’une période groupée |
| POST | `/api/v1/accounting/assets/disposals` | cession ou mise au rebut comptabilisée |
| POST | `/api/v1/accounting/assets/disposals/reverse` | contre-passation d’une sortie |
| GET | `/api/v1/consolidation` | groupes autorisés, candidats visibles, modes, membres, mappings, balance, réconciliation et éliminations |
| GET | `/api/v1/consolidation/export` | piste JSON autonome qualifiée comme agrégation interne ou consolidation légale |
| POST | `/api/v1/consolidation/groups` | création d’un brouillon avec mode obligatoire, sans attribution implicite de droits |
| POST | `/api/v1/consolidation/groups/update` | modification optimiste du libellé et des paramètres non figés |
| POST | `/api/v1/consolidation/groups/activate` | activation après prévisualisation et contrôles de composition/mapping |
| POST | `/api/v1/consolidation/groups/archive` | archivage versionné du groupe |
| POST | `/api/v1/consolidation/groups/reactivate` | réactivation versionnée après contrôle de composition |
| POST | `/api/v1/consolidation/groups/members` | ajout daté d’un membre après contrôle de son droit propre |
| POST | `/api/v1/consolidation/groups/members/remove` | suppression avant données, sinon sortie datée |
| POST | `/api/v1/consolidation/legal-attributes` | nouvelle version datée et sourcée de l’identité juridique |
| POST | `/api/v1/consolidation/periods` | période et ratios de conversion figés par membre |
| POST | `/api/v1/consolidation/periods/close` | clôture après mapping exhaustif des comptes mouvementés |
| POST | `/api/v1/consolidation/mappings` | correspondance compte source vers compte consolidé |
| POST | `/api/v1/consolidation/mappings/disable` | désactivation ou nouvelle version datée d’un mapping figé |
| POST | `/api/v1/consolidation/intercompany-pairs` | paire de comptes réciproques à réconcilier |
| POST | `/api/v1/consolidation/intercompany-pairs/disable` | désactivation ou nouvelle version datée d’une paire figée |
| POST | `/api/v1/consolidation/eliminations` | écriture équilibrée, justifiée et immuable hors grand livre |
| GET | `/api/v1/liquidites` | dépenses, récurrences, pièces et catalogues du dossier |
| POST | `/api/v1/liquidites/depenses` | création d’une dépense en brouillon |
| POST | `/api/v1/liquidites/depenses/modifier` | modification optimiste d’un brouillon et de ses lignes |
| POST | `/api/v1/liquidites/depenses/soumettre` | soumission à approbation |
| POST | `/api/v1/liquidites/depenses/approuver` | approbation explicite |
| POST | `/api/v1/liquidites/depenses/refuser` | refus explicite d’une dépense soumise |
| POST | `/api/v1/liquidites/depenses/comptabiliser` | comptabilisation via `EntryService` |
| POST | `/api/v1/liquidites/depenses/annuler` | annulation d’un brouillon ou d’une dépense approuvée non comptabilisée |
| POST | `/api/v1/liquidites/recurrences` | création d’un modèle récurrent |
| POST | `/api/v1/liquidites/recurrences/pause` | suspension ou reprise optimiste |
| POST | `/api/v1/liquidites/recurrences/generer` | génération idempotente des échéances |
| GET | `/api/v1/liquidites/banque` | espace banque, lettrage et paiements sortants |
| GET | `/api/v1/liquidites/taux-change` | historique BNS et taux quotidien OFDF pour les monnaies actives |
| GET | `/api/v1/liquidites/taux-interet` | taux du marché monétaire BNS pour les monnaies actives |
| POST | `/api/v1/liquidites/banque/imports/previsualiser` | analyse sans comptabilisation d’un CAMT ou PostFinance |
| POST | `/api/v1/liquidites/banque/imports/confirmer` | import audité avec source et empreinte archivées |
| POST | `/api/v1/liquidites/banque/rapprochements` | rapprochement explicite 1–1, 1–N ou N–1 |
| POST | `/api/v1/liquidites/banque/rapprochements/annuler` | annulation auditée et libération des lignes |
| POST | `/api/v1/liquidites/banque/suggestions` | proposition d’écriture sans validation silencieuse |
| POST | `/api/v1/liquidites/banque/suggestions/accepter` | acceptation explicite d’une suggestion |
| POST | `/api/v1/liquidites/lettrage/paiements` | création d’un paiement indépendant des factures |
| POST | `/api/v1/liquidites/lettrage/allocations` | allocation partielle ou multiple d’un paiement |
| POST | `/api/v1/liquidites/lettrage/allocations/annuler` | délettrage tant que la période reste ouverte |
| POST | `/api/v1/liquidites/paiements/lots` | préparation idempotente d’un lot sortant |
| POST | `/api/v1/liquidites/paiements/lots/exporter` | génération et téléchargement pain.001, sans transmission |
| POST | `/api/v1/liquidites/paiements/lots/confirmer` | confirmation, comptabilisation et lettrage depuis le relevé |
| GET | `/api/v1/facturation` | ventes, achats, documents commerciaux, contacts 360°, échéancier et récurrences |
| GET | `/api/v1/facturation/export` | export CSV filtré avec date de référence |
| POST | `/api/v1/facturation/documents` | création d’un document en brouillon |
| POST | `/api/v1/facturation/documents/modifier` | modification optimiste d’un brouillon et de ses lignes |
| POST | `/api/v1/facturation/documents/emettre` | émission et numérotation idempotente |
| POST | `/api/v1/facturation/documents/comptabiliser` | comptabilisation via `EntryService` |
| POST | `/api/v1/facturation/documents/avoirs` | création d’un brouillon d’avoir |
| POST | `/api/v1/facturation/documents/extourner` | contre-passation du solde ouvert, avec conservation des lettrages actifs |
| POST | `/api/v1/facturation/documents/pdf` | archive et téléchargement PDF d’une facture client ou fournisseur, avec QR client lorsque possible |
| POST | `/api/v1/facturation/contacts` | création idempotente dans le registre unique |
| POST | `/api/v1/facturation/contacts/modifier` | édition optimiste du contact |
| POST | `/api/v1/facturation/contacts/supprimer` | suppression d’un contact inutilisé ou archivage avec historique |
| POST | `/api/v1/facturation/contacts/reactiver` | réactivation optimiste d’un contact archivé |
| POST | `/api/v1/facturation/commerciaux` | création ou édition d’une offre, demande, réponse ou commande brouillon |
| POST | `/api/v1/facturation/commerciaux/statut` | envoi, réception, acceptation, refus ou archivage |
| POST | `/api/v1/facturation/commerciaux/convertir` | conversion reliée vers réponse, commande ou facture brouillon |
| POST | `/api/v1/facturation/commerciaux/pdf` | téléchargement PDF d’une offre, demande ou commande sans effet comptable |
| POST | `/api/v1/facturation/recurrences` | nouveau modèle client ou fournisseur |
| POST | `/api/v1/facturation/recurrences/pause` | suspension ou reprise optimiste |
| POST | `/api/v1/facturation/recurrences/generer` | génération idempotente de brouillons |
| POST | `/api/v1/facturation/rappels` | traçage d’un rappel |
| POST | `/api/v1/facturation/paiements` | saisie d’un paiement indépendant |
| POST | `/api/v1/facturation/paiements/pdf` | téléchargement du justificatif de paiement et de ses allocations |
| POST | `/api/v1/facturation/paiements/comptabiliser` | comptabilisation idempotente d’un paiement lettré |
| POST | `/api/v1/facturation/allocations` | allocation N–N d’un paiement |
| POST | `/api/v1/facturation/allocations/avoirs` | allocation d’un avoir |
| POST | `/api/v1/facturation/allocations/annuler` | délettrage audité |
| GET | `/api/v1/pedagogie` | catalogue, copies, étapes, progression et capacités |
| POST | `/api/v1/pedagogie/catalogue/installer` | installation idempotente des sept compétences ciblées |
| POST | `/api/v1/pedagogie/modeles` | publication d’un scénario versionné |
| POST | `/api/v1/pedagogie/tentatives` | validation d’une réponse par le moteur comptable |
| POST | `/api/v1/pedagogie/indices` | révélation tracée de l’indice suivant |
| GET | `/api/v1/pedagogie/correction` | correction protégée si sa règle d’ouverture est satisfaite |
| POST | `/api/v1/pedagogie/correction/autoriser` | autorisation explicite du formateur |
| POST | `/api/v1/pedagogie/reinitialiser` | remplacement atomique d’une copie d’exercice |
| POST | `/api/v1/pedagogie/groupes` | création d’un groupe |
| POST | `/api/v1/pedagogie/groupes/membres` | ajout d’un apprenant au groupe |
| POST | `/api/v1/pedagogie/assignations` | assignation individuelle ou collective d’une copie isolée |
| GET | `/api/v1/pedagogie/export` | export CSV du suivi formateur |

Les mutations exigent `X-CSRF-Token`. Un client peut envoyer
`X-Contract-Version: compta-api-v1`; une autre version est refusée avec
`409 CONTRACT_VERSION_UNSUPPORTED`.

Le registre des organisations ne dépend pas du dossier de session.
`installation.admin` voit tout et reste seul autorisé à créer ou supprimer.
`organisation.manage` limite la liste et chaque mutation aux organisations
explicitement attribuées. Un identifiant hors périmètre est renvoyé en 404.
L’archivage avec dossier actif, une version obsolète ou une suppression avec
dépendances renvoient un conflit 409 typé. Le détail expose les dépendances de
suppression avant confirmation.

Les routes de structure des dossiers ne dépendent pas du dossier de session.
`installation.admin` et `organisation.manage` peuvent créer un dossier dans
leur organisation. `dossier.manage` autorise seulement la gestion du dossier
déjà attribué, jamais la création d’un frère. L’assistant répond uniquement
après l’installation transactionnelle du plan, de l’exercice, de la période,
du journal et des références. L’archivage du dossier courant efface sa
sélection de session ; `/api/v1/dossiers` reflète aussitôt le nouvel état.

Les routes `/api/v1/structures/access` sont indépendantes du contexte courant.
Elles sont limitées à `installation.admin` ou à `organisation.manage` sur
l’organisation demandée. La lecture distingue les rôles d’installation,
d’organisation et de dossier. Une mutation exige la version SHA-256 de la
matrice, puis le `confirmation_token` rendu par l’aperçu. Le retrait du dernier
administrateur d’une structure active renvoie
`409 STRUCTURE_ACCESS_LAST_ADMIN`, sauf transfert explicite à un successeur.
La copie lors de la création d’un dossier porte uniquement sur les lignes
directes de `utilisateur_roles_dossier` et exige le `preview_hash` courant.

L’import global est réservé à `installation.admin` et utilise deux fichiers :
`utilisateurs.csv` pour l’identité, l’état actif et le mot de passe d’import,
puis `roles_acces.csv` pour les affectations d’installation, d’organisation et
de dossier. Aucun mot de passe ni hachage n’est exporté. Un mot de passe vide
conserve celui d’un utilisateur existant et un nouvel utilisateur exige au
moins douze caractères. Après prévisualisation, l’import remplace uniquement
les affectations des utilisateurs présents dans le premier CSV ; les autres
utilisateurs restent intacts. La transaction protège le compte opérateur et le
dernier administrateur actif.

## Listes

Les paramètres communs sont `page`, `per_page` (maximum 100), `sort` et
`order=asc|desc`. Chaque route définit ses tris et filtres autorisés :

- dossiers : tris `id`, `name`, `organization_name`, `type`; filtres `type`,
  `search` ;
- exercices : tris `id`, `label`, `start_date`, `end_date`, `status`; filtres
  `status`, `search`.
- organisations : filtres `status=active|archived|all` et `search` sur le nom,
  la raison sociale ou l’IDE ; `page` et `per_page` avec un maximum de 100.

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
OCAS et restent explicitement à vérifier auprès des organismes concernés.

La lecture comptable exige `exercise_id`, accepte `account_id`,
`date_start`, `date_end` et `vat_statement_id`, puis renvoie le journal,
l’extrait, le plan, les états financiers, la TVA, la checklist de clôture et le
dossier fiscal préparatoire. Les états n’écrivent jamais dans le grand livre.
Les mutations utilisent uniquement le scope de session et les services métier
existants. Elles exigent `compta.edit`, `compta.setup`, `compta.validate`,
`compta.export` ou la permission TVA correspondant précisément à l’étape.
Tous les montants transmis sont des centimes entiers.

Un export de rapport exige les dates exactes et inclut ces paramètres ainsi que
l’empreinte du grand livre. Une archive contient en plus le journal complet de
l’exercice et une empreinte de son contenu ; elle est vérifiée au
téléchargement. Une archive créée en double ne peut être supprimée que tant que
l’exercice reste ouvert : celles d’un exercice fermé sont protégées. L’export
eCH-0217 est validé mais jamais déclaré transmis automatiquement.

Les dépenses utilisent le même document fournisseur, les mêmes contacts, codes
TVA, comptes, pièces jointes, paiements et allocations que Facturation. Elles
ajoutent un workflow explicite `brouillon → à approuver → approuvé →
comptabilisé`. La création et la génération récurrente ne comptabilisent
jamais. `depenses.approve` et `depenses.post` sont deux permissions distinctes.

Le rapprochement bancaire ne fusionne jamais la banque et le grand livre :
chaque source reste historisée et l’association est réversible avant clôture.
Le lettrage est N–N et refuse les surallocations. La préparation, l’export et
la confirmation des paiements sortants utilisent trois permissions distinctes.
Un export pain.001 reste toujours qualifié de « non transmis » ; la dette
n’est soldée qu’après présence du débit dans un relevé bancaire confirmé.

La lecture Facturation accepte `as_of_date`, `direction=all|sales|purchases`,
`status`, `search` et `contact_id`. La date est reprise dans la réponse et dans
l’export. Les tranches d’aging incluent exactement 0–30, 31–60, 61–90 et plus
de 90 jours ; les paiements non alloués sont séparés des tranches mais déduits
du solde net. Les mutations restent scopées par la session et les documents
émis ne sont jamais réécrits. Les offres, demandes d’offre, réponses et
commandes n’ont aucun effet comptable. Leur conversion vers une facture crée
un brouillon et conserve les liens documentaires. Une commande suit les états
`brouillon → envoye → livre → facture` : la conversion en facture est refusée
avant `livre`, côté interface comme côté service. La création directe d’une
commande ou d’une facture reste autorisée.

Le contrat pédagogique ne renvoie jamais `solution_json` dans le workspace.
Une correction est obtenue uniquement par sa route dédiée après autorisation.
Un contexte réel renvoie un workspace indisponible et aucune donnée
pédagogique ; modèles et assignations sont limités à une organisation de nature
`pedagogique`. Les créations et validations réutilisent `EntryService`, sans
moteur de saisie parallèle.

## Sécurité du compte et configuration initiale

`GET /api/v1/security` n’expose ni secret TOTP ni code de récupération. Il
indique uniquement le mode actif, le nombre de codes restants et la
disponibilité des méthodes selon la configuration du serveur. Les mutations de
sécurité exigent le mot de passe courant ; un changement de mot de passe ou de
second facteur révoque les autres sessions.

Le contrat du guide distingue :

- `progress.completed`, nombre de critères métier effectivement satisfaits ;
- la position d’affichage, calculée par Vue depuis l’étape courante ;
- `cancelled`, abandon explicite et persistant pour le dossier ;
- `finished`, conclusion validée lorsque tous les prérequis obligatoires sont
  encore vrais.

`POST /api/v1/configuration/setup-guide/status` accepte seulement
`{"action":"cancel"}` ou `{"action":"resume"}`. Réduire visuellement le guide
n’appelle pas cette route et ne modifie aucune donnée.

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

La base est construite par `001_initial.sql`, puis enrichie par les migrations
additives immuables telles que `002_consolidation_governance.sql`. Tout retour
arrière de schéma se fait par restauration de la sauvegarde SQLite créée avant
migration. Voir
[`../../configuration.md`](../../configuration.md).
