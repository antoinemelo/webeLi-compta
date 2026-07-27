# Facturation, contacts et échéancier

Les ventes, achats, paiements et lettrages acceptent les devises activées pour
le dossier. Le montant original et sa conversion figée sont affichés ensemble ;
les gains ou pertes réalisés proviennent exclusivement du lettrage. Voir
[`multidevise.md`](multidevise.md).

L’espace Vue `/app/facturation` est propre au dossier sélectionné. Il sépare
les ventes, achats, récurrences, contacts et échéancier sans créer de registre
ou de moteur parallèle. L’ancienne route `/facturation` redirige vers Vue.

## Cycle documentaire

Un brouillon ne possède aucun numéro. L’émission attribue atomiquement un
numéro par dossier, année et série (`F-AAAA-NNN` pour une facture client), puis
fige l’identité, l’adresse, les lignes, les prix et les snapshots TVA. Le rejeu
de l’émission rend le même numéro. La comptabilisation produit une écriture
équilibrée et idempotente.

Avant émission, l’en-tête et les lignes du brouillon restent modifiables dans
la fenêtre de facture. Un avoir ne peut être créé qu’après l’émission de la
facture d’origine.

Une facture comptabilisée n’est jamais supprimée ni réécrite. Sa correction
passe par un avoir émis et comptabilisé ; l’original demeure dans l’historique.
Les factures fournisseurs exigent leur numéro externe, unique par fournisseur.

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
les paiements. La vue 360° rassemble documents, avoirs, paiements, créances,
dettes et aging du contact. La création depuis l’API exige une clé idempotente ;
son rejeu retourne le même identifiant sans dupliquer le contact.

## PDF et QR-facture

L’émission client crée une référence SCOR. Le PDF archivé contient une
QR-facture suisse avec son payload SPC. L’adresse du créancier se configure
dans l’identité de l’organisation ; l’IBAN vient de `iban_facturation` ou du
premier compte de trésorerie actif possédant un IBAN.

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
