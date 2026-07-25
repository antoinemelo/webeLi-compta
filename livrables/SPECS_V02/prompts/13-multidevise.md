# Lot 13 — Multi-devise

Applique le prompt maître.

Objectif : gérer les devises sans altérer le mode CHF actuel.

Implémente le modèle décrit dans `docs/04-donnees-et-migrations.md` :

- devise de base par dossier, devises autorisées et taux datés/sourcés ;
- montant d'origine + conversion figée sur document, paiement et ligne
  comptable concernée ;
- règlement partiel et gain/perte de change réalisé ;
- réévaluation de clôture explicite, traçable et contre-passable ;
- affichage systématique devise d'origine et base.

Utilise entiers et ratios/échelles fixes, jamais `float`. Aucun appel réseau
automatique n'est requis ; import manuel contrôlé d'abord.

Acceptation :

- le scénario mono-CHF produit exactement les données/résultats antérieurs ;
- EUR/CHF avec deux paiements à taux différents équilibré au centime ;
- source/date/taux retrouvables depuis l'écriture ;
- taux absent, incohérent ou futur refusé clairement.
