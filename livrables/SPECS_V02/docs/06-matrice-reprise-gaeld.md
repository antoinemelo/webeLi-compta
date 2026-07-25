# Matrice de reprise de Gäld

| Élément Gäld | Décision COMPTA |
|---|---|
| Domaines séparés | Adapter progressivement par module |
| `LedgerService` unique | Conserver `EntryService`, ne pas le remplacer |
| Services de lecture/Reporting | Reprendre comme projections PDO |
| Vue 3 et composants UI | Reprendre le principe, aligné sur le CMS |
| Inertia/Laravel | Refuser |
| PostgreSQL/Redis/queues | Refuser comme prérequis |
| Dashboard KPI/aging | Reprendre avec définitions comptables corrigées |
| Dépenses + récurrences | Reprendre dans Liquidités |
| CAMT/règles/rapprochement | Compléter l'existant COMPTA |
| pain.001 | Ajouter avec validation et statut « non transmis » |
| Contacts partagés | Reprendre, en gardant les tables COMPTA |
| Factures récurrentes | Ajouter |
| Immobilisations | Ajouter |
| Lettrage comptable | Ajouter sans confondre avec rapprochement bancaire |
| Clôture/archives | Ajouter autour des périodes et états existants |
| Multi-devise | Ajouter tardivement avec snapshots, sans `float` |
| Consolidation | Ajouter comme projection optionnelle |
| OCR, Meilisearch, webhooks | Reporter |
| Facturation SaaS/Stripe | Hors périmètre |
| Code source AGPL | Ne pas copier sans décision de licence |
