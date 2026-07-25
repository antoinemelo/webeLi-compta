# Décisions métier confirmées

Ces décisions ont été confirmées le 25 juillet 2026. Elles remplacent les
hypothèses de la première version.

| Sujet | Décision |
|---|---|
| Nom du produit | Nom provisoire `Compta` ; marque configurable en base |
| PHP minimal | 8.2 |
| Hébergement | Mutualisé Apache/PHP, webroot `public/` |
| Périmètre | Suisse, CHF, interface française au MVP |
| Multi-organisations | Plusieurs organisations par installation, sans consolidation |
| Multi-dossiers | Plusieurs mandats par organisation et exercices par mandat |
| Plan comptable | Plan suisse PME VEB officiel + overlay association, source citée |
| TVA | Opérationnelle au MVP : effective/TDFN, décompte et eCH-0217 |
| Numérotation factures | Séquence par dossier et année, `F-AAAA-NNN` |
| Créanciers | Factures fournisseurs au MVP, sans workflow d'approbation |
| Imports bancaires | CSV et CAMT ; `camt.053`/`.054` au MVP |
| QR-facture | Oui pour factures clients, dépendances vendorizées |
| PDF | TCPDF seulement si nécessaire ; impression HTML ailleurs |
| Salaires | Genève uniquement |
| Swissdec | Aucune transmission |
| Impôt à la source | Taux individuel Lasso, limite clairement affichée |
| LPP | Taux paramétrique initial, architecture extensible |
| Certificat salaire | XML/PDF, format officiel courant à valider |
| E-mail | SMTP ; file simple de relance, aucun daemon obligatoire |
| Suppression | Archivage ; contre-passation pour comptabilité validée |
| Rétention audit | 10 ans par défaut pour dossiers réels, à confirmer juridiquement |
| Enseignement | Individuel et groupes collaboratifs multi-postes |
| École/production | Peuvent coexister dans une même installation avec isolation forte |
| Déploiements | Copies autonomes dans différents répertoires selon le contexte |
| Événements/SUISA | Extension après MVP |

## Modèle de déploiement

Le produit ne force ni séparation ni regroupement. Chaque répertoire installé est
une instance autonome (`edu`, `entreprise-1`, `maison-a`, etc.). Une instance peut
contenir plusieurs organisations et mélanger usages scolaires/réels si les
permissions le permettent.

L'interface et les services doivent rendre toute confusion difficile :

- contexte organisation/dossier/exercice toujours visible ;
- couleurs et bandeaux distincts pour réel, démonstration et exercice ;
- scopes stricts sur navigation, recherche, exports et actions ;
- apprenant sans droit sur une organisation réelle par défaut ;
- impossibilité technique de réinitialiser un dossier réel ;
- nom/path de cookie, stockage, base URL et sauvegardes propres à l'instance.

## Limites assumées

- aucune consolidation entre organisations ;
- aucune transmission Swissdec ;
- aucun workflow d'approbation de facture fournisseur ;
- pas d'édition temps réel caractère par caractère : collaboration multi-postes
  par transactions courtes, version optimiste et conflits explicites.
