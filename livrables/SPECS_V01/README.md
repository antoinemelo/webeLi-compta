# Dossier de conception — Compta / Salaires / Enseignement

Date de l'analyse : 25 juillet 2026  
Révision : 2 — décisions métier confirmées le 25 juillet 2026  
Sources étudiées :

- `/home/amelo/Documents/DEV/Ecol_WebeLi/web/journal`
- source OCAS configurée par `OCAS_DB_PATH`
- `/home/amelo/Documents/DEV/Ecol_WebeLi/web/mod` comme référence de structure et d'exploitation Webe.li

## Conclusion courte

La cible recommandée est un **monolithe modulaire PHP 8.2+ / SQLite**, rendu côté
serveur, sans SPA ni étape de compilation obligatoire. Elle reprend presque
intégralement le domaine « salaires » de l’OCAS, mais remplace sa comptabilité de
caisse par un vrai journal en partie double inspiré de `journal/Compta.py`.

La source de vérité comptable est composée d'écritures équilibrées et immuables.
Les mouvements bancaires, factures débiteurs, factures créanciers et décomptes de
salaire se rapprochent de ces écritures sans jamais les remplacer.

Le mode d'enseignement utilise exactement le même moteur comptable que le mode
réel, dans des dossiers isolés, réinitialisables et identifiés sans ambiguïté
comme exercices.

## Contenu

- `docs/00-analyse-des-sources.md` : audit des deux projets et décisions de reprise.
- `docs/01-vision-perimetre.md` : vision, utilisateurs, MVP et hors périmètre.
- `docs/02-specifications-fonctionnelles.md` : parcours et exigences détaillés.
- `docs/03-modele-comptable.md` : règles de partie double et invariants.
- `docs/04-tva-suisse.md` : TVA suisse opérationnelle dès la première version.
- `docs/04-architecture-et-donnees.md` : structure PHP, modules et modèle de données.
- `docs/05-securite-exploitation.md` : sécurité, sauvegarde, migration et livraison.
- `docs/06-roadmap-recette.md` : lots, critères d'acceptation et stratégie de tests.
- `docs/07-decisions-confirmees.md` : décisions métier confirmées.
- `prompts/` : prompts ordonnés pour faire réaliser l'application par un agent.

## Ordre d'utilisation des prompts

1. Donner `prompts/00-prompt-maitre.md` à l'agent et lui fournir tout ce dossier.
2. Exécuter les prompts `01` à `11` dans l'ordre, un lot par branche/PR.
3. Exiger une validation complète à la fin de chaque lot.
4. Utiliser `12-revue-finale.md` sur une nouvelle session ou un agent distinct.

Les prompts sont volontairement conçus pour produire de petits incréments
vérifiables. Aucun prompt n'autorise la reconstruction destructive d'une base
réelle.

## Choix structurants recommandés

- PHP 8.2 comme minimum réel, testé en CI ; SQLite 3 avec clés étrangères et WAL.
- Montants stockés en centimes entiers, jamais en flottants.
- Plusieurs organisations par installation, sans consolidation.
- Hiérarchie : installation → organisation → dossier/mandat → exercice.
- Une base par installation ; tous les objets métier sont isolés par organisation
  et dossier.
- Dossiers de type `reel`, `demo` ou `exercice`, avec isolation applicative stricte.
- Une même installation peut contenir dossiers réels et scolaires, avec barrières
  visuelles, permissions et interdictions de réinitialisation.
- Déploiement autonome dans différents sous-répertoires ; aucun chemin racine,
  domaine ou nom de cookie n'est codé en dur.
- Écriture comptable validée immuable ; correction par contre-passation.
- États de facture dérivés des échéances et allocations de paiement.
- Imports bancaires idempotents, prévisualisés avant validation.
- TVA suisse opérationnelle au MVP, règles et taux datés.
- Sauvegarde vérifiée avant migration ; migrations SQL numérotées et journalisées.
- HTML serveur accessible, un peu de JavaScript progressif, aucun CDN.

## Avertissement métier

Le logiciel peut faciliter une comptabilité et une paie suisses, mais les taux,
barèmes, obligations déclaratives et formats officiels évoluent. Toute mise en
production doit faire valider les paramètres annuels et les exports réglementaires
par la personne compétente (fiduciaire, caisse, administration).
