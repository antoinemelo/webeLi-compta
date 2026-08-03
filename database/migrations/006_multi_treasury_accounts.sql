-- Plusieurs comptes de trésorerie opérationnels peuvent partager un même
-- compte du grand livre. Chaque ligne comptable de trésorerie conserve son
-- compte opérationnel afin de garantir les soldes et rapprochements par banque.

PRAGMA defer_foreign_keys = ON;

DROP TRIGGER trg_comptes_tresorerie_scope_insert;
DROP TRIGGER trg_comptes_tresorerie_scope_update;
DROP TRIGGER trg_dossier_compte_facturation_insert;
DROP TRIGGER trg_dossier_compte_facturation_update;
DROP TRIGGER trg_imports_bancaires_scope_insert;
DROP TRIGGER trg_lignes_bancaires_scope_insert;
DROP TRIGGER trg_rapprochement_ligne_comptable_scope;

CREATE TABLE comptes_tresorerie_nouveau (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_comptable_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('banque', 'poste', 'caisse', 'carte')),
    iban TEXT NOT NULL DEFAULT '',
    bic TEXT NOT NULL DEFAULT '',
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (length(monnaie) = 3),
    multiplicateur_comptable INTEGER NOT NULL DEFAULT 1
        CHECK (multiplicateur_comptable IN (-1, 1)),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    archive_le TEXT,
    archive_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);

INSERT INTO comptes_tresorerie_nouveau (
    id, organisation_id, dossier_id, compte_comptable_id, libelle, type,
    iban, bic, monnaie, multiplicateur_comptable, actif, archive_le,
    archive_par, cree_le, cree_par, modifie_le, version
)
SELECT
    id, organisation_id, dossier_id, compte_comptable_id, libelle, type,
    iban, bic, monnaie, multiplicateur_comptable, actif, archive_le,
    archive_par, cree_le, cree_par, modifie_le, version
FROM comptes_tresorerie;

DROP TABLE comptes_tresorerie;
ALTER TABLE comptes_tresorerie_nouveau RENAME TO comptes_tresorerie;

CREATE INDEX idx_comptes_tresorerie_scope
    ON comptes_tresorerie(organisation_id, dossier_id, actif, type);

CREATE UNIQUE INDEX uq_comptes_tresorerie_iban
    ON comptes_tresorerie(dossier_id, iban)
    WHERE iban <> '';

CREATE TRIGGER trg_comptes_tresorerie_scope_insert
BEFORE INSERT ON comptes_tresorerie
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN comptes c ON c.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND c.id = NEW.compte_comptable_id
          AND c.organisation_id = NEW.organisation_id
          AND c.imputable = 1
    ) THEN RAISE(ABORT, 'scope de compte de trésorerie invalide') END;
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.dossier_id
          AND t.compte_comptable_id = NEW.compte_comptable_id
          AND (t.monnaie <> NEW.monnaie
               OR t.multiplicateur_comptable <> NEW.multiplicateur_comptable)
    ) THEN RAISE(ABORT, 'devise ou sens incompatible avec le compte comptable') END;
END;

CREATE TRIGGER trg_comptes_tresorerie_scope_update
BEFORE UPDATE ON comptes_tresorerie
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN comptes c ON c.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND c.id = NEW.compte_comptable_id
          AND c.organisation_id = NEW.organisation_id
          AND c.imputable = 1
    ) THEN RAISE(ABORT, 'scope de compte de trésorerie invalide') END;
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id <> NEW.id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.dossier_id
          AND t.compte_comptable_id = NEW.compte_comptable_id
          AND (t.monnaie <> NEW.monnaie
               OR t.multiplicateur_comptable <> NEW.multiplicateur_comptable)
    ) THEN RAISE(ABORT, 'devise ou sens incompatible avec le compte comptable') END;
END;

CREATE TRIGGER trg_dossier_compte_facturation_insert
BEFORE INSERT ON dossiers
WHEN NEW.compte_tresorerie_facturation_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id = NEW.compte_tresorerie_facturation_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.id
          AND t.actif = 1 AND t.iban <> ''
    ) THEN RAISE(ABORT, 'compte de facturation invalide') END;
END;

CREATE TRIGGER trg_dossier_compte_facturation_update
BEFORE UPDATE OF compte_tresorerie_facturation_id ON dossiers
WHEN NEW.compte_tresorerie_facturation_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id = NEW.compte_tresorerie_facturation_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.id
          AND t.actif = 1 AND t.iban <> ''
    ) THEN RAISE(ABORT, 'compte de facturation invalide') END;
END;

