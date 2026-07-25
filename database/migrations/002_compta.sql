CREATE TABLE modeles_plan_comptable (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL,
    version TEXT NOT NULL,
    libelle TEXT NOT NULL,
    source_url TEXT NOT NULL,
    attribution TEXT NOT NULL,
    est_overlay INTEGER NOT NULL DEFAULT 0 CHECK (est_overlay IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (code, version)
);

CREATE TABLE modele_comptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    modele_id INTEGER NOT NULL REFERENCES modeles_plan_comptable(id) ON DELETE CASCADE,
    variante TEXT NOT NULL DEFAULT 'commun',
    numero TEXT NOT NULL,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (
        type IN ('actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan')
    ),
    sens_normal TEXT NOT NULL CHECK (sens_normal IN ('debit', 'credit')),
    parent_numero TEXT,
    niveau INTEGER NOT NULL CHECK (niveau BETWEEN 1 AND 9),
    imputable INTEGER NOT NULL DEFAULT 1 CHECK (imputable IN (0, 1)),
    marque TEXT NOT NULL DEFAULT '',
    parametre_requis TEXT NOT NULL DEFAULT '',
    ordre INTEGER NOT NULL DEFAULT 0,
    UNIQUE (modele_id, variante, numero)
);
CREATE INDEX idx_modele_comptes_ordre
    ON modele_comptes(modele_id, variante, ordre, numero);

CREATE TABLE periodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'ouverte' CHECK (statut IN ('ouverte', 'fermee')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_debut <= date_fin),
    UNIQUE (exercice_id, date_debut, date_fin)
);
CREATE INDEX idx_periodes_scope ON periodes(dossier_id, exercice_id, date_debut, date_fin);

CREATE TABLE journaux (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (
        type IN ('general', 'achats', 'ventes', 'banque', 'caisse', 'salaires', 'ouverture')
    ),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, code)
);

CREATE TABLE comptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    numero TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (
        type IN ('actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan')
    ),
    sens_normal TEXT NOT NULL CHECK (sens_normal IN ('debit', 'credit')),
    parent_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    niveau INTEGER NOT NULL DEFAULT 1 CHECK (niveau BETWEEN 1 AND 9),
    imputable INTEGER NOT NULL DEFAULT 1 CHECK (imputable IN (0, 1)),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    marque TEXT NOT NULL DEFAULT '',
    source_modele TEXT NOT NULL DEFAULT '',
    source_version TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, numero)
);
CREATE INDEX idx_comptes_scope_type ON comptes(dossier_id, type, actif, numero);

CREATE TABLE ecritures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    journal_id INTEGER NOT NULL REFERENCES journaux(id) ON DELETE RESTRICT,
    numero TEXT NOT NULL DEFAULT '',
    date_comptable TEXT NOT NULL,
    libelle TEXT NOT NULL,
    reference TEXT NOT NULL DEFAULT '',
    piece TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'validee', 'contre_passee')),
    source_type TEXT NOT NULL DEFAULT 'manuel',
    source_id TEXT NOT NULL DEFAULT '',
    source_action TEXT NOT NULL DEFAULT '',
    cle_idempotence TEXT,
    empreinte_commande TEXT,
    contrepassation_de_id INTEGER UNIQUE REFERENCES ecritures(id) ON DELETE RESTRICT,
    validee_le TEXT,
    validee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX uq_ecritures_numero
    ON ecritures(dossier_id, numero)
    WHERE numero <> '';
CREATE UNIQUE INDEX uq_ecritures_idempotence
    ON ecritures(dossier_id, cle_idempotence)
    WHERE cle_idempotence IS NOT NULL;
CREATE UNIQUE INDEX uq_ecritures_source
    ON ecritures(dossier_id, source_type, source_id, source_action)
    WHERE source_type <> 'manuel' AND source_id <> '';
CREATE INDEX idx_ecritures_journal
    ON ecritures(dossier_id, exercice_id, date_comptable, journal_id, statut);

CREATE TABLE lignes_ecriture (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ecriture_id INTEGER NOT NULL REFERENCES ecritures(id) ON DELETE CASCADE,
    compte_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL DEFAULT '',
    debit_centimes INTEGER NOT NULL DEFAULT 0 CHECK (debit_centimes >= 0),
    credit_centimes INTEGER NOT NULL DEFAULT 0 CHECK (credit_centimes >= 0),
    ordre INTEGER NOT NULL DEFAULT 0,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (
        (debit_centimes > 0 AND credit_centimes = 0)
        OR (credit_centimes > 0 AND debit_centimes = 0)
    ),
    UNIQUE (ecriture_id, ordre)
);
CREATE INDEX idx_lignes_compte ON lignes_ecriture(compte_id, ecriture_id);

CREATE TABLE sequences_journaux (
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    journal_id INTEGER NOT NULL REFERENCES journaux(id) ON DELETE RESTRICT,
    dernier_numero INTEGER NOT NULL DEFAULT 0 CHECK (dernier_numero >= 0),
    PRIMARY KEY (exercice_id, journal_id)
);

