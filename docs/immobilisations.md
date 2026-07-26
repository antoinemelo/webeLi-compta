# Immobilisations et amortissements

L’onglet **Comptabilité > Amortissements** est le registre unique des actifs
immobilisés. Il relie chaque fiche à une catégorie, une référence ou facture
d’acquisition, cinq comptes du plan comptable et un échéancier.

## Règle de calcul

La méthode disponible est `lineaire_30_360`. La base amortissable est la
valeur d’acquisition moins la valeur résiduelle. Elle est répartie sur la durée
utile selon une convention de 30 jours par mois et 360 jours par année :

- le premier et le dernier mois sont proratisés ;
- tous les montants restent des centimes entiers ;
- le cumul est calculé par division entière, de sorte que le dernier centime
  est absorbé par le plan et que sa somme égale toujours la base ;
- cette règle est un choix comptable explicite de l’application, pas une
  affirmation de conformité fiscale universelle.

Les comptes de la catégorie sont copiés sur la fiche lors de sa création. Une
modification ultérieure de la catégorie ne réécrit donc pas l’historique.

## Parcours

1. Créer une catégorie et sélectionner le compte d’actif, le compte correcteur
   d’amortissements cumulés, la charge de dotation et les comptes de gain/perte
   de cession.
2. Créer la fiche avec sa pièce, ses dates, ses valeurs et sa durée.
3. Contrôler le plan prévisionnel puis comptabiliser chaque échéance dans un
   journal et un exercice ouverts.
4. Utiliser **Contre-passer** pour corriger une dotation validée. La ligne
   planifiée redevient comptabilisable ; l’écriture d’origine reste visible.
5. Une cession ou mise au rebut solde la valeur brute et les amortissements
   cumulés, constate le produit éventuel et le gain ou la perte. Elle peut
   elle-même être contre-passée.

Une fiche peut être corrigée directement tant qu’aucune de ses échéances n’a
produit d’écriture. Après cela, le grand livre validé reste immuable.

## Contrôles

La vue Réconciliation compare, à la fin de l’exercice choisi, le brut et les
amortissements cumulés du registre aux soldes des comptes correspondants.
Toute échéance échue non comptabilisée bloque aussi le contrôle automatique de
clôture de la période.

Les mutations exigent CSRF, permission et dossier actif. Les clés
d’idempotence empêchent une double dotation ou une double sortie au rejeu.
