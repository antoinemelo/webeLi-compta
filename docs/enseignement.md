# Enseignement

Le module `Pedagogie` orchestre le moteur comptable existant. Il ne possède pas
de moteur parallèle : les copies utilisent les mêmes comptes, écritures,
validations et rapports que les autres dossiers.

## Modèles et copies

Un modèle appartient à une organisation pédagogique. Chaque publication crée
une version immuable contenant :

- le snapshot du plan comptable, des journaux, exercices et périodes ;
- les soldes et données initiales ;
- les consignes et étapes ordonnées ;
- les indices gradués et validateurs déclaratifs ;
- la solution et sa règle d’ouverture.

Un dossier réel ne peut jamais servir de source. L’assignation clone la version
dans un nouveau dossier de type `exercice`, individuel ou partagé par un groupe.
Le modèle et chaque copie conservent ainsi des identifiants et données séparés.

## Collaboration

Les membres travaillent par transactions courtes. Chaque mutation conserve son
auteur. Deux créations distinctes sont conservées ; la modification d’un même
brouillon exige sa version courante. Une version périmée reçoit une réponse HTTP
409 et n’écrase rien.

Le retrait daté d’un membre révoque immédiatement son rôle sur le dossier
partagé. Les contributions individuelles restent dans l’historique du groupe.

## Validation et accompagnement

Les règles peuvent contrôler les comptes, sens, montants, effets comptables
équivalents, soldes et résultat. Une écriture répartie différemment reste donc
acceptable si son effet agrégé correspond à la règle.

Chaque tentative est immuable. Les indices sont révélés dans leur ordre et leur
consultation est tracée. La solution n’est chargée qu’après autorisation
manuelle, seuil de tentatives ou date configurée.

## Réinitialisation et isolation

La réinitialisation est réservée aux dossiers `exercice`. Elle crée
atomiquement une nouvelle copie depuis le snapshot, archive l’ancienne,
réattribue les accès et écrit un événement d’audit. Le modèle et l’historique
des tentatives ne sont pas supprimés.

Un apprenant limité à ce rôle ne voit que ses attributions explicites. Les
organisations réelles, dossiers réels, recherches et sélecteurs associés sont
exclus de ses listes. Toutes les pages d’un dossier d’exercice affichent le
bandeau permanent « EXERCICE — DONNÉES FICTIVES ».
