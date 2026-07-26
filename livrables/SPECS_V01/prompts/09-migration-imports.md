# Prompt 09 — Migration des données existantes

Construis des importeurs, sans écrire directement dans une base réelle.

## Sources

- `journal` : plan comptable, soldes initiaux, journal `::`, configuration des
  comptes de factures et éventuels fichiers de paiements.
- `ocas` : employés, fiches, taux, factures, débiteurs, comptes, imports et axes.

## Exigences

- Inventaire et rapport préalable en lecture seule.
- Import dans une nouvelle base/copie avec `--dry-run` par défaut.
- Mappage explicite des comptes et statuts ambigus.
- Sélection explicite de l'organisation et du dossier cibles ; interdiction de
  répartir implicitement une source entre plusieurs organisations.
- Migration des anciennes catégories de caisse vers comptes/lignes en partie
  double et codes TVA, avec file d'ambiguïtés plutôt qu'une supposition.
- Conservation de la provenance (`source_system`, identifiant, empreinte).
- Rejeu idempotent.
- Rapport lignes importées/ignorées/ambiguës/invalides.
- Contrôles après import : balance, totaux par exercice, factures ouvertes,
  TVA par période, fiches par employé, clés étrangères et intégrité.
- Aucune donnée source modifiée.
- Anonymiseur séparé pour créer un jeu de recette.

Fournis un guide de rapprochement manuel et demande validation humaine avant
de déclarer la migration prête pour la production.
