# COMPTA — cible progressive inspirée de Gäld

Révision 3.1 — audit du 25 juillet, addendum du 26 juillet 2026

Ce dossier remplace les hypothèses de la révision précédente sur l'interface et
le périmètre futur. Il ne demande ni réécriture du moteur COMPTA, ni Laravel, ni
PostgreSQL. La cible est un monolithe modulaire :

- moteur et données COMPTA conservés ;
- PHP 8.2+ et SQLite conservés pour l'hébergement mutualisé ;
- Vue 3, TypeScript et Vite adoptés progressivement selon le modèle du CMS (sous /home/amelo/Documents/DEV/Ecol_WebeLi/web/mod/);
- API JSON interne, versionnée et petite, sans reproduire Inertia ou Eloquent ;
- base SQLite canonique reconstruisible en développement, puis migrations
  additives après le gel de production ;
- modules métier alimentant tous le même grand livre.
- taux et paramètres de charges salariales repris prioritairement de Lasso,
  puis convertis au format entier et versionné de COMPTA.

## Contenu

- `docs/01-audit-comparatif.md` : constat vérifié sur COMPTA et Gäld.
- `docs/02-cible-fonctionnelle.md` : navigation et périmètre corrigés.
- `docs/03-architecture-cible.md` : architecture PHP/SQLite/Vue.
- `docs/04-donnees-et-migrations.md` : stratégie de conservation des données.
- `docs/05-roadmap-et-recette.md` : ordre, risques et portes de qualité.
- `docs/06-matrice-reprise-gaeld.md` : ce qui est repris, adapté ou refusé.
- `docs/07-sources.md` : sources locales et publiques effectivement consultées.
- `prompts/00-prompt-maitre.md` : garde-fous à fournir dans chaque session.
- `prompts/01` à `14` : lots d'implémentation ordonnés.
- `prompts/15-revue-finale.md` : audit contradictoire avant livraison.

## Mode d'emploi

1. Conserver ce dossier dans le dépôt pendant toute la réalisation.
2. Donner d'abord `00-prompt-maitre.md` à l'agent.
3. Exécuter les prompts dans l'ordre, un lot par branche ou changement
   atomique. Ne jamais lancer plusieurs lots qui modifient le schéma en parallèle.
4. Exiger les critères d'acceptation et les commandes de preuve avant de passer
   au lot suivant.
5. Jusqu'au gel de production, maintenir une base initiale propre et
   reconstruire les données de développement. Après le gel, une migration
   appliquée n'est jamais réécrite.

La consolidation et la multi-devise sont volontairement placées après les
parcours mono-entité en CHF. Elles sont importantes, mais ne doivent pas
fragiliser le socle quotidien.
