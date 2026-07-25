# Prompt 12 — Revue finale indépendante

Agis comme réviseur indépendant. N'implémente rien lors de la première passe.

Lis les spécifications, inspecte le code et vérifie par preuves :

1. invariants de partie double, immutabilité et contre-passation ;
2. exactitude TVA effective/TDFN, décomptes et eCH-0217 ;
3. exactitude des allocations et états de facture ;
4. parité des calculs salaires genevois Lasso et absence de Swissdec ;
5. isolation organisations/dossiers et rôles, surtout apprenants/PII ;
6. collaboration de groupe et conflits de concurrence sans perte ;
7. migrations, sauvegarde/restauration et intégrité ;
8. CSV/CAMT, QR-facture et sources normatives ;
9. compatibilité PHP 8.2 et sous-répertoires de deux instances ;
10. accessibilité et parcours principaux ;
11. absence de secrets/données réelles ;
12. couverture des scénarios d'acceptation et cohérence documentation/code.

Classe les constats en bloquant, majeur, mineur et suggestion. Pour chacun,
donne fichier/ligne, scénario reproductible, impact et correction minimale.
Exécute les tests pertinents toi-même. Termine par une décision :

- `REFUS` si un bloquant ou une preuve critique manque ;
- `ACCEPTATION CONDITIONNELLE` avec actions précises ;
- `PRÊT POUR PILOTE` seulement si toutes les gates critiques sont prouvées.
