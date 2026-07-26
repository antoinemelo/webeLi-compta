# Lot 14 — Entités légales et consolidation

Applique le prompt maître.

Ce lot construit le moteur de consolidation. Il n’achève pas à lui seul
l’administration des structures : exécute ensuite les lots 14b à 14e avant le
lot 15.

Objectif : gérer plusieurs entités et une consolidation optionnelle sans
affaiblir l'isolation.

Implémente :

- organisations existantes présentées comme entités légales, avec attributs
  juridiques datés ;
- groupes, membres, périodes et mappings de comptes ;
- balance consolidée en lecture, conversions si devises différentes ;
- écritures d'élimination séparées, documentées et auditables ;
- réconciliation des comptes inter-entités et écarts.

Ne déplace aucune écriture entre organisations et ne crée aucun accès implicite.
Un groupe n'accorde pas de droit sur ses membres.

Acceptation :

- somme des balances + éliminations = consolidation ;
- chaque montant est drillable jusqu'à la balance source ;
- aucune élimination dans les grands livres statutaires ;
- tests de fuite horizontale et de droits sur chaque entité ;
- export autonome de la piste de consolidation.
