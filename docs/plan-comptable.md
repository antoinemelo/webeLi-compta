# Plan comptable configurable

L’écran Vue **Plan comptable** est disponible sous
`/app/configuration/referentiels/plan`, comme premier référentiel de
Configuration, après sélection d’un dossier. Les anciennes routes PHP
redirigent vers cette vue unique. Les modifications exigent la permission
`compta.setup`; la validation d’une ouverture exige aussi `compta.validate`.
Chaque plan est propre au couple organisation/dossier : types, règles,
rubriques, comptes et ordre peuvent diverger sans affecter un autre dossier.

## 1. Fonctionnement plus / moins et moins / plus

Chaque compte conserve un `sens_normal` explicite :

- `debit` : fonctionnement plus / moins ; le débit augmente le solde naturel ;
- `credit` : fonctionnement moins / plus ; le crédit augmente le solde naturel.

Les préfixes de `regles_sens_comptes` définissent les comptes automatiques en
moins / plus. Par défaut, les préfixes suisses `2` et `3` sont créés pour chaque
dossier ; tous les autres comptes automatiques sont en plus / moins.

Le champ `comptes.sens_mode` vaut :

- `automatique` pour appliquer les préfixes ;
- `debit` ou `credit` pour une exception explicite.

Cette exception permet notamment de conserver les comptes correcteurs du plan
VEB (`1069`, par exemple) sans faire du premier chiffre l’unique règle métier.
Une modification des préfixes recalcule `sens_normal` uniquement pour les
comptes automatiques. Les écritures historiques ne changent jamais : seules
leur présentation en solde naturel peut évoluer.

## 2. Rubriques et structure de bouclement

La structure suit les niveaux du plan comptable suisse PME :

- classe : numéro à un chiffre ;
- groupe principal : numéro à deux chiffres ;
- groupe : numéro à trois chiffres ;
- sous-groupe : sans numéro, pour faciliter la navigation ;
- compte : numéro unique à quatre chiffres.

Chaque rubrique référence explicitement son parent. Chaque compte référence sa
rubrique directe ; l’application restitue ensuite tout le chemin, par exemple
`1020 Avoirs en banque → 100 Trésorerie → 10 Actifs circulants → 1 ACTIFS`.
Le rattachement explicite permet d’éditer la structure sans déduire
silencieusement la rubrique du seul numéro du compte.

Les types fonctionnels sont limités à `actif`, `passif`, `produit`, `charge` et
`hors_bilan`; leurs libellés sont éditables dans l’onglet **Types de comptes**.
Les fonds propres font partie du passif. Le type est choisi uniquement sur une
classe : les groupes principaux, groupes et comptes l’héritent de leur parent.
Les rubriques et comptes dont le numéro commence par `9` appartiennent
obligatoirement au type `hors_bilan`.
Les rubriques peuvent être créées, renommées, déplacées vers un parent valide
et réordonnées par glisser-déposer. Une rubrique encore liée à des enfants ou
à des comptes doit d’abord être vidée avant son retrait.

## 3. Comptes

Le numéro est unique dans un dossier et comporte exactement quatre chiffres.
L’écran permet de créer et modifier le numéro, le libellé, le parent structurel
réel et le mode de fonctionnement. Le type du compte est déduit de ce parent.
Un parent de compte est toujours un groupe principal ou un groupe.
Le modèle ne crée aucun niveau artificiel pour combler une structure VEB qui
passe directement d’une classe au compte. Les écritures référencent
l’identifiant interne du compte : une renumérotation ou un reclassement
conserve donc les liens comptables.

Un compte jamais utilisé peut être supprimé. Un compte référencé par une
écriture est seulement désactivé afin de préserver l’historique.

## 4. Soldes d’ouverture

La saisie utilise un montant naturel signé :

- un montant positif est placé sur le sens normal du compte ;
- un montant négatif est placé sur le côté inverse.

Ainsi, `1000 Caisse = 100.00` produit un débit de CHF 100 et
`2000 Fournisseurs = 100.00` produit un crédit de CHF 100 lorsque les règles
usuelles `2, 3` sont actives.

Seuls les comptes de type actif et passif figurent dans l’ouverture ; les
produits, charges et comptes hors bilan en sont exclus. L’ouverture est
enregistrée en brouillon et peut être corrigée tant qu’elle
n’est pas validée. La validation exige au moins deux lignes, un équilibre exact
au centime, un exercice et une période ouverts. Après validation, elle est
immuable et toute correction passe par une contre-passation.

## Navigation

Les fonctions sont présentées dans cet ordre : types de comptes, règles de
sens, rubriques, comptes et ouverture. Les niveaux structurels ont leurs
propres contrôles compacts. Les tableaux s’étendent avec leur contenu : aucun
ascenseur vertical interne ne concurrence celui de la page. Vue ne calcule
aucune règle comptable ; toutes les mutations appellent l'API et les services
PHP qui alimentaient déjà le moteur.
