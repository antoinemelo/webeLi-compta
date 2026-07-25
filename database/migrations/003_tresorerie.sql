CREATE TABLE comptes_tresorerie (
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
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, compte_comptable_id)
);
CREATE UNIQUE INDEX uq_comptes_tresorerie_iban
    ON comptes_tresorerie(dossier_id, iban)
    WHERE iban <> '';
CREATE INDEX idx_comptes_tresorerie_scope
    ON comptes_tresorerie(organisation_id, dossier_id, actif, type);

CREATE TABLE imports_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    format TEXT NOT NULL CHECK (format IN ('postfinance_csv', 'camt053', 'camt054')),
    namespace_xml TEXT NOT NULL DEFAULT '',
    nom_fichier TEXT NOT NULL,
    empreinte_source TEXT NOT NULL,
    contenu_source BLOB NOT NULL,
    iban_detecte TEXT NOT NULL DEFAULT '',
    monnaie_detectee TEXT NOT NULL DEFAULT '',
    date_debut TEXT NOT NULL DEFAULT '',
    date_fin TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'previsualise'
        CHECK (statut IN ('previsualise', 'confirme', 'erreur')),
    erreurs_json TEXT NOT NULL DEFAULT '[]',
    nb_total INTEGER NOT NULL DEFAULT 0 CHECK (nb_total >= 0),
    nb_importees INTEGER NOT NULL DEFAULT 0 CHECK (nb_importees >= 0),
    nb_doublons INTEGER NOT NULL DEFAULT 0 CHECK (nb_doublons >= 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    confirme_le TEXT,
    confirme_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (compte_tresorerie_id, empreinte_source)
);
CREATE INDEX idx_imports_bancaires_scope
    ON imports_bancaires(dossier_id, statut, cree_le);

CREATE TABLE lignes_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    import_id INTEGER NOT NULL REFERENCES imports_bancaires(id) ON DELETE RESTRICT,
    empreinte TEXT NOT NULL,
    rang_occurrence INTEGER NOT NULL DEFAULT 0 CHECK (rang_occurrence >= 0),
    identifiant_bancaire TEXT NOT NULL DEFAULT '',
    groupe_id TEXT NOT NULL DEFAULT '',
    date_comptabilisation TEXT NOT NULL,
    date_valeur TEXT NOT NULL DEFAULT '',
    libelle TEXT NOT NULL DEFAULT '',
    tiers TEXT NOT NULL DEFAULT '',
    communication TEXT NOT NULL DEFAULT '',
    type_reference TEXT NOT NULL DEFAULT '',
    reference TEXT NOT NULL DEFAULT '',
    iban_contrepartie TEXT NOT NULL DEFAULT '',
    code_transaction TEXT NOT NULL DEFAULT '',
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes <> 0),
    frais_centimes INTEGER NOT NULL DEFAULT 0 CHECK (frais_centimes >= 0),
    monnaie TEXT NOT NULL CHECK (length(monnaie) = 3),
    solde_apres_centimes INTEGER,
    donnees_brutes_json TEXT NOT NULL DEFAULT '{}',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (compte_tresorerie_id, empreinte)
);
CREATE INDEX idx_lignes_bancaires_compte_date
    ON lignes_bancaires(compte_tresorerie_id, date_comptabilisation, id);
CREATE INDEX idx_lignes_bancaires_reference
    ON lignes_bancaires(dossier_id, type_reference, reference);

CREATE TABLE soldes_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    import_id INTEGER NOT NULL REFERENCES imports_bancaires(id) ON DELETE RESTRICT,
    type TEXT NOT NULL,
    date_solde TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL,
    monnaie TEXT NOT NULL CHECK (length(monnaie) = 3),
    empreinte TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (compte_tresorerie_id, empreinte)
);
CREATE INDEX idx_soldes_bancaires_date
    ON soldes_bancaires(compte_tresorerie_id, date_solde, id);

CREATE TABLE suggestions_comptabilisation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    ligne_bancaire_id INTEGER NOT NULL
        REFERENCES lignes_bancaires(id) ON DELETE RESTRICT,
    compte_contrepartie_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    confiance INTEGER NOT NULL DEFAULT 0 CHECK (confiance BETWEEN 0 AND 100),
    raison TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'proposee'
        CHECK (statut IN ('proposee', 'acceptee', 'rejetee')),
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    decidee_le TEXT,
    decidee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (ligne_bancaire_id, compte_contrepartie_id)
);

CREATE TABLE rapprochements_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL DEFAULT '',
    total_banque_centimes INTEGER NOT NULL,
    total_comptable_centimes INTEGER NOT NULL,
    difference_centimes INTEGER NOT NULL,
    tolerance_centimes INTEGER NOT NULL DEFAULT 0 CHECK (tolerance_centimes >= 0),
    statut TEXT NOT NULL DEFAULT 'confirme' CHECK (statut = 'confirme'),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (difference_centimes = total_banque_centimes - total_comptable_centimes),
    CHECK (
        difference_centimes BETWEEN -tolerance_centimes AND tolerance_centimes
    )
);
CREATE INDEX idx_rapprochements_scope
    ON rapprochements_bancaires(dossier_id, compte_tresorerie_id, cree_le);

