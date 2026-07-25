# Lot 08 — Facturation, contacts et aging

Applique le prompt maître.

Objectif : transformer la facturation existante en parcours débiteurs et
créanciers complet.

Implémente :

- vues séparées ventes, achats, récurrences, contacts et échéancier ;
- modèles récurrents idempotents pour factures clients et fournisseurs ;
- contact 360° : documents, avoirs, paiements, solde et aging ;
- échéances explicites, rappels, paiements partiels et allocations N–N ;
- filtres et exports avec date de référence visible.

Conserve numérotation, snapshots, QR-facture, SCOR et comptabilisation actuels.
Une récurrence génère d'abord un brouillon. Les documents émis/validés restent
immuables.

Acceptation :

- aging créances et dettes concorde au centime avec les allocations ;
- 0–30, 31–60, 61–90 et >90 testés aux bornes ;
- avoir et paiement anticipé traités sans solde incohérent ;
- aucune duplication de contact ou de numéro au rejeu.
