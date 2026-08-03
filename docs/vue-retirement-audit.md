# Audit de retrait de l’interface PHP

Date de contrôle : 2026-07-29.

## Référentiels de Configuration

Le retrait est complet pour ce périmètre :

- un seul contrat, `GET /api/v1/configuration/references` ;
- aucune propriété `legacy_path` ;
- aucune projection parallèle dans `ConfigurationService` ;
- écrans Vue natifs pour contacts, TVA, taux sociaux, comptes de trésorerie,
  journaux, exercices et périodes ;
- rôles directs du dossier gérés dans l’onglet Accès ;
- plan comptable géré dans la vue Vue spécialisée ;
- mutations scopées, contrôlées par permission, auditées et protégées par
  version lorsque l’objet est modifiable.

## Couverture actuelle

Le retrait fonctionnel de l’ancienne interface PHP est terminé. L’interface
authentifiée unique est Vue pour :

| Domaine | Couverture Vue |
|---|---|
| Facturation | échéancier, offres, commandes, achats, ventes, récurrences, contacts, PDF, paiements et allocations |
| Salaires | employés, contrats, calculs, fiches, annuels, paiements et certificats |
| Apprentissage | catalogue, exercices, suivi, travail, correction et réinitialisation |
| Liquidités | dépenses, rapprochement, lettrage, paiements sortants et données de marché |
| Comptabilité | journalisation, extraits, états, archives, clôture, immobilisations, fiscal et consolidation |
| Configuration | modules, entité, paiements, référentiels, salaires, audit et parcours initial |

Les routes historiques encore publiques ne rendent aucun écran métier
concurrent : elles redirigent vers `/app`. Les templates PHP conservés servent
uniquement les frontières publiques ou techniques nécessaires, notamment la
connexion, la récupération du mot de passe et le shell de chargement.

## Ce qui reste volontairement en PHP

Le retrait concernait le rendu métier, pas le serveur. Les services PHP,
SQLite, contrôleurs API, permissions, validations, arrondis, exports et
générateurs PDF/XML restent les sources de vérité. Vue ne reçoit jamais
d’autorité comptable autonome.

Toute nouvelle fonction doit donc :

1. exposer un contrat JSON strict et scopé ;
2. conserver les règles métier et mutations côté PHP ;
3. utiliser le shell Vue et ses composants partagés ;
4. démontrer permissions, contrôles et écritures par les tests d’intégration ;
5. compléter la recette navigateur avant livraison.
