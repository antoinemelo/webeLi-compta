# Rapports, clôture, TVA et dossier fiscal

La clôture comprend la réévaluation explicite et contre-passable des factures
ouvertes en devises. Le grand livre expose le montant d’origine et le snapshot
du taux ; les états financiers restent exprimés dans la devise fonctionnelle.

Ce parcours est disponible sous `/app/compta/etats`, `/app/compta/tva`,
`/app/compta/cloture` et `/app/compta/fiscal`. Il lit le même grand livre
SQLite que la journalisation, la facturation, les liquidités et les salaires.
Il n’existe aucun moteur de calcul ni écran PHP parallèle.

## États financiers

La période demandée reste comprise dans l’exercice sélectionné. Chaque écran et
chaque export montre ou embarque les paramètres `exercise_id`, `date_start`,
`date_end` et les statuts de grand livre retenus (`validee`,
`contre_passee`). Les rapports sont strictement en lecture :

- grand livre : solde initial, débits, crédits et solde naturel final ;
- balance de vérification : total débit égal au total crédit ;
- bilan : actif égal au passif, résultat courant inclus ;
- compte de résultat : produits, charges et résultat comparés au dernier
  exercice antérieur disponible ;
- flux de trésorerie : méthode directe à partir des comptes de liquidités
  configurés.

Le flux présente son ouverture, ses entrées, ses sorties, sa variation nette,
sa clôture et son écart de réconciliation. Les catégories de flux sont des
propositions à valider ; elles ne changent jamais les écritures.

Les contrôles bloquants vérifient :

1. `Σ débits = Σ crédits` ;
2. `actif = passif + résultat courant` ;
3. le même résultat dans la balance, le compte de résultat et le bilan ;
4. `liquidités initiales + variation = liquidités finales`.

Les exports CSV portent leur période et l’empreinte SHA-256 du grand livre afin
de rendre le calcul identifiable.

## Journal détaillé et import

La journalisation conserve une liste récente légère, complétée par **Voir tout
le journal** pour afficher chaque ligne de chaque écriture. L’export détaillé
reprend les colonnes `ecriture`, `date`, `journal`, `reference`, `piece`,
`libelle_ecriture`, `compte`, `libelle_ligne`, `debit`, `credit` et `statut`.

Le même format peut être réimporté après prévisualisation. Les lignes sont
groupées par la colonne `ecriture`; chaque groupe doit contenir au moins deux
lignes, appartenir à l’exercice, utiliser des journaux et comptes actifs et
être équilibré au centime. Le lot entier est transactionnel et son empreinte
empêche un double import. Un statut `brouillon` conserve l’écriture à
contrôler; un statut `validee` la numérote dans la même transaction.

## TVA

L’onglet TVA réutilise les régimes, codes, comptes, lignes sources, décomptes et
exports déjà gérés par le module TVA. Une période est préparée depuis les
sources comptabilisées, rapprochée avec les comptes TVA, contrôlée, puis
exportée. Le détail remonte de chaque case AFC à ses lignes et écritures
sources.

L’export eCH-0217 2.0.0 est validé par le profil XSD avant archivage. COMPTA ne
transmet rien à l’AFC : l’utilisateur télécharge le XML, le vérifie et
l’importe manuellement, puis marque le décompte comme déclaré. Ce
fonctionnement a été revérifié le 26 juillet 2026 auprès des sources
officielles [AFC — Décompter la TVA en ligne](https://www.estv.admin.ch/fr/decompter-la-tva-en-ligne)
et [eCH-0217 2.0.0](https://www.ech.ch/fr/ech/ech-0217/2.0.0).

## Clôture

La checklist combine des contrôles automatiques et trois revues documentées :
pièces justificatives, ajustements et revue fiscale. Une période ne peut être
fermée si un contrôle financier automatique échoue ou si elle contient encore
une écriture brouillon. La fermeture interdit ensuite toute nouvelle
validation dans cette période. La réouverture reste une action explicite,
permissionnée, versionnée et auditée.

Une archive de clôture est un document JSON immuable. Elle contient les
paramètres, les rapports, la TVA et la checklist au moment de sa création, avec
trois empreintes :

- empreinte des paramètres ;
- empreinte du grand livre ;
- empreinte du contenu complet.

La fermeture crée atomiquement une archive des rapports et contrôles de la
période. Le bouton d’archivage permet en plus de figer un dossier complet avec
son espace TVA avant ou après les revues manuelles.

Le téléchargement recalcule l’empreinte du contenu et refuse une archive
altérée. Un rejeu strictement identique retourne la même archive ; un contenu
de checklist ou de dossier fiscal différent produit une nouvelle archive,
même si le grand livre n’a pas changé.

## Dossier fiscal préparatoire

Le dossier fiscal rassemble, sans calculer de déclaration officielle :

- l’état des rapprochements bancaires ;
- le décompte des documents et pièces liées ou manquantes ;
- la situation TVA ;
- des ajustements de travail proposés, validés ou écartés.

Les ajustements sont des notes en centimes et ne créent aucune écriture. Leur
clé idempotente ne peut pas être réutilisée pour un contenu différent. Une
archive dédiée fige le dossier de travail pour transmission à un spécialiste.

Le bandeau « dossier préparatoire » est volontairement permanent : ce parcours
ne constitue ni un conseil fiscal, ni une déclaration, ni un envoi à une
administration.

## Permissions

- `compta.view` : lecture des états, de la TVA, de la checklist et du dossier ;
- `compta.export` : exports CSV et archives financières ;
- `compta.setup` : checklist, périodes et ajustements préparatoires ;
- `tva.setup`, `tva.prepare`, `tva.control`, `tva.export`, `tva.declare` :
  séparation des étapes TVA.

Toutes les mutations exigent le CSRF, utilisent le dossier de session et
refusent un scope injecté dans la charge utile.
