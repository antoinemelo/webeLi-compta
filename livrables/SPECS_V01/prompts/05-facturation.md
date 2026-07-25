# Prompt 05 — Débiteurs et créanciers

Implémente contacts, factures clients, factures fournisseurs, paiements,
allocations et avoirs.

## Livrables

- Contact multi-rôles et snapshots d'adresse sur document.
- Cycle brouillon/émission/comptabilisation/annulation autorisé.
- Numérotation transactionnelle par dossier/année.
- Factures clients avec PDF, QR-facture et SCOR.
- Factures fournisseurs, justificatif et unicité du numéro externe.
- Codes TVA snapshotés par ligne, prix net/brut, dates de prestation et écritures
  conformes au régime fourni par le module Tva.
- Échéances et états dérivés, dont partiellement payé et retard.
- Paiements indépendants et allocations N–N.
- Écritures générées idempotentes via le module Compta.
- Rappels manuels traçables.
- Aucun workflow d'approbation des factures fournisseurs.

## Contraintes de dépendances

Fixe la plateforme Composer à PHP 8.2. Résous des versions compatibles, lance
`composer audit`, puis exécute génération SCOR/QR/PDF sous PHP 8.2. Ne contourne
pas le contrôle de plateforme et ne modifie pas `vendor/composer/platform_check.php`.

## Tests obligatoires

- Plusieurs brouillons sans collision ; deux numéros émis jamais identiques.
- Paiements 400 + 600 sur facture 1 000.
- Surallocation de 1 centime refusée.
- Un paiement réparti sur plusieurs factures.
- Annulation par avoir/contre-passation sans suppression historique.
- Écriture client/fournisseur équilibrée.
- Facture multi-taux, exonérée et avoir : bases/TVA identiques au module Tva.
- PDF/QR générable depuis l'archive release PHP 8.2.
