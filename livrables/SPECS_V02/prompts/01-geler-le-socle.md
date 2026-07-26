# Lot 01 — Geler le socle et établir la référence

Applique le prompt maître.

Objectif : rendre l'état COMPTA actuel traçable et reproductible avant toute
refonte. N'ajoute aucune fonction métier.

Travail :

- inventorier code, schéma courant, routes, permissions et commandes ;
- enregistrer les résultats de référence des rapports sur des fixtures stables ;
- séparer clairement tests rapides et tests d'intégration sans les réécrire ;
- ajouter une commande de qualification unique qui fait lint, tests, migration
  vierge, contrôle d'intégrité et vérification du paquet ;
- documenter PHP/extensions réellement requis et la construction d'une archive
  mutualisée ;
- initialiser proprement l'historique Git avec l'accord du propriétaire si le
  dépôt est toujours sans commit.

Acceptation :

- état initial restaurable et hashé ;
- `001_initial.sql` représente exactement le schéma courant ;
- résultats comptables, TVA, paie, facturation et pédagogie inchangés ;
- installation vierge et rejeu idempotent prouvés ;
- aucun secret, base réelle, sauvegarde ou fichier local ajouté au dépôt.
