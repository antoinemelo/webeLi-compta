# Configuration du dossier

L’écran Vue `/app/configuration` rassemble les réglages transversaux sans
dupliquer les données métier. Il exige la permission `dossier.manage` sur le
dossier sélectionné.

## Sources de vérité

| Réglage | Source unique |
|---|---|
| Identité légale et coordonnées | `organisations` |
| Devise de base | `dossiers.monnaie` |
| Modules actifs | `modules_dossier` |
| Conditions et défauts de paiement | `conditions_paiement`, `defauts_conditions_paiement` |
| Comptes bancaires | `comptes_tresorerie` |
| TVA | régimes, taux et codes du module TVA |
| Charges sociales | paramètres annuels du module Salaires |
| Plan, journaux, exercices et périodes | tables du moteur comptable |
| Débiteurs et créanciers | registre `contacts` de Facturation |
| Utilisateurs et droits | tables d’authentification et d’autorisations |
| Traçabilité | `audit_events` |

Le contrat `/api/v1/configuration/references` lit directement ces sources. Il
n’existe plus de seconde projection SQL dans `ConfigurationService`, ni de
chemin vers un formulaire PHP.

Sous `/app/configuration/referentiels`, les référentiels sont gérés dans Vue :

- débiteurs et créanciers : création et édition optimiste de contacts
  multi-rôles via `ContactService` ;
- codes TVA : taux légaux en lecture et création de codes datés via
  `VatConfigurationService` ;
- charges sociales : millésimes en ppm via
  `PayrollConfigurationService`, avec reprise proposée des valeurs Lasso 2026.
- comptes bancaires, postaux, caisse et cartes : création, édition et
  activation via `TreasuryAccountService`, toujours liés au grand livre ;
- journaux : création et édition optimiste via `AccountingSetupService` ;
- exercices et périodes : création, fermeture et réouverture contrôlées ; un
  exercice ne peut être fermé tant qu’une de ses périodes reste ouverte ;
- rôles directs du dossier : affectation transactionnelle et auditée, sans
  modifier les rôles hérités de l’organisation ou de l’installation.

Le plan comptable est géré par l’écran Vue spécialisé `/app/compta/plan`. Les
instantanés des factures et fiches validées restent inchangés.

## Modules

Les modules Apprentissage, Liquidités, Facturation, Comptabilité et Salaires
sont activables par dossier. Une désactivation :

- retire le module de la navigation ;
- refuse aussi ses routes Vue, PHP et API côté serveur avec HTTP 403 ;
- ne supprime ni ne modifie aucune donnée.

La réactivation restaure donc l’accès aux données existantes. Le tableau de
bord et la configuration restent disponibles afin d’éviter de verrouiller le
dossier.

## Conditions de paiement

Une condition possède une direction, un délai entier en jours, une éventuelle
option « fin de mois » et une période de validité. L’échéance est calculée
ainsi : date du document + délai, puis dernier jour du mois obtenu lorsque
l’option est active.

Les défauts client et fournisseur sont datés. Un nouveau défaut ne peut prendre
effet qu’après le dernier déjà enregistré. Lors de la création d’un document,
la condition résolue et ses paramètres sont copiés dans un snapshot. Une
facture émise conserve ainsi son échéance et son historique lorsque les
réglages futurs changent.

## Base canonique et retour arrière

L'identité, les modules et les conditions de paiement font partie de
`database/migrations/001_initial.sql`. Une installation de développement est
donc créée directement dans son état fonctionnel courant, sans rejouer
l'historique intermédiaire des lots.

Jusqu'au gel de production, une modification structurelle cohérente met à jour
cette base initiale et reconstruit la base de développement après une sauvegarde
de confort. Après le gel, les évolutions seront additives et
`php bin/console db:migrate --apply --backup` redeviendra obligatoire pour une
base en service. Un retour arrière s'effectue toujours par restauration d'une
sauvegarde contrôlée, jamais en retirant manuellement des colonnes.

Les taux de charges sociales restent ceux du module Salaires, issus de la
correspondance Lasso documentée. Leur import et leur évolution annuelle
demeurent versionnés ; les fiches validées conservent leurs snapshots.

Le périmètre exact pouvant être retiré sans perte fonctionnelle est consigné
dans [`vue-retirement-audit.md`](vue-retirement-audit.md).
