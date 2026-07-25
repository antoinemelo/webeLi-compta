# Cible fonctionnelle corrigée

## Navigation principale

1. **Tableau de bord**
   - solde comptable de trésorerie et solde bancaire importé, avec écart ;
   - chiffre d'affaires et charges de l'exercice depuis les écritures validées ;
   - créances et dettes ouvertes, échues et aging ;
   - dernières écritures et lignes bancaires non rapprochées ;
   - période, devise de base et date de calcul toujours visibles.
2. **Apprentissage** — masqué si le module est désactivé.
   - catalogue, exercices ciblés, espace apprenant, suivi formateur, correction.
3. **Liquidités**
   - utilisation : dépenses ponctuelles et récurrentes, justificatifs ;
   - rapprochement bancaire ;
   - lettrage des paiements avec documents ouverts ;
   - paiements sortants et génération pain.001, jamais présentée comme envoyée.
4. **Facturation**
   - ventes ponctuelles/récurrentes, avoirs et rappels ;
   - achats et factures fournisseurs ponctuelles/récurrentes ;
   - contacts uniques, rôles client/fournisseur et vue 360° ;
   - échéancier créances/dettes avec tranches 0–30, 31–60, 61–90, plus de 90.
5. **Comptabilité**
   - journalisation débit/crédit et écritures composées ;
   - extraits de compte en liste ou compte en T, journal et grand livre ;
   - balance de vérification, bilan, résultat et flux de trésorerie ;
   - TVA, dossier de déclaration fiscale assistée ;
   - immobilisations, plans d'amortissement, clôture et archives financières.
6. **Salaires**
   - employés ;
   - calcul horaire ou mensuel et traitements par période ;
   - fiches, paiements, charges sociales ;
   - certificats et récapitulatifs annuels.
7. **Configuration**
   - identité de l'entité légale et dossiers comptables ;
   - modules activés, utilisateurs et droits ;
   - comptes bancaires, plan comptable, journaux, exercices et périodes ;
   - TVA, charges sociales, conditions/délais de paiement ;
   - devises et taux de change ;
   - audit, sauvegarde, import/export.

## Clarifications métier

- Le chiffre d'affaires et les charges du tableau de bord viennent du grand
  livre validé, pas du total brut des factures ou dépenses.
- Une dépense est un document métier ; sa comptabilisation passe toujours par
  `EntryService`. Une transaction bancaire n'est jamais une écriture comptable.
- Factures émises et reçues partagent contacts, échéances, paiements et
  allocations, mais gardent leurs cycles et comptes par défaut.
- Le registre Contacts est unique. Configuration peut y renvoyer, sans créer
  un second CRUD « débiteurs/créanciers ».
- « Réconciliation » est décliné sans ambiguïté : rapprochement bancaire,
  lettrage document/paiement et, plus tard, réconciliation inter-entités.
- La déclaration fiscale est un dossier de préparation, contrôles et exports.
  L'application ne prétend ni conseiller fiscalement ni transmettre à
  l'administration sans intégration officielle prouvée.
- Une organisation COMPTA représente d'abord une entité légale. Plusieurs
  organisations permettent le multi-entités. La consolidation est une
  projection séparée et optionnelle, jamais une fusion de grands livres.

## Priorité

Le noyau utilisable « best-in-class » est : saisie fiable, factures, dépenses,
banque, lettrage, états justes, clôture et audit. OCR, synchronisation bancaire
directe, webhooks et automatisations avancées sont hors priorité.
