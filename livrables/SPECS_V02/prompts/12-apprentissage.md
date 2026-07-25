# Lot 12 — Apprentissage et exercices ciblés

Applique le prompt maître.

Objectif : porter le moteur pédagogique existant dans Vue et enrichir le
catalogue sans créer un moteur comptable scolaire distinct.

Implémente :

- catalogue par compétence : débit/crédit, TVA, facturation, salaires,
  rapprochement, clôture et lecture d'états ;
- scénarios versionnés avec données initiales, étapes, indices, validation,
  solution protégée et barème ;
- espace apprenant, progression, feedback explicatif et réinitialisation ;
- tableau formateur, assignation individuelle/groupe et export des résultats ;
- activation du module depuis Configuration.

La validation juge l'équivalence comptable lorsque l'ordre ou le libellé n'est
pas significatif. Elle n'expose jamais la solution avant autorisation.

Acceptation :

- isolation absolue réel/démo/exercice ;
- conflits optimistes et contributions attribuées ;
- module désactivé refusé côté API ;
- scénarios de chaque compétence testés avec succès et erreur pédagogique.
