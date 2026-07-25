# Lot 10 — Immobilisations et amortissements

Applique le prompt maître.

Objectif : suivre les immobilisations sans tableur parallèle.

Implémente :

- fiche actif, catégorie, pièce d'acquisition, date de mise en service, valeur,
  valeur résiduelle, durée, méthode et comptes ;
- plan d'amortissement prévisionnel en centimes ;
- dotations périodiques idempotentes via `EntryService` ;
- cession, mise au rebut, correction et contre-passation ;
- registre, échéancier et réconciliation au grand livre.

Commence par la méthode linéaire. Toute autre méthode nécessite une règle
documentée et des tests. Aucun calcul au `float`.

Acceptation :

- somme des dotations + valeur nette = base amortissable au centime ;
- prorata, dernier centime, exercice décalé et cession testés ;
- aucune dotation dans période close ou deux fois pour la même période ;
- registre réconcilié avec comptes d'actif et amortissements cumulés.
