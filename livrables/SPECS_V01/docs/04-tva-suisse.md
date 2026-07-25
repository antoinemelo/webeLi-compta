# TVA suisse — périmètre opérationnel du MVP

## 1. Principe

La TVA est opérationnelle dès la première version réelle. Le logiciel aide à
qualifier, comptabiliser, contrôler et préparer le décompte, mais ne choisit pas
à la place de l'utilisateur la qualification juridique d'une prestation.

Les règles sont versionnées par date d'effet. Les taux initiaux publiés par l'AFC
au moment de la spécification sont :

- taux normal : 8,1 % ;
- taux réduit : 2,6 % ;
- taux spécial hébergement : 3,8 %.

Sources :

- `https://www.estv.admin.ch/fr/taxe-sur-la-valeur-ajoutee`
- `https://www.estv.admin.ch/fr/taux-de-la-tva-suisse`
- `https://www.estv.admin.ch/fr/decompter-la-tva-en-ligne`

## 2. Paramètres par organisation et dossier

- statut : non assujetti, assujetti, assujettissement volontaire ;
- numéro IDE/TVA et dates de début/fin d'assujettissement ;
- méthode : effective ou taux de la dette fiscale nette (TDFN) ;
- mode de décompte : contre-prestations convenues ou reçues ;
- périodicité et périodes de décompte ;
- comptes de TVA due, décompte TVA, impôt préalable marchandises/prestations,
  impôt préalable investissements/autres charges et corrections ;
- TDFN autorisés par l'AFC, branche, date d'effet et seuils informatifs.

Une modification de méthode ou d'assujettissement est datée et auditée. Elle ne
réécrit jamais les documents ou décomptes antérieurs.

## 3. Codes fiscaux

Un code TVA contient :

- identifiant stable et libellé ;
- traitement : normal, réduit, spécial, taux zéro, exonéré avec droit à
  déduction, exclu sans droit à déduction, hors champ/non contre-prestation,
  impôt sur les acquisitions, importation ou correction ;
- taux légal et intervalle de validité ;
- droit à déduction de l'impôt préalable et pourcentage déductible par défaut ;
- cases/chiffres de décompte AFC ;
- comptes comptables proposés.

Les dons, subventions, prestations de formation/culture et opérations étrangères
doivent pouvoir recevoir un traitement explicite. L'application n'infère pas
automatiquement leur qualification à partir du nom du contact ou du compte.

## 4. Documents et calculs

- Chaque ligne de facture porte un code TVA snapshoté, une date de prestation,
  une base nette, un montant TVA et un total brut en centimes.
- Saisie en prix net ou brut, avec règle d'arrondi documentée au centime par ligne.
- Réductions, frais et arrondis sont ventilés de manière déterministe.
- Avoirs et annulations inversent bases et TVA avec lien au document d'origine.
- Le taux applicable est déterminé par la date de prestation/règle de transition,
  pas simplement par la date de création.
- Les factures émises montrent le taux légal même avec méthode TDFN.
- Les snapshots rendent les documents reproductibles après changement des taux.

## 5. Méthode effective

- TVA collectée par code et période.
- Impôt préalable séparé selon les comptes 1170/1171 du plan PME.
- Pourcentage de déduction modifiable par ligne avec motif obligatoire.
- Corrections/réductions de déduction et double affectation passées par écritures
  dédiées, datées et auditables.
- Décompte = chiffre d'affaires qualifié, TVA due, impôt préalable admissible,
  corrections et solde.

## 6. TDFN

- Les factures clients utilisent toujours les taux légaux.
- Le décompte calcule la dette sur le chiffre d'affaires brut selon le ou les TDFN
  autorisés et datés.
- L'impôt préalable n'est pas déduit comme en méthode effective.
- Plusieurs TDFN peuvent être configurés ; chaque activité/ligne est rattachée au
  taux accordé pertinent.
- Les changements de méthode exigent des écritures de correction saisies avec
  justification ; aucune correction automatique opaque.

## 7. Décomptes

Cycle :

```text
préparé → contrôlé → exporté → déclaré → payé / remboursé → corrigé
```

- Un décompte snapshotte paramètres, taux, agrégats et liste des écritures sources.
- Drill-down de chaque case jusqu'aux lignes comptables et documents.
- Contrôles de périodes manquantes, codes inconnus, dates hors assujettissement,
  bases/TVA incohérentes et écritures postérieures.
- Après déclaration, correction par décompte rectificatif ; pas de réécriture.
- Export XML **eCH-0217 e‑TVA version 2.0.0**, validé par XSD et accompagné d'un
  récapitulatif lisible. L'envoi reste manuel via « Décompte TVA pro ».

## 8. Comptabilisation

Exemples, comptes configurables :

```text
Facture client :
  Débit  1100 Débiteurs                         brut
  Crédit 3xxx Produit                           net
  Crédit 2200 TVA due                           TVA

Facture fournisseur, méthode effective :
  Débit  4xxx/6xxx Charge                       net
  Débit  1170 ou 1171 Impôt préalable           TVA déductible
  Débit  charge concernée                       part non déductible
  Crédit 2000 Créanciers                        brut
```

Le règlement du décompte transfère le solde via le compte de décompte TVA, sans
confondre déclaration, paiement bancaire et période fiscale.

## 9. Tests obligatoires

- Calcul net → TVA → brut et brut → TVA → net pour chaque taux.
- Plusieurs taux sur une facture, rabais, avoir total et partiel.
- Taux différents selon date de prestation et transition.
- Non-assujetti : aucune TVA comptabilisée malgré un prix saisi.
- Exclu, exonéré et hors champ produisent des catégories distinctes.
- Déduction 100 %, partielle et nulle ; correction avec motif.
- Méthode effective en convenu et reçu.
- TDFN : taux légal sur facture, taux accordé sur chiffre d'affaires brut, sans
  déduction ordinaire de l'impôt préalable.
- Décompte traçable et rectificatif sans mutation de l'original.
- XML eCH-0217 conforme au XSD et importable dans un environnement de test ou
  validateur disponible.
