# Prompt 01 — Socle

En appliquant le prompt maître, crée le socle du projet.

## Livrables

- Arborescence définie dans `04-architecture-et-donnees.md`.
- Front controller, routeur minimal, réponses, vues/layout et gestion d'erreurs.
- Configuration dev/prod et configuration locale ignorée par Git.
- Connexion PDO SQLite sûre : foreign keys, WAL, busy timeout.
- Moteur de migrations numérotées avec table, checksum, plan et apply.
- Commande `bin/console` avec `app:doctor`, `db:migrate`, `db:integrity`.
- Authentification, CSRF, sessions, anti-bruteforce.
- Organisations, dossiers/mandats, exercices, rôles et permissions à chaque scope.
- Audit minimal des connexions et mutations.
- Layout accessible avec sélecteur de dossier.
- Support d'un base path/sous-répertoire, `APP_INSTANCE_ID`, cookie et stockage
  propres à chaque installation.
- Tests unitaires, intégration SQLite et HTTP du socle.

## Critères

- Un utilisateur sans droit ne peut pas ouvrir un dossier en changeant l'URL.
- Un identifiant d'une autre organisation est refusé même si le numéro de dossier existe.
- Un POST sans CSRF échoue.
- Deux migrations successives et un rejeu sont idempotents.
- Une migration déjà appliquée dont le checksum change bloque explicitement.
- `storage/` est absent du web public.
- Le diagnostic distingue avertissement et erreur bloquante.
- Deux instances sous deux chemins du même hôte ne partagent ni session, ni URL,
  ni fichier de stockage.
