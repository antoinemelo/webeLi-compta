# Comptabilité dans Vue

Les parcours quotidiens de comptabilité sont servis sous `/app/compta` :

- `journalisation` : saisie composée et écritures récentes ;
- `extraits` : grand livre et représentation en compte en T ;
- `etats` : balances de vérification, bilan, compte de résultat, flux de
  trésorerie et archives ;
- `cloture` : amortissements, TVA, contrôles et dossier fiscal ;
- `consolidation` : agrégation interne ou consolidation légale.

Le plan est administré depuis
`/app/configuration/referentiels/plan` : types, règles de sens, rubriques,
comptes et ouverture.

## Architecture

`AccountingApiController` valide HTTP, session, CSRF et permissions.
`AccountingWorkspaceService` orchestre ensuite les services métier existants :

- `EntryService` pour les écritures et ouvertures ;
- `ChartOfAccountsService` pour le plan ;
- `ReportingService` pour journal, extraits, états et archives.

Vue ne contient ni SQL, ni calcul de solde, ni règle de validation comptable.
Les montants de l'API sont des centimes entiers. L'organisation et le dossier
proviennent exclusivement de la session ; leur injection dans une charge utile
est rejetée.

## Fin des doubles parcours

Les anciennes routes `/compta`, `/compta/saisie`, `/compta/compte` et
`/compta/plan` redirigent vers leur équivalent Vue. Leurs gabarits et le script
historique du plan ont été retirés. Journal, extraits, états financiers,
archives, clôture et dossier fiscal utilisent désormais le même parcours Vue ;
il ne subsiste aucun écran PHP parallèle.

## Journal et navigation vers les sources

Les écritures récentes se trient par date, numéro, compte débité, compte
crédité, libellé, montant ou statut. Les actions globales du journal sont
regroupées sous « ⋮ » : journal détaillé, export CSV et import CSV. Le statut
`brouillon` est un lien de reprise ; un brouillon peut être finalisé ou supprimé
définitivement. Lorsqu’une écriture résumée n’a pas le même total au débit et au
crédit, son montant est présenté comme `-,--`.

Les numéros de compte ouvrent l’extrait correspondant et exposent le libellé au
survol. Une référence `FV-…` ou `FA-…` ouvre la facture source, aussi bien dans
les écritures récentes que dans le journal détaillé. Depuis **Écritures
récentes**, le numéro de l’écriture ouvre la fenêtre détaillée en la limitant à
cette seule opération. Dans la fenêtre elle-même, les numéros d’écriture restent
du texte simple ; les comptes ne sont également plus des liens lorsque la vue
est limitée à une opération. La fenêtre utilise la largeur disponible afin de
préserver les colonnes et limite le retour à la ligne.

## Schéma de développement

La version 0.6.1 est construite par les migrations immuables `001` à `008`.
`001_initial.sql` fournit le socle canonique ; `002` à `008` ajoutent la
gouvernance de consolidation, le lien contact/employé, la suppression
contrôlée des archives, la récupération du mot de passe, la ventilation des
comptes de trésorerie opérationnels, les sous-totaux des états financiers et
les bénéficiaires précis des paiements salariaux.

Une installation neuve applique les huit versions. Une base existante reçoit
uniquement les versions manquantes avec
`php bin/console db:migrate --apply --backup`. Les fichiers `001` à `008` ne
sont plus modifiés ; la prochaine évolution structurelle commence à `009`.
Voir [`migrations.md`](migrations.md).

## Retour arrière

Le code revient par le commit Git précédent. Une base reconstruite revient
uniquement par restauration de sa sauvegarde ; aucune tentative de suppression
manuelle de tables, colonnes ou lignes de `schema_migrations` n'est supportée.
