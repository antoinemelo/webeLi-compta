# Organisations et dossiers

Le registre Vue est disponible sous `/app/organisations-dossiers`, depuis
l’icône de filtre de l’en-tête. Il expose
une arborescence organisation → dossiers actifs ou archivés et permet de créer
plusieurs dossiers réellement exploitables dans la même organisation.

## Droits

- `installation.admin` voit toutes les organisations et peut en créer ou en
  supprimer une réellement vide ;
- `organisation.manage` voit et modifie uniquement les organisations qui lui
  sont attribuées et peut y créer ou administrer des dossiers ;
- `dossier.manage` permet d’administrer le dossier attribué, mais jamais de
  créer un dossier frère ni d’élargir son propre périmètre ;
- une création ou une réactivation n’accorde jamais de rôle implicitement ;
- un identifiant hors périmètre répond comme une ressource introuvable, sans
  divulguer son nom.

Les mutations exigent le jeton CSRF. Le nom usuel, le statut et l’identité
juridique utilisent la version optimiste de l’organisation.

## Gouvernance des accès

La section **Accès aux structures** de l’arborescence présente, pour chaque
utilisateur administrable, trois sources distinctes : rôle d’installation,
rôle hérité de l’organisation et rôle direct du dossier. Les permissions
effectives sont recalculées par le serveur ; un rôle d’organisation n’est
jamais recopié dans les dossiers.

Toute modification suit deux appels : prévisualisation des permissions
avant/après, puis confirmation avec l’empreinte de cette prévisualisation et
la version de la matrice. Un changement concurrent produit un conflit 409.
Le rejeu d’un état déjà appliqué est sans effet et ne crée aucun doublon.
L’audit conserve les rôles et permissions avant/après.

Un gestionnaire d’organisation ne voit que les utilisateurs déjà rattachés à
son organisation ou à l’un de ses dossiers. Seul l’administrateur
d’installation peut amorcer l’accès d’un compte encore étranger à ce
périmètre ou modifier les rôles d’installation. `dossier.manage` n’autorise
aucune auto-attribution. Une structure active conserve toujours au moins un
administrateur effectif ; le retrait du dernier exige de désigner un
successeur, auquel le rôle administrateur est transféré dans la même
transaction.

Après une révocation, `/api/v1/context` invalide le dossier courant et
`/api/v1/dossiers` le retire du sélecteur dès la requête suivante, y compris
dans une autre session déjà ouverte.

## Assistant de création d’un dossier

L’assistant demande le nom, le slug unique dans l’organisation, le type, la
devise de base, les modules, la variante du plan VEB, les options association,
le premier exercice, sa première période et le journal général.

Une option non cochée par défaut permet de choisir un dossier frère actif,
prévisualiser ses seules attributions directes puis confirmer exactement cette
matrice. Son empreinte est revérifiée dans la transaction de création. Une
modification de la source oblige à refaire l’aperçu ; les rôles hérités et les
groupes de consolidation ne sont jamais copiés.

Une seule transaction crée le dossier et initialise les modules, le plan
comptable, l’exercice, sa période, le journal général, les codes TVA et un
régime TVA initial lorsque Comptabilité est activée. Par prudence, ce régime
est `non_assujetti`, sans numéro TVA, et commence au premier jour de l’exercice ;
il reste modifiable depuis `Comptabilité > Clôture > TVA`. Une panne à
n’importe quelle étape annule l’ensemble : aucun dossier partiel ne reste
visible.

Le résumé final indique les nombres de comptes, exercices, périodes et
journaux, la devise et les modules actifs. Le sélecteur global est rechargé
immédiatement, sans reconnexion.

Le nom reste modifiable avec contrôle de version. Le type et la devise peuvent
encore changer tant que le dossier ne contient que les lignes techniques de
l’assistant ; dès qu’une donnée métier existe, ils deviennent historiques et
le refus énumère les dépendances.

## Identité juridique

Une organisation réelle est créée avec une première identité comportant au
minimum une date de début, une raison sociale et une source. Chaque évolution
ajoute une ligne à `attributs_juridiques_organisation` et ferme la précédente
à la veille de la nouvelle date. Les anciennes lignes ne sont ni réécrites ni
effacées pendant le cycle normal.

L’écran historique est la seule voie de mutation de la raison sociale, de la
forme juridique, de l’IDE et de l’adresse. L’onglet `Configuration > Entité`
les affiche en lecture seule et conserve la gestion des coordonnées
opérationnelles, de l’IBAN de facturation et de la devise du dossier.

## Archivage, réactivation et suppression

L’archivage est la sortie normale. Il est refusé tant qu’un dossier actif
appartient à l’organisation. La réactivation remet seulement `actif=1` et
n’altère aucun rôle.

La suppression physique est réservée à `installation.admin`. Le service
inspecte les clés étrangères SQLite et refuse l’opération dès qu’une dépendance
métier subsiste, en retournant les tables et leurs nombres de lignes. Les
dossiers archivés et les identités juridiques datées bloquent donc eux aussi la
suppression. Pour une organisation réellement vierge, les paramètres et rôles
de portée organisation sont nettoyés par leurs contraintes, puis
l’organisation est supprimée. L’événement d’audit demeure avec l’identifiant
de cible et son instantané `before`.

Il n’existe aucune suppression en cascade des dossiers, écritures, documents
financiers, consolidations ou événements d’audit.

Pour un dossier, l’archivage est également la sortie normale : il le retire des
nouvelles sélections et mutations sans toucher à son historique. S’il était
courant, le contexte de session est vidé. La réactivation exige une
organisation active.

Un dossier initialisé mais sans donnée métier peut être supprimé. Ses seules
lignes techniques (plan, rubriques, exercice, période, journal, modules et
codes/régimes TVA) sont retirées dans la même transaction. Toute écriture,
document, contact, consolidation ou autre donnée métier bloque la suppression
et impose l’archivage. Un dossier utilisé n’est jamais déplacé : un dossier
vide doit être recréé dans la bonne organisation.

## API et preuves

Les routes sont détaillées dans
[`contracts/api-v1/README.md`](contracts/api-v1/README.md). La liste accepte
`search`, `status=active|archived|all`, `page` et `per_page` (maximum 100).

Le cas d’intégration « registre des organisations et cycle de vie » couvre la
création sans droit implicite, la recherche, les conflits de version,
l’historique daté, les refus d’archivage et de suppression, la réactivation,
l’isolation d’un gestionnaire, la suppression vide et la conservation de
l’audit. La suite HTTP ajoute CSRF, validation d’une organisation réelle et
IDOR. Le cas « dossiers multiples et initialisation atomique » couvre deux
dossiers réels, leur isolation, le rollback simulé, les conflits de version,
les champs historiques et les suppressions sûre/refusée. Playwright crée deux
dossiers à 360 px, vérifie leur présence immédiate dans le sélecteur puis
archive et supprime le dossier vide. Le cas « gouvernance des accès aux
structures » couvre l’héritage, les rôles directs sur deux dossiers frères,
le rejeu idempotent, la copie exacte, l’isolation des utilisateurs, le dernier
administrateur et l’audit. Les preuves HTTP ajoutent CSRF, IDOR et révocation
du contexte/sélecteur ; Playwright exerce la révocation et la restauration
entre deux sessions navigateur.
