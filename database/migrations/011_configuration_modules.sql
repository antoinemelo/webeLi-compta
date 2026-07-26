ALTER TABLE organisations ADD COLUMN raison_sociale TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN forme_juridique TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN numero_ide TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN adresse_ligne1 TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN adresse_ligne2 TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN code_postal TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN localite TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN canton TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN pays TEXT NOT NULL DEFAULT 'CH';
ALTER TABLE organisations ADD COLUMN telephone TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN email TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN site_web TEXT NOT NULL DEFAULT '';
ALTER TABLE organisations ADD COLUMN modifie_le TEXT;

CREATE UNIQUE INDEX uq_organisations_numero_ide
    ON organisations(numero_ide)
    WHERE numero_ide <> '';

CREATE TABLE modules_application (
    code TEXT PRIMARY KEY CHECK (
        code IN ('apprentissage', 'liquidites', 'facturation', 'comptabilite', 'salaires')
    ),
    libelle TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    ordre INTEGER NOT NULL DEFAULT 0,
    actif_global INTEGER NOT NULL DEFAULT 1 CHECK (actif_global IN (0, 1))
);

CREATE TABLE modules_dossier (
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE CASCADE,
    module_code TEXT NOT NULL REFERENCES modules_application(code) ON DELETE RESTRICT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (dossier_id, module_code)
);
CREATE INDEX idx_modules_dossier_scope
    ON modules_dossier(organisation_id, dossier_id, actif, module_code);

CREATE TRIGGER trg_modules_dossier_scope_insert
BEFORE INSERT ON modules_dossier
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de module invalide') END;
END;

CREATE TRIGGER trg_modules_dossier_scope_update
BEFORE UPDATE ON modules_dossier
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de module invalide') END;
END;

INSERT INTO modules_application (code, libelle, description, ordre) VALUES
    ('apprentissage', 'Apprentissage', 'Exercices et suivi pédagogique ciblé.', 10),
    ('liquidites', 'Liquidités', 'Dépenses, banque, lettrage et paiements.', 20),
    ('facturation', 'Facturation', 'Factures, avoirs, contacts et échéancier.', 30),
    ('comptabilite', 'Comptabilité', 'Journal, comptes et documents financiers.', 40),
    ('salaires', 'Salaires', 'Employés, calculs, fiches et décomptes annuels.', 50);

CREATE TRIGGER trg_dossiers_modules_insert
AFTER INSERT ON dossiers
BEGIN
    INSERT INTO modules_dossier (organisation_id, dossier_id, module_code)
    SELECT NEW.organisation_id, NEW.id, code
    FROM modules_application
    WHERE actif_global = 1;
END;

INSERT INTO modules_dossier (organisation_id, dossier_id, module_code)
SELECT d.organisation_id, d.id, m.code
FROM dossiers d
CROSS JOIN modules_application m;
