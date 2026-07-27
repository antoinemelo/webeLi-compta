# Interface et accessibilité

L’interface Vue nécessite JavaScript. Les règles comptables, autorisations,
versions et jetons CSRF restent validés côté serveur.

## Contexte et environnements

L’en-tête authentifié expose l’organisation et le dossier actifs, suivis de
l’exercice et de la devise de référence. Le type du dossier ne sert jamais de
contrôle d’accès et aucun bandeau de démonstration ne surcharge l’espace de
travail.

## Navigation et saisie

La navigation principale devient un panneau mobile sur petit écran et reste
une barre latérale permanente sur grand écran. Le lien d’évitement mène
directement au contenu. La page courante utilise `aria-current`.

La saisie comptable suit l’ordre clavier en-tête, lignes, brouillon, validation.
Les totaux débit/crédit et la différence sont annoncés avec `aria-live`. Le
service comptable contrôle ensuite les comptes, la période, le journal,
l’équilibre et les droits. Le plan comptable se réordonne soit à la souris, soit
au clavier avec `Flèche haut` et `Flèche bas`.

## Checklist vérifiée

- [x] structure `lang`, titre, en-tête, navigation, contenu principal et lien
  d’évitement ;
- [x] libellés explicites ou noms accessibles pour les champs et actions ;
- [x] focus visible renforcé, messages d’erreur `role=alert`, confirmations et
  changements annoncés ;
- [x] actions essentielles exprimées par du texte, sans icône seule ;
- [x] mise en page fluide à 360 px et sans largeur de page fixe à 200 % ;
- [x] tableaux larges limités à un défilement horizontal local, aucun
  défilement vertical imbriqué ;
- [x] feuille d’impression A4 sans navigation, boutons ni ombres ;
- [x] mouvement réduit respecté avec `prefers-reduced-motion` ;
- [x] ressources locales et chemins compatibles avec un sous-répertoire ;
- [x] parcours HTTP protégés par authentification, permissions, CSRF et
  contrôle serveur ;
- [x] conflit d’édition collaborative retourné en HTTP 409 sans écrasement.

Les contrôles correspondants sont exécutés par `php bin/console test`. La
recette de publication doit en plus parcourir au clavier le tableau de bord, la
saisie, le plan, la facturation, les salaires et l’enseignement dans le
navigateur réellement déployé.
