# Qualification finale contradictoire

Date de qualification : 27 juillet 2026
Périmètre : lots 01 à 15 de `SPECS_V02`

## Verdict

Le candidat est **qualifiable pour livraison** : aucun écart de centime, fuite
de scope, mutation d'écriture validée, suppression de structure utilisée,
confusion entre agrégation et consolidation légale, migration destructive ou
dépendance de production interdite n'a été observé.

Deux affirmations dépassent ce qu'une recette automatisée locale peut certifier
et restent donc explicitement **NON VÉRIFIABLES** :

- la conformité intégrale WCAG 2.2 AA, qui requiert en complément un audit
  humain avec lecteurs d'écran et contrôle indépendant des contrastes ;
- la provenance absolue de chaque ligne de code. Le dépôt ne contient aucun
  marqueur Gäld détecté et les dépendances ont une licence compatible, mais une
  analyse statique ne constitue pas une preuve juridique exhaustive.

Ces limites ne masquent aucun échec des critères automatisables. Elles doivent
être conservées dans le dossier de livraison.

## Commandes de référence

```bash
php bin/console qualify
E2E_PORT=8098 npm --prefix frontend/admin-vue run test:e2e
php bin/console app:doctor
php bin/console db:migrate --plan
git diff --check
```

`qualify` couvre les hashes de migrations, la syntaxe PHP, les suites rapide et
d'intégration, une installation SQLite vierge, le rejeu des migrations, le
contrôle d'intégrité, les préconditions de production et le build Vue. Les
tests HTTP publics utilisent des données locales déterministes et ne dépendent
pas du réseau.

## Matrice contradictoire

| Exigence | Statut | Preuve et commande |
|---|---|---|
| Invariants comptables et rapports | RÉUSSI | Équilibre au centime, immutabilité des écritures validées, contre-passation, verrou de période et réconciliation des états : `php tests/run.php --suite=integration --case="comptabilité générale"` |
| TVA suisse | RÉUSSI | Méthodes effective/TDFN, snapshots de taux, écritures, arrondis et export eCH-0217 non présenté comme transmis : `php tests/run.php --suite=integration --case="TVA suisse"` |
| Paie | RÉUSSI | Parité des 32 calculs OCAS puis salaire mensuel/horaire, dettes, paiements, fiches et certificat annuel : `php tests/run.php --suite=quick --case="salaires OCAS"` et `php tests/run.php --suite=integration --case="salaires genevois"` |
| Immobilisations | RÉUSSI | Plans, dotations, cessions et écritures réconciliés : `php tests/run.php --suite=integration --case="immobilisations"` |
| Change | RÉUSSI | Montants persistés en unités entières, paiements partiels, écarts réalisés et réévaluation latente contre-passable : `php tests/run.php --suite=integration --case="multidevise"` |
| Agrégation et consolidation | RÉUSSI | Agrégation interne sans élimination, consolidation légale avec périmètre, mappings, éliminations hors livres et refus des usages ambigus : `php tests/run.php --suite=integration --case="multi-entités"` |
| Registre des organisations | RÉUSSI | Création, modification, archivage, réactivation et suppression physique limitée aux structures vides : `php tests/run.php --suite=integration --case="registre des organisations"` |
| Cycle de vie des dossiers | RÉUSSI | Initialisation atomique, plusieurs dossiers par organisation, archive consultable et suppression sûre : `php tests/run.php --suite=integration --case="dossiers multiples"` |
| Gouvernance des accès | RÉUSSI | Accès explicites, retrait immédiat, session concurrente et absence de fuite inter-scope : `php tests/run.php --suite=integration --case="gouvernance des accès"` |
| Installation et migrations | RÉUSSI | Construction depuis une base vide, migrations 001/002, rejeu sans effet et intégrité : `php bin/console qualify` |
| Restauration réelle | RÉUSSI | Une base est sauvegardée, la cible est remplacée, puis le marqueur et l'intégrité sont relus dans la base restaurée : `php tests/run.php --suite=integration --case="diagnostic"` |
| RBAC, CSRF et isolation | RÉUSSI | Refus des rôles insuffisants, jetons CSRF et accès inter-dossiers/inter-organisations : `php tests/run.php --suite=integration --case="HTTP et CSRF"` et `php tests/run.php --suite=integration --case="authentification"` |
| Uploads et traversée de chemin | RÉUSSI | Taille, MIME réel, liste blanche et réduction d'un nom `../../justificatif.pdf` à son basename ; contenu stocké hors webroot : `php tests/run.php --suite=integration --case="dépenses"` |
| XSS et injection | RÉUSSI | Échappement HTML, rendu Vue littéral d'un nom contenant `<script>`, absence d'exécution, tris SQL en liste blanche, DTD/XXE refusés : `php bin/console test --suite=quick` et `npm --prefix frontend/admin-vue run test:e2e -- --grep "registre des organisations"` |
| Concurrence SQLite | RÉUSSI | WAL, `busy_timeout`, exclusion de deux `BEGIN IMMEDIATE`, reprise après libération, idempotence et numérotation unique : `php tests/run.php --suite=integration --case="migrations et SQLite"` |
| Contrats API | RÉUSSI | Validation des contrats JSON, erreurs structurées et routes versionnées : `php bin/console qualify` |
| Clavier, focus et largeur 360 px | RÉUSSI | Navigation clavier, focus visible, dialogues et viewport mobile : `npm --prefix frontend/admin-vue run test:e2e -- --grep "navigation clavier"` |
| Impression | RÉUSSI | États en Courier New, chiffres alignés, devise dans le titre, contrôles de navigation masqués en média `print` : `npm --prefix frontend/admin-vue run test:e2e -- --grep "états, clôture"` |
| Conformité WCAG 2.2 AA complète | NON VÉRIFIABLE | Les critères automatisables précédents sont verts ; audit humain et technologies d'assistance externes requis pour une certification complète. |
| Build sans réseau d'exécution | RÉUSSI | Assets Vue et polices locales, aucun CDN ; la production sert le manifest construit sans Node : `php bin/console qualify` |
| Hébergement mutualisé PHP 8.2 | RÉUSSI | PDO SQLite, code et `vendor/` suffisent ; aucun Redis, PostgreSQL, worker, Docker ou Node requis à l'exécution : `php bin/console app:doctor` |
| Licences des dépendances | RÉUSSI | PHP : BSD-2-Clause, MIT et LGPL-3.0-or-later ; Vue, Pinia et Vue Router : MIT ; Bootstrap : MIT ; polices : OFL. |
| Absence de marqueur ou dépendance Gäld | RÉUSSI | Recherche statique du code et des manifests sans import, namespace, paquet ou marqueur Gäld. |
| Provenance juridique exhaustive du code | NON VÉRIFIABLE | Une expertise de provenance indépendante reste nécessaire pour transformer l'absence de marqueur en garantie juridique absolue. |

