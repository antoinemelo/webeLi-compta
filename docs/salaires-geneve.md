# Salaires genevois

Le module salarial appartient toujours à une organisation et à un dossier.
Employeur, employés, taux, tarifs, unités, mapping, fiches, dettes et paiements
ne sont donc jamais partagés implicitement entre deux dossiers.

## Mise en route

Dans **Salaires genevois → Paramètres** :

1. enregistrer l’employeur du dossier ;
2. saisir tous les taux de l’année, leur source et leur date de vérification ;
3. créer, si nécessaire, les unités et tarifs proposés ;
4. rattacher chaque charge et dette à un compte du plan comptable du dossier.

Aucun taux annuel de production n’est fourni par défaut. Les valeurs légales,
contractuelles et individuelles doivent être contrôlées avant chaque exercice.

## Cycle d’une fiche

Une fiche est calculée en centimes et milli-heures. Le brouillon reçoit un
snapshot de l’employé, de l’employeur, des taux et de chaque composant. La
validation fige ces données et crée les dettes séparées : salaire net, OCAS,
LAA, LPP et impôt à la source.

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

La fiche imprimable et le certificat XML annuel sont des sorties internes. Le
XML est archivé avec son empreinte SHA-256 et signale explicitement qu’il n’est
ni transmis ni certifié.

## Confidentialité et limites

Le numéro AVS et les données nominatives complètes exigent la permission
`salaires.pii`. Sans elle, l’AVS est masqué et les détails privés sont retirés.

Le périmètre fonctionnel est limité au canton de Genève. Le taux individuel
d’impôt à la source n’est pas un barème fiscal complet. Le taux LPP simple ne
modélise pas à lui seul l’âge, le seuil d’entrée, le salaire coordonné et toutes
les règles d’un plan de prévoyance. Il n’existe dans ce module ni endpoint,
ni dépendance, ni prétention de conformité Swissdec.
