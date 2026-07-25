# Prompt 11 — Qualification et release

Crée la chaîne de qualification et une archive installable.

## Commandes

Expose une commande unique avec profils :

- `quick` : lint + unités ;
- `complete` : tous tests, intégrité, sécurité statique, backup/restore ;
- `release` : complete + E2E + migration N-1 + package + installation neuve + smoke.

## Exigences de l'archive

- Dépendances prêtes à l'emploi et compatibles PHP 8.2.
- Aucun secret, `.env`, configuration locale, base réelle, document, log ou test PII.
- Manifeste version, commit, PHP minimal, migrations et SHA-256.
- Guide d'installation et commandes de vérification.
- Validation du ZIP extrait dans un répertoire temporaire.
- Test de deux installations simultanées sous deux sous-répertoires.
- Fixtures CAMT versionnées et XSD eCH-0217 inclus/traçable selon droits de diffusion.
- Rapport des versions normatives vérifiées : plan VEB, AFC/eCH, SPS CAMT et QR.
- Rapport JSON et Markdown de chaque qualification release.

Exécute tous les scénarios obligatoires de `06-roadmap-recette.md`. Un prérequis
manquant produit « incomplet », jamais « réussi ».
