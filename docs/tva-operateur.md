# TVA suisse — guide opérateur

Vérification réglementaire : **25 juillet 2026**.

Sources de référence :

- AFC, [taux de la TVA suisse](https://www.estv.admin.ch/fr/taux-de-la-tva-suisse) ;
- AFC, [TDFN et taux forfaitaires](https://www.estv.admin.ch/fr/tva-taux-de-la-dette-fiscale-nette-et-taux-forfaitaires) ;
- AFC, [Décompter la TVA en ligne](https://www.estv.admin.ch/fr/decompter-la-tva-en-ligne) ;
- eCH, [eCH-0217 2.0.0](https://www.ech.ch/fr/ech/ech-0217/2.0.0) ;
- SECO/veb.ch, [plan comptable suisse PME](https://www.kmu.admin.ch/dam/kmu/fr/dokumente/savoir-pratique/Finances/240812%20Schulkontenrahmen%20VEB%20-%20FR.pdf.download.pdf/240812%20Schulkontenrahmen%20VEB%20-%20FR.pdf).

Les taux légaux vérifiés sont 8,1 % (normal), 2,6 % (réduit) et
3,8 % (hébergement). Ils sont enregistrés avec leur date d'effet ; aucun service
ne contient de taux implicite.

## 1. Configurer

1. Installer le plan comptable du dossier et vérifier les comptes 1170, 1171,
   2200 et 2201.
2. Dans **Configuration > Référentiels > TVA > Avec ou sans TVA**, créer un
   régime daté : non-assujettissement ou assujettissement, méthode effective
   ou TDFN, contre-prestations convenues ou reçues et périodicité.
3. Créer les codes fiscaux utiles. La qualification est toujours choisie par
   l'utilisateur : le logiciel ne déduit jamais automatiquement le régime d'un
   don, d'une subvention, d'une formation ou d'une opération étrangère.
4. En TDFN, enregistrer chaque activité et taux exactement selon l'autorisation
   AFC, avec son identifiant technique de cinq caractères.

Un changement crée une nouvelle ligne d'historique. Il ne modifie aucun snapshot
ou décompte antérieur.

## 2. Saisir et contrôler

Chaque ligne reçoit explicitement un code TVA et une date de prestation.
Le calcul accepte un prix net ou brut et arrondit au centime, par ligne,
symétriquement pour les avoirs négatifs.

Pour l'impôt préalable, toute déduction différente de la valeur du code exige un
motif. En TDFN, la facture client affiche encore le taux légal, le décompte
applique le taux accordé au chiffre d'affaires brut, et l'impôt préalable
ordinaire reste nul.

En mode reçu, chaque paiement est enregistré et le décompte ne retient que sa
part proportionnelle. La somme des paiements ne peut pas dépasser le brut.

## 3. Préparer le décompte

```bash
php bin/console tva:periode-create \
  --organisation=1 --dossier=1 --debut=2026-01-01 --fin=2026-03-31
php bin/console tva:decompte-prepare \
  --organisation=1 --dossier=1 --periode=1
php bin/console tva:decompte-control \
  --organisation=1 --dossier=1 --decompte=1
```

La préparation fige le régime, les taux, les agrégats, les cases AFC et toutes
les écritures sources. Le contrôle de la méthode effective exige une concordance
exacte avec les comptes TVA du grand livre.

Le drill-down fourni par `VatStatementService::drillDown()` relie chaque montant
à la ligne comptable et à l'écriture d'origine.

## 4. Exporter et déclarer

```bash
php bin/console tva:ech-export \
  --organisation=1 --dossier=1 --decompte=1 --sortie=/chemin/tva-2026-q1.xml
```

L'export est généré en eCH-0217 **2.0.0** et validé hors ligne contre le profil
XSD courant embarqué. Avec `ext-dom`, libxml réalise la validation XSD ; sans
cette extension, le validateur portable applique les mêmes séquences,
cardinalités et types du profil.

L'export n'est **pas transmis**. L'opérateur doit :

1. ouvrir « Décompte TVA pro » ;
2. importer le XML ;
3. vérifier et compléter les données ;
4. remettre le décompte sur le portail ;
5. seulement ensuite confirmer localement :

```bash
php bin/console tva:decompte-declare \
  --organisation=1 --dossier=1 --decompte=1
```

## 5. Rectifier et comptabiliser

Un déclaré n'est jamais réécrit :

```bash
php bin/console tva:decompte-prepare \
  --organisation=1 --dossier=1 --periode=1 --rectifie=1
```

Le rectificatif possède son propre snapshot et un numéro de correction.
`VatSettlementService` produit ensuite, sur demande explicite, l'écriture
équilibrée de transfert vers le compte 2201. La déclaration fiscale, son
paiement bancaire et la période fiscale restent trois événements distincts.