CREATE TRIGGER trg_imports_bancaires_scope_insert
BEFORE INSERT ON imports_bancaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id = NEW.compte_tresorerie_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope d''import bancaire invalide') END;
END;

CREATE TRIGGER trg_lignes_bancaires_scope_insert
BEFORE INSERT ON lignes_bancaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM imports_bancaires i
        JOIN comptes_tresorerie t ON t.id = i.compte_tresorerie_id
        WHERE i.id = NEW.import_id
          AND i.organisation_id = NEW.organisation_id
          AND i.dossier_id = NEW.dossier_id
          AND t.id = NEW.compte_tresorerie_id
    ) THEN RAISE(ABORT, 'scope de ligne bancaire invalide') END;
END;

ALTER TABLE lignes_ecriture
    ADD COLUMN compte_tresorerie_operationnel_id INTEGER
    REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT;

ALTER TABLE paiements
    ADD COLUMN compte_tresorerie_operationnel_id INTEGER
    REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT;

ALTER TABLE paiements_salaires
    ADD COLUMN compte_tresorerie_operationnel_id INTEGER
    REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT;

CREATE TRIGGER trg_rapprochement_ligne_comptable_scope
BEFORE INSERT ON rapprochement_lignes_comptables
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM rapprochements_bancaires r
        JOIN lignes_ecriture l
          ON l.compte_tresorerie_operationnel_id = r.compte_tresorerie_id
        JOIN ecritures e ON e.id = l.ecriture_id
        WHERE r.id = NEW.rapprochement_id
          AND l.id = NEW.ligne_ecriture_id
          AND e.organisation_id = r.organisation_id
          AND e.dossier_id = r.dossier_id
          AND e.statut IN ('validee', 'contre_passee')
          AND (l.debit_centimes - l.credit_centimes) = NEW.montant_centimes
    ) THEN RAISE(ABORT, 'ligne comptable hors rapprochement') END;
END;

DROP TRIGGER trg_lignes_scope_insert;
DROP TRIGGER trg_lignes_scope_update;

UPDATE lignes_ecriture
SET compte_tresorerie_operationnel_id = (
    SELECT t.id
    FROM comptes_tresorerie t
    JOIN ecritures e ON e.id = lignes_ecriture.ecriture_id
    WHERE t.organisation_id = e.organisation_id
      AND t.dossier_id = e.dossier_id
      AND t.compte_comptable_id = lignes_ecriture.compte_id
    LIMIT 1
)
WHERE EXISTS (
    SELECT 1
    FROM comptes_tresorerie t
    JOIN ecritures e ON e.id = lignes_ecriture.ecriture_id
    WHERE t.organisation_id = e.organisation_id
      AND t.dossier_id = e.dossier_id
      AND t.compte_comptable_id = lignes_ecriture.compte_id
);

UPDATE paiements
SET compte_tresorerie_operationnel_id = COALESCE(
    (SELECT l.compte_tresorerie_id
     FROM lignes_bancaires l WHERE l.id = paiements.ligne_bancaire_id),
    (SELECT t.id FROM comptes_tresorerie t
     WHERE t.organisation_id = paiements.organisation_id
       AND t.dossier_id = paiements.dossier_id
       AND t.compte_comptable_id = paiements.compte_tresorerie_id
     LIMIT 1)
)
WHERE compte_tresorerie_id IS NOT NULL;

UPDATE paiements_salaires
SET compte_tresorerie_operationnel_id = (
    SELECT t.id FROM comptes_tresorerie t
    WHERE t.organisation_id = paiements_salaires.organisation_id
      AND t.dossier_id = paiements_salaires.dossier_id
      AND t.compte_comptable_id = paiements_salaires.compte_tresorerie_id
    LIMIT 1
);

CREATE INDEX idx_lignes_tresorerie_operationnelle
    ON lignes_ecriture(compte_tresorerie_operationnel_id, ecriture_id);

CREATE TRIGGER trg_lignes_scope_insert
BEFORE INSERT ON lignes_ecriture
BEGIN
    SELECT CASE WHEN (SELECT statut FROM ecritures WHERE id = NEW.ecriture_id) <> 'brouillon'
        THEN RAISE(ABORT, 'écriture validée immuable') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM ecritures e
        JOIN comptes c
          ON c.id = NEW.compte_id
         AND c.dossier_id = e.dossier_id
         AND c.organisation_id = e.organisation_id
        WHERE e.id = NEW.ecriture_id
    ) THEN RAISE(ABORT, 'compte hors scope de l''écriture') END;
    SELECT CASE WHEN NEW.compte_tresorerie_operationnel_id IS NOT NULL
      AND NOT EXISTS (
        SELECT 1
        FROM ecritures e
        JOIN comptes_tresorerie t
          ON t.id = NEW.compte_tresorerie_operationnel_id
         AND t.compte_comptable_id = NEW.compte_id
         AND t.organisation_id = e.organisation_id
         AND t.dossier_id = e.dossier_id
        WHERE e.id = NEW.ecriture_id
      ) THEN RAISE(ABORT, 'compte de trésorerie opérationnel hors scope') END;