Les rares conversions `float` encore repérées concernent des heures, des
pourcentages validés ou la normalisation d'une source avant conversion à
échelle entière. Aucun montant monétaire n'est stocké ou calculé en flottant et
aucune colonne monétaire `REAL`, `FLOAT` ou `DECIMAL` n'est présente.

## Neuf scénarios transversaux

| # | Scénario | Statut | Preuve |
|---|---|---|---|
| 1 | Facture client partiellement payée | RÉUSSI | Aging, tableau de bord, lettrage, TVA et grand livre concordent dans les cas `débiteurs`, `projection du tableau de bord` et `TVA suisse`. |
| 2 | Facture fournisseur récurrente | RÉUSSI | Approbation, écriture, pain.001, CAMT et rapprochement idempotent dans les cas `dépenses`, `lettrage et paiements sortants` et `trésorerie`. |
| 3 | Salaires horaire et mensuel | RÉUSSI | Dettes, paiement, fiche et annuel concordent dans `salaires genevois, écritures et paiements`. |
| 4 | Clôture | RÉUSSI | Dotations, TVA, flux, bilan et résultat reproductibles ; période fermée refusée dans `comptabilité générale`, `immobilisations` et le parcours E2E des états. |
| 5 | Devise étrangère | RÉUSSI | Facture, deux paiements à des taux distincts et gain/perte équilibrés au centime dans `multidevise`. |
| 6 | Une organisation, deux dossiers | RÉUSSI | Agrégation réconciliable et drillable, sans mutation des livres sources, dans `multi-entités` et le parcours E2E d'agrégation interne. |
| 7 | Deux entités légales | RÉUSSI | Isolation, balance consolidée et éliminations réconciliées dans `multi-entités` et le parcours E2E de consolidation légale. |
| 8 | Cycle de vie | RÉUSSI | Structure vide supprimable ; structure utilisée seulement archivable et historique consultable dans les cas organisations/dossiers et leurs parcours E2E. |
| 9 | Exercice pédagogique | RÉUSSI | Données réelles invisibles et non réinitialisées dans `enseignement, collaboration et isolation` et son parcours E2E. |

Commande de rejeu globale :

```bash
php tests/run.php --suite=integration
E2E_PORT=8098 npm --prefix frontend/admin-vue run test:e2e
```

## Points d'exploitation

`app:doctor` signale que les extensions PHP `dom`, `xmlreader`, `xmlwriter`,
`simplexml` et `zip` ne sont pas installées sur la machine de qualification.
Elles sont recommandées, mais non bloquantes : la validation eCH dispose du
repli Java documenté et le ZIP de livraison est construit hors du processus
PHP. L'hébergeur doit néanmoins être contrôlé avant activation de ces fonctions.

Vite émet un avertissement de taille pour le bundle JavaScript, sans échec de
build. Il s'agit d'une optimisation future et non d'une dépendance de
production ni d'un défaut métier.

## Retour arrière

1. désactiver les mutations applicatives ;
2. conserver le ZIP, le commit qualifié et son empreinte SHA-256 ;
3. valider la sauvegarde SQLite avec `php bin/console db:integrity` ;
4. remplacer le code par le ZIP qualifié précédent ;
5. restaurer la base sauvegardée seulement si une migration avait été
   appliquée ;
6. rejouer `app:doctor`, `db:migrate --plan` et `db:integrity` avant réouverture.

Une migration déjà appliquée n'est jamais réécrite. Une écriture validée n'est
jamais modifiée pour réparer un résultat : elle est contre-passée.
