# Modèle comptable et invariants

## 1. Source de vérité

La comptabilité repose sur :

- `ecritures` : en-tête, date, journal, statut et provenance ;
- `lignes_ecriture` : compte, libellé, débit ou crédit en centimes ;
- `allocations` : rapprochement financier entre paiements/avoirs et documents ;
- `rapprochements_bancaires` : correspondance banque ↔ comptabilité.

Une facture, une fiche de salaire ou un import bancaire ne constitue jamais à
lui seul une écriture comptable.

## 2. Convention des montants

- Tous les montants monétaires sont des entiers signés en centimes dans le code.
- Une ligne comptable utilise deux colonnes non négatives `debit_centimes` et
  `credit_centimes`, dont exactement une est strictement positive.
- Les taux sont stockés comme chaînes décimales ou points de base selon le besoin,
  jamais comme valeurs binaires utilisées sans règle d'arrondi.
- Arrondi commercial au centime à chaque composant réglementaire, puis somme des
  composants figés.

## 3. Invariants de validation

Pour toute écriture validée :

```text
Σ débit_centimes = Σ crédit_centimes
Σ débit_centimes > 0
statut = validee
date ∈ exercice ouvert
chaque compte appartient au même dossier
aucune ligne n'est modifiable ou supprimable
```

Ces contrôles existent à la fois dans le service métier et, lorsque SQLite le
permet, dans les contraintes de schéma/triggers. La transaction valide l'en-tête
et toutes les lignes atomiquement.

## 4. Sens et soldes

- Actif/charge : solde naturel = débits − crédits.
- Passif/fonds propres/produit : solde naturel = crédits − débits.
- La balance expose séparément mouvements débit, mouvements crédit et solde.
- Le bilan et le résultat s'appuient sur le type de compte, pas sur le premier
  chiffre codé en dur.
- Les préfixes restent utiles pour les modèles pédagogiques, jamais comme unique
  règle métier.

## 5. Cycle des écritures

```text
brouillon → validée
validée → contre-passée (via une nouvelle écriture inverse)
```

Pas de retour vers brouillon. La contre-passation porte sa propre date, référence
l'écriture d'origine et inverse exactement chaque ligne. L'original reste visible.

## 6. Écritures générées

Chaque module appelle le même service de comptabilisation avec une clé
d'idempotence :

- facture client : créance / produits ;
- facture fournisseur : charges ou actifs / dette ;
- paiement client : liquidité / créance ;
- paiement fournisseur : dette / liquidité ;
- paie : charges de personnel / dettes nettes et sociales ;
- paiement salaire : dette employé / liquidité ;
- paiement charges : dette caisse / liquidité.

Une commande rejouée avec la même clé ne crée pas de doublon.

## 7. Ouverture et clôture

- Les soldes d'ouverture sont une écriture dédiée, importable avec prévisualisation.
- Une période fermée refuse nouvelle validation et contre-passation datée dedans.
- La clôture produit un rapport de contrôles avant toute mutation.
- Après confirmation et sauvegarde, elle génère les écritures nécessaires,
  ferme l'exercice et prépare l'ouverture suivante.
- Une réouverture est une action administrateur exceptionnelle, motivée et auditée.

## 8. Contrôles permanents

- balance générale à zéro ;
- aucune écriture validée déséquilibrée ;
- aucune allocation supérieure aux montants disponibles ;
- aucun document « payé » avec solde non nul ;
- total des ventilations analytiques égal à la ligne ;
- rapprochements sans double consommation ;
- clés étrangères valides et `PRAGMA integrity_check = ok`.

## 9. Multi-organisations

Une transaction comptable ne traverse jamais deux organisations ou deux dossiers.
Il n'existe ni consolidation, ni compte inter-sociétés automatisé au MVP. Si une
opération réelle concerne deux organisations de la même installation, elle est
saisie séparément dans chaque dossier, avec références croisées purement
informatives. Les séquences, exercices, journaux, comptes et paramètres TVA sont
propres à chaque dossier.
