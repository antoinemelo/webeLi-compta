# Facturation, contacts et échéancier

Les ventes, achats, paiements et lettrages acceptent les devises activées pour
le dossier. Le montant original et sa conversion figée sont affichés ensemble ;
les gains ou pertes réalisés proviennent exclusivement du lettrage. Voir
[`multidevise.md`](multidevise.md).

L’espace Vue `/app/facturation` est propre au dossier sélectionné. Il s’ouvre
sur **Échéancier**, puis présente **Offres / Commandes / Achats / Ventes /
Récurrences / Contacts**, sans créer de registre ou de moteur parallèle.
L’ancienne route `/facturation` redirige vers Vue. L’export de la vue reste
aligné à droite de la barre d’onglets.

## Cycle documentaire

Un brouillon ne possède aucun numéro. L’émission attribue atomiquement un
numéro par dossier, année et série (`FV-AAAA-NNN` pour une facture client), puis
fige l’identité, l’adresse, les lignes, les prix et les snapshots TVA. Le rejeu
de l’émission rend le même numéro. La comptabilisation produit une écriture
équilibrée et idempotente.

Avant émission, l’en-tête et les lignes du brouillon restent modifiables dans
la fenêtre de facture. Un avoir ne peut être créé qu’après l’émission de la
facture d’origine.

Une facture comptabilisée n’est jamais supprimée ni réécrite. Sa correction
passe soit par un avoir émis et comptabilisé, soit par une extourne ;
l’original demeure dans l’historique.
Sans lettrage, l’extourne contre-passe exactement l’écriture d’origine. Avec un
lettrage actif, elle conserve les allocations et contre-passe uniquement le
solde encore ouvert, y compris sa part proportionnelle de TVA. La facture est
alors soldée et son solde ouvert passe à zéro. Un avoir déjà émis interdit
toujours l’extourne. Les éventuels brouillons d’avoir liés sont annulés.
Les factures fournisseurs exigent leur numéro externe, unique par fournisseur.

Les documents commerciaux précèdent facultativement la facture :

- offre client → commande client → facture client ;
- offre fournisseur → commande fournisseur → facture fournisseur.

Une commande ou une facture peut toujours être créée directement. Une offre
client ou fournisseur peut être acceptée ou refusée. Une offre fournisseur
corrigée crée une nouvelle offre reliée à l’ancienne ; l’ancienne reste
consultable avec le statut « remplacée ». Les préfixes sont `OC` pour les
offres clients, `OF` pour les offres fournisseurs, `CC` et `CF` pour les
commandes. Ces documents n’écrivent jamais dans le grand
livre : leurs références exigent une quantité et un prix unitaire, mais aucun
compte comptable. Une répartition peut être préparée sur une commande ; elle
devient obligatoire lors de la conversion en facture. Le compte débiteur ou
créancier de la facture est présenté comme **Compte de paiement**. Une
conversion vers la facturation crée d’abord un brouillon modifiable. Les
offres, demandes, réponses et commandes restent consultables et imprimables
dans la même présentation que les factures, y compris après facturation ou
annulation. Les liens entre documents et entre leurs lignes restent auditables,
y compris après la modification d’une commande créée depuis une offre.

Les listes conservent leurs actions sous un menu vertical « ⋮ » nommé pour la
ligne : consulter, convertir, comptabiliser, créer un avoir, extourner, produire le PDF,
annuler ou archiver selon l’état. Une commande suit explicitement le parcours
**Brouillon → Envoyée → Livrée → Facturée**. L’envoi et la confirmation de
livraison sont deux actions distinctes ; le bouton de facturation n’apparaît
qu’après la livraison, et le serveur refuse également toute facturation
anticipée. Une commande livrée reste consultable et annulable, et sa
facturation ne la retire jamais de l’historique. Cliquer sur la référence
ouvre le brouillon en modification ou le document stabilisé en consultation.
Le nom du contact ouvre sa fiche 360°.
Après une conversion, l’interface ouvre directement **Commandes**, **Ventes**
ou **Achats** selon le document créé. Les colonnes des offres, commandes,
achats, ventes, récurrences et contacts sont triables dans les deux sens.
Une offre fournisseur est enregistrée directement, sans demande préalable
distincte. Plusieurs offres du même fournisseur coexistent avec leurs propres
références.

## TVA et échéance

Le régime TVA est daté par dossier. Lorsqu’il est « non assujetti », le parcours
de vente ne présente ni code ni calcul TVA. Une facture fournisseur est alors
saisie TVA comprise et la totalité du montant est comptabilisée en charge,
sans impôt préalable récupérable.

La condition de paiement client ou fournisseur est résolue selon la date du
document. Elle préremplit l’échéance explicite. En l’absence de défaut
applicable, le formulaire conserve la saisie manuelle et renvoie directement
vers `Configuration > Paiements` pour en créer un.

## Récurrences

Un modèle client ou fournisseur conserve la cadence, le contact, le compte de
paiement et les lignes. La génération jusqu’à une date est idempotente par
modèle et date d’échéance. Elle crée uniquement un brouillon sans numéro :
l’opérateur doit encore le contrôler, l’émettre et le comptabiliser.

