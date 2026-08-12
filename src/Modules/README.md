# Modules

Chaque module expose ses cas d’usage et repositories sans accéder directement
aux contrôleurs d’un autre module. Le noyau ne dépend d’aucun module.

La version 0.6.1 comprend les modules suivants :

- `Dossiers` : organisations, dossiers, scopes et gouvernance des accès ;
- `Configuration` : modules, identité et référentiels du dossier ;
- `Shell` et `Dashboard` : contexte Vue, navigation et projections de lecture ;
- `Compta` : écritures, plan, ouvertures, rapports et clôture ;
- `Facturation` : contacts, documents, paiements et allocations ;
- `Tresorerie` : comptes opérationnels, banque, dépenses et paiements sortants ;
- `Tva` et `Devises` : fiscalité suisse, change et données datées ;
- `Salaires` : calculs et paiements du périmètre genevois ;
- `Immobilisations` : registre, amortissements et sorties ;
- `Consolidation` : agrégation, consolidation et éliminations hors livres ;
- `Pedagogie` : modèles et copies isolées d'exercices.

Les échanges entre domaines passent par leurs services publics. En particulier,
les modules générateurs utilisent
`Compta\Modules\Compta\EntryService::postGenerated()` au lieu d'écrire
directement dans le grand livre. Le schéma correspondant est porté par les
migrations immuables `001` à `008`; la prochaine migration porte le numéro
`009`.
