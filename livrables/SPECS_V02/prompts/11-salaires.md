# Lot 11 — Salaires horaires, mensuels et annuels

Applique le prompt maître.

Objectif : étendre l'excellent noyau genevois sans changer ses résultats
historiques.

Implémente :

- import prévisualisable des taux annuels depuis la table OCAS
  `taux_par_annee` de la base indiquée par `OCAS_DB_PATH` ;
- correspondance exhaustive des clés de `lib/calc.php` vers les champs COMPTA,
  conversion des fractions en ppm entiers et rapport des clés inconnues ;
- contrat employé horaire ou mensuel, dates d'effet et taux/version ;
- traitement par période, variables, heures, absences et ajustements explicites ;
- fiches, validation, comptabilisation, dettes et paiements existants en Vue ;
- récapitulatifs annuels par employé et employeur ;
- certificats annuels avec statut préparé/contrôlé/exporté/non transmis.

Les données OCAS sont prioritaires pour la reprise, mais restent datées,
sourcées et validées par l'opérateur. Si la base OCAS n'est pas disponible,
n'invente aucun millésime importé : utilise seulement les valeurs déjà présentes
dans COMPTA et signale la source manquante. Ne remplace jamais un taux ayant déjà
servi à une fiche validée. Préserve les 32 calculs de parité OCAS et tous les
snapshots.

Acceptation :

- prévisualisation sans écriture, confirmation auditée et rejeu idempotent ;
- chaque année/clé OCAS est importée ou justifiée comme non applicable ;
- taux LAA réduit/plein, parts employé/employeur et repli annuel sont couverts ;
- horaire et mensuel équilibrent coûts, retenues, net et dettes ;
- changement de taux sans effet rétroactif ;
- annulation par contre-passation ;
- PII masquées sans permission et exports nominatifs audités.
