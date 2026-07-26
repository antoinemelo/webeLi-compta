# Lot 14c — Dossiers, initialisation et cycle de vie

Applique le prompt maître et pars du lot 14b validé. Ce lot précède `14d`,
`14e` et le lot 15.

## Objectif

Permettre de créer et gérer plusieurs dossiers dans une même organisation ou
dans des organisations différentes. Un dossier nouvellement créé doit être
immédiatement sélectionnable et utilisable, sans suite de commandes CLI.

## Implémente

- dans `Configuration > Organisations et dossiers`, une arborescence
  organisation → dossiers avec états actif/archivé ;
- la création transactionnelle d’un dossier avec nom, slug, type, devise de
  base et modules activés ;
- un assistant d’initialisation proposant :
  - variante de plan comptable ;
  - options association/projets/fonds affectés existantes ;
  - premier exercice et première période ;
  - journal général initial ;
  - installation des référentiels requis par les modules choisis ;
- la réutilisation exclusive des services existants (`ScopeManager`,
  `PlanSeeder`, configuration comptable, TVA, modules), sans SQL métier dans le
  contrôleur ;
- la modification du nom et des paramètres encore modifiables avec contrôle
  optimiste ;
- l’archivage, la réactivation et la suppression sûre d’un dossier ;
- le rafraîchissement du sélecteur global sans reconnexion après une mutation ;
- une vue de synthèse de l’initialisation : plan, exercice, période, journaux,
  modules et devise.

## Invariants

- la création et l’initialisation forment une seule transaction : aucun dossier
  partiellement initialisé ne subsiste après échec ;
- le slug est unique dans l’organisation ;
- type et devise de base ne changent plus dès qu’une donnée métier les rend
  historiques ; le refus explique la dépendance ;
- un dossier actif appartient à une organisation active ;
- archiver un dossier le retire des nouvelles sélections et mutations, sans
  rendre son historique destructible ;
- si le dossier courant est archivé, la session perd ce contexte et revient à
  un choix valide ;
- la suppression physique est réservée à un dossier sans donnée métier. Les
  seules lignes techniques créées par l’assistant peuvent alors être retirées
  transactionnellement ;
- aucune écriture validée ni archive métier n’est supprimée ou déplacée ;
- déplacer un dossier utilisé vers une autre organisation est interdit. Un
  dossier encore vide peut être recréé explicitement plutôt que ré-identifié.

## Droits

- `installation.admin` gère tous les dossiers ;
- `organisation.manage` peut créer et administrer les dossiers de sa seule
  organisation ;
- `dossier.manage` ne permet pas de créer un dossier frère ni d’élargir son
  propre périmètre ;
- le créateur ne reçoit aucun droit implicite au-delà de ceux déjà effectifs ;
  l’affectation des accès relève du lot 14d.

## Acceptation

- une même organisation contient deux dossiers réels créés depuis Vue ;
- chacun possède son propre plan, exercice, période, journaux et grand livre ;
- une panne simulée pendant l’assistant ne laisse aucun dossier incomplet ;
- les deux dossiers apparaissent dans le sélecteur sans reconnexion ;
- un dossier vide est supprimable, un dossier contenant une écriture validée
  est seulement archivable ;
- aucune donnée d’un dossier n’apparaît dans l’autre ;
- tests concurrence sur slug, versions, CSRF, IDOR, installation vierge et E2E
  du parcours complet.
