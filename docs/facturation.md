# Facturation, contacts et échéancier

Les ventes, achats, paiements et lettrages acceptent les devises activées pour
le dossier. Le montant original et sa conversion figée sont affichés ensemble ;
les gains ou pertes réalisés proviennent exclusivement du lettrage. Voir
[`multidevise.md`](multidevise.md).

L’espace Vue `/app/facturation` est propre au dossier sélectionné. Il sépare
les ventes, achats, offres, commandes, récurrences, contacts et échéancier sans
créer de registre ou de moteur parallèle. L’ancienne route `/facturation`
redirige vers Vue.

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
passe par un avoir émis et comptabilisé ; l’original demeure dans l’historique.
Les factures fournisseurs exigent leur numéro externe, unique par fournisseur.

Les documents commerciaux précèdent facultativement la facture :

- offre client → commande client → facture client ;
- demande d’offre fournisseur → réponse fournisseur → commande fournisseur →
  facture fournisseur.

Une commande ou une facture peut toujours être créée directement. Une offre
client et une réponse fournisseur peuvent être acceptées ou refusées. Une
réponse fournisseur corrigée crée une nouvelle réponse reliée à l’ancienne ;
l’ancienne reste consultable avec le statut « remplacée ». Les préfixes sont
`OF`, `DOF`, `ROF`, `CC` et `CF`. Ces documents n’écrivent jamais dans le grand
livre : leurs positions exigent une quantité et un prix unitaire, mais aucun
compte comptable. Une répartition peut être préparée sur une commande ; elle
devient obligatoire lors de la conversion en facture. Le compte débiteur ou
créancier de la facture est présenté comme **Compte de paiement**. Une
conversion vers la facturation crée d’abord un brouillon modifiable. Les
offres, demandes, réponses et commandes restent consultables et imprimables,
y compris après facturation ou annulation. Les liens entre documents et entre
leurs lignes restent auditables.

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

Un modèle client ou fournisseur conserve la cadence, le contact, le compte
collectif et les lignes. La génération jusqu’à une date est idempotente par
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

Lorsque le paiement est entièrement lettré, l’interface le comptabilise dans le
grand livre. Le compte collectif doit être celui des factures allouées ; un
rejeu rend la même écriture.

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

## PDF et QR-facture

L’émission client crée une référence SCOR. Le PDF archivé présente une
hiérarchie claire de l’émetteur, du destinataire, des positions, du total et du
paiement, puis contient une QR-facture suisse avec son payload SPC. Le nom de
l’organisation/dossier tient lieu de signature visuelle. L’adresse du
créancier se configure dans l’identité de l’organisation. L’IBAN vient
exclusivement du compte de trésorerie actif sélectionné pour la facturation ;
aucun premier compte arbitraire ni IBAN libre n’est utilisé.

L’archive PDF, son empreinte SHA-256, le payload QR et les snapshots de contact
restent attachés au document.

## Permissions et retour arrière

La consultation exige `facturation.view`. Les brouillons, contacts et modèles
utilisent `facturation.manage`, l’émission/PDF `facturation.issue`, la
comptabilisation `facturation.post`, les paiements/allocations
`facturation.pay` et les rappels `facturation.remind`.

En développement, les tables de récurrence et l’idempotence des contacts font
partie de `database/migrations/001_initial.sql`. Une reconstruction doit être
précédée d’une sauvegarde de confort puis suivie de `db:integrity`. Après gel
de production, cette évolution devra être portée par une migration additive.
