# Organisations et dossiers

Le registre Vue est disponible sous `/app/configuration/structures`. Le lot
14b couvre le cycle de vie des organisations ; le cycle de vie détaillé des
dossiers sera ajouté au lot 14c.

## Droits

- `installation.admin` voit toutes les organisations et peut en créer ou en
  supprimer une réellement vide ;
- `organisation.manage` voit et modifie uniquement les organisations qui lui
  sont attribuées ;
- une création ou une réactivation n’accorde jamais de rôle implicitement ;
- un identifiant hors périmètre répond comme une ressource introuvable, sans
  divulguer son nom.

Les mutations exigent le jeton CSRF. Le nom usuel, le statut et l’identité
juridique utilisent la version optimiste de l’organisation.

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

## API et preuves

Les routes sont détaillées dans
[`contracts/api-v1/README.md`](contracts/api-v1/README.md). La liste accepte
`search`, `status=active|archived|all`, `page` et `per_page` (maximum 100).

Le cas d’intégration « registre des organisations et cycle de vie » couvre la
création sans droit implicite, la recherche, les conflits de version,
l’historique daté, les refus d’archivage et de suppression, la réactivation,
l’isolation d’un gestionnaire, la suppression vide et la conservation de
l’audit. La suite HTTP ajoute CSRF, validation d’une organisation réelle et
IDOR. Playwright couvre le parcours opérateur complet.
