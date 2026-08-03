# Immobilisations et amortissements

L’onglet **Comptabilité > Amortissements** est le registre unique des actifs
immobilisés. Il relie chaque fiche à une catégorie, une référence ou facture
d’acquisition, cinq comptes du plan comptable et un échéancier.

## Règle de calcul

La méthode disponible est `lineaire_30_360`. La base amortissable est la
valeur d’acquisition moins la valeur résiduelle. Elle est répartie sur la durée
utile selon une convention de 30 jours par mois et 360 jours par année :

- les échéances sont trimestrielles ; le premier et le dernier trimestre sont
  proratisés ;
- tous les montants restent des centimes entiers ;
- le cumul est calculé par division entière, de sorte que le dernier centime
  est absorbé par le plan et que sa somme égale toujours la base ;
- cette règle est un choix comptable explicite de l’application, pas une
  affirmation de conformité fiscale universelle.

La durée utile et les comptes de la catégorie sont copiés sur la fiche lors de
sa création. Ils ne sont pas ressaisis sur l’immobilisation. Une modification
ultérieure de la catégorie ne réécrit donc pas l’historique.

## Parcours

1. Créer une catégorie et sélectionner le compte d’actif, le compte correcteur
   d’amortissements cumulés, la charge de dotation et les comptes de gain/perte
   de cession.
2. Créer la fiche dans sa fenêtre modale avec sa pièce, ses dates et ses
   valeurs. La valeur résiduelle proposée est de CHF 0.01.
3. Contrôler l’**Échéancier**, regroupé par compte d’actif, catégorie et
   période. Chaque groupe reste compact et affiche le nombre de périodes à
   comptabiliser, comptabilisées et à venir. Son bouton d’agrandissement déplie
   le tableau sous l’intitulé, sur trois onglets : **Échus à comptabiliser** par
   défaut, **Échus comptabilisés** et **À venir**. Une seule catégorie est
   dépliée à la fois et l’icône permet aussi de la réduire. Une seule action
   comptabilise atomiquement les dotations de tous les actifs sous-jacents dans
   un journal et un exercice ouverts. Leurs codes restent visibles et ouvrent
   chaque fiche en consultation.
4. Utiliser **Contre-passer** pour corriger les dotations validées d’une
   période. Les lignes planifiées redeviennent comptabilisables ; les écritures
   d’origine restent visibles.
5. Une cession ou mise au rebut solde la valeur brute et les amortissements
   cumulés, constate le produit éventuel et le gain ou la perte. Elle peut
   elle-même être contre-passée.

Les catégories et le registre utilisent un menu d’actions « ⋮ ». Cliquer sur
le code d’un actif depuis le registre, l’échéancier ou la réconciliation ouvre
sa fiche en consultation modale.

Une fiche peut être corrigée directement tant qu’aucune de ses échéances n’a
produit d’écriture. Après cela, le grand livre validé reste immuable.

## Contrôles

La vue Réconciliation compare, à la fin de l’exercice choisi, le brut et les
amortissements cumulés du registre aux soldes des comptes correspondants.
Chaque ligne peut être dépliée : le détail juxtapose les actifs du registre
(code, référence, dates et valeur brute) et les mouvements validés retrouvés
sur le compte d’actif du grand livre (date, journal, écriture, référence,
débit, crédit et solde net). L’écart restant à justifier est rappelé au-dessus
des deux sources ; l’absence de mouvement indique notamment qu’il faut vérifier
le compte des lignes de la facture d’acquisition ou passer un reclassement.
Toute échéance échue non comptabilisée bloque aussi le contrôle automatique de
clôture de la période.

Les mutations exigent CSRF, permission et dossier actif. Les clés
d’idempotence empêchent une double dotation ou une double sortie au rejeu.
