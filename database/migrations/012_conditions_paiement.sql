CREATE TABLE conditions_paiement (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    direction TEXT NOT NULL DEFAULT 'tous'
        CHECK (direction IN ('client', 'fournisseur', 'tous')),
    delai_jours INTEGER NOT NULL DEFAULT 30 CHECK (delai_jours BETWEEN 0 AND 3650),
    fin_de_mois INTEGER NOT NULL DEFAULT 0 CHECK (fin_de_mois IN (0, 1)),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (dossier_id, code, date_debut)
);
CREATE INDEX idx_conditions_paiement_scope_date
    ON conditions_paiement(dossier_id, direction, actif, date_debut, date_fin);

CREATE TABLE defauts_conditions_paiement (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    direction TEXT NOT NULL CHECK (direction IN ('client', 'fournisseur')),
    condition_id INTEGER NOT NULL REFERENCES conditions_paiement(id) ON DELETE RESTRICT,
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (dossier_id, direction, date_debut)
);
CREATE INDEX idx_defauts_conditions_scope_date
    ON defauts_conditions_paiement(dossier_id, direction, date_debut, date_fin);

CREATE TRIGGER trg_conditions_paiement_scope_insert
BEFORE INSERT ON conditions_paiement
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de condition de paiement invalide') END;
END;

CREATE TRIGGER trg_defauts_conditions_scope_insert
BEFORE INSERT ON defauts_conditions_paiement
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM conditions_paiement c
        WHERE c.id = NEW.condition_id
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
          AND c.actif = 1
          AND c.date_debut <= NEW.date_debut
          AND (c.date_fin IS NULL OR c.date_fin >= NEW.date_debut)
          AND (c.direction = 'tous' OR c.direction = NEW.direction)
    ) THEN RAISE(ABORT, 'condition par défaut invalide') END;
END;

ALTER TABLE documents_financiers
    ADD COLUMN condition_paiement_id INTEGER
        REFERENCES conditions_paiement(id) ON DELETE RESTRICT;
ALTER TABLE documents_financiers
    ADD COLUMN condition_paiement_snapshot_json TEXT NOT NULL DEFAULT '{}';

CREATE INDEX idx_documents_condition_paiement
    ON documents_financiers(dossier_id, condition_paiement_id);

CREATE TRIGGER trg_documents_condition_paiement_immuable
BEFORE UPDATE ON documents_financiers
WHEN OLD.statut <> 'brouillon'
 AND (
    NEW.condition_paiement_id IS NOT OLD.condition_paiement_id
    OR NEW.condition_paiement_snapshot_json <> OLD.condition_paiement_snapshot_json
 )
BEGIN
    SELECT RAISE(ABORT, 'condition de paiement d''un document émis immuable');
END;
