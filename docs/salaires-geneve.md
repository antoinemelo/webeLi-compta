# Salaires genevois

Le module salarial appartient toujours à une organisation et à un dossier.
Employeur, employés, taux, tarifs, unités, mapping, fiches, dettes et paiements
ne sont donc jamais partagés implicitement entre deux dossiers.

## Mise en route

À l’ouverture de **Salaires**, le bloc **Préparation des salaires** expose les
prérequis au lieu de laisser échouer un calcul. Si l’employeur manque, le
formulaire est prérempli depuis l’identité légale de l’organisation puis doit
être explicitement confirmé. Le calcul reste désactivé tant que l’employeur et
un millésime de taux applicable ne sont pas disponibles.

Dans **Salaires → Annuels** :

1. contrôler ou modifier l’employeur du dossier ;
2. saisir tous les taux de l’année, leur source et leur date de vérification ;
3. créer, si nécessaire, les unités et tarifs proposés ;
4. rattacher chaque charge et dette à un compte du plan comptable du dossier.

Le mapping comptable n’est pas nécessaire au calcul d’un brouillon, mais il est
obligatoire avant sa validation et la création des dettes salariales.

Aucun taux annuel de production n’est fourni par défaut. Configurez
`OCAS_DB_PATH` vers la source SQLite des taux OCAS pour
prévisualiser puis confirmer un millésime. Une source absente ou incomplète est
signalée sans créer de valeurs. Les valeurs légales, contractuelles et
individuelles doivent être contrôlées avant chaque exercice.

## Cycle d’une fiche

Chaque employé reçoit un ou plusieurs contrats datés, horaires ou mensuels.
Le traitement d’une période distingue heures, absences, primes, indemnités et
ajustements. Une fiche est calculée en centimes et milli-heures. Le brouillon reçoit un
snapshot de l’employé, de l’employeur, des taux et de chaque composant. La
validation fige ces données et crée les dettes séparées : salaire net, OCAS,
LAA, LPP et impôt à la source.

Avant validation, un brouillon peut être repris dans le formulaire, recalculé
avec contrôle de version ou supprimé. Son employé et sa période restent fixes ;
un changement de périmètre exige de supprimer le brouillon puis d’en créer un
nouveau. Après validation, aucune édition ni suppression directe n’est permise.

La comptabilisation produit une écriture détaillée et équilibrée. Elle est
idempotente : rejouer la même action ne crée pas une seconde écriture. Une
fiche validée ne se modifie pas ; une correction passe par l’annulation et une
nouvelle fiche.

Les paiements sont indépendants des fiches et s’allouent aux dettes :

- un paiement à un employé ne peut couvrir que sa dette nette ;
- un paiement à un organisme ne peut couvrir que les charges correspondantes ;
- ni le paiement ni la dette ne peuvent être suralloués ;
- la fiche devient payée lorsque toutes ses dettes sont allouées.

## Import et exports

L’import JSON accepte la structure suivante :

```json
{
  "type": "fiches_salaires",
  "fiches": [{
    "numero_avs": "756.1234.5678.90",
    "annee": 2026,
    "mois": 7,
    "prestations": [{
      "libelle": "Cours",
      "unite_libelle": "Heure",
      "heures_unite_milli": 1000,
      "quantite_milli": 10000,
      "taux_horaire_centimes": 3000
    }]
  }]
}
```

La simulation n’écrit rien. L’application est atomique, et le couple AVS /
période rend le rejeu idempotent.

La fiche et le certificat XML annuel sont des sorties internes. Le certificat
suit les états préparé, contrôlé puis exporté. Le XML est archivé avec son
empreinte SHA-256 et signale explicitement qu’il n’est ni transmis ni certifié.

## Confidentialité et limites

Le numéro AVS et les données nominatives complètes exigent la permission
`salaires.pii`. Sans elle, l’AVS est masqué et les détails privés sont retirés.

Le périmètre fonctionnel est limité au canton de Genève. Le taux individuel
d’impôt à la source n’est pas un barème fiscal complet. Le taux LPP simple ne
modélise pas à lui seul l’âge, le seuil d’entrée, le salaire coordonné et toutes
les règles d’un plan de prévoyance. Il n’existe dans ce module ni endpoint,
ni dépendance, ni prétention de conformité Swissdec.
