# Prompt 06 — Salaires genevois

Porte le domaine salaires de l’OCAS en conservant ses résultats métier, mais en
l'intégrant proprement au nouveau noyau et à la comptabilité. Le périmètre est
strictement genevois au MVP et n'inclut aucune transmission Swissdec.

## Travail préparatoire

Établis une table de correspondance entre fonctions/tables/tests OCAS et la cible.
Porte d'abord les 32 assertions de `tests/calc_test.php`, puis seulement le code.

## Livrables

- Employeur, employés, taux annuels, tarifs, unités et prestations.
- Calculs employé/employeur, vacances, LAA, LPP, impôt source.
- Fiche snapshot avec composants et taux figés.
- Statuts brouillon, validée, comptabilisée, payée, annulée.
- Impression/e-mail, certificat annuel et export XML.
- Import JSON avec simulation et idempotence par AVS/période.
- Mapping configurable des comptes de charges et dettes.
- Écriture de paie détaillant net, assurances, LPP, impôt et charges employeur.
- Allocation/rapprochement des paiements de salaire et charges.

## Tests obligatoires

- Parité exacte avec tous les cas OCAS.
- Changement de taux sans effet sur fiche validée.
- Arrondis par composant et total cohérent.
- Rejeu de comptabilisation sans doublon.
- Écriture de paie équilibrée.
- Accès PII limité et AVS masqué hors fiche autorisée.
- Aucun endpoint, dépendance ou écran ne prétend transmettre via Swissdec.

Documente explicitement que le taux unique d'impôt source et le modèle LPP
initial ne remplacent pas des barèmes officiels complets. Retire du formulaire les
choix de cantons non genevois inutiles au MVP, sans coder les taux en dur.
