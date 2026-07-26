# Correspondance OCAS → COMPTA — salaires genevois

Cette table précède le portage du moteur. Le périmètre cible est strictement
genevois et ne contient aucune transmission Swissdec.

| Source OCAS | Responsabilité | Cible COMPTA |
|---|---|---|
| `lib/calc.php::calculer_fiche()` | Brut, vacances, retenues, net et charges patronales | `Salaires\PayrollCalculator::calculate()` |
| `lib/calc.php::seuil_heures()` | Seuil mensuel LAA de 8 h/semaine | `PayrollCalculator::monthlyHourThreshold()` |
| `lib/calc.php::laa_effectif()` | Choix LAA réduit/plein | `PayrollCalculator::effectiveAccidentRates()` |
| `taux_par_annee` | Taux sociaux annuels | `taux_salaires_annuels`, valeurs entières en millionièmes |
| `taux_horaires` | Tarifs proposés | `tarifs_salaires`, montants en centimes |
| `unites` | Heure, demi-journée, jour, service | `unites_prestation`, durées en milli-heures |
| `employes` | Identité, AVS, procédure et paramètres individuels | `employes`, limité à Genève et protégé par permissions PII |
| `fiches` | Snapshot mensuel et montants calculés | `fiches_salaires` |
| `fiche_lignes` | Prestations quantité × unité × tarif | `lignes_prestation` |
| `taux_json` et colonnes calculées | Taux et composants figés | `taux_snapshot_json` et `composants_fiche` |
| `sauvegarder_fiche()` | Création/recalcul d’une fiche | `PayrollService::createDraft()` ; un brouillon corrigé est annulé puis recréé |
| `date_paiement` implicite | État payé | cycle explicite brouillon/validée/comptabilisée/payée/annulée |
| `importer_fiches_salaire()` | Simulation et import par AVS/période | `PayrollImportService::import()` |
| `agreger_certificat()` | Agrégats annuels | `PayrollCertificateService::annualData()` |
| `build_certificat_xml()` | Export annuel interne à contrôler | `PayrollCertificateService::generateXml()` |
| `_fiche_body.php` / `fiche_print.php` | Fiche écran et impression | `PayrollView.vue` et contrat `/api/v1/salaires` |
| `fiche_email_html()` | Envoi de fiche | file traçable `emails_salaires`, sans prétendre à un envoi réussi |
| Résumé des retenues | OCAS, LAA, LPP et impôt à payer | `dettes_salaires` et allocations de paiements |
| aucune écriture en partie double | — | mapping configurable et `EntryService::postGenerated()` |

## Parité de tests

Les 32 assertions de `ocas/tests/calc_test.php` sont reprises dans
`Tests::payrollCalculatorParityTests()` avec des centimes et taux entiers. Les
cas couvrent le salaire de référence, les vacances, AVS/AC/A.mat/LAA/LPP,
l’impôt source, les charges patronales, CPE/LFP, les seuils mensuels et le choix
LAA.

## Import contrôlé

`OcasRateImportService` couvre exhaustivement les seize clés utilisées par
`lib/calc.php`. Il convertit les fractions décimales en ppm par arithmétique
entière, rapporte toute clé inconnue comme non applicable et refuse un millésime
incomplet. La prévisualisation ne modifie rien ; la confirmation compare une
empreinte SHA-256, conserve la source et la date de contrôle, puis devient
idempotente. Un taux déjà utilisé par une fiche validée ne peut plus être
remplacé.

## Limites explicites

Le taux individuel unique d’impôt à la source repris de l’OCAS ne constitue pas
un barème fiscal officiel complet. Le taux LPP paramétrique initial ne remplace
pas un plan de prévoyance ni ses règles d’âge, de seuil et de salaire coordonné.
Les paramètres annuels doivent être validés par les organismes et spécialistes
compétents avant un usage réel.
