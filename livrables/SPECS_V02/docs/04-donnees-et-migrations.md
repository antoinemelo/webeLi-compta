# Données et migrations

## Décision

Conserver la base SQLite et faire évoluer le schéma courant. Ne pas créer une
seconde base applicative et ne pas convertir vers PostgreSQL.

Le projet étant encore exclusivement en développement, les anciens incréments
001 à 012 sont consolidés dans `001_initial.sql`. Une base vide atteint
directement le modèle courant. Cette base initiale peut encore être corrigée
avant son gel, avec reconstruction explicite de la base de développement,
sauvegarde de confort, `integrity_check` et `foreign_key_check`.

À partir du premier déploiement contenant des données à conserver, la base
initiale est gelée. Toute évolution suivante reçoit alors un nouveau numéro et
respecte sauvegarde, préconditions, transformation idempotente et contrôles.

## Évolutions proposées après gel

- dépenses et modèles récurrents ;
- factures récurrentes et échéances explicites ;
- ordres de paiement et lots pain.001 ;
- immobilisations, plans et dotations ;
- paie mensuelle et récapitulatifs annuels ;
- montants d'origine, devises et snapshots de taux de change ;
- groupes de consolidation, mappings et éliminations.

Les numéros ne sont pas réservés par le plan : l'agent vérifie le dépôt et
prend le prochain numéro libre seulement après le gel.

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
