# Lot 06 — Utilisation des liquidités

Applique le prompt maître.

Objectif : ajouter les dépenses ponctuelles et récurrentes au module
Liquidités.

Implémente :

- document de dépense avec fournisseur, dates, lignes, TVA, échéance,
  justificatif, comptes et statut ;
- cycle brouillon → à approuver → approuvé → comptabilisé, puis annulation par
  contre-passation ;
- modèles récurrents avec prochaine échéance, fin, pause et génération
  idempotente par cron/CLI ;
- paiement séparé de la dépense et allocation explicite ;
- écrans liste, détail, création, récurrences et pièces jointes.

Ne comptabilise jamais automatiquement à la simple création. Réutilise TVA,
contacts, pièces jointes, `EntryService` et allocations existants.

Acceptation :

- brut/net/TVA et écriture fournisseur équilibrés au centime ;
- rejeu de récurrence sans doublon ;
- justificatif validé et stocké hors webroot ;
- approbateur et comptabilisateur contrôlés par permissions distinctes.
