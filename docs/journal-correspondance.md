# Correspondance avec le programme Journal

Le programme historique étudié est
`/home/amelo/Documents/DEV/Ecol_WebeLi/web/journal/Compta.py`, avec les fichiers
de référence de `config/02_Start/`. Son organisation fonctionnelle reste la
référence pour les gestes quotidiens ; COMPTA en conserve la logique tout en
remplaçant les fichiers texte et nombres flottants par des contrôles
transactionnels.

| Journal historique | COMPTA |
|---|---|
| mandat | organisation / dossier sélectionné |
| `plan_comptable.txt` | plan éditable propre au dossier |
| `comptes.txt` | types et rubriques structurelles configurables |
| `moinsplus.txt` | préfixes `--/++` et exceptions par compte |
| `soldes_initiaux.txt` | brouillon et validation d’ouverture |
| `journal.txt` | écritures et lignes en partie double |
| Journalisation | saisie simple débit / crédit / libellé / montant |
| opérations composées | saisie avancée à plusieurs lignes |
| extrait de compte | vue en liste ou compte en T |
| Grand Livre | extraits et balances de vérification, avec mouvements, totaux et solde naturel |
| `totaux.txt` | bilan et résultat selon la structure du plan |
| `facture.txt` et fichiers ouverts | module Facturation et allocations N–N |

## Parcours repris

L’entrée **Comptabilité** rassemble désormais les mêmes gestes que la fenêtre
principale historique :

1. journaliser une opération ;
2. choisir un compte et lire son extrait ;
3. consulter les balances de vérification et ouvrir un extrait par compte ;
4. préparer les soldes initiaux ;
5. ouvrir le bilan et le compte de résultat ;
6. configurer le plan du dossier ;
7. archiver les états et le journal complet d’un exercice.

La journalisation simple garde l’ordre de travail familier : compte au débit,
compte au crédit, libellé et montant. Le journal présente les numéros des
comptes débités et crédités et les valeurs en CHF. Depuis un extrait, l’action
« Nouvelle opération liée à ce compte » prépositionne le compte du côté de son
fonctionnement normal.

Une référence `FV-*` ou `FA-*` ouvre sa facture dans Facturation. Une référence
`DEP-*`, issue du workflow de dépense, ouvre au contraire son détail sous
**Liquidités > Dépenses** afin de ne jamais la confondre avec une facture.

## Rigueur ajoutée

Les comportements fragiles du fichier historique ne sont pas reproduits :

- les montants utilisent des centimes entiers, jamais des flottants ;
- les dates sont de vraies dates ISO et doivent appartenir à une période
  ouverte ;
- les comptes, journaux et exercices sont vérifiés dans le dossier actif ;
- une écriture composée doit avoir au moins deux lignes et rester équilibrée ;
- une écriture validée est numérotée, immuable et contre-passée plutôt
  qu’effacée ;
- les droits, jetons CSRF, versions concurrentes et événements d’audit sont
  contrôlés côté serveur ;
- le suivi des factures appartient au module Facturation, sans fichiers
  sentinelles ni déduction depuis un nom de fichier.

Cette adaptation ne nécessite aucune migration : elle exploite le modèle
comptable déjà validé.
