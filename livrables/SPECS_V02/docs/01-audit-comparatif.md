# Audit comparatif

## Périmètre et preuves

COMPTA a été audité dans `/home/amelo/Documents/DEV/Ecol_WebeLi/web/compta`.
Le dépôt ne contient encore aucun commit : tous les fichiers sont non suivis
sur `main`. Cela n'altère pas le code, mais constitue un risque majeur de
traçabilité avant toute évolution.

Gäld a été audité depuis `Scanix/Gaeld`, branche `develop`, commit
`3b8811d7da2d4c02b28b812fc056eca0532039f4` du 10 juin 2026. L'audit a porté
sur le code, les migrations, les routes, les tests, les pages Vue et la CI ; il
ne se limite pas au site marketing.

Vérifications COMPTA effectuées :

- les 72 fichiers PHP de `src/` passent `php -l` ;
- `php bin/console test` passe 342 assertions sans échec sur les familles
  sécurité, paie, pédagogie, migrations, scopes, comptabilité, trésorerie, TVA,
  facturation, HTTP et exploitation ;
- une base vierge temporaire migre correctement de 001 à 010 avec sauvegarde ;
- `app:doctor` et `db:integrity` réussissent sur cette base ; les extensions XML
  et ZIP sont seulement signalées comme absentes dans l'environnement d'audit ;
- la base de travail annonce les migrations 001 à 010 comme appliquées.

Les tests de Gäld n'ont pas été réexécutés localement : son environnement exige
Laravel, PostgreSQL, Redis et ses dépendances. Le dépôt contient 170 fichiers de
tests et une CI qui construit Vue, lance Pint, PHPStan, les migrations
PostgreSQL et PHPUnit.

## COMPTA : forces à préserver

- Partie double centralisée, contrôle d'équilibre au centime, scopes
  organisation/dossier, idempotence et contre-passation.
- Plan VEB configurable, ouvertures, périodes, journaux, grand livre, balance,
  bilan et résultat.
- SQLite correctement configuré : clés étrangères, WAL, `busy_timeout`,
  migrations avec checksums et sauvegarde obligatoire.
- Imports PostFinance et CAMT 053/054, conservation de la source, doublons,
  rapprochements 1–1, 1–N et N–1.
- TVA effective/TDFN, taux datés, snapshots, encaissements, rectificatifs et
  export eCH-0217.
- Factures clients et fournisseurs, avoirs, contacts multi-rôles, paiements et
  allocations N–N, QR-facture et SCOR.
- Paie genevoise reproductible, montants en centimes, heures en millièmes,
  dettes sociales, paiements et certificats.
- Exercices pédagogiques clonés et isolés, indices, tentatives, correction et
  conflits optimistes.

## Source salariale Lasso confirmée

Le projet Lasso sous `/home/amelo/Documents/DEV/Ecol_WebeLi/web/lasso` est la
source de reprise des paramètres de charges salariales. Les éléments
déterminants sont :

- `lib/calc.php` : clés `TAUX_DEFAUT`, sélection annuelle
  `taux_stockes()`/`taux_pour_annee()` et règle LAA réduit/plein ;
- la table SQLite `taux_par_annee`, définie dans `lib/db.php` ;
- `views/taux.php` : libellés et regroupements opérateur ;
- `tests/calc_test.php` : résultats historiques à conserver.

La copie auditée ne contient pas `data/database.sqlite`. L'import doit donc lire
la base effectivement configurée par `APP_DB_PATH` lorsqu'elle est fournie ; il
ne doit pas prétendre avoir repris des valeurs annuelles absentes. Les constantes
de `TAUX_DEFAUT` servent de référence de compatibilité, pas de vérité légale
permanente.

## COMPTA : écarts réels

1. Le tableau de bord n'expose aucun indicateur : il sélectionne un dossier et
   affiche des raccourcis.
2. Le module Trésorerie existe côté services et CLI, mais n'a ni écran ni routes
   web.
3. Le web est concentré dans `WebApplication.php` (2 567 lignes). Les plus gros
   services dépassent aussi 700 à 1 200 lignes. Les domaines existent dans les
   noms, pas encore dans les frontières HTTP.
4. Il n'existe pas d'API JSON interne ni d'application Vue. Les 13 templates PHP
   regroupent de nombreux formulaires sur quelques pages.
5. Manquent : dépenses récurrentes, factures récurrentes, échéancier aging,
   pain.001, flux de trésorerie, immobilisations/amortissements, salaires
   mensuels explicites, décomptes annuels complets et déclaration fiscale
   assistée.
6. Les relevés savent lire une devise et les QR-factures acceptent CHF/EUR, mais
   le grand livre n'est pas multi-devise ; les transferts multi-devises sont
   explicitement refusés.
7. Plusieurs organisations existent, mais pas la consolidation ni la
   réconciliation inter-entités.
8. Les contacts ne doivent pas être dupliqués entre Facturation et
   Configuration : un seul registre doit être exposé par plusieurs liens.
9. La migration 004 mélange TVA, facturation, salaires et pédagogie dans un
   fichier très volumineux. Elle reste historique et ne doit pas être modifiée.

## Gäld : apports pertinents

- Découpage par domaines et séparation des écritures (`LedgerService`) des
  projections de lecture (`LedgerQueryService`, Reporting).
- Vue 3 avec composants réutilisables, états vides, tables, dialogues,
  navigation secondaire et pages dédiées.
- Tableau de bord regroupant trésorerie, revenus, charges, impayés, aging,
  activité récente, budget et TVA.
- Workflows explicites : brouillon, approbation, comptabilisation, annulation.
- Dépenses et récurrences, rapprochement, règles de suggestion, paiements
  sortants pain.001, lettrage, immobilisations et amortissements.
- Factures récurrentes, contacts partagés, aging, clôture guidée, archives,
  centres de coûts, multi-devise et consolidation optionnelles.
- DTO, requêtes de validation, politiques, tests de sécurité horizontale et
  composants d'interface cohérents.

## Gäld : éléments à ne pas transposer

- Laravel 13, Inertia, Eloquent, PostgreSQL, Redis, Horizon, Scout/Meilisearch,
  Docker obligatoire et services SaaS.
- Son système de cache distribué et ses jobs asynchrones comme prérequis.
- Sa surface API, webhooks, facturation SaaS, OCR et recherche globale avant les
  parcours comptables essentiels.
- Le stockage décimal de montants dans certains modèles : COMPTA garde les
  centimes entiers et ses règles d'arrondi éprouvées.
- Une copie de code AGPL. Les idées et parcours peuvent inspirer ; le code ne
  doit pas être repris sans décision de licence explicite.
- La complexité DDD complète. COMPTA a besoin de frontières nettes, pas d'une
  classe par opération ou d'une couche d'abstraction sans bénéfice.

## Conclusion

Le bon projet n'est ni l'ancien écran COMPTA conservé indéfiniment, ni un fork
de Gäld. C'est le moteur COMPTA derrière des contrats internes stables, des
projections de lecture et une interface Vue progressive. La migration commence
par figer les invariants et versionner l'état actuel, puis remplace les écrans
par parcours sans déplacer la source de vérité comptable.
