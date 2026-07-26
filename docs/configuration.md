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

Les cartes de référentiels sont des vues et des liens vers ces sources. Elles
ne créent pas de second registre.

Sous `/app/configuration/referentiels`, trois référentiels sont entièrement
gérés dans Vue :

- débiteurs et créanciers : création et édition optimiste de contacts
  multi-rôles via `ContactService` ;
- codes TVA : taux légaux en lecture et création de codes datés via
  `VatConfigurationService` ;
- charges sociales : millésimes en ppm via
  `PayrollConfigurationService`, avec reprise proposée des valeurs Lasso 2026.

L’ancien onglet Contacts redirige vers Configuration et l’ancien formulaire de
taux salariaux ne permet plus une seconde écriture. Les instantanés des factures
et fiches validées restent inchangés.

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
