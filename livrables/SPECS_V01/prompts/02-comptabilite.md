# Prompt 02 — Comptabilité générale

Implémente le module `Compta` et aucun autre domaine.

## Livrables

- Seed du Plan comptable suisse PME VEB du 12 août 2024 avec attribution visible
  de la source et structure conservée.
- Overlay association versionné (cotisations, dons, subventions, projets/fonds
  affectés selon paramétrage), types/sens normaux, hiérarchie, activation.
- Exercices, périodes et journaux.
- Écritures brouillon, lignes débit/crédit en centimes.
- Service atomique de validation avec tous les invariants.
- Contre-passation liée, sans mutation de l'original.
- Soldes d'ouverture.
- Journal, grand livre, balance, bilan et compte de résultat.
- Filtres, pagination, CSV et vues imprimables.
- Service interne idempotent pour écritures générées par d'autres modules.

## Tests obligatoires

- Écriture simple et composée.
- Déséquilibre d'un centime refusé.
- Compte d'un autre dossier refusé.
- Écriture traversant deux organisations/dossiers refusée.
- Date hors exercice/période fermée refusée.
- Concurrence/rejeu de clé idempotente sans doublon.
- Contre-passation exacte.
- Balance débit/crédit égale et soldes selon sens du compte.
- Compte utilisé seulement désactivable.

N'utilise jamais le premier chiffre du compte comme unique source du type.
N'implémente aucune consolidation. Les références entre organisations restent
informatives et ne créent pas une transaction multi-dossiers.
