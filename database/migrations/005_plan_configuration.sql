ALTER TABLE comptes ADD COLUMN sens_mode TEXT NOT NULL DEFAULT 'automatique'
    CHECK (sens_mode IN ('automatique', 'debit', 'credit'));

CREATE TABLE regles_sens_comptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    prefixe TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, prefixe),
    CHECK (length(prefixe) BETWEEN 1 AND 20),
    CHECK (prefixe NOT GLOB '*[^0-9]*')
);
CREATE INDEX idx_regles_sens_scope
    ON regles_sens_comptes(dossier_id, prefixe);

CREATE TABLE rubriques_comptables (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    prefixe TEXT NOT NULL,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (
        type IN ('actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan')
    ),
    ordre INTEGER NOT NULL DEFAULT 0,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    source_modele TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, prefixe),
    CHECK (length(prefixe) BETWEEN 1 AND 20),
    CHECK (prefixe NOT GLOB '*[^0-9]*')
);
CREATE INDEX idx_rubriques_scope
    ON rubriques_comptables(dossier_id, actif, ordre, prefixe);

CREATE TRIGGER trg_regles_sens_scope_insert
BEFORE INSERT ON regles_sens_comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de règle de sens invalide') END;
END;

CREATE TRIGGER trg_regles_sens_scope_update
BEFORE UPDATE ON regles_sens_comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de règle de sens invalide') END;
END;

CREATE TRIGGER trg_rubriques_scope_insert
BEFORE INSERT ON rubriques_comptables
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de rubrique comptable invalide') END;
END;

CREATE TRIGGER trg_rubriques_scope_update
BEFORE UPDATE ON rubriques_comptables
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de rubrique comptable invalide') END;
END;

CREATE TRIGGER trg_dossiers_plan_configuration_defaults
AFTER INSERT ON dossiers
BEGIN
    INSERT INTO regles_sens_comptes
        (organisation_id, dossier_id, prefixe)
    VALUES
        (NEW.organisation_id, NEW.id, '2'),
        (NEW.organisation_id, NEW.id, '3');
    INSERT INTO parametres_dossier (dossier_id, cle, valeur)
    VALUES (NEW.id, 'plan_sens_initialise', '1');
END;

INSERT INTO regles_sens_comptes (organisation_id, dossier_id, prefixe)
SELECT organisation_id, id, '2' FROM dossiers;

INSERT INTO regles_sens_comptes (organisation_id, dossier_id, prefixe)
SELECT organisation_id, id, '3' FROM dossiers;

INSERT OR IGNORE INTO parametres_dossier (dossier_id, cle, valeur)
SELECT id, 'plan_sens_initialise', '1' FROM dossiers
;

UPDATE comptes
SET sens_mode = CASE
    WHEN sens_normal = CASE
        WHEN numero LIKE '2%' OR numero LIKE '3%' THEN 'credit'
        ELSE 'debit'
    END THEN 'automatique'
    ELSE sens_normal
END;

INSERT OR IGNORE INTO rubriques_comptables
    (organisation_id, dossier_id, prefixe, libelle, type, ordre, source_modele)
SELECT organisation_id, dossier_id, numero, libelle, type,
       (niveau * 100000) + id, source_modele
FROM comptes
WHERE imputable = 0;
