# Prompt 07 — Enseignement

Implémente le module `Pedagogie` sans dupliquer le moteur comptable.

## Livrables

- Modèles d'exercice versionnés.
- Plan, soldes, données initiales, consignes, étapes et indices.
- Assignation individuelle et groupe par clonage isolé.
- Groupes avec membres, rôles éventuels, dates d'adhésion et dossier partagé.
- Collaboration depuis plusieurs postes par transactions courtes et verrou
  optimiste `version`; conflit HTTP 409 sur le même brouillon, sans écrasement.
- Bandeau permanent de données fictives.
- Progression du groupe et contributions individuelles attribuées à leur auteur.
- Validateurs déclaratifs portant sur comptes, sens, montants, soldes et rapports.
- Affichage gradué des indices.
- Correction visible seulement après règle du formateur.
- Réinitialisation atomique réservée aux dossiers `exercice`.
- Tableau de suivi formateur sans exposer de dossier réel.

## Tests obligatoires

- Un apprenant A ne voit ni B, ni modèle/solution, ni dossier réel.
- Deux membres créent simultanément des écritures distinctes sans perte.
- Deux membres modifient la même version : le second reçoit un conflit explicite.
- Un ancien membre retiré du groupe perd immédiatement l'accès.
- Une solution comptablement équivalente est acceptée lorsque la règle le permet.
- Le reset n'affecte pas le modèle et crée un audit.
- Une tentative reste traçable après correction.
- Une route de reset sur un dossier `reel` est refusée au service et au HTTP.
- Dans une instance mixte, listes, recherche et exports ne révèlent aucune
  organisation ou métadonnée réelle à un apprenant.
