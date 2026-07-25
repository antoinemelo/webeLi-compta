# Lot 07 — Banque, lettrage et paiements sortants

Applique le prompt maître.

Objectif : exposer et compléter les services de trésorerie existants.

Implémente les onglets :

- Rapprochement : import prévisualisé, suggestions, validation 1–1/1–N/N–1,
  écart banque/comptabilité et annulation auditée ;
- Lettrage : allocation d'un paiement à une ou plusieurs factures/dettes, solde
  restant et délettrage autorisé avant clôture ;
- Paiements sortants : sélection de dettes approuvées, lot, contrôle IBAN/BIC,
  génération pain.001 et téléchargement ;
- historique et statuts « préparé », « exporté », « confirmé par relevé ».

Le fichier pain.001 n'est jamais décrit comme transmis. Il ne marque aucun
document payé avant confirmation ou rapprochement.

Acceptation :

- source CAMT et pain.001 conservées avec empreinte ;
- doublons, double allocation, montant divergent et période close refusés ;
- permissions et séparation des tâches testées ;
- cas facture partielle, paiement groupé et frais bancaires couverts.
