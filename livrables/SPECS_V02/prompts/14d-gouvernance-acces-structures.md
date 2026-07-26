# Lot 14d — Gouvernance des accès aux structures

Applique le prompt maître et pars des lots 14b et 14c validés. Ce lot précède
`14e` et le lot 15.

## Objectif

Rendre administrables les accès aux organisations et dossiers nouvellement
créés, sans droit implicite, escalade de privilège ni blocage involontaire du
dernier administrateur.

## Implémente

- une section « Accès » dans l’arborescence des structures ;
- la liste des utilisateurs et rôles effectifs pour une organisation ou un
  dossier, en distinguant clairement :
  - rôle d’installation ;
  - rôle hérité de l’organisation ;
  - rôle direct du dossier ;
- l’attribution et le retrait des rôles d’organisation et de dossier via les
  tables RBAC existantes ;
- une prévisualisation des permissions effectives avant confirmation ;
- l’audit avant/après, le contrôle optimiste et des opérations idempotentes ;
- la révocation immédiate du contexte de session devenu inaccessible ;
- une option explicite, lors de la création d’un dossier, pour recopier une
  matrice d’accès depuis un dossier de la même organisation. Cette copie est
  prévisualisée, confirmée et auditée ; elle n’est jamais automatique.

## Garde-fous

- un utilisateur ne peut attribuer que des rôles qu’il est autorisé à
  administrer et uniquement dans son périmètre ;
- seul `installation.admin` attribue ou retire un rôle d’installation ;
- `organisation.manage` administre les rôles de son organisation et de ses
  dossiers, sans découvrir les autres organisations ;
- `dossier.manage` ne s’auto-attribue aucun droit supplémentaire ;
- le dernier administrateur effectif d’une structure active ne peut pas être
  retiré sans transfert explicite ;
- un rôle d’organisation ne doit jamais être matérialisé silencieusement comme
  plusieurs rôles dossier ;
- un groupe de consolidation ne crée et ne propage toujours aucun droit.

## Acceptation

- un administrateur donne à un comptable l’accès à deux dossiers frères, puis
  retire un seul de ces accès ;
- le retrait prend effet sur l’API et le sélecteur dès la requête suivante ;
- la copie prévisualisée des accès produit exactement les rôles confirmés ;
- le dernier administrateur ne peut pas se retirer sans successeur ;
- un gestionnaire d’organisation ne liste aucun utilisateur/rôle d’une autre
  organisation ;
- les tests couvrent héritage, rôle direct, doublon idempotent, révocation,
  CSRF, IDOR et E2E multi-session.
