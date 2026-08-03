# Tableau de bord comptable

Le tableau de bord Vue lit `GET /api/v1/dashboard` avec un exercice et une date
d’arrêté explicites. La projection interroge directement SQLite, sans cache,
table matérialisée ni écriture. Le dossier et l’organisation viennent
exclusivement de la session autorisée.

## Définitions

- Trésorerie comptable : somme débit moins crédit des écritures `validee` et
  `contre_passee` de l’exercice, jusqu’à la date incluse, multipliée par le
  sens configuré du compte de trésorerie. La carte « Trésorerie par compte »
  est ventilée par compte opérationnel (BCGe, UBS, etc.), même lorsque ces
  comptes alimentent tous le même compte général 1020.
- Solde bancaire : dernier solde importé à la date, avec priorité `CLBD`, puis
  `ITBD`. L’écart est affiché seulement si le solde bancaire et le compte sont
  dans la devise de base du dossier. L’écart total porte uniquement sur les
  comptes couverts par un solde bancaire comparable ; la couverture est
  affichée séparément.
- Chiffre d’affaires : solde créditeur net des comptes dont le type configuré
  est `produit`. Les factures ne sont jamais additionnées pour ce KPI.
- Charges : solde débiteur net des comptes dont le type configuré est
  `charge`. Un mouvement créditeur sur un tel compte diminue les charges.
- Créances et dettes : factures et avoirs `emis` ou `comptabilise`, datés au
  plus tard à l’arrêté et nets des allocations `valide`. Un avoir restant est
  négatif. Un paiement non alloué reste dans « paiements à traiter ».
- Échu : date d’échéance strictement antérieure à la date d’arrêté. Les tranches
  sont non échu, 1–30, 31–60, 61–90 et plus de 90 jours.
- Lignes bancaires à traiter : lignes d’un import confirmé, à la date, sans
  rapprochement bancaire confirmé.
- Activité récente : dix dernières écritures validées ou contre-passées,
  accompagnées d’un lien vers leur source connue ou vers le journal.

L’état des allocations est celui connu au moment du calcul. Le schéma actuel ne
date pas l’effet métier d’un lettrage ; le tableau de bord ne prétend donc pas
reconstituer l’historique d’un délettrage.

## Performance et index

Les agrégats ne renvoient qu’une ligne par famille, l’aging au maximum dix
lignes et l’activité dix écritures. Un test représentatif charge 500 écritures,
200 factures et 100 lignes bancaires, mesure la réponse sous le budget de
500 ms et vérifie `EXPLAIN QUERY PLAN`.

Les plans utilisent les index historiques :

- `idx_ecritures_journal` ;
- `idx_documents_scope_etat` ;
- `idx_lignes_bancaires_compte_date` ;
- les index d’allocations par document, paiement et avoir.

La mesure ne justifie aucun index supplémentaire propre au tableau de bord.

## Concordance et retour arrière

Les tests comparent les produits et charges au compte de résultat, la
trésorerie à la balance, le solde bancaire à `TreasuryStateService` et les
montants ouverts aux factures, paiements et avoirs. Ils couvrent également une
période fermée et un exercice vide.

Le retour arrière du code consiste à revenir à un commit qualifié. Si le schéma
a évolué depuis ce commit, la base revient exclusivement par restauration
d’une sauvegarde SQLite vérifiée ; aucune colonne n’est retirée manuellement.
