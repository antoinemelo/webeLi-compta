# Lot 04 — Tableau de bord comptablement informatif

Applique le prompt maître.

Objectif : livrer le premier écran Vue utile sans dupliquer la comptabilité.

Crée une projection de lecture qui retourne, pour une date et un exercice :

- trésorerie comptable par compte, dernier solde bancaire et écart ;
- chiffre d'affaires et charges depuis les seules écritures validées ;
- créances et dettes ouvertes, échues et réparties par aging ;
- lignes bancaires non rapprochées et paiements à traiter ;
- dernières écritures avec lien vers leur source ;
- métadonnées de calcul, devise de base et état vide explicite.

Définis précisément les signes, dates et comptes inclus. N'utilise ni total de
factures comme chiffre d'affaires, ni cache obligatoire. Ajoute les index
seulement après mesure avec `EXPLAIN QUERY PLAN`.

Acceptation :

- égalité démontrée avec balance, factures/allocations et état bancaire ;
- paiement partiel, avoir, exercice sans données et période fermée testés ;
- requêtes bornées et temps mesuré sur jeu représentatif ;
- aucune mutation depuis la projection ou l'écran.
