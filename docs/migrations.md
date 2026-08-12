# Migrations et compatibilité de schéma

Ce guide décrit la référence de la version applicative **0.6.1**. Son schéma
SQLite courant est obtenu en appliquant, dans l'ordre, les migrations
immuables `001` à `008`.

## Couverture 0.6.1

| Version | Fichier | Objet principal |
|---|---|---|
| `001` | `001_initial.sql` | socle canonique, référentiels et modules métier |
| `002` | `002_consolidation_governance.sql` | gouvernance, modes et cycle de vie de la consolidation |
| `003` | `003_contact_employee_link.sql` | rattachement contrôlé d'un employé au registre de contacts |
| `004` | `004_deletable_financial_archives.sql` | suppression protégée des archives financières en double |
| `005` | `005_password_recovery.sql` | récupération du mot de passe par jeton à usage unique |
| `006` | `006_multi_treasury_accounts.sql` | ventilation des écritures et paiements par compte de trésorerie opérationnel |
| `007` | `007_financial_statement_rubric_subtotals.sql` | sous-totaux configurables des rubriques dans les états financiers |
| `008` | `008_payroll_payment_beneficiaries.sql` | bénéficiaire comptable précis des paiements salariaux |

`001_initial.sql` reste le socle d'une installation neuve, mais ne représente
pas seul le schéma 0.6.1. Une base neuve comme une base existante doit atteindre
la version `008`.

## Règles d'évolution

Les huit fichiers et leurs empreintes sont figés. Ils ne doivent être ni
réécrits, ni renommés, ni réordonnés. Le manifeste
`docs/baseline/migrations.sha256`, `db:migrate` et la qualification refusent
toute divergence.

La prochaine évolution structurelle doit être une migration additive `009_*`.
Elle doit être applicable atomiquement par le moteur de migration, préserver
les données existantes et être ajoutée au manifeste d'empreintes. Le schéma
initial ne doit pas être modifié pour intégrer rétroactivement une évolution.

## Installer ou mettre à niveau

Avant toute intervention sur une base en service :

```bash
php bin/console app:doctor
php bin/console db:migrate --plan
php bin/console db:integrity
```

Le plan doit présenter les versions déjà appliquées avec le statut `applied`,
les versions manquantes avec le statut `pending`, et aucun `mismatch` ni
`missing`.

L'application contrôlée crée une sauvegarde SQLite cohérente avant la première
mutation :

```bash
php bin/console db:migrate --apply --backup
php bin/console db:integrity
```

Sur une installation neuve, cette commande applique `001` à `008`. Sur une
ancienne base, elle applique uniquement le suffixe manquant, dans l'ordre. Un
second appel ne doit rien appliquer.

La base applicative peut être contrôlée directement :

```sql
SELECT version, checksum, applied_at
FROM schema_migrations
ORDER BY version;
```

Pour la version 0.6.1, huit lignes de `001` à `008` sont attendues.

## Qualification et livraison

Avant une livraison, exécuter la porte unique :

```bash
php bin/console qualify
```

Elle vérifie notamment les huit empreintes, l'application sur une base vide,
le rejeu idempotent, le refus d'un checksum altéré, les tests, le diagnostic et
l'intégrité SQLite. Une archive de version 0.6.1 doit embarquer les huit
fichiers de migration et le fichier `VERSION` contenant exactement `0.6.1`.

## Retour arrière

Une migration appliquée n'est jamais annulée en supprimant manuellement une
table, une colonne ou une ligne de `schema_migrations`. En cas d'échec :

1. arrêter les écritures sur l'instance concernée ;
2. remettre le code du commit précédemment qualifié ;
3. restaurer la sauvegarde SQLite créée avant la migration ;
4. exécuter `db:integrity`, puis contrôler le plan de migration attendu par ce
   commit.

Le retour arrière porte toujours ensemble sur le code et sa base compatible.
