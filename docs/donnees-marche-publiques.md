# Données publiques de change et de taux

L’onglet unique `Liquidités > Taux` regroupe les taux de change et les taux
d’intérêt. Il présente l’exercice sélectionné ainsi que les douze mois qui le
précèdent et utilise uniquement les monnaies actives sous
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

Le cache ne conserve que l’union des besoins des exercices ouverts : pour
chaque monnaie définie, les mois de l’exercice et les douze mois précédents,
bornés au dernier mois civil complet publié. Une donnée reste conservée tant
qu’au moins une organisation ou un dossier en a besoin ; les séries, monnaies
et périodes devenues inutiles sont purgées.

La BNS n’est interrogée que si une combinaison monnaie/période requise manque.
L’OFDF est interrogé pour le jour courant et, si nécessaire, jusqu’à sept jours
en arrière ; seuls les taux des monnaies définies sont stockés. Une même
demande incomplète ou en échec n’est pas répétée le même jour. Les valeurs déjà
conservées restent consultables avec un avertissement visible.

Les décimales sont stockées comme entiers à l’échelle `100000000`. Les textes
décimaux d’origine normalisés, l’unité, la source et les dates de mise à jour
restent également archivés.

## Séparation comptable

Ce cache est un référentiel analytique. Il ne modifie jamais automatiquement
un document, un paiement ou une écriture. Les opérations multidevises
continuent d’utiliser un taux daté explicitement sélectionné et d’en archiver
un snapshot immuable.
