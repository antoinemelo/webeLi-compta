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

Le catalogue initial couvre sept compétences : débit/crédit, TVA, facturation,
salaires, rapprochement, clôture et lecture d’états. Chaque fiche indique son
niveau, sa durée estimée, sa version, son nombre d’étapes et son barème. Le
bouton d’installation est idempotent : il ne crée pas de doublon lorsqu’une
compétence est déjà publiée.

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

L’espace Vue `/app/apprentissage` expose trois parcours :

- `Catalogue` pour consulter ou publier les scénarios versionnés ;
- `Exercices` pour ouvrir la copie isolée, journaliser, demander un indice,
  vérifier une écriture et repartir d’une copie vierge ;
- `Suivi` pour assigner individuellement ou par groupe, suivre les points,
  tentatives et contributeurs, autoriser une correction et exporter en CSV.

Les messages de réussite et d’échec appartiennent à la version du scénario.
Ils expliquent le contrôle comptable sans révéler la solution protégée.

## Réinitialisation et isolation

La réinitialisation est réservée aux dossiers `exercice`. Elle crée
atomiquement une nouvelle copie depuis le snapshot, archive l’ancienne,
réattribue les accès et écrit un événement d’audit. Le modèle et l’historique
des tentatives ne sont pas supprimés.

Un apprenant limité à ce rôle ne voit que ses attributions explicites. Les
organisations réelles, dossiers réels, recherches et sélecteurs associés sont
exclus de ses listes. Toutes les pages d’un dossier d’exercice affichent le
bandeau permanent « EXERCICE — DONNÉES FICTIVES ».

Le module se désactive dans `Configuration > Modules`. Le serveur refuse alors
à la fois la route Vue et toutes les routes `/api/v1/pedagogie`; masquer la
navigation côté client ne constitue pas le contrôle d’accès.
