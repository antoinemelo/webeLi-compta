# Lot 14b — Registre et cycle de vie des organisations

Applique le prompt maître. Ce lot précède obligatoirement `14c`, `14d`, `14e`
et le lot 15.

## Objectif

Permettre à un administrateur d’installation de construire et maintenir le
registre des organisations depuis Vue, sans accès SQL ni commande manuelle.
Une organisation est une entité légale ou pédagogique stable ; son identifiant
ne change jamais.

## Implémente

- un espace Vue `Configuration > Organisations et dossiers`, visible avec
  `installation.admin`, listant aussi les organisations archivées ;
- une API JSON versionnée pour lister, créer, modifier, archiver, réactiver et,
  dans le seul cas sûr décrit ci-dessous, supprimer une organisation ;
- la création avec nom, nature `reelle|pedagogique`, identité juridique
  initiale datée et source obligatoire pour une organisation réelle ;
- la modification du nom d’usage avec contrôle optimiste par `version` ;
- les attributs juridiques via l’historique daté existant, sans réécrire une
  ancienne version ;
- audit avant/après pour chaque mutation et réponse explicite aux conflits de
  version ;
- pagination, recherche et filtre actif/archivé.

## Cycle de vie et effacement

- `archiver` est l’opération normale lorsqu’une organisation a déjà été
  utilisée ; aucune donnée métier n’est effacée ;
- l’archivage d’une organisation est refusé tant qu’elle possède un dossier
  actif ;
- la réactivation restaure la visibilité administrative, mais n’accorde aucun
  rôle supplémentaire ;
- la suppression physique est autorisée uniquement si l’organisation ne
  possède aucun dossier, aucune donnée métier et aucune dépendance autre que
  ses paramètres initiaux nettoyables dans la même transaction ;
- un refus de suppression énumère les dépendances qui imposent l’archivage ;
- aucune suppression en cascade d’écriture, document, paiement, salaire,
  justificatif, audit ou archive n’est permise.

## Droits et isolation

- seule la permission `installation.admin` permet de créer ou supprimer une
  organisation ;
- `organisation.manage` reste limité à l’organisation explicitement accordée
  et ne permet pas de découvrir les autres organisations ;
- les identifiants d’organisation envoyés par le client sont contrôlés côté
  service ; aucun simple filtrage Vue n’est considéré comme une protection ;
- aucune création d’organisation n’accorde implicitement un accès à un autre
  utilisateur.

## Acceptation

- un administrateur crée, renomme, archive et réactive une organisation depuis
  Vue, avec audit et versions vérifiés ;
- une organisation vide peut être supprimée sans résidu ;
- une organisation avec dossier actif ne peut être ni supprimée ni archivée ;
- un gestionnaire d’une organisation ne voit ni ne modifie une organisation
  voisine ;
- une identité juridique antérieure reste retrouvable après changement ;
- E2E à 360 px, clavier, messages d’erreur et confirmation destructive
  accessibles ;
- tests service, SQLite, contrat HTTP, CSRF, IDOR et installation vierge.
