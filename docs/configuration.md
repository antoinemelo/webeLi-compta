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

## Migrations et retour arrière

Les migrations additives sont :

- `011_configuration_modules.sql` : identité légale, registre des modules et
  activation par dossier ;
- `012_conditions_paiement.sql` : conditions datées, défauts et snapshots de
  facturation.

Elles ont été testées sur une copie arrêtée à la version 010. Avant application,
`php bin/console db:migrate --apply --backup` crée une sauvegarde vérifiable.
Un retour arrière applicatif nécessite la restauration de cette sauvegarde :
les colonnes ajoutées ne doivent pas être retirées manuellement d’une base en
service.

Les taux de charges sociales restent ceux du module Salaires, issus de la
correspondance Lasso documentée. Leur import et leur évolution annuelle
demeurent versionnés ; les fiches validées conservent leurs snapshots.