CREATE TABLE rapprochement_lignes_bancaires (
    rapprochement_id INTEGER NOT NULL
        REFERENCES rapprochements_bancaires(id) ON DELETE RESTRICT,
    ligne_bancaire_id INTEGER NOT NULL
        REFERENCES lignes_bancaires(id) ON DELETE RESTRICT,
    montant_centimes INTEGER NOT NULL,
    PRIMARY KEY (rapprochement_id, ligne_bancaire_id),
    UNIQUE (ligne_bancaire_id)
);

CREATE TABLE rapprochement_lignes_comptables (
    rapprochement_id INTEGER NOT NULL
        REFERENCES rapprochements_bancaires(id) ON DELETE RESTRICT,
    ligne_ecriture_id INTEGER NOT NULL
        REFERENCES lignes_ecriture(id) ON DELETE RESTRICT,
    montant_centimes INTEGER NOT NULL,
    PRIMARY KEY (rapprochement_id, ligne_ecriture_id),
    UNIQUE (ligne_ecriture_id)
);

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

CREATE TRIGGER trg_lignes_bancaires_immuables_update
BEFORE UPDATE ON lignes_bancaires
BEGIN
    SELECT RAISE(ABORT, 'ligne bancaire immuable');
END;

CREATE TRIGGER trg_lignes_bancaires_immuables_delete
BEFORE DELETE ON lignes_bancaires
BEGIN
    SELECT RAISE(ABORT, 'ligne bancaire immuable');
END;

CREATE TRIGGER trg_soldes_bancaires_immuables_update
BEFORE UPDATE ON soldes_bancaires
BEGIN
    SELECT RAISE(ABORT, 'solde bancaire immuable');
END;

CREATE TRIGGER trg_soldes_bancaires_scope_insert
BEFORE INSERT ON soldes_bancaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM imports_bancaires i
        WHERE i.id = NEW.import_id
          AND i.compte_tresorerie_id = NEW.compte_tresorerie_id
          AND i.organisation_id = NEW.organisation_id
          AND i.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope de solde bancaire invalide') END;
END;

CREATE TRIGGER trg_soldes_bancaires_immuables_delete
BEFORE DELETE ON soldes_bancaires
BEGIN
    SELECT RAISE(ABORT, 'solde bancaire immuable');
END;

CREATE TRIGGER trg_rapprochement_ligne_banque_scope
BEFORE INSERT ON rapprochement_lignes_bancaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM rapprochements_bancaires r
        JOIN lignes_bancaires l
          ON l.compte_tresorerie_id = r.compte_tresorerie_id
         AND l.organisation_id = r.organisation_id
         AND l.dossier_id = r.dossier_id
        WHERE r.id = NEW.rapprochement_id
          AND l.id = NEW.ligne_bancaire_id
          AND l.montant_centimes = NEW.montant_centimes
    ) THEN RAISE(ABORT, 'ligne bancaire hors rapprochement') END;
END;

CREATE TRIGGER trg_suggestions_comptabilisation_scope_insert
BEFORE INSERT ON suggestions_comptabilisation
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM lignes_bancaires l
        JOIN comptes c ON c.id = NEW.compte_contrepartie_id
        WHERE l.id = NEW.ligne_bancaire_id
          AND l.organisation_id = NEW.organisation_id
          AND l.dossier_id = NEW.dossier_id
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
          AND c.imputable = 1
    ) THEN RAISE(ABORT, 'scope de suggestion invalide') END;
END;

CREATE TRIGGER trg_rapprochement_ligne_comptable_scope
BEFORE INSERT ON rapprochement_lignes_comptables
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM rapprochements_bancaires r
        JOIN comptes_tresorerie t ON t.id = r.compte_tresorerie_id
        JOIN lignes_ecriture l ON l.compte_id = t.compte_comptable_id
        JOIN ecritures e ON e.id = l.ecriture_id
        WHERE r.id = NEW.rapprochement_id
          AND l.id = NEW.ligne_ecriture_id
          AND e.organisation_id = r.organisation_id
          AND e.dossier_id = r.dossier_id
          AND e.statut IN ('validee', 'contre_passee')
          AND (l.debit_centimes - l.credit_centimes) = NEW.montant_centimes
    ) THEN RAISE(ABORT, 'ligne comptable hors rapprochement') END;
END;

INSERT INTO permissions (code, libelle) VALUES
    ('tresorerie.view', 'Consulter la trésorerie'),
    ('tresorerie.import', 'Importer des relevés bancaires'),
    ('tresorerie.reconcile', 'Confirmer les rapprochements bancaires'),
    ('tresorerie.setup', 'Configurer les comptes de trésorerie');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'administrateur' AND p.code LIKE 'tresorerie.%';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('comptable', 'formateur')
  AND p.code IN (
      'tresorerie.view', 'tresorerie.import',
      'tresorerie.reconcile', 'tresorerie.setup'
  );

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('apprenant', 'lecteur')
  AND p.code = 'tresorerie.view';
