# Débiteurs et créanciers

L’écran **Débiteurs et créanciers** est propre au dossier sélectionné. Il
regroupe les documents, les contacts et les paiements dans trois onglets.

## Cycle documentaire

Un brouillon ne possède aucun numéro. L’émission attribue atomiquement un
numéro par dossier, année et série (`F-AAAA-NNN` pour une facture client), puis
fige l’identité, l’adresse, les lignes, les prix et les snapshots TVA. La
comptabilisation produit une écriture équilibrée et idempotente.

Une facture comptabilisée n’est jamais supprimée ni réécrite. Son annulation
passe par un avoir émis et comptabilisé ; l’original demeure dans l’historique.
Les factures fournisseurs exigent leur numéro externe, unique par fournisseur,
et acceptent un justificatif PDF ou image de 10 Mo au maximum. Aucun circuit
d’approbation fournisseur n’est introduit.

## Paiements

Un paiement existe indépendamment des factures. Les allocations N–N permettent
de répartir plusieurs paiements sur une facture ou un paiement sur plusieurs
factures. La somme allouée ne peut dépasser ni le paiement ni le solde de la
facture, même d’un centime. Les états ouvert, partiellement payé, payé et en
retard sont dérivés des documents, allocations et échéances.

## PDF et QR-facture

L’émission client crée une référence SCOR. Le PDF archivé contient une
QR-facture suisse avec son payload SPC. L’adresse du créancier se configure
dans `parametres_organisation` avec les clés `adresse_ligne1`,
`adresse_ligne2`, `code_postal`, `localite` et `pays`. L’IBAN vient de
`iban_facturation` ou, à défaut, du premier compte de trésorerie actif possédant
un IBAN.

Les dépendances PDF/QR sont verrouillées pour PHP 8.2. L’archive PDF, son
empreinte SHA-256, le payload QR et les snapshots de contact restent attachés
au document.