END;

CREATE TRIGGER trg_lignes_scope_update
BEFORE UPDATE ON lignes_ecriture
BEGIN
    SELECT CASE WHEN (SELECT statut FROM ecritures WHERE id = OLD.ecriture_id) <> 'brouillon'
        THEN RAISE(ABORT, 'écriture validée immuable') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM ecritures e
        JOIN comptes c
          ON c.id = NEW.compte_id
         AND c.dossier_id = e.dossier_id
         AND c.organisation_id = e.organisation_id
        WHERE e.id = NEW.ecriture_id
    ) THEN RAISE(ABORT, 'compte hors scope de l''écriture') END;
    SELECT CASE WHEN NEW.compte_tresorerie_operationnel_id IS NOT NULL
      AND NOT EXISTS (
        SELECT 1
        FROM ecritures e
        JOIN comptes_tresorerie t
          ON t.id = NEW.compte_tresorerie_operationnel_id
         AND t.compte_comptable_id = NEW.compte_id
         AND t.organisation_id = e.organisation_id
         AND t.dossier_id = e.dossier_id
        WHERE e.id = NEW.ecriture_id
      ) THEN RAISE(ABORT, 'compte de trésorerie opérationnel hors scope') END;
END;

CREATE TRIGGER trg_ecritures_tresorerie_ventilee
BEFORE UPDATE OF statut ON ecritures
WHEN OLD.statut = 'brouillon' AND NEW.statut = 'validee'
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1
        FROM lignes_ecriture l
        WHERE l.ecriture_id = NEW.id
          AND l.compte_tresorerie_operationnel_id IS NULL
          AND EXISTS (
            SELECT 1 FROM comptes_tresorerie t
            WHERE t.organisation_id = NEW.organisation_id
              AND t.dossier_id = NEW.dossier_id
              AND t.compte_comptable_id = l.compte_id
              AND t.actif = 1
          )
    ) THEN RAISE(ABORT, 'ligne de trésorerie non ventilée') END;
END;

DROP TRIGGER trg_paiements_salaires_scope;
CREATE TRIGGER trg_paiements_salaires_scope BEFORE INSERT ON paiements_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        JOIN comptes c ON c.id = NEW.compte_tresorerie_id
        WHERE d.id = NEW.dossier_id AND d.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
          AND c.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'paiement salaire hors scope') END;
    SELECT CASE WHEN NEW.compte_tresorerie_operationnel_id IS NOT NULL
      AND NOT EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id = NEW.compte_tresorerie_operationnel_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.dossier_id
          AND t.compte_comptable_id = NEW.compte_tresorerie_id
      ) THEN RAISE(ABORT, 'compte opérationnel du paiement salaire invalide') END;
    SELECT CASE WHEN NEW.employe_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM employes e WHERE e.id = NEW.employe_id
          AND e.dossier_id = NEW.dossier_id
          AND e.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'employé du paiement hors scope') END;
END;

DROP TRIGGER trg_paiements_scope_insert;
CREATE TRIGGER trg_paiements_scope_insert BEFORE INSERT ON paiements
BEGIN
    SELECT CASE WHEN NEW.contact_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM contacts c WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact du paiement hors scope') END;
    SELECT CASE WHEN NEW.compte_tresorerie_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes c WHERE c.id = NEW.compte_tresorerie_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'compte de trésorerie hors scope') END;
    SELECT CASE WHEN NEW.compte_tresorerie_operationnel_id IS NOT NULL
      AND NOT EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id = NEW.compte_tresorerie_operationnel_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.dossier_id
          AND t.compte_comptable_id = NEW.compte_tresorerie_id
      ) THEN RAISE(ABORT, 'compte de trésorerie opérationnel hors scope') END;
    SELECT CASE WHEN NEW.compte_collectif_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes c WHERE c.id = NEW.compte_collectif_id
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
          AND c.type IN ('actif', 'passif')
          AND c.actif = 1 AND c.imputable = 1
    ) THEN RAISE(ABORT, 'compte de paiement hors scope') END;
END;
