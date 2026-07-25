# Données et migrations

## Décision

Conserver la base SQLite et faire évoluer le schéma courant. Ne pas créer une
seconde base applicative et ne pas convertir vers PostgreSQL.

Les migrations 001 à 010 sont un historique immuable. La première évolution
porte le numéro 011. Avant application : verrou de maintenance, sauvegarde
SQLite vérifiée, espace disque contrôlé, plan affiché, puis `integrity_check` et
`foreign_key_check`.

## Évolutions proposées

- 011 : registre des modules, préférences d'interface et métadonnées d'entité
  légale sur `organisations`.
- 012 : conditions de paiement et projections de tableau de bord si des tables
  matérialisées sont réellement nécessaires.
- 013 : dépenses et modèles récurrents.
- 014 : factures récurrentes et échéances explicites.
- 015 : ordres de paiement et lots pain.001.
- 016 : immobilisations, plans et dotations.
- 017 : paie mensuelle et récapitulatifs annuels.
- 018 : montants d'origine, devises et snapshots de taux de change.
- 019 : groupes de consolidation, mappings et éliminations.

Les numéros sont réservés par le plan ; l'agent doit vérifier le dépôt avant de
les employer et prendre les prochains numéros libres.

## Multi-devise

Le grand livre reste exprimé dans la devise de base du dossier, CHF par défaut.
Chaque ligne concernée peut aussi figer :

- devise d'origine ISO 4217 ;
- montant d'origine en unité mineure ;
- taux rationnel ou entier à échelle fixe, source et date ;
- montant converti en centimes de devise de base ;
- règle d'arrondi et différence.

Les gains/pertes réalisés sont comptabilisés lors du règlement. Les écarts non
réalisés passent par une opération explicite de clôture et de contre-passation.
Aucun `float` n'est autorisé.

## Reprise des taux salariaux Lasso

La source est la table `taux_par_annee` de la base Lasso désignée par son
`APP_DB_PATH`. Fournir une commande d'import en deux temps :

1. prévisualiser années, clés, valeurs, correspondances et anomalies sans
   écriture ;
2. confirmer l'import dans les paramètres annuels COMPTA.

Convertir les fractions Lasso en ppm entiers avec une règle d'arrondi testée.
Conserver année, clé source, valeur source, chemin/empreinte de la base, date
d'import et opérateur. Ne jamais écraser un millésime COMPTA déjà utilisé par
une fiche validée. Les snapshots de fiches existants restent intacts.

## Multi-entités

Les organisations existantes deviennent les entités légales sans changer leurs
identifiants. On ajoute les champs légaux manquants. Un groupe de consolidation
référence plusieurs organisations ; chaque grand livre reste isolé. Les
éliminations ont leurs propres écritures de consolidation et ne modifient pas
les livres statutaires.

## Reprise et retour arrière

Chaque migration fournit : préconditions, sauvegarde, transformation
idempotente, contrôles de comptage, journal de reprise et procédure de
restauration. Sur SQLite, une reconstruction de table se fait dans une
transaction testée sur une copie réaliste. Aucun renommage métier n'est effectué
uniquement pour embellir le modèle.
