# Données publiques de change et de taux

Les onglets `Liquidités > Taux de change` et `Liquidités > Taux d’intérêt`
présentent l’exercice sélectionné ainsi que les douze mois qui le précèdent.
Ils utilisent uniquement les monnaies actives sous
`Configuration > Référentiels > Devises et change`.

## Sources

- `devkum` de la Banque nationale suisse : moyennes mensuelles et valeurs de
  fin de mois, cotées en CHF ;
- `zimoma` de la Banque nationale suisse : taux du marché monétaire mensuels ;
- service quotidien de l’Office fédéral de la douane et de la sécurité des
  frontières : taux de vente, date de publication et jours de validité.

Les URL sont fixées côté serveur et limitées à ces trois services HTTPS. Une
réponse vide, trop volumineuse, mal formée, redirigée ou contenant un DTD est
refusée.

## Cache partagé

Les tables `series_marche_publiques`, `valeurs_marche_mensuelles`,
`taux_change_publics_quotidiens` et `actualisations_marche_publiques` ne portent
aucun identifiant d’organisation ou de dossier. Une synchronisation réussie
alimente donc toutes les organisations de l’instance.

La BNS est interrogée si le dernier mois civil complet manque. L’OFDF est
interrogé pour le jour courant et, si nécessaire, jusqu’à sept jours en
arrière. Une seule tentative est faite par jour après un échec ; les valeurs
déjà conservées restent consultables avec un avertissement visible.

Les décimales sont stockées comme entiers à l’échelle `100000000`. Les textes
décimaux d’origine normalisés, l’unité, la source et les dates de mise à jour
restent également archivés.

## Séparation comptable

Ce cache est un référentiel analytique. Il ne modifie jamais automatiquement
un document, un paiement ou une écriture. Les opérations multidevises
continuent d’utiliser un taux daté explicitement sélectionné et d’en archiver
un snapshot immuable.
