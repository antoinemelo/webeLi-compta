# Lot 05 — Configuration et modules

Applique le prompt maître.

Objectif : centraliser les référentiels sans créer de doubles sources.

Intègre dans la base initiale canonique :

- activation par dossier des modules, dont Apprentissage ;
- identité légale de l'organisation, devise de base et coordonnées ;
- conditions de paiement datées et valeurs par défaut ;
- écrans Vue natifs pour comptes bancaires, TVA, charges sociales, plan,
  journaux, exercices, périodes, rôles du dossier et audit ;
- journalisation de toute modification sensible.

Contacts reste un registre unique de Facturation, simplement accessible par un
écran Vue natif depuis Configuration. Les codes TVA et taux de charges sociales
sont eux aussi gérés dans Configuration, mais continuent d’appeler leurs
services métier respectifs. Les taux historiques utilisés par un document ou
une fiche ne changent jamais.

Acceptation :

- module désactivé absent de la navigation et refusé côté serveur ;
- réactivation retrouve les données intactes ;
- changement de défaut sans effet rétroactif ;
- aucun lien de ces trois référentiels ne renvoie vers un formulaire PHP
  historique ;
- aucun référentiel ne dépend d’une seconde projection SQL ou d’un écran
  historique ;
- installation vierge testée depuis `001_initial.sql`, sans dépendance à un
  ancien historique de migrations.
