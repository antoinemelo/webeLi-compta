# Lot 02 — Contrats HTTP internes et découpage du contrôleur

Applique le prompt maître.

Objectif : créer une petite API `/api/v1` et réduire progressivement
`WebApplication.php` sans toucher au moteur.

Implémente :

- réponses JSON uniformes `data/meta/errors`, erreurs typées et corrélation ;
- contrôleurs minces par module, registre de routes et validateurs d'entrée ;
- endpoints de lecture contexte, navigation, permissions, exercices et
  référentiels nécessaires au futur shell ;
- pagination, tri et filtres en liste blanche ;
- CSRF sur mutations, 401/403/404/409/422 cohérents et en-têtes de sécurité ;
- tests de contrat, isolation de scope et non-régression des routes HTML.

Ne crée pas de repository générique ni de mini-framework. Réutilise les services
existants et extrais seulement ce qui est touché.

Acceptation :

- aucune logique SQL/comptable dans un contrôleur ;
- un changement d'identifiant dans l'URL ne permet aucune fuite ;
- contrats documentés par exemples versionnés ;
- routes HTML historiques toujours opérationnelles.
