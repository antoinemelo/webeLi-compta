# Prompt 08 — Interface et accessibilité

Harmonise l'interface de tous les modules sans modifier leurs règles métier.

## Objectifs

- Navigation claire : instance, organisation, dossier, exercice et module
  toujours identifiables.
- Bandeaux/couleurs fortement distincts pour réel, démonstration et exercice,
  complétés par du texte et jamais utilisés comme contrôle d'autorisation.
- Tableau de bord actionnable.
- Formulaire d'écriture rapide au clavier avec contrôle d'équilibre en direct.
- Vues cohérentes des statuts, erreurs, confirmations et états vides.
- Responsive mobile et impression.
- WCAG 2.2 AA : structure, labels, focus, contraste, erreurs, annonces.
- JavaScript progressif : toutes les mutations importantes restent possibles
  et validées côté serveur.
- Aucun CDN ni collecte externe.
- URLs et assets corrects lorsque l'application est servie sous un sous-répertoire.

## Validation

Teste les parcours clavier, zoom 200 %, largeur 360 px et impression. Ajoute des
tests E2E des parcours critiques et une courte checklist d'accessibilité vérifiée.
Ne remplace pas du texte essentiel par une icône sans libellé accessible.
Teste également deux utilisateurs d'un même groupe et le dialogue de conflit 409.
