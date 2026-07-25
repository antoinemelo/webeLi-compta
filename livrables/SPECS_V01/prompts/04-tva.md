# Prompt 04 — TVA suisse

Implémente le module `Tva` selon `docs/04-tva-suisse.md`, avant les modules de
facturation qui le consommeront.

## Sources à vérifier au début du lot

- AFC : taxe sur la valeur ajoutée, taux en vigueur, méthodes de décompte.
- AFC : schéma et documentation eCH-0217 e‑TVA acceptés par Décompte TVA pro.
- Plan PME VEB : comptes 1170, 1171, 2200 et 2201.

Consigne dans le code et la documentation la date de vérification. Ne copie pas
une règle temporelle depuis ce prompt sans la confronter aux sources officielles.

## Livrables

- Configuration d'assujettissement par organisation/dossier avec historique daté.
- Méthodes effective et TDFN ; modes contre-prestations convenues et reçues.
- Taux légaux versionnés, codes fiscaux et traitements :
  imposable, réduit, spécial, exonéré, exclu, hors champ, acquisition, import.
- Calculs en centimes, saisie nette/brute et snapshots par ligne.
- Impôt préalable 1170/1171, part déductible, corrections motivées.
- Plusieurs TDFN autorisés et rattachement des activités.
- Périodes et décomptes snapshotés, drill-down jusqu'aux écritures.
- Décompte rectificatif sans mutation d'un déclaré.
- Génération et validation XSD d'eCH-0217 e‑TVA version courante acceptée.
- Services internes appelables par facturation et comptabilité.
- Documentation opérateur : préparer, contrôler, exporter, déclarer et comptabiliser.

## Garde-fous

- L'application ne qualifie jamais juridiquement une prestation toute seule.
- Une règle/taux a toujours une date d'effet ; aucun taux magique dispersé.
- Une organisation non assujettie ne comptabilise pas de TVA.
- TDFN : taux légal sur facture, taux accordé sur CA brut, pas de déduction
  ordinaire de l'impôt préalable.
- Un export eCH n'est jamais présenté comme transmis ; le portail AFC reste l'étape finale.

## Tests obligatoires

Implémente tous les tests de `docs/04-tva-suisse.md`, plus :

- isolation de deux organisations avec régimes différents ;
- changement de méthode sans effet rétroactif ;
- bases négatives des avoirs et arrondis multi-taux ;
- période convenu/reçu avec paiement partiel ;
- réconciliation décompte ↔ grand livre TVA ;
- fixture eCH valide et mutations invalides rejetées par XSD.
