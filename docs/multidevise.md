# Multidevise

La devise du dossier reste la devise fonctionnelle du grand livre. Une devise
étrangère doit être activée dans `Configuration > Référentiels > Devises et
change`, puis disposer d’un taux daté, sourcé et vérifié.

## Convention de calcul

Les montants restent des entiers en unités mineures. Un taux est un ratio
`numérateur / dénominateur` exprimant les centimes de la devise de base par
centime de la devise d’origine. Aucun flottant n’intervient dans le moteur.

Chaque document, paiement et ligne comptable pertinente archive la devise et
le montant d’origine, la contre-valeur fonctionnelle, le ratio, la date et la
source du taux, ainsi que l’éventuel centime d’arrondi. Une devise inactive ou
un taux absent, futur ou incomplet est refusé.

## Règlement et clôture

Le lettrage libère la créance ou la dette à son taux historique et valorise le
paiement à son propre taux. La différence devient un gain ou une perte réalisé
dans l’écriture du paiement. Un paiement étranger doit être lettré avant sa
comptabilisation.

La clôture propose une réévaluation explicite des factures comptabilisées
encore ouvertes. Elle utilise le dernier taux disponible à la date choisie,
archive le détail par document et génère une écriture distincte et
contre-passable.

Les quatre comptes de gains et pertes réalisés ou latents sont configurés par
dossier. Les listes gardent le montant d’origine visible ; les agrégats et le
tableau de bord sont toujours exprimés dans la devise de base.