Les mois courts conservent le jour de référence dans la limite du dernier jour
du mois. Une suspension est réversible ; un modèle terminé ne peut pas être
réactivé implicitement.

## Paiements et aging

Un paiement existe indépendamment des factures. Les allocations N–N permettent
de répartir plusieurs paiements sur une facture ou un paiement sur plusieurs
factures. La somme allouée ne peut dépasser ni le paiement ni le solde du
document, même d’un centime.

Le contact indicatif du paiement ne limite pas ses allocations : chaque
facture conserve son propre contact. En revanche, toutes les allocations
doivent partager le sens, la devise et le compte de paiement.

Le déclencheur configuré dans **Configuration > Paiements** comptabilise le
paiement au premier lettrage ou au lettrage complet. Une association bancaire
le comptabilise immédiatement. Le lien unique vers l’écriture rend tout rejeu
idempotent ; un paiement créé depuis le journal est déjà comptabilisé.

L’échéancier exige une date de référence visible :

- non échu : échéance postérieure à la date de référence ;
- 0–30 jours : échéance du jour incluse jusqu’au trentième jour ;
- 31–60 jours ;
- 61–90 jours ;
- plus de 90 jours.

Un avoir non alloué apparaît négativement dans sa tranche. Un paiement non
alloué réduit le solde net du contact, mais reste affiché séparément comme
acompte : il n’est pas artificiellement vieilli. L’export CSV reprend les
filtres et inscrit la date de référence sur chaque ligne.

Sous le tableau, un graphique compare les créances et les dettes dans ces cinq
tranches. Il reprend exactement les mêmes montants, affiche leurs valeurs
signées et conserve le tableau comme restitution accessible.

## Contact 360°

Le registre de contacts est unique et partagé avec Configuration, Liquidités et
les paiements. La vue 360° rassemble offres, demandes d’offre, commandes,
factures, avoirs, paiements, créances, dettes et aging du contact. Les listes
affichent aussi les nombres d’offres et de commandes actives. La création depuis
l’API exige une clé idempotente ; son rejeu retourne le même identifiant sans
dupliquer le contact.

Une personne peut être indépendante ou reliée à une entreprise du même
dossier. La recherche utilise la raison sociale, le prénom et le nom. Un
contact sans dépendance est supprimé physiquement ; dès qu’un document, un
paiement ou une autre référence existe, il est archivé. Les filtres
**Actifs / Archivés / Tous** permettent ensuite de le consulter et de le
réactiver depuis Facturation comme depuis Configuration.

La création et la modification d’un contact s’effectuent dans une fenêtre
modale. Le rattachement d’une personne à une entreprise est facultatif. Le
profil 360° donne accès aux offres, demandes, commandes, factures, avoirs et
paiements ; la section des pièces financières est nommée **Factures**. Cliquer
sur une référence télécharge directement le PDF du document commercial, de la
facture ou du paiement sans fermer ni remplacer la fiche contact. Les tableaux
indiquent aussi le nombre d’offres et de commandes encore actives.

## PDF et QR-facture

L’émission client crée une référence SCOR. Le PDF archivé présente une
hiérarchie claire de l’émetteur, du destinataire, des références, du total et du
paiement, puis contient une QR-facture suisse avec son payload SPC. Le nom de
l’organisation/dossier tient lieu de signature visuelle. L’adresse du
créancier se configure dans l’identité de l’organisation. L’IBAN vient
exclusivement du compte de trésorerie actif sélectionné pour la facturation ;
aucun premier compte arbitraire ni IBAN libre n’est utilisé.

L’archive PDF, son empreinte SHA-256, le payload QR et les snapshots de contact
restent attachés à la facture. Les offres, demandes et commandes utilisent le
même langage visuel et sont générées à la demande sans écriture comptable. Le
justificatif PDF d’un paiement récapitule ses factures allouées et son solde
encore non affecté.

La consultation à l’écran d’un achat ou d’une vente présente séparément
l’identité de la pièce, son contact ouvrable, ses dates et états, le total,
la part lettrée, le solde ouvert, les bases nettes et TVA par ligne, ainsi que
les références de paiement, d’écriture et de change disponibles.
Chaque ligne reste visible après l’émission et la comptabilisation avec sa
désignation, sa quantité, son prix unitaire et son mode de saisie. La même
information figure sur le PDF. Pour un achat saisi TVA comprise sous le régime
« Sans TVA · non assujetti », le code TVA du fournisseur et la saisie nette ou
brute restent disponibles. Le prix est explicitement signalé comme « TVA
comprise · non récupérable » : la TVA figure sur la pièce, mais aucune part
n’est récupérée ni isolée en comptabilité.

## Permissions et retour arrière

La consultation exige `facturation.view`. Les brouillons, contacts et modèles
utilisent `facturation.manage`, l’émission/PDF `facturation.issue`, la
comptabilisation `facturation.post`, les paiements/allocations
`facturation.pay` et les rappels `facturation.remind`.

Les tables de récurrence et l'idempotence des contacts appartiennent au socle
`001_initial.sql`, au sein de la couverture immuable `001` à `008` de la
version 0.6.1. Toute évolution structurelle suivante reçoit une migration
additive à partir de `009`. La mise à niveau sauvegarde la base et reste suivie
de `db:integrity`; voir [`migrations.md`](migrations.md).
