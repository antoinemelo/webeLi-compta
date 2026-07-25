# Spécifications fonctionnelles

## 1. Tableau de bord

Le tableau de bord dépend du dossier et de la période sélectionnés. Il affiche :

- soldes banque/caisse comptables et, si disponible, soldes bancaires importés ;
- factures clients ouvertes, échues et en retard ;
- factures fournisseurs à payer dans 7, 30 et 60 jours ;
- salaires et charges sociales à payer ;
- écritures brouillon, imports à traiter et écarts de rapprochement ;
- résultat provisoire de l'exercice.

Chaque total ouvre la liste filtrée qui le compose.

## 2. Plan comptable et exercices

- Import CSV et modèle PME suisse VEB fourni avec attribution de la source,
  plus un overlay association conservant la structure.
- Numéro unique dans un dossier ; libellé ; type ; sens normal ; parent facultatif.
- Types minimaux : actif, passif, fonds propres, produit, charge, hors bilan.
- Marques fonctionnelles : liquidité, client collectif, fournisseur collectif,
  salaire à payer, charge sociale à payer, résultat.
- Un compte utilisé ne se supprime pas : il devient inactif.
- Un exercice a une date de début/fin et des périodes ouvertes/fermées.
- Les soldes d'ouverture sont importés ou générés par clôture.

## 3. Écritures

### Brouillon

Une écriture contient date comptable, journal, libellé, référence/pièce, lignes,
axes et justificatifs. Elle peut être déséquilibrée tant qu'elle reste brouillon,
mais l'UI affiche l'écart en direct.

### Validation

La validation exige :

- au moins deux lignes ;
- somme des débits = somme des crédits au centime ;
- comptes actifs et autorisés dans la période ;
- montant strictement positif sur chaque ligne ;
- aucun débit et crédit simultanés sur la même ligne ;
- date dans un exercice et une période ouverts.

Après validation, contenu et lignes sont immuables. Une erreur se corrige avec une
contre-passation liée à l'original, puis une nouvelle écriture.

### Consultation

Filtres par période, journal, compte, contact, document, montant, statut, axe et
texte. Exports CSV et impression avec totaux contrôlables.

## 4. Liquidités et rapprochement

- Un compte de trésorerie représente banque, poste, caisse ou carte.
- Import CSV et ISO 20022 CAMT en deux temps : prévisualisation, mappage, puis validation.
- CAMT couvre au MVP `camt.053` et `camt.054`, avec détection du namespace/version.
- Les doublons sont détectés par identifiant bancaire ou empreinte stable.
- La ligne importée reste distincte de l'écriture comptable.
- Rapprochement 1–1, 1–N ou N–1 entre lignes bancaires et lignes comptables.
- Tolérance de montant nulle par défaut ; tolérance paramétrable et auditée.
- Un rapprochement peut être proposé automatiquement mais confirmé manuellement.
- L'état montre solde importé, solde comptable et différence à une date donnée.
- Les mouvements internes entre deux liquidités ne produisent ni charge ni produit.

## 5. Contacts, débiteurs et créanciers

Un contact unique peut être client, fournisseur, employé ou plusieurs à la fois.
Les adresses et coordonnées utilisées sur un document sont copiées comme snapshot.

### Factures clients

- Brouillon modifiable, numérotation lors de l'émission.
- Lignes, quantité, prix, code/taux TVA daté, traitement TVA, axe analytique et communication.
- Échéance, QR-facture suisse, SCOR et PDF.
- À l'émission, création optionnelle d'une écriture :
  débit clients / crédit produits / crédit TVA due selon les lignes.
- États dérivés : brouillon, émise, partiellement payée, payée, en retard, annulée.
- Une note de crédit est un document propre, allouable à une facture.

### Factures fournisseurs

- Saisie du numéro fournisseur, dates, échéance, lignes et justificatif.
- Détection de doublon `(fournisseur, numéro)` avec avertissement bloquant.
- À la comptabilisation : débit charges/actifs et impôt préalable déductible /
  crédit fournisseurs, selon la méthode TVA du dossier.
- Proposition de paiement sans marquer la facture payée avant allocation réelle.

### Paiements et allocations

Une allocation lie un montant positif d'un paiement ou d'une note de crédit à un
document. Plusieurs allocations sont possibles dans les deux sens. La somme
allouée ne dépasse ni le disponible du paiement ni le solde du document, sauf
flux d'avoir explicitement prévu.

Le solde d'un document est toujours calculé :

`total du document - allocations valides - avoirs valides`.

## 6. Salaires

Le périmètre fonctionnel de Lasso est conservé :

- employeur, employés, canton, AVS, procédure, taux horaire ;
- taux sociaux par année ;
- prestations quantité × unité × taux ;
- supplément vacances ;
- déductions employé et charges patronales ;
- choix LAA selon seuil mensuel ;
- impôt à la source au taux configuré, avec limite documentée ;
- fiche figée, impression, e-mail et certificat annuel ;
- import avec simulation et correspondance AVS.

Ajouts nécessaires :

- statuts brouillon, validée, comptabilisée, payée, annulée ;
- corrections par nouvelle version/annulation, jamais réécriture silencieuse ;
- écriture de paie paramétrable et équilibrée ;
- dettes séparées envers employé, assurances, LPP, impôt source et autres caisses ;
- rapprochement des paiements avec ces dettes ;
- contrôle des périodes fermées ;
- accès aux données personnelles selon rôle.

## 7. Analytique

- Axes et valeurs configurables par dossier.
- Affectation sur ligne comptable, ligne de facture ou prestation de salaire.
- Ventilation dont la somme doit correspondre à la ligne source.
- Rapports par axe sans modifier la comptabilité générale.

## 8. Enseignement

- Un formateur crée un modèle avec plan, soldes, consignes et transactions attendues.
- L'assignation crée une copie isolée pour chaque apprenant ou groupe.
- Un dossier de groupe est partagé par ses membres depuis plusieurs postes.
- Chaque mutation conserve son auteur ; la progression distingue résultats du
  groupe et contributions individuelles.
- Les brouillons utilisent un verrou optimiste. En cas d'édition simultanée du
  même objet, la seconde sauvegarde reçoit un conflit explicite avec possibilité
  de recharger, sans écraser silencieusement la première.
- La progression enregistre étapes vues, tentatives et validations.
- Les règles de validation portent sur les effets comptables, pas seulement les
  numéros d'écriture : comptes, sens, montants, soldes ou résultat attendu.
- Les indices sont graduels et leur consultation est tracée.
- La solution n'est jamais envoyée au navigateur avant autorisation.
- La réinitialisation est permise seulement sur `exercice` et crée un événement d'audit.
- Un bandeau permanent « EXERCICE — DONNÉES FICTIVES » évite toute confusion.
- Dans une installation mixte, navigation, recherche, exports et sélecteurs ne
  montrent que les organisations/dossiers autorisés.

## 9. Audit et pièces

Tracer connexion, création, modification, validation, contre-passation, import,
rapprochement, export sensible, changement de rôle, clôture, sauvegarde et
restauration. Une entrée d'audit contient acteur, dossier, action, cible, date,
IP tronquée/raisonnable et résumé avant/après sans mot de passe ni secret.

Les justificatifs sont stockés hors webroot, nommés par identifiant interne,
contrôlés par type/taille et servis via une route autorisée.
