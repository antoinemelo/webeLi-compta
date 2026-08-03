# Banque, lettrage et paiements sortants

L’onglet « Taux », placé après « Paiements », regroupe les taux de change et
les taux d’intérêt publics. Son cache SQLite est global à l’instance et ne
duplique pas les données entre dossiers. Voir
[`donnees-marche-publiques.md`](donnees-marche-publiques.md).

L’espace Vue **Liquidités** sépare volontairement trois réalités :

1. le relevé bancaire importé, qui constate les mouvements de la banque ;
2. le grand livre, qui porte les écritures comptables validées ;
3. le lettrage, qui explique comment un paiement solde un ou plusieurs
   documents.

Cette séparation n’empêche pas l’intégration : chaque paiement porte une
origine et, lorsqu’il est comptabilisé, l’identifiant unique de son écriture.
Le même paiement ne peut donc jamais être comptabilisé deux fois.

## Rapprochement bancaire

Sous `/app/liquidites/rapprochement`, l’opérateur choisit un compte de
trésorerie et un relevé CAMT ou PostFinance.

Le compte de trésorerie est une dimension opérationnelle distincte du compte
général : BCGe et UBS peuvent ainsi être rapprochés séparément tout en étant
comptabilisés ensemble sous 1020 Banque. Toute nouvelle écriture sur un compte
général partagé doit préciser le compte opérationnel ; cette ventilation est
conservée dans les soldes d’ouverture, les paiements, les salaires, les
transferts, les exports CSV et les rapports de trésorerie.

- La prévisualisation analyse le fichier sans créer d’écriture comptable.
- La confirmation archive dans SQLite la source originale et son empreinte
  SHA-256, puis crée uniquement les lignes bancaires qui ne sont pas des
  doublons.
- Le rapprochement associe explicitement des lignes bancaires et comptables.
  Les combinaisons 1–1, 1–N et N–1 sont acceptées lorsque les sommes concordent
  dans la tolérance choisie.
- Une ligne ne peut appartenir qu’à un rapprochement actif.
- Une annulation auditée libère les lignes sans supprimer l’historique.
- Une écriture située dans une période close ne peut être rapprochée ni libérée.

Les suggestions de comptabilisation restent des propositions. Elles ne créent
une écriture qu’après acceptation explicite par un utilisateur disposant de
`compta.validate`.

## Lettrage

Sous `/app/liquidites/lettrage`, un paiement existant peut être réparti sur
plusieurs documents ouverts ; un document peut réciproquement recevoir
plusieurs paiements. La création n’est pas placée dans cet onglet :
**Liquidités > Paiements > Liste et paiement individuel > Saisir un paiement**
ouvre une fenêtre modale. Le contact y est facultatif et seulement indicatif :
chaque allocation reprend le contact de la facture concernée. Un paiement
partiel peut ainsi être ventilé entre plusieurs contacts.

Toutes les sommes sont des centimes entiers. Le service refuse notamment :

- une allocation supérieure au solde du paiement ou du document ;
- un paiement et un document de sens, devise ou compte de paiement
  incompatibles ;
- une ligne bancaire dont le signe ou le montant disponible ne correspond pas ;
- un délettrage après clôture comptable ou inclusion dans un décompte TVA.

Le délettrage conserve l’allocation annulée et, lorsque la TVA est au mode
« reçu », ajoute un mouvement TVA inverse au journal immuable.

Les comptes déclarés dans le référentiel de trésorerie — banque, poste, caisse
ou carte — servent au règlement mais ne sont jamais proposés comme comptes
collectifs à lettrer. Seuls les collectifs clients et fournisseurs sont
éligibles.

## Paiements sortants

Sous `/app/liquidites/paiements`, le sous-onglet **Liste et paiement
individuel** affiche par défaut les paiements non entièrement lettrés. Le
sous-onglet **Lots** reste distinct de la saisie individuelle. Pour ces lots, seuls les
documents fournisseurs issus du workflow Dépenses, approuvés puis
comptabilisés et encore ouverts, sont éligibles.

1. **Préparer** crée un lot idempotent, contrôle les coordonnées IBAN/BIC,
   fige les bénéficiaires, références et montants, mais ne crée aucun paiement.
2. **Générer et télécharger** produit un fichier
   `pain.001.001.09.ch.03`, l’archive avec son empreinte SHA-256 et place le lot
   au statut exporté. COMPTA ne transmet pas le fichier à une banque.
3. **Confirmer par le relevé** exige une ligne bancaire débitée et non
   rapprochée. Alors seulement COMPTA crée les paiements, les comptabilise,
   lettre les dettes et rapproche le débit bancaire.

Un écart correspondant à des frais bancaires peut être comptabilisé
séparément sur le compte de charge choisi. Sans compte de frais, tout écart est
refusé. Une dette exportée n’est donc jamais présentée comme payée avant sa
confirmation par un relevé bancaire.

Le profil pain.001 est isolé dans `Pain001Generator`. Il pourra être remplacé
ou complété lors d’une évolution SPS sans modifier le moteur d’allocation ou
de comptabilisation.

## Intégration au grand livre

Une écriture manuelle ou importée crée automatiquement un paiement déjà
comptabilisé lorsqu’elle comporte exactement une ligne sur un compte de
trésorerie et une contrepartie sur un véritable compte collectif clients ou
fournisseurs. Une dépense, une immobilisation ou un transfert entre comptes de
trésorerie ne crée donc aucun candidat au lettrage. Le contact n’est pas requis
dans le journal.

Inversement, un paiement saisi dans Liquidités reste non comptabilisé jusqu’à
son premier lettrage, son lettrage complet selon l’option du dossier, ou son
association à une ligne bancaire. La comptabilisation porte le montant total
sur le compte de paiement ; le reliquat reste visible comme non lettré. En
devise étrangère, le lettrage complet est toujours attendu afin de figer
correctement les écarts de change.

## Permissions

| Action | Permission |
|---|---|
| Consulter l’espace | `tresorerie.view` |
| Importer un relevé | `tresorerie.import` |
| Confirmer ou annuler un rapprochement | `tresorerie.reconcile` |
| Proposer une écriture | `compta.edit` |
| Accepter une suggestion | `compta.validate` |
| Créer, lettrer ou délettrer un paiement | `facturation.pay` |
| Préparer un lot sortant | `paiements.prepare` |
| Générer et télécharger pain.001 | `paiements.export` |
| Confirmer depuis le relevé | `paiements.confirm` |

Les trois permissions de paiement sortant permettent une séparation effective
entre préparation, export et confirmation.

## Contrôles et retour arrière

Les tests d’intégration couvrent les doublons d’import, la double consommation,
les écarts, les périodes closes, les allocations partielles, les frais et la
séparation des permissions. Le scénario navigateur vérifie le parcours Vue et
le refus d’un rapprochement incomplet.

En développement, le schéma canonique reste `database/migrations/001_initial.sql`.
Une base active incompatible est sauvegardée et contrôlée avant reconstruction.
Après un gel de production, un changement de schéma devra passer par une
migration additive et un retour arrière par restauration de la sauvegarde
SQLite vérifiée.
