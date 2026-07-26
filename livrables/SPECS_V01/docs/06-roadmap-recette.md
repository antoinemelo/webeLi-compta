# Roadmap et recette

## Lots

### Lot 0 — Décisions et preuve de concept

- Enregistrer les choix de `07-decisions-confirmees.md`.
- Créer le squelette, la connexion SQLite et une migration.
- Prouver une écriture équilibrée en centimes et un rapport de balance.

Sortie : architecture validée, aucune reprise massive encore engagée.

### Lot 1 — Noyau exploitable

- Authentification, organisations, dossiers, rôles et audit.
- Support de plusieurs instances en sous-répertoires.
- Migrations, sauvegarde/restauration, diagnostic.
- Layout accessible et changement de contexte.

### Lot 2 — Comptabilité générale

- Plan PME suisse VEB et overlay association.
- Exercices, périodes, journaux.
- Brouillons, validation, contre-passation.
- Journal, grand livre, balance, bilan et résultat.

### Lot 3 — Trésorerie

- Comptes banque/caisse.
- Imports CSV et CAMT prévisualisés/idempotents.
- Rapprochement et état de liquidités.

### Lot 4 — Débiteurs et créanciers

- Contacts, factures clients/fournisseurs, échéances.
- QR-facture/PDF compatible PHP 8.2.
- Paiements partiels, allocations, retards et avoirs.
- Aucun workflow d'approbation des factures fournisseurs.

### Lot 5 — TVA

- Codes et taux datés, méthode effective et TDFN.
- TVA sur ventes/achats, corrections, décomptes et drill-down.
- Export eCH-0217 validé.

### Lot 6 — Salaires genevois

- Portage des calculs et tests OCAS.
- Fiches figées, certificats et exports, sans transmission Swissdec.
- Comptabilisation et suivi des dettes/paiements.

### Lot 7 — Enseignement

- Modèles, assignations individuelles et groupes multi-postes.
- Concurrence, validations, indices et reset.
- Isolation apprenants/formateurs et bandeau exercice.

### Lot 8 — Durcissement et release

- Accessibilité, performance, sécurité, imports historiques.
- Tests E2E, restauration, migration d'une copie réelle anonymisée.
- Package de release et guides.

## Pyramide de tests

- **Unitaires** : arrondis, TVA, salaires, soldes, états, allocations, pédagogie.
- **Intégration SQLite** : transactions, concurrence, migrations, idempotence, RBAC.
- **HTTP** : CSRF, scopes organisation/dossier, conflits 409, téléchargements.
- **E2E** : scénarios métier majeurs dans une instance jetable.
- **Release** : installation neuve, upgrade, backup/restore, smoke HTTP.

## Scénarios d'acceptation obligatoires

1. Passer une écriture 1000 Banque / 3000 Produit de CHF 100 et obtenir une
   balance équilibrée, un actif +100 et un produit +100.
2. Refuser une écriture déséquilibrée ou dans une période fermée.
3. Contre-passer sans modifier l'original.
4. Importer deux fois le même relevé sans doubler les mouvements.
5. Importer des fixtures `camt.053` et `camt.054` de deux namespaces supportés.
6. Rapprocher un virement interne entre deux banques sans charge/produit.
7. Émettre une facture de CHF 1'000, allouer deux paiements de 400 et 600, puis
   constater successivement `partiellement_payee` et `payee`.
8. Interdire une allocation totale de 1'001.
9. Enregistrer une facture fournisseur et la dette correspondante.
10. Produire un décompte TVA méthode effective avec plusieurs taux et impôt
    préalable, puis un export eCH-0217 validé.
11. Produire un décompte TDFN sans déduire l'impôt préalable ordinaire.
12. Recalculer les cas de référence OCAS avec résultats identiques au centime.
13. Modifier les taux 2027 sans changer une fiche 2026 validée.
14. Comptabiliser une paie genevoise et solder séparément net et charges sociales.
15. Empêcher un apprenant d'accéder à un dossier réel, même avec un ID deviné.
16. Faire saisir simultanément deux membres d'un groupe sans perte de données ;
    provoquer un conflit sur le même brouillon et obtenir un HTTP 409 explicite.
17. Réinitialiser un exercice sans affecter son modèle ni un autre groupe.
18. Migrer une base N-1 après sauvegarde, puis réussir les contrôles d'intégrité.
19. Restaurer l'archive dans une cible temporaire et comparer les totaux clés.
20. Installer deux copies sous deux sous-répertoires du même hôte et prouver
    l'absence de collision de session, stockage, URL et sauvegarde.
21. Installer le package sous PHP 8.2 et générer une QR-facture.

## Gate avant mise en production

La livraison est refusée si l'un de ces éléments échoue :

- lint PHP ;
- tests unitaires/intégration/HTTP ;
- scénarios E2E critiques ;
- `foreign_key_check` ou `integrity_check` ;
- backup/restore aller-retour ;
- installation neuve du ZIP ;
- migration depuis la version publiée précédente ;
- audit de dépendances critique non accepté ;
- fuite de secret, base, document ou donnée personnelle dans le paquet ;
- compatibilité PHP minimale annoncée ;
- validation eCH-0217 et compatibilité des fixtures CAMT annoncées.

## Définition de terminé pour chaque lot

- code, migration et rollback opérationnel documenté ;
- tests des cas positifs et négatifs ;
- documentation utilisateur et exploitant à jour ;
- aucune donnée fabriquée laissée dans l'instance ;
- rapport de validation joint ;
- changements limités au lot, revus et réversibles par restauration.
