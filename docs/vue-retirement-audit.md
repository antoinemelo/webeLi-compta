# Audit de retrait de l’interface PHP

Date de contrôle : 2026-07-26.

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

## Éléments PHP encore fonctionnellement nécessaires

Le retrait global de l’interface PHP n’est pas encore sûr :

| Domaine | Couverture Vue actuelle | Fonction encore exclusivement PHP |
|---|---|---|
| Facturation | navigation et contacts | documents, émission, avoirs, paiements, allocations, rappels, PDF |
| Salaires | navigation et taux sociaux | employés, calculs, validation, comptabilisation, paiements, fiches, certificats |
| Apprentissage | navigation | modèles, groupes, assignations, travail, correction et réinitialisation |
| Liquidités | dépenses ponctuelles/récurrentes, approbation, pièces et comptabilisation | import bancaire, rapprochement, lettrage et émission de paiements (lot 07) |
| Comptabilité | journalisation, extraits et plan | certaines vues imprimables et exports de rapports |

Les services PHP métier et SQLite ne sont pas concernés par ce retrait : ils
restent les sources de vérité derrière les API.

## Règle de suppression

Pour chaque domaine restant :

1. exposer les lectures et mutations par un contrat JSON strict ;
2. construire le parcours Vue sans logique comptable côté navigateur ;
3. démontrer la parité des permissions, contrôles, arrondis et écritures ;
4. rediriger temporairement les URL publiques nécessaires ;
5. supprimer le contrôleur HTML et son gabarit seulement après réussite des
   tests d’intégration et navigateur.

Supprimer aujourd’hui tous les gabarits et routes PHP ferait perdre des outils
opérationnels. Leur retrait doit donc suivre les lots métier, et non précéder
leur remplacement.
