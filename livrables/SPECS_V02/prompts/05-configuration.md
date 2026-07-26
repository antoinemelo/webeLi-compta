# Lot 05 — Configuration et modules

Applique le prompt maître.

Objectif : centraliser les référentiels sans créer de doubles sources.

Intègre dans la base initiale canonique :

- activation par dossier des modules, dont Apprentissage ;
- identité légale de l'organisation, devise de base et coordonnées ;
- conditions de paiement datées et valeurs par défaut ;
- écrans/liens pour comptes bancaires, TVA, charges sociales, plan, journaux,
  exercices, périodes, utilisateurs et audit ;
- journalisation de toute modification sensible.

Contacts reste un registre unique de Facturation, simplement accessible par un
lien depuis Configuration. Les taux historiques utilisés par un document ou une
fiche ne changent jamais.

Acceptation :

- module désactivé absent de la navigation et refusé côté serveur ;
- réactivation retrouve les données intactes ;
- changement de défaut sans effet rétroactif ;
- installation vierge testée depuis `001_initial.sql`, sans dépendance à un
  ancien historique de migrations.
