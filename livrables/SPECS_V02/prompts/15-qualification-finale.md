# Lot 15 — Qualification et revue contradictoire

Applique le prompt maître. Travaille comme réviseur indépendant : ne présume pas
que les lots précédents sont corrects.

Audite :

- invariants comptables, TVA, paie, immobilisations, change et consolidation ;
- migrations depuis une copie 010 et depuis chaque version livrée ;
- restauration réelle d'une sauvegarde ;
- RBAC, CSRF, uploads, XSS, injection, traversée de chemin et isolation ;
- concurrence SQLite, idempotence, numérotation et verrous ;
- contrats API, accessibilité, 360 px, impression et build sans réseau ;
- paquet mutualisé PHP 8.2 sans Node/Redis/PostgreSQL en production ;
- licences et absence de code Gäld copié sans autorisation.

Rejoue les sept scénarios transversaux de la roadmap. Pour chaque exigence,
produis preuve, statut réussi/échoué/non vérifiable et commande ou test.

Bloque la livraison pour tout écart de centime, migration destructive non
réversible, fuite de scope, mutation d'écriture validée, transmission officielle
prétendue à tort ou dépendance de production interdite. Ne corrige pas
silencieusement un résultat métier : documente le défaut, ajoute un test rouge,
puis propose le plus petit correctif séparé.
