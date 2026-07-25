# Prompt 03 — Trésorerie

Implémente `Tresorerie` sur l'API interne de `Compta`.

## Livrables

- Comptes banque, poste, caisse et carte associés à un compte comptable.
- Import PostFinance CSV repris des cas testés de Lasso.
- Import ISO 20022 `camt.053` et `camt.054` selon les Swiss Payment Standards SIX.
- Architecture de parseurs par type et namespace/version, avec XML source conservé.
- Prévisualisation : compte, période, lignes, erreurs et doublons.
- Empreinte idempotente qui conserve les vrais doublons d'un même relevé.
- Lignes bancaires immuables après import.
- Suggestions de comptabilisation, jamais validées silencieusement.
- Rapprochements 1–1, 1–N et N–1.
- État solde bancaire, solde comptable et différence.
- Flux explicite pour virement interne.

## Tests obligatoires

- Réimport sans doublon ; doublons légitimes conservés.
- Débit/crédit et dates PostFinance correctement normalisés.
- Fixtures CAMT de plusieurs namespaces : entrées, détails, charges, références
  SCOR/QR, IBAN, soldes et écritures groupées correctement normalisés.
- XML externe traité sans résolution d'entités ni accès réseau (XXE interdit).
- Double consommation d'une ligne refusée.
- Sommes d'un rapprochement égales.
- Virement interne équilibré sans produit/charge.
- Isolation stricte des dossiers.