CREATE TRIGGER trg_periodes_scope_insert
BEFORE INSERT ON periodes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN exercices x ON x.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND x.id = NEW.exercice_id
          AND NEW.date_debut >= x.date_debut
          AND NEW.date_fin <= x.date_fin
    ) THEN RAISE(ABORT, 'scope ou dates de période invalides') END;
END;

CREATE TRIGGER trg_periodes_scope_update
BEFORE UPDATE ON periodes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN exercices x ON x.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND x.id = NEW.exercice_id
          AND NEW.date_debut >= x.date_debut
          AND NEW.date_fin <= x.date_fin
    ) THEN RAISE(ABORT, 'scope ou dates de période invalides') END;
END;

CREATE TRIGGER trg_journaux_scope_insert
BEFORE INSERT ON journaux
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de journal invalide') END;
END;

CREATE TRIGGER trg_journaux_scope_update
BEFORE UPDATE ON journaux
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de journal invalide') END;
END;

CREATE TRIGGER trg_comptes_scope_insert
BEFORE INSERT ON comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de compte invalide') END;
    SELECT CASE WHEN NEW.parent_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes
        WHERE id = NEW.parent_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'parent de compte hors scope') END;
END;

CREATE TRIGGER trg_comptes_scope_update
BEFORE UPDATE ON comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de compte invalide') END;
    SELECT CASE WHEN NEW.parent_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes
        WHERE id = NEW.parent_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'parent de compte hors scope') END;
END;

CREATE TRIGGER trg_ecritures_scope_insert
BEFORE INSERT ON ecritures
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN exercices x ON x.dossier_id = d.id
        JOIN journaux j ON j.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND x.id = NEW.exercice_id
          AND j.id = NEW.journal_id
          AND j.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope d''écriture invalide') END;
END;

CREATE TRIGGER trg_ecritures_scope_update
BEFORE UPDATE ON ecritures
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN exercices x ON x.dossier_id = d.id
        JOIN journaux j ON j.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND x.id = NEW.exercice_id
          AND j.id = NEW.journal_id
          AND j.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope d''écriture invalide') END;
END;

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
END;

CREATE TRIGGER trg_ecritures_delete
BEFORE DELETE ON ecritures
WHEN OLD.statut <> 'brouillon'
BEGIN
    SELECT RAISE(ABORT, 'écriture validée immuable');
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
END;

CREATE TRIGGER trg_lignes_delete
BEFORE DELETE ON lignes_ecriture
WHEN (SELECT statut FROM ecritures WHERE id = OLD.ecriture_id) <> 'brouillon'
BEGIN
    SELECT RAISE(ABORT, 'écriture validée immuable');
END;

CREATE TRIGGER trg_ecritures_contenu_immuable
BEFORE UPDATE ON ecritures
WHEN OLD.statut <> 'brouillon' AND (
    NEW.organisation_id <> OLD.organisation_id
    OR NEW.dossier_id <> OLD.dossier_id
    OR NEW.exercice_id <> OLD.exercice_id
    OR NEW.journal_id <> OLD.journal_id
    OR NEW.numero <> OLD.numero
    OR NEW.date_comptable <> OLD.date_comptable
    OR NEW.libelle <> OLD.libelle
    OR NEW.reference <> OLD.reference
    OR NEW.piece <> OLD.piece
    OR NEW.source_type <> OLD.source_type
    OR NEW.source_id <> OLD.source_id
    OR NEW.source_action <> OLD.source_action
    OR COALESCE(NEW.cle_idempotence, '') <> COALESCE(OLD.cle_idempotence, '')
    OR COALESCE(NEW.empreinte_commande, '') <> COALESCE(OLD.empreinte_commande, '')
    OR COALESCE(NEW.contrepassation_de_id, 0) <> COALESCE(OLD.contrepassation_de_id, 0)
)
BEGIN
    SELECT RAISE(ABORT, 'contenu d''écriture validée immuable');
END;

CREATE TRIGGER trg_ecritures_transition
BEFORE UPDATE OF statut ON ecritures
WHEN NOT (
    NEW.statut = OLD.statut
    OR (OLD.statut = 'brouillon' AND NEW.statut = 'validee')
    OR (OLD.statut = 'validee' AND NEW.statut = 'contre_passee')
)
BEGIN
    SELECT RAISE(ABORT, 'transition de statut interdite');
END;

INSERT INTO permissions (code, libelle) VALUES
    ('compta.view', 'Consulter la comptabilité'),
    ('compta.edit', 'Saisir des écritures comptables'),
    ('compta.validate', 'Valider et contre-passer des écritures'),
    ('compta.setup', 'Configurer le plan, les périodes et les journaux'),
    ('compta.export', 'Exporter les rapports comptables');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'administrateur' AND p.code LIKE 'compta.%';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('comptable', 'formateur')
  AND p.code IN ('compta.view', 'compta.edit', 'compta.validate', 'compta.setup', 'compta.export');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('apprenant', 'lecteur')
  AND p.code IN ('compta.view', 'compta.export');
