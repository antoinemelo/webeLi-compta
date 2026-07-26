# Analyse des sources

## 1. Projet `journal`

### Forces à conserver

`Compta.py` matérialise correctement le raisonnement comptable :

- plan comptable numéroté et configurable ;
- saisie débit / crédit avec compte de contrepartie ;
- soldes initiaux, journal, grand livre, bilan et résultat ;
- fonctionnement distinct des comptes actifs/charges et passifs/produits ;
- suivi de comptes de liquidités ;
- comptes configurables pour créances et dettes ;
- factures ouvertes, échéances, retards et paiements partiels ;
- « mandats » et scénarios d'exercices accompagnés d'instructions.

Le format du journal est simple (`no::débit::crédit::libellé::montant`) et a fait
ses preuves à l'usage. Le modèle conceptuel, plus que l'interface Tkinter ou les
fichiers texte, doit être transposé.

### Limites à corriger lors de la transposition

- Les données sont dispersées dans des fichiers texte et des noms de fichiers.
- Le suivi de facture dépend du numéro de pièce et de fichiers auxiliaires.
- Certaines règles sont déduites de préfixes codés en dur (`110`, `200`, etc.).
- Les paiements partiels ne sont pas modélisés comme allocations explicites.
- Les contrôles d'équilibre, de période et d'intégrité ne sont pas centralisés.
- La modification ou suppression d'historique n'a pas de piste d'audit robuste.
- L'UI et la logique métier sont fortement mêlées dans un fichier de 2 235 lignes.

### Décision

Reprendre le vocabulaire, les parcours et les calculs de soldes, mais les
implémenter sur un modèle relationnel normalisé : `ecritures` +
`lignes_ecriture`, documents, échéances et allocations.

## 2. Projet `ocas`

### Forces à reprendre

- Application PHP/SQLite déjà utilisable sur hébergement mutualisé.
- Authentification, CSRF, échappement, requêtes préparées, sessions expirantes.
- Paramètres employeur et taux par année.
- Employés, prestations, fiches mensuelles et certificats annuels.
- Montants et taux d'une fiche figés à sa création.
- Calcul LAA selon le seuil mensuel, charges employé et employeur.
- Exports/impression, e-mail, sauvegarde SQLite.
- Facturation débiteurs avec QR-facture suisse, SCOR, échéance et rappels.
- Import PostFinance idempotent, règles de catégorisation et axes analytiques.
- Modules activables et migrations `PRAGMA user_version`.
- Tests métier utiles.

### Limites observées

La comptabilité OCAS est principalement une comptabilité de caisse : une ligne
bancaire porte une catégorie du plan de comptes. Ce modèle ne suffit pas pour :

- passer une écriture composée de plusieurs lignes ;
- suivre proprement caisse, banque, créances, dettes et comptes transitoires ;
- distinguer date de document, date comptable, échéance et date de paiement ;
- produire un grand livre complet, une balance et des lettrages explicites ;
- rattacher plusieurs paiements à une facture ou un paiement à plusieurs factures ;
- comptabiliser les salaires en dettes avant leur paiement.

Le modèle courant duplique par ailleurs le lien facture/paiement
(`factures.ecriture_id` et `ecritures.facture_id`), ce qui doit être remplacé par
une table d'allocations.

### Résultats des tests exécutés

- Salaires : **32 assertions réussies**.
- Comptabilité/import : **47 assertions réussies**.
- Événements : **47 tests réussis**.
- Facturation : arrêt au test SCOR avant la fin.

Le blocage de facturation vient du paquet `vendor/` : `composer.json` annonce
PHP `>=8.0` et le README PHP 8.1+, mais les versions verrouillées de
`symfony/intl`, `symfony/validator` et `endroid/qr-code` exigent PHP 8.4.1.
L'environnement PHP 8.2.32 refuse donc l'autoload.

### Décision

Reprendre le moteur de paie après extraction en services testables. Reprendre
les concepts de facturation et de QR-facture, mais reconstruire les dépendances
avec une plateforme Composer fixée à la version PHP minimale, puis tester
l'archive sur cette version.

## 3. Apports de la structure Webe.li

La cible ne doit pas reprendre toute la complexité du CMS. Elle doit en adopter
les habitudes qui facilitent réellement la maintenance :

- séparation `public/`, `src/Core/`, `src/Modules/`, `templates/`, `database/`,
  `storage/`, `tools/` et `tests/` ;
- configuration locale et données hors du webroot ;
- modules avec frontières explicites ;
- migrations incrémentales journalisées, checksums et sauvegarde préalable ;
- commandes unifiées pour diagnostiquer, migrer, sauvegarder, tester et empaqueter ;
- distinction dépôt source / archive de livraison ;
- qualification reproductible et rapports lisibles.

## 4. Matrice de reprise

| Élément | Source | Décision |
|---|---|---|
| Calcul de salaire | OCAS | Reprendre et renforcer les tests |
| Fiche figée / taux annuels | OCAS | Reprendre |
| Employés et employeur | OCAS | Reprendre, ajouter droits/PII |
| QR-facture / SCOR | OCAS | Reprendre après correction des dépendances |
| Journal en partie double | Journal | Reconcevoir en SQL |
| Grand livre / balance / bilan / résultat | Journal | Reprendre les règles, ajouter clôture |
| Liquidités | Journal | Reprendre avec rapprochement bancaire |
| Débiteurs / créanciers | Les deux | Unifier dans contacts + documents + allocations |
| Import bancaire / dédoublonnage | OCAS | Reprendre et rendre multi-format |
| Axes analytiques | OCAS | Reprendre au niveau des lignes comptables |
| Mandats / exercices | Journal | Reprendre comme dossiers isolés |
| Sécurité web | OCAS + Webe.li | Reprendre et compléter RBAC/audit |
| Migrations / qualification | Webe.li | Adapter en version légère |
| Module événements | OCAS | Hors MVP, extension ultérieure |

## 5. Sources normatives retenues

### Plan comptable

Le modèle initial est le **Plan comptable suisse PME
(Mattle/Helbling/Pfaff), version de référence publiée par veb.ch**, document
français daté du 12 août 2024 et diffusé via le portail PME de la Confédération :

`https://www.kmu.admin.ch/dam/fr/sd-web/ddOMnlBEN93Z/240812%20Schulkontenrahmen%20VEB%20-%20FR.pdf`

Le document précise que des comptes individuels peuvent être omis ou ajoutés,
mais que la structure doit être conservée. Toute copie ou adaptation livrée avec
l'application mentionne la source. Un profil « association » est donc un overlay
documenté sur la structure PME, pas une nomenclature incompatible.

### TVA

Les sources de référence sont les publications courantes de l'Administration
fédérale des contributions (AFC). Au moment de cette révision, les taux légaux
publiés depuis le 1er janvier 2024 sont 8,1 %, 2,6 % et 3,8 %. L'application ne
doit toutefois jamais supposer qu'ils sont éternels : taux, catégories, méthodes
et dates d'effet sont des données versionnées.

Le décompte en ligne est obligatoire et le portail AFC accepte l'import XML
eCH-0217 e‑TVA version 2.0.0. Le logiciel prépare et valide le décompte/export ;
l'utilisateur contrôle et transmet dans le portail officiel.

### Paiements

Les parseurs CAMT suivent les Swiss Payment Standards (SPS) publiés par SIX pour
`camt.053` (relevé) et `camt.054` (notifications débit/crédit). Le parseur
détecte le namespace et la version, tolère les versions encore supportées et
conserve le XML source pour audit, sans coder une seule version en dur.
