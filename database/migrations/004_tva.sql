CREATE TABLE tva_regimes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL CHECK (statut IN ('non_assujetti', 'assujetti', 'volontaire')),
    numero_tva TEXT NOT NULL DEFAULT '',
    methode TEXT NOT NULL CHECK (methode IN ('effective', 'tdfn')),
    mode_decompte TEXT NOT NULL CHECK (mode_decompte IN ('convenues', 'recues')),
    periodicite TEXT NOT NULL CHECK (
        periodicite IN ('mensuelle', 'trimestrielle', 'semestrielle', 'annuelle')
    ),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    compte_impot_prealable_materiel_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_impot_prealable_investissements_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_tva_due_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_decompte_tva_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_corrections_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    source_reglementaire TEXT NOT NULL,
    verifie_le TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    CHECK (
        (statut = 'non_assujetti' AND numero_tva = '')
        OR (statut <> 'non_assujetti' AND numero_tva <> '')
    )
);
CREATE INDEX idx_tva_regimes_scope_dates
    ON tva_regimes(dossier_id, date_debut, date_fin);

CREATE TABLE tva_taux_legaux (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    categorie TEXT NOT NULL CHECK (categorie IN ('normal', 'reduit', 'special', 'zero')),
    libelle TEXT NOT NULL,
    taux_bp INTEGER NOT NULL CHECK (taux_bp BETWEEN 0 AND 10000),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    source_url TEXT NOT NULL,
    verifie_le TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (categorie, date_debut)
);

CREATE TABLE tva_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    traitement TEXT NOT NULL CHECK (
        traitement IN (
            'normal', 'reduit', 'special', 'exonere', 'exclu',
            'hors_champ', 'acquisition', 'import', 'correction'
        )
    ),
    nature TEXT NOT NULL CHECK (
        nature IN ('collectee', 'prealable', 'acquisition', 'non_taxable', 'correction')
    ),
    taux_legal_id INTEGER REFERENCES tva_taux_legaux(id) ON DELETE RESTRICT,
    droit_deduction INTEGER NOT NULL DEFAULT 0 CHECK (droit_deduction IN (0, 1)),
    deduction_defaut_bp INTEGER NOT NULL DEFAULT 0 CHECK (
        deduction_defaut_bp BETWEEN 0 AND 10000
    ),
    chiffre_afc TEXT NOT NULL DEFAULT '',
    compte_tva_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    CHECK (
        (traitement IN ('normal', 'reduit', 'special', 'acquisition', 'import')
            AND taux_legal_id IS NOT NULL)
        OR (traitement NOT IN ('normal', 'reduit', 'special', 'acquisition', 'import')
            AND taux_legal_id IS NULL)
    ),
    UNIQUE (dossier_id, code, date_debut)
);
CREATE INDEX idx_tva_codes_scope_dates ON tva_codes(dossier_id, code, date_debut, date_fin);

CREATE TABLE tva_tdfn (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    activite_id TEXT NOT NULL CHECK (length(activite_id) = 5),
    activite TEXT NOT NULL,
    taux_bp INTEGER NOT NULL CHECK (taux_bp BETWEEN 0 AND 10000),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    seuil_chiffre_affaires_centimes INTEGER,
    autorisation_reference TEXT NOT NULL,
    source_url TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (dossier_id, activite_id, date_debut)
);

CREATE TABLE tva_lignes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    ligne_ecriture_id INTEGER NOT NULL UNIQUE
        REFERENCES lignes_ecriture(id) ON DELETE RESTRICT,
    code_tva_id INTEGER NOT NULL REFERENCES tva_codes(id) ON DELETE RESTRICT,
    date_prestation TEXT NOT NULL,
    mode_saisie TEXT NOT NULL CHECK (mode_saisie IN ('net', 'brut')),
    base_nette_centimes INTEGER NOT NULL,
    tva_centimes INTEGER NOT NULL,
    total_brut_centimes INTEGER NOT NULL,
    taux_legal_snapshot_bp INTEGER NOT NULL CHECK (
        taux_legal_snapshot_bp BETWEEN 0 AND 10000
    ),
    code_snapshot TEXT NOT NULL,
    traitement_snapshot TEXT NOT NULL,
    nature_snapshot TEXT NOT NULL,
    chiffre_afc_snapshot TEXT NOT NULL DEFAULT '',
    deduction_bp INTEGER NOT NULL DEFAULT 0 CHECK (deduction_bp BETWEEN 0 AND 10000),
    tva_deductible_centimes INTEGER NOT NULL DEFAULT 0,
    correction_centimes INTEGER NOT NULL DEFAULT 0,
    motif_correction TEXT NOT NULL DEFAULT '',
    tdfn_id INTEGER REFERENCES tva_tdfn(id) ON DELETE RESTRICT,
    activite_id_snapshot TEXT NOT NULL DEFAULT '',
    taux_tdfn_snapshot_bp INTEGER CHECK (
        taux_tdfn_snapshot_bp IS NULL
        OR taux_tdfn_snapshot_bp BETWEEN 0 AND 10000
    ),
    document_type TEXT NOT NULL DEFAULT '',
    document_id TEXT NOT NULL DEFAULT '',
    document_ligne_id TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (total_brut_centimes = base_nette_centimes + tva_centimes),
    CHECK (
        (correction_centimes = 0 AND motif_correction = '')
        OR (correction_centimes <> 0 AND motif_correction <> '')
    )
);
CREATE INDEX idx_tva_lignes_scope_date ON tva_lignes(dossier_id, date_prestation, nature_snapshot);

CREATE TABLE tva_encaissements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    tva_ligne_id INTEGER NOT NULL REFERENCES tva_lignes(id) ON DELETE RESTRICT,
    date_paiement TEXT NOT NULL,
    montant_brut_centimes INTEGER NOT NULL CHECK (montant_brut_centimes <> 0),
    source_type TEXT NOT NULL,
    source_id TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, source_type, source_id, tva_ligne_id)
);
CREATE INDEX idx_tva_encaissements_date ON tva_encaissements(dossier_id, date_paiement);

CREATE TABLE tva_periodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    regime_tva_id INTEGER NOT NULL REFERENCES tva_regimes(id) ON DELETE RESTRICT,
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'ouverte'
        CHECK (statut IN ('ouverte', 'preparee', 'controlee', 'exportee', 'declaree', 'payee', 'remboursee', 'corrigee')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_fin >= date_debut),
    UNIQUE (dossier_id, date_debut, date_fin)
);

CREATE TABLE tva_decomptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    periode_tva_id INTEGER NOT NULL REFERENCES tva_periodes(id) ON DELETE RESTRICT,
    rectifie_de_id INTEGER REFERENCES tva_decomptes(id) ON DELETE RESTRICT,
    numero_correction INTEGER NOT NULL DEFAULT 0 CHECK (numero_correction >= 0),
    type_soumission INTEGER NOT NULL DEFAULT 1 CHECK (type_soumission BETWEEN 1 AND 3),
    statut TEXT NOT NULL DEFAULT 'prepare'
        CHECK (statut IN ('prepare', 'controle', 'exporte', 'declare', 'paye', 'rembourse', 'corrige')),
    methode_snapshot TEXT NOT NULL CHECK (methode_snapshot IN ('effective', 'tdfn')),
    mode_decompte_snapshot TEXT NOT NULL CHECK (mode_decompte_snapshot IN ('convenues', 'recues')),
    numero_tva_snapshot TEXT NOT NULL,
    nom_organisation_snapshot TEXT NOT NULL,
    date_arret TEXT NOT NULL,
    parametres_json TEXT NOT NULL,
    agregats_json TEXT NOT NULL,
    total_chiffre_affaires_centimes INTEGER NOT NULL,
    tva_due_centimes INTEGER NOT NULL,
    impot_prealable_centimes INTEGER NOT NULL,
    corrections_centimes INTEGER NOT NULL,
    solde_centimes INTEGER NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    controle_le TEXT,
    controle_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    declare_le TEXT,
    declare_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (
        solde_centimes = tva_due_centimes - impot_prealable_centimes + corrections_centimes
    ),
    UNIQUE (periode_tva_id, numero_correction)
);

CREATE TABLE tva_decompte_sources (
    decompte_tva_id INTEGER NOT NULL REFERENCES tva_decomptes(id) ON DELETE RESTRICT,
    tva_ligne_id INTEGER NOT NULL REFERENCES tva_lignes(id) ON DELETE RESTRICT,
    encaissement_id INTEGER REFERENCES tva_encaissements(id) ON DELETE RESTRICT,
    proportion_bp INTEGER NOT NULL DEFAULT 10000 CHECK (proportion_bp BETWEEN -10000 AND 10000),
    base_centimes INTEGER NOT NULL,
    tva_centimes INTEGER NOT NULL,
    tva_deductible_centimes INTEGER NOT NULL,
    correction_centimes INTEGER NOT NULL DEFAULT 0,
    brut_centimes INTEGER NOT NULL,
    code_snapshot TEXT NOT NULL,
    chiffre_afc_snapshot TEXT NOT NULL,
    UNIQUE (decompte_tva_id, tva_ligne_id, encaissement_id)
);

CREATE TABLE tva_decompte_cases (
    decompte_tva_id INTEGER NOT NULL REFERENCES tva_decomptes(id) ON DELETE RESTRICT,
    chiffre_afc TEXT NOT NULL,
    libelle TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL,
    PRIMARY KEY (decompte_tva_id, chiffre_afc)
);

CREATE TABLE tva_exports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    decompte_tva_id INTEGER NOT NULL REFERENCES tva_decomptes(id) ON DELETE RESTRICT,
    format TEXT NOT NULL DEFAULT 'ech-0217',
    version_schema TEXT NOT NULL DEFAULT '2.0.0',
    contenu_xml BLOB NOT NULL,
    empreinte_sha256 TEXT NOT NULL,
    schema_valide INTEGER NOT NULL CHECK (schema_valide IN (0, 1)),
    erreurs_json TEXT NOT NULL DEFAULT '[]',
    transmis INTEGER NOT NULL DEFAULT 0 CHECK (transmis = 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (decompte_tva_id, empreinte_sha256)
);

CREATE TRIGGER trg_tva_regimes_scope_insert
BEFORE INSERT ON tva_regimes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope du régime TVA invalide') END;
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM tva_regimes r WHERE r.dossier_id = NEW.dossier_id
          AND COALESCE(r.date_fin, '9999-12-31') >= NEW.date_debut
          AND COALESCE(NEW.date_fin, '9999-12-31') >= r.date_debut
    ) THEN RAISE(ABORT, 'chevauchement de régimes TVA') END;
END;

CREATE TRIGGER trg_tva_regimes_historique_update
BEFORE UPDATE ON tva_regimes
BEGIN
    SELECT CASE WHEN
        NEW.organisation_id <> OLD.organisation_id
        OR NEW.dossier_id <> OLD.dossier_id
        OR NEW.statut <> OLD.statut
        OR NEW.numero_tva <> OLD.numero_tva
        OR NEW.methode <> OLD.methode
        OR NEW.mode_decompte <> OLD.mode_decompte
        OR NEW.periodicite <> OLD.periodicite
        OR NEW.date_debut <> OLD.date_debut
        OR NEW.compte_impot_prealable_materiel_id IS NOT OLD.compte_impot_prealable_materiel_id
        OR NEW.compte_impot_prealable_investissements_id IS NOT OLD.compte_impot_prealable_investissements_id
        OR NEW.compte_tva_due_id IS NOT OLD.compte_tva_due_id
        OR NEW.compte_decompte_tva_id IS NOT OLD.compte_decompte_tva_id
        OR NEW.compte_corrections_id IS NOT OLD.compte_corrections_id
        OR OLD.date_fin IS NOT NULL
        OR NEW.date_fin IS NULL
        OR NEW.date_fin < NEW.date_debut
    THEN RAISE(ABORT, 'historique du régime TVA immuable') END;
END;

CREATE TRIGGER trg_tva_codes_scope_insert
BEFORE INSERT ON tva_codes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope du code TVA invalide') END;
    SELECT CASE WHEN NEW.compte_tva_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes c WHERE c.id = NEW.compte_tva_id
          AND c.dossier_id = NEW.dossier_id AND c.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'compte du code TVA hors scope') END;
END;

CREATE TRIGGER trg_tva_lignes_scope_insert
BEFORE INSERT ON tva_lignes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM lignes_ecriture l
        JOIN ecritures e ON e.id = l.ecriture_id
        JOIN tva_codes c ON c.id = NEW.code_tva_id
        WHERE l.id = NEW.ligne_ecriture_id
          AND e.organisation_id = NEW.organisation_id
          AND e.dossier_id = NEW.dossier_id
          AND e.statut IN ('validee', 'contre_passee')
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope de ligne TVA invalide') END;
END;

CREATE TRIGGER trg_tva_lignes_immuables_update BEFORE UPDATE ON tva_lignes
BEGIN SELECT RAISE(ABORT, 'snapshot TVA immuable'); END;
CREATE TRIGGER trg_tva_lignes_immuables_delete BEFORE DELETE ON tva_lignes
BEGIN SELECT RAISE(ABORT, 'snapshot TVA immuable'); END;
CREATE TRIGGER trg_tva_encaissements_scope_insert
BEFORE INSERT ON tva_encaissements
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM tva_lignes t WHERE t.id = NEW.tva_ligne_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope d''encaissement TVA invalide') END;
END;
CREATE TRIGGER trg_tva_encaissements_immuables_update BEFORE UPDATE ON tva_encaissements
BEGIN SELECT RAISE(ABORT, 'encaissement TVA immuable'); END;
CREATE TRIGGER trg_tva_encaissements_immuables_delete BEFORE DELETE ON tva_encaissements
BEGIN SELECT RAISE(ABORT, 'encaissement TVA immuable'); END;
CREATE TRIGGER trg_tva_periodes_scope_insert
BEFORE INSERT ON tva_periodes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM tva_regimes r WHERE r.id = NEW.regime_tva_id
          AND r.organisation_id = NEW.organisation_id
          AND r.dossier_id = NEW.dossier_id
          AND NEW.date_debut >= r.date_debut
          AND NEW.date_fin <= COALESCE(r.date_fin, '9999-12-31')
    ) THEN RAISE(ABORT, 'scope ou dates de période TVA invalides') END;
END;
CREATE TRIGGER trg_tva_decomptes_immuables_update
BEFORE UPDATE OF methode_snapshot, mode_decompte_snapshot, numero_tva_snapshot,
    parametres_json, agregats_json, total_chiffre_affaires_centimes,
    tva_due_centimes, impot_prealable_centimes, corrections_centimes, solde_centimes
ON tva_decomptes
BEGIN SELECT RAISE(ABORT, 'snapshot du décompte TVA immuable'); END;
CREATE TRIGGER trg_tva_decompte_sources_immuables_update
BEFORE UPDATE ON tva_decompte_sources
BEGIN SELECT RAISE(ABORT, 'source du décompte TVA immuable'); END;
CREATE TRIGGER trg_tva_decompte_sources_immuables_delete
BEFORE DELETE ON tva_decompte_sources
BEGIN SELECT RAISE(ABORT, 'source du décompte TVA immuable'); END;
CREATE TRIGGER trg_tva_decompte_cases_immuables_update
BEFORE UPDATE ON tva_decompte_cases
BEGIN SELECT RAISE(ABORT, 'case du décompte TVA immuable'); END;
CREATE TRIGGER trg_tva_decompte_cases_immuables_delete
BEFORE DELETE ON tva_decompte_cases
BEGIN SELECT RAISE(ABORT, 'case du décompte TVA immuable'); END;
CREATE TRIGGER trg_tva_exports_scope_insert
BEFORE INSERT ON tva_exports
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM tva_decomptes d WHERE d.id = NEW.decompte_tva_id
          AND d.organisation_id = NEW.organisation_id
          AND d.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope d''export TVA invalide') END;
END;
CREATE TRIGGER trg_tva_exports_immuables_update BEFORE UPDATE ON tva_exports
BEGIN SELECT RAISE(ABORT, 'export TVA immuable'); END;
CREATE TRIGGER trg_tva_exports_immuables_delete BEFORE DELETE ON tva_exports
BEGIN SELECT RAISE(ABORT, 'export TVA immuable'); END;

INSERT INTO tva_taux_legaux
    (categorie, libelle, taux_bp, date_debut, source_url, verifie_le)
VALUES
    ('normal', 'Taux normal', 810, '2024-01-01',
     'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse', '2026-07-25'),
    ('reduit', 'Taux réduit', 260, '2024-01-01',
     'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse', '2026-07-25'),
    ('special', 'Taux spécial hébergement', 380, '2024-01-01',
     'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse', '2026-07-25'),
    ('zero', 'Taux zéro', 0, '2024-01-01',
     'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse', '2026-07-25');

INSERT INTO permissions (code, libelle) VALUES
    ('tva.view', 'Consulter la TVA'),
    ('tva.setup', 'Configurer la TVA'),
    ('tva.prepare', 'Préparer un décompte TVA'),
    ('tva.control', 'Contrôler un décompte TVA'),
    ('tva.export', 'Exporter un décompte e-TVA'),
    ('tva.declare', 'Marquer un décompte TVA déclaré');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'administrateur' AND p.code LIKE 'tva.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('comptable', 'formateur')
  AND p.code IN ('tva.view', 'tva.setup', 'tva.prepare', 'tva.control', 'tva.export');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('apprenant', 'lecteur') AND p.code = 'tva.view';

-- Schéma de référence du module Débiteurs et créanciers.
CREATE TABLE contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    type_personne TEXT NOT NULL DEFAULT 'entreprise'
        CHECK (type_personne IN ('entreprise', 'personne')),
    raison_sociale TEXT NOT NULL DEFAULT '',
    prenom TEXT NOT NULL DEFAULT '',
    nom TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    telephone TEXT NOT NULL DEFAULT '',
    langue TEXT NOT NULL DEFAULT 'fr' CHECK (langue IN ('fr', 'de', 'it', 'en')),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (
        (type_personne = 'entreprise' AND raison_sociale <> '')
        OR (type_personne = 'personne' AND (prenom <> '' OR nom <> ''))
    )
);
CREATE INDEX idx_contacts_scope_nom
    ON contacts(dossier_id, actif, raison_sociale, nom, prenom);

CREATE TABLE contact_roles (
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('client', 'fournisseur', 'employe', 'autre')),
    PRIMARY KEY (contact_id, role)
);

CREATE TABLE adresses_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    type TEXT NOT NULL DEFAULT 'facturation'
        CHECK (type IN ('facturation', 'livraison', 'correspondance')),
    ligne1 TEXT NOT NULL,
    ligne2 TEXT NOT NULL DEFAULT '',
    code_postal TEXT NOT NULL,
    localite TEXT NOT NULL,
    pays TEXT NOT NULL DEFAULT 'CH' CHECK (length(pays) = 2),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX idx_adresses_contact ON adresses_contacts(contact_id, actif, type);

CREATE TABLE pieces_jointes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    nom_fichier TEXT NOT NULL,
    type_mime TEXT NOT NULL,
    taille_octets INTEGER NOT NULL CHECK (taille_octets >= 0),
    empreinte_sha256 TEXT NOT NULL CHECK (length(empreinte_sha256) = 64),
    contenu BLOB NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, empreinte_sha256)
);

CREATE TABLE sequences_documents (
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    annee INTEGER NOT NULL CHECK (annee BETWEEN 2000 AND 9999),
    prefixe TEXT NOT NULL,
    dernier_numero INTEGER NOT NULL DEFAULT 0 CHECK (dernier_numero >= 0),
    PRIMARY KEY (dossier_id, annee, prefixe)
);

CREATE TABLE documents_financiers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (
        type IN (
            'facture_client', 'avoir_client',
            'facture_fournisseur', 'avoir_fournisseur'
        )
    ),
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'emis', 'comptabilise', 'annule')),
    numero TEXT NOT NULL DEFAULT '',
    numero_externe TEXT NOT NULL DEFAULT '',
    date_document TEXT NOT NULL,
    date_echeance TEXT NOT NULL,
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (monnaie IN ('CHF', 'EUR')),
    adresse_snapshot_json TEXT NOT NULL,
    contact_snapshot_json TEXT NOT NULL,
    total_net_centimes INTEGER NOT NULL DEFAULT 0,
    total_tva_centimes INTEGER NOT NULL DEFAULT 0,
    total_brut_centimes INTEGER NOT NULL DEFAULT 0,
    compte_collectif_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    ecriture_annulation_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    document_origine_id INTEGER REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    justificatif_id INTEGER REFERENCES pieces_jointes(id) ON DELETE RESTRICT,
    reference_scor TEXT NOT NULL DEFAULT '',
    qr_payload TEXT NOT NULL DEFAULT '',
    pdf_archive BLOB,
    pdf_empreinte_sha256 TEXT NOT NULL DEFAULT '',
    emis_le TEXT,
    comptabilise_le TEXT,
    annule_le TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_echeance >= date_document),
    CHECK (total_brut_centimes = total_net_centimes + total_tva_centimes),
    CHECK (
        (statut = 'brouillon' AND numero = '')
        OR (statut <> 'brouillon' AND numero <> '')
    )
);
CREATE UNIQUE INDEX uq_documents_numero
    ON documents_financiers(dossier_id, numero) WHERE numero <> '';
CREATE UNIQUE INDEX uq_documents_numero_fournisseur
    ON documents_financiers(dossier_id, contact_id, numero_externe)
    WHERE type IN ('facture_fournisseur', 'avoir_fournisseur')
      AND numero_externe <> '';
CREATE INDEX idx_documents_scope_etat
    ON documents_financiers(dossier_id, type, statut, date_echeance, contact_id);

CREATE TABLE lignes_document (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE CASCADE,
    ordre INTEGER NOT NULL,
    libelle TEXT NOT NULL,
    quantite_milli INTEGER NOT NULL CHECK (quantite_milli > 0),
    prix_unitaire_centimes INTEGER NOT NULL CHECK (prix_unitaire_centimes >= 0),
    mode_saisie TEXT NOT NULL CHECK (mode_saisie IN ('net', 'brut')),
    compte_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    code_tva_id INTEGER NOT NULL REFERENCES tva_codes(id) ON DELETE RESTRICT,
    date_prestation TEXT NOT NULL,
    deduction_bp INTEGER CHECK (deduction_bp IS NULL OR deduction_bp BETWEEN 0 AND 10000),
    motif_correction TEXT NOT NULL DEFAULT '',
    tdfn_id INTEGER REFERENCES tva_tdfn(id) ON DELETE RESTRICT,
    base_nette_centimes INTEGER NOT NULL,
    tva_centimes INTEGER NOT NULL,
    total_brut_centimes INTEGER NOT NULL,
    taux_tva_snapshot_bp INTEGER NOT NULL CHECK (taux_tva_snapshot_bp BETWEEN 0 AND 10000),
    code_tva_snapshot TEXT NOT NULL,
    traitement_tva_snapshot TEXT NOT NULL,
    nature_tva_snapshot TEXT NOT NULL,
    chiffre_afc_snapshot TEXT NOT NULL DEFAULT '',
    deduction_snapshot_bp INTEGER NOT NULL CHECK (deduction_snapshot_bp BETWEEN 0 AND 10000),
    tva_deductible_centimes INTEGER NOT NULL DEFAULT 0,
    activite_tdfn_snapshot TEXT NOT NULL DEFAULT '',
    taux_tdfn_snapshot_bp INTEGER,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (total_brut_centimes = base_nette_centimes + tva_centimes),
    UNIQUE (document_id, ordre)
);
CREATE INDEX idx_lignes_document ON lignes_document(document_id, ordre);

CREATE TABLE paiements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
    sens TEXT NOT NULL CHECK (sens IN ('encaissement', 'decaissement')),
    date_paiement TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (monnaie IN ('CHF', 'EUR')),
    reference TEXT NOT NULL DEFAULT '',
    compte_tresorerie_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    ligne_bancaire_id INTEGER REFERENCES lignes_bancaires(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'valide' CHECK (statut IN ('valide', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    annule_le TEXT,
    annule_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL
);
CREATE INDEX idx_paiements_scope ON paiements(dossier_id, contact_id, date_paiement);

CREATE TABLE allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    paiement_id INTEGER REFERENCES paiements(id) ON DELETE RESTRICT,
    avoir_id INTEGER REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    statut TEXT NOT NULL DEFAULT 'valide' CHECK (statut IN ('valide', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    annule_le TEXT,
    annule_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK ((paiement_id IS NOT NULL) <> (avoir_id IS NOT NULL))
);
CREATE INDEX idx_allocations_document ON allocations(document_id, statut);
CREATE INDEX idx_allocations_paiement ON allocations(paiement_id, statut);
CREATE INDEX idx_allocations_avoir ON allocations(avoir_id, statut);

CREATE TABLE rappels_factures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    niveau INTEGER NOT NULL DEFAULT 1 CHECK (niveau BETWEEN 1 AND 9),
    canal TEXT NOT NULL CHECK (canal IN ('courrier', 'email', 'telephone', 'autre')),
    note TEXT NOT NULL DEFAULT '',
    rappele_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL
);
CREATE INDEX idx_rappels_document ON rappels_factures(document_id, rappele_le);

CREATE TRIGGER trg_contacts_scope_insert BEFORE INSERT ON contacts
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de contact invalide') END;
END;
CREATE TRIGGER trg_contacts_scope_update BEFORE UPDATE ON contacts
BEGIN
    SELECT CASE WHEN NEW.organisation_id <> OLD.organisation_id
        OR NEW.dossier_id <> OLD.dossier_id
        THEN RAISE(ABORT, 'scope de contact immuable') END;
END;

CREATE TRIGGER trg_pieces_jointes_scope_insert BEFORE INSERT ON pieces_jointes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de justificatif invalide') END;
END;

CREATE TRIGGER trg_documents_scope_insert BEFORE INSERT ON documents_financiers
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM contacts c WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact du document hors scope') END;
    SELECT CASE WHEN NEW.compte_collectif_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes c WHERE c.id = NEW.compte_collectif_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'compte collectif hors scope') END;
END;
CREATE TRIGGER trg_documents_scope_update BEFORE UPDATE ON documents_financiers
BEGIN
    SELECT CASE WHEN NEW.organisation_id <> OLD.organisation_id
        OR NEW.dossier_id <> OLD.dossier_id
        THEN RAISE(ABORT, 'scope de document immuable') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM contacts c WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact du document hors scope') END;
END;

CREATE TRIGGER trg_documents_emis_immuables
BEFORE UPDATE ON documents_financiers WHEN OLD.statut <> 'brouillon'
BEGIN
    SELECT CASE WHEN NEW.contact_id <> OLD.contact_id
        OR NEW.type <> OLD.type
        OR NEW.numero <> OLD.numero
        OR NEW.numero_externe <> OLD.numero_externe
        OR NEW.date_document <> OLD.date_document
        OR NEW.date_echeance <> OLD.date_echeance
        OR NEW.monnaie <> OLD.monnaie
        OR NEW.adresse_snapshot_json <> OLD.adresse_snapshot_json
        OR NEW.contact_snapshot_json <> OLD.contact_snapshot_json
        OR NEW.total_net_centimes <> OLD.total_net_centimes
        OR NEW.total_tva_centimes <> OLD.total_tva_centimes
        OR NEW.total_brut_centimes <> OLD.total_brut_centimes
        OR COALESCE(NEW.compte_collectif_id, 0) <> COALESCE(OLD.compte_collectif_id, 0)
        THEN RAISE(ABORT, 'contenu du document émis immuable') END;
END;

CREATE TRIGGER trg_documents_historique_delete
BEFORE DELETE ON documents_financiers WHEN OLD.statut <> 'brouillon'
BEGIN SELECT RAISE(ABORT, 'document émis immuable'); END;

CREATE TRIGGER trg_lignes_document_scope_insert BEFORE INSERT ON lignes_document
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM documents_financiers d
        JOIN comptes c ON c.id = NEW.compte_id
        JOIN tva_codes t ON t.id = NEW.code_tva_id
        WHERE d.id = NEW.document_id AND d.statut = 'brouillon'
          AND c.organisation_id = d.organisation_id AND c.dossier_id = d.dossier_id
          AND t.organisation_id = d.organisation_id AND t.dossier_id = d.dossier_id
    ) THEN RAISE(ABORT, 'ligne de document hors scope ou document figé') END;
END;

CREATE TRIGGER trg_lignes_document_update BEFORE UPDATE ON lignes_document
BEGIN
    SELECT CASE WHEN (SELECT statut FROM documents_financiers WHERE id = OLD.document_id) <> 'brouillon'
        THEN RAISE(ABORT, 'lignes du document émises immuables') END;
END;
CREATE TRIGGER trg_lignes_document_delete BEFORE DELETE ON lignes_document
BEGIN
    SELECT CASE WHEN (SELECT statut FROM documents_financiers WHERE id = OLD.document_id) <> 'brouillon'
        THEN RAISE(ABORT, 'lignes du document émises immuables') END;
END;

CREATE TRIGGER trg_paiements_scope_insert BEFORE INSERT ON paiements
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM contacts c WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact du paiement hors scope') END;
    SELECT CASE WHEN NEW.compte_tresorerie_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes c WHERE c.id = NEW.compte_tresorerie_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'compte de trésorerie hors scope') END;
END;

CREATE TRIGGER trg_allocations_scope_insert BEFORE INSERT ON allocations
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM documents_financiers d WHERE d.id = NEW.document_id
          AND d.organisation_id = NEW.organisation_id AND d.dossier_id = NEW.dossier_id
          AND d.statut IN ('emis', 'comptabilise')
    ) THEN RAISE(ABORT, 'document cible hors scope ou non émis') END;
    SELECT CASE WHEN NEW.paiement_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM paiements p WHERE p.id = NEW.paiement_id
          AND p.organisation_id = NEW.organisation_id AND p.dossier_id = NEW.dossier_id
          AND p.statut = 'valide'
    ) THEN RAISE(ABORT, 'paiement source hors scope') END;
    SELECT CASE WHEN NEW.avoir_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM documents_financiers a WHERE a.id = NEW.avoir_id
          AND a.organisation_id = NEW.organisation_id AND a.dossier_id = NEW.dossier_id
          AND a.type IN ('avoir_client', 'avoir_fournisseur')
          AND a.statut IN ('emis', 'comptabilise')
    ) THEN RAISE(ABORT, 'avoir source hors scope') END;
END;

CREATE TRIGGER trg_rappels_scope_insert BEFORE INSERT ON rappels_factures
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM documents_financiers d WHERE d.id = NEW.document_id
          AND d.organisation_id = NEW.organisation_id AND d.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'rappel hors scope') END;
END;

INSERT INTO permissions (code, libelle) VALUES
    ('facturation.view', 'Consulter les débiteurs et créanciers'),
    ('facturation.manage', 'Gérer contacts et brouillons'),
    ('facturation.issue', 'Émettre des factures clients'),
    ('facturation.post', 'Comptabiliser les documents'),
    ('facturation.pay', 'Gérer paiements et allocations'),
    ('facturation.remind', 'Tracer les rappels');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'administrateur' AND p.code LIKE 'facturation.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('comptable', 'formateur') AND p.code LIKE 'facturation.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('apprenant', 'lecteur') AND p.code = 'facturation.view';

-- Schéma de référence du module Salaires genevois.
CREATE TABLE employeurs_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    nom TEXT NOT NULL,
    rue TEXT NOT NULL DEFAULT '',
    npa TEXT NOT NULL DEFAULT '',
    localite TEXT NOT NULL DEFAULT '',
    pays TEXT NOT NULL DEFAULT 'CH' CHECK (length(pays) = 2),
    telephone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    heures_hebdo_milli INTEGER NOT NULL DEFAULT 40000 CHECK (heures_hebdo_milli > 0),
    contact_nom TEXT NOT NULL DEFAULT '',
    contact_telephone TEXT NOT NULL DEFAULT '',
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id)
);

CREATE TABLE employes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    prenom TEXT NOT NULL,
    nom TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    rue TEXT NOT NULL DEFAULT '',
    npa TEXT NOT NULL DEFAULT '',
    localite TEXT NOT NULL DEFAULT '',
    numero_avs TEXT NOT NULL,
    numero_avs_normalise TEXT NOT NULL,
    date_naissance TEXT NOT NULL DEFAULT '',
    canton TEXT NOT NULL DEFAULT 'GE' CHECK (canton = 'GE'),
    procedure TEXT NOT NULL DEFAULT 'ordinaire'
        CHECK (procedure IN ('ordinaire', 'simplifiee', 'ordinaire_impot_source')),
    supplement_vacances_ppm INTEGER NOT NULL DEFAULT 83300
        CHECK (supplement_vacances_ppm BETWEEN 0 AND 1000000),
    impot_source_ppm INTEGER NOT NULL DEFAULT 0
        CHECK (impot_source_ppm BETWEEN 0 AND 1000000),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, numero_avs_normalise)
);
CREATE INDEX idx_employes_scope_nom ON employes(dossier_id, actif, nom, prenom);

CREATE TABLE taux_salaires_annuels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    annee INTEGER NOT NULL CHECK (annee BETWEEN 2000 AND 9999),
    avs_ppm INTEGER NOT NULL CHECK (avs_ppm BETWEEN 0 AND 1000000),
    ac_ppm INTEGER NOT NULL CHECK (ac_ppm BETWEEN 0 AND 1000000),
    amat_ppm INTEGER NOT NULL CHECK (amat_ppm BETWEEN 0 AND 1000000),
    laa_reduit_ppm INTEGER NOT NULL CHECK (laa_reduit_ppm BETWEEN 0 AND 1000000),
    laa_plein_ppm INTEGER NOT NULL CHECK (laa_plein_ppm BETWEEN 0 AND 1000000),
    lpp_ppm INTEGER NOT NULL CHECK (lpp_ppm BETWEEN 0 AND 1000000),
    emp_avs_ppm INTEGER NOT NULL CHECK (emp_avs_ppm BETWEEN 0 AND 1000000),
    emp_ac_ppm INTEGER NOT NULL CHECK (emp_ac_ppm BETWEEN 0 AND 1000000),
    emp_amat_ppm INTEGER NOT NULL CHECK (emp_amat_ppm BETWEEN 0 AND 1000000),
    emp_af_ppm INTEGER NOT NULL CHECK (emp_af_ppm BETWEEN 0 AND 1000000),
    emp_laa_reduit_ppm INTEGER NOT NULL CHECK (emp_laa_reduit_ppm BETWEEN 0 AND 1000000),
    emp_laa_plein_ppm INTEGER NOT NULL CHECK (emp_laa_plein_ppm BETWEEN 0 AND 1000000),
    emp_frais_ppm INTEGER NOT NULL DEFAULT 0 CHECK (emp_frais_ppm BETWEEN 0 AND 1000000),
    emp_cpe_ppm INTEGER NOT NULL DEFAULT 0 CHECK (emp_cpe_ppm BETWEEN 0 AND 1000000),
    emp_lfp_ppm INTEGER NOT NULL DEFAULT 0 CHECK (emp_lfp_ppm BETWEEN 0 AND 1000000),
    emp_lpp_ppm INTEGER NOT NULL CHECK (emp_lpp_ppm BETWEEN 0 AND 1000000),
    source TEXT NOT NULL DEFAULT '',
    verifie_le TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, annee)
);

CREATE TABLE tarifs_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    montant_horaire_centimes INTEGER NOT NULL CHECK (montant_horaire_centimes > 0),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    ordre INTEGER NOT NULL DEFAULT 0,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE unites_prestation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    heures_milli INTEGER NOT NULL CHECK (heures_milli > 0),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    ordre INTEGER NOT NULL DEFAULT 0,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE mapping_comptes_salaires (
    dossier_id INTEGER PRIMARY KEY REFERENCES dossiers(id) ON DELETE RESTRICT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    charge_salaires_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    charge_ocas_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    charge_laa_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    charge_lpp_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_net_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_ocas_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_laa_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_lpp_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_impot_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE fiches_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    employe_id INTEGER NOT NULL REFERENCES employes(id) ON DELETE RESTRICT,
    annee INTEGER NOT NULL CHECK (annee BETWEEN 2000 AND 9999),
    mois INTEGER NOT NULL CHECK (mois BETWEEN 1 AND 12),
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'validee', 'comptabilisee', 'payee', 'annulee')),
    employe_snapshot_json TEXT NOT NULL,
    employeur_snapshot_json TEXT NOT NULL,
    taux_snapshot_json TEXT NOT NULL,
    nombre_heures_milli INTEGER NOT NULL CHECK (nombre_heures_milli >= 0),
    salaire_travail_centimes INTEGER NOT NULL CHECK (salaire_travail_centimes >= 0),
    supplement_centimes INTEGER NOT NULL CHECK (supplement_centimes >= 0),
    brut_centimes INTEGER NOT NULL CHECK (brut_centimes >= 0),
    ded_avs_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_avs_centimes >= 0),
    ded_ac_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_ac_centimes >= 0),
    ded_amat_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_amat_centimes >= 0),
    ded_laa_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_laa_centimes >= 0),
    ded_lpp_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_lpp_centimes >= 0),
    ded_impot_source_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_impot_source_centimes >= 0),
    total_deductions_centimes INTEGER NOT NULL DEFAULT 0 CHECK (total_deductions_centimes >= 0),
    net_centimes INTEGER NOT NULL CHECK (net_centimes >= 0),
    emp_avs_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_avs_centimes >= 0),
    emp_ac_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_ac_centimes >= 0),
    emp_amat_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_amat_centimes >= 0),
    emp_af_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_af_centimes >= 0),
    emp_laa_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_laa_centimes >= 0),
    emp_frais_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_frais_centimes >= 0),
    emp_cpe_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_cpe_centimes >= 0),
    emp_lfp_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_lfp_centimes >= 0),
    emp_lpp_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_lpp_centimes >= 0),
    total_charges_employeur_centimes INTEGER NOT NULL DEFAULT 0
        CHECK (total_charges_employeur_centimes >= 0),
    cout_total_centimes INTEGER NOT NULL CHECK (cout_total_centimes >= 0),
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    ecriture_annulation_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    validee_le TEXT,
    comptabilisee_le TEXT,
    payee_le TEXT,
    annulee_le TEXT,
    email_envoye_le TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (brut_centimes = salaire_travail_centimes + supplement_centimes),
    CHECK (net_centimes = brut_centimes - total_deductions_centimes),
    CHECK (cout_total_centimes = brut_centimes + total_charges_employeur_centimes)
);
CREATE UNIQUE INDEX uq_fiche_salaire_periode_active
    ON fiches_salaires(employe_id, annee, mois) WHERE statut <> 'annulee';
CREATE INDEX idx_fiches_salaires_scope
    ON fiches_salaires(dossier_id, annee, mois, statut, employe_id);

CREATE TABLE lignes_prestation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE CASCADE,
    ordre INTEGER NOT NULL,
    libelle TEXT NOT NULL,
    unite_libelle_snapshot TEXT NOT NULL,
    heures_unite_milli INTEGER NOT NULL CHECK (heures_unite_milli > 0),
    quantite_milli INTEGER NOT NULL CHECK (quantite_milli > 0),
    taux_horaire_centimes INTEGER NOT NULL CHECK (taux_horaire_centimes > 0),
    nombre_heures_milli INTEGER NOT NULL CHECK (nombre_heures_milli > 0),
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    UNIQUE (fiche_salaire_id, ordre)
);

CREATE TABLE composants_fiche (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE CASCADE,
    code TEXT NOT NULL,
    libelle TEXT NOT NULL,
    categorie TEXT NOT NULL
        CHECK (categorie IN ('gain', 'retenue_employe', 'charge_employeur', 'net')),
    base_centimes INTEGER NOT NULL,
    taux_ppm INTEGER NOT NULL DEFAULT 0 CHECK (taux_ppm BETWEEN 0 AND 1000000),
    montant_centimes INTEGER NOT NULL,
    ordre INTEGER NOT NULL,
    UNIQUE (fiche_salaire_id, code)
);

CREATE TABLE dettes_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (type IN ('net', 'ocas', 'laa', 'lpp', 'impot_source')),
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    compte_dette_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (fiche_salaire_id, type)
);
CREATE INDEX idx_dettes_salaires_scope ON dettes_salaires(dossier_id, type, fiche_salaire_id);

CREATE TABLE paiements_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    beneficiaire_type TEXT NOT NULL CHECK (beneficiaire_type IN ('employe', 'organisme')),
    employe_id INTEGER REFERENCES employes(id) ON DELETE RESTRICT,
    date_paiement TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    reference TEXT NOT NULL DEFAULT '',
    compte_tresorerie_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'valide' CHECK (statut IN ('valide', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (
        (beneficiaire_type = 'employe' AND employe_id IS NOT NULL)
        OR (beneficiaire_type = 'organisme' AND employe_id IS NULL)
    )
);

CREATE TABLE allocations_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    paiement_salaire_id INTEGER NOT NULL REFERENCES paiements_salaires(id) ON DELETE RESTRICT,
    dette_salaire_id INTEGER NOT NULL REFERENCES dettes_salaires(id) ON DELETE RESTRICT,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    statut TEXT NOT NULL DEFAULT 'valide' CHECK (statut IN ('valide', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL
);
CREATE INDEX idx_allocations_salaires_paiement
    ON allocations_salaires(paiement_salaire_id, statut);
CREATE INDEX idx_allocations_salaires_dette
    ON allocations_salaires(dette_salaire_id, statut);

CREATE TABLE certificats_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    employe_id INTEGER NOT NULL REFERENCES employes(id) ON DELETE RESTRICT,
    annee INTEGER NOT NULL CHECK (annee BETWEEN 2000 AND 9999),
    donnees_snapshot_json TEXT NOT NULL,
    xml_archive TEXT NOT NULL,
    empreinte_sha256 TEXT NOT NULL CHECK (length(empreinte_sha256) = 64),
    statut TEXT NOT NULL DEFAULT 'genere' CHECK (statut IN ('genere', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, employe_id, annee)
);

CREATE TABLE emails_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE RESTRICT,
    destinataire TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'en_attente'
        CHECK (statut IN ('en_attente', 'envoye', 'echec')),
    erreur TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    traite_le TEXT
);

CREATE TABLE imports_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    empreinte_sha256 TEXT NOT NULL CHECK (length(empreinte_sha256) = 64),
    resume_json TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, empreinte_sha256)
);

CREATE TRIGGER trg_employeur_salaire_scope BEFORE INSERT ON employeurs_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope employeur invalide') END;
END;
CREATE TRIGGER trg_employes_scope BEFORE INSERT ON employes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope employé invalide') END;
END;
CREATE TRIGGER trg_taux_salaires_scope BEFORE INSERT ON taux_salaires_annuels
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope taux salaires invalide') END;
END;
CREATE TRIGGER trg_mapping_salaires_scope BEFORE INSERT ON mapping_comptes_salaires
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM (
            SELECT NEW.charge_salaires_id AS id UNION ALL SELECT NEW.charge_ocas_id
            UNION ALL SELECT NEW.charge_laa_id UNION ALL SELECT NEW.charge_lpp_id
            UNION ALL SELECT NEW.dette_net_id UNION ALL SELECT NEW.dette_ocas_id
            UNION ALL SELECT NEW.dette_laa_id UNION ALL SELECT NEW.dette_lpp_id
            UNION ALL SELECT NEW.dette_impot_id
        ) ids LEFT JOIN comptes c ON c.id = ids.id
        WHERE c.id IS NULL OR c.organisation_id <> NEW.organisation_id
          OR c.dossier_id <> NEW.dossier_id
    ) THEN RAISE(ABORT, 'mapping salaires hors scope') END;
END;
CREATE TRIGGER trg_mapping_salaires_scope_update BEFORE UPDATE ON mapping_comptes_salaires
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM (
            SELECT NEW.charge_salaires_id AS id UNION ALL SELECT NEW.charge_ocas_id
            UNION ALL SELECT NEW.charge_laa_id UNION ALL SELECT NEW.charge_lpp_id
            UNION ALL SELECT NEW.dette_net_id UNION ALL SELECT NEW.dette_ocas_id
            UNION ALL SELECT NEW.dette_laa_id UNION ALL SELECT NEW.dette_lpp_id
            UNION ALL SELECT NEW.dette_impot_id
        ) ids LEFT JOIN comptes c ON c.id = ids.id
        WHERE c.id IS NULL OR c.organisation_id <> NEW.organisation_id
          OR c.dossier_id <> NEW.dossier_id
    ) THEN RAISE(ABORT, 'mapping salaires hors scope') END;
END;
CREATE TRIGGER trg_fiches_salaires_scope BEFORE INSERT ON fiches_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM employes e WHERE e.id = NEW.employe_id
          AND e.organisation_id = NEW.organisation_id AND e.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'employé de fiche hors scope') END;
END;
CREATE TRIGGER trg_fiches_salaires_contenu_immuable
BEFORE UPDATE ON fiches_salaires WHEN OLD.statut <> 'brouillon'
BEGIN
    SELECT CASE WHEN NEW.employe_id <> OLD.employe_id
        OR NEW.annee <> OLD.annee OR NEW.mois <> OLD.mois
        OR NEW.employe_snapshot_json <> OLD.employe_snapshot_json
        OR NEW.employeur_snapshot_json <> OLD.employeur_snapshot_json
        OR NEW.taux_snapshot_json <> OLD.taux_snapshot_json
        OR NEW.brut_centimes <> OLD.brut_centimes
        OR NEW.net_centimes <> OLD.net_centimes
        OR NEW.total_deductions_centimes <> OLD.total_deductions_centimes
        OR NEW.total_charges_employeur_centimes <> OLD.total_charges_employeur_centimes
        THEN RAISE(ABORT, 'fiche validée immuable') END;
END;
CREATE TRIGGER trg_fiches_salaires_delete
BEFORE DELETE ON fiches_salaires WHEN OLD.statut <> 'brouillon'
BEGIN SELECT RAISE(ABORT, 'fiche validée non supprimable'); END;
CREATE TRIGGER trg_lignes_prestation_update BEFORE UPDATE ON lignes_prestation
BEGIN
    SELECT CASE WHEN (SELECT statut FROM fiches_salaires WHERE id = OLD.fiche_salaire_id) <> 'brouillon'
        THEN RAISE(ABORT, 'prestations validées immuables') END;
END;
CREATE TRIGGER trg_lignes_prestation_delete BEFORE DELETE ON lignes_prestation
BEGIN
    SELECT CASE WHEN (SELECT statut FROM fiches_salaires WHERE id = OLD.fiche_salaire_id) <> 'brouillon'
        THEN RAISE(ABORT, 'prestations validées immuables') END;
END;
CREATE TRIGGER trg_composants_fiche_update BEFORE UPDATE ON composants_fiche
BEGIN
    SELECT CASE WHEN (SELECT statut FROM fiches_salaires WHERE id = OLD.fiche_salaire_id) <> 'brouillon'
        THEN RAISE(ABORT, 'composants validés immuables') END;
END;
CREATE TRIGGER trg_composants_fiche_delete BEFORE DELETE ON composants_fiche
BEGIN
    SELECT CASE WHEN (SELECT statut FROM fiches_salaires WHERE id = OLD.fiche_salaire_id) <> 'brouillon'
        THEN RAISE(ABORT, 'composants validés immuables') END;
END;
CREATE TRIGGER trg_allocations_salaires_scope BEFORE INSERT ON allocations_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM paiements_salaires p JOIN dettes_salaires d
          ON d.id = NEW.dette_salaire_id
        WHERE p.id = NEW.paiement_salaire_id
          AND p.organisation_id = NEW.organisation_id
          AND p.dossier_id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND d.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'allocation salaire hors scope') END;
END;
CREATE TRIGGER trg_paiements_salaires_scope BEFORE INSERT ON paiements_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        JOIN comptes c ON c.id = NEW.compte_tresorerie_id
        WHERE d.id = NEW.dossier_id AND d.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
          AND c.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'paiement salaire hors scope') END;
    SELECT CASE WHEN NEW.employe_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM employes e WHERE e.id = NEW.employe_id
          AND e.dossier_id = NEW.dossier_id
          AND e.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'employé du paiement hors scope') END;
END;

INSERT INTO permissions (code, libelle) VALUES
    ('salaires.view', 'Consulter les totaux salariaux'),
    ('salaires.pii', 'Consulter les données personnelles salariales'),
    ('salaires.manage', 'Gérer employés, paramètres et brouillons'),
    ('salaires.validate', 'Valider les fiches de salaire'),
    ('salaires.post', 'Comptabiliser les fiches de salaire'),
    ('salaires.pay', 'Gérer paiements et allocations de salaires'),
    ('salaires.export', 'Imprimer et exporter les certificats salariaux');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'administrateur' AND p.code LIKE 'salaires.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'comptable' AND p.code LIKE 'salaires.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'formateur'
  AND p.code IN ('salaires.view', 'salaires.manage', 'salaires.validate', 'salaires.export');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code IN ('apprenant', 'lecteur') AND p.code = 'salaires.view';

-- Schéma de référence du module Enseignement.
CREATE TABLE modeles_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    titre TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'publie', 'archive')),
    version_courante INTEGER NOT NULL DEFAULT 0 CHECK (version_courante >= 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX idx_modeles_exercice_scope
    ON modeles_exercice(organisation_id, statut, titre);

CREATE TABLE versions_modeles_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    modele_id INTEGER NOT NULL REFERENCES modeles_exercice(id) ON DELETE RESTRICT,
    numero_version INTEGER NOT NULL CHECK (numero_version > 0),
    plan_snapshot_json TEXT NOT NULL,
    soldes_initiaux_json TEXT NOT NULL DEFAULT '[]',
    donnees_initiales_json TEXT NOT NULL DEFAULT '[]',
    consignes TEXT NOT NULL,
    solution_json TEXT NOT NULL DEFAULT '{}',
    regle_correction TEXT NOT NULL DEFAULT 'manuelle'
        CHECK (regle_correction IN ('manuelle', 'apres_tentatives', 'date')),
    valeur_correction TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'publie', 'archive')),
    publie_le TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (modele_id, numero_version)
);

CREATE TABLE etapes_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version_modele_id INTEGER NOT NULL
        REFERENCES versions_modeles_exercice(id) ON DELETE RESTRICT,
    code TEXT NOT NULL,
    titre TEXT NOT NULL,
    consigne TEXT NOT NULL,
    ordre INTEGER NOT NULL DEFAULT 0,
    UNIQUE (version_modele_id, code)
);

CREATE TABLE indices_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    etape_id INTEGER NOT NULL REFERENCES etapes_exercice(id) ON DELETE RESTRICT,
    niveau INTEGER NOT NULL CHECK (niveau > 0),
    contenu TEXT NOT NULL,
    UNIQUE (etape_id, niveau)
);

CREATE TABLE regles_validation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    etape_id INTEGER NOT NULL REFERENCES etapes_exercice(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (
        type IN ('comptes', 'sens', 'montants', 'ecriture_equivalente',
                 'soldes', 'rapport')
    ),
    configuration_json TEXT NOT NULL,
    message_succes TEXT NOT NULL DEFAULT 'Étape validée.',
    message_echec TEXT NOT NULL DEFAULT 'La réponse comptable ne satisfait pas la règle.',
    ordre INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE groupes_pedagogiques (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    nom TEXT NOT NULL,
    dossier_partage_id INTEGER REFERENCES dossiers(id) ON DELETE RESTRICT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (organisation_id, nom)
);

CREATE TABLE membres_groupes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    groupe_id INTEGER NOT NULL REFERENCES groupes_pedagogiques(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    role_groupe TEXT NOT NULL DEFAULT 'membre'
        CHECK (role_groupe IN ('membre', 'coordinateur')),
    adhesion_le TEXT NOT NULL DEFAULT (datetime('now')),
    retrait_le TEXT,
    ajoute_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1
);
CREATE UNIQUE INDEX uq_membre_groupe_actif
    ON membres_groupes(groupe_id, utilisateur_id) WHERE retrait_le IS NULL;

CREATE TABLE assignations_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    version_modele_id INTEGER NOT NULL
        REFERENCES versions_modeles_exercice(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    groupe_id INTEGER REFERENCES groupes_pedagogiques(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'en_cours'
        CHECK (statut IN ('en_cours', 'terminee', 'archivee')),
    correction_autorisee INTEGER NOT NULL DEFAULT 0
        CHECK (correction_autorisee IN (0, 1)),
    generation INTEGER NOT NULL DEFAULT 1 CHECK (generation > 0),
    assignee_le TEXT NOT NULL DEFAULT (datetime('now')),
    assignee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    terminee_le TEXT,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (
        (utilisateur_id IS NOT NULL AND groupe_id IS NULL)
        OR (utilisateur_id IS NULL AND groupe_id IS NOT NULL)
    )
);
CREATE UNIQUE INDEX uq_assignation_dossier_active
    ON assignations_exercice(dossier_id) WHERE statut <> 'archivee';
CREATE INDEX idx_assignations_cible
    ON assignations_exercice(organisation_id, utilisateur_id, groupe_id, statut);

CREATE TABLE progressions_etapes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignation_id INTEGER NOT NULL
        REFERENCES assignations_exercice(id) ON DELETE RESTRICT,
    etape_id INTEGER NOT NULL REFERENCES etapes_exercice(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'a_faire'
        CHECK (statut IN ('a_faire', 'vue', 'validee')),
    vue_le TEXT,
    validee_le TEXT,
    validee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (assignation_id, etape_id)
);

CREATE TABLE tentatives_pedagogiques (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    assignation_id INTEGER NOT NULL
        REFERENCES assignations_exercice(id) ON DELETE RESTRICT,
    etape_id INTEGER NOT NULL REFERENCES etapes_exercice(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE SET NULL,
    reussie INTEGER NOT NULL CHECK (reussie IN (0, 1)),
    resultat_json TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_tentatives_progression
    ON tentatives_pedagogiques(assignation_id, etape_id, cree_le);

CREATE TABLE consultations_indices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignation_id INTEGER NOT NULL
        REFERENCES assignations_exercice(id) ON DELETE RESTRICT,
    indice_id INTEGER NOT NULL REFERENCES indices_exercice(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    consulte_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (assignation_id, indice_id, utilisateur_id)
);

CREATE TABLE contributions_pedagogiques (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    assignation_id INTEGER NOT NULL
        REFERENCES assignations_exercice(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE SET NULL,
    action TEXT NOT NULL CHECK (
        action IN ('creation', 'modification', 'validation', 'indice', 'tentative')
    ),
    resume_json TEXT NOT NULL DEFAULT '{}',
    cree_le TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_contributions_auteur
    ON contributions_pedagogiques(assignation_id, utilisateur_id, cree_le);

CREATE TRIGGER trg_modele_exercice_scope BEFORE INSERT ON modeles_exercice
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM organisations o WHERE o.id = NEW.organisation_id
          AND o.nature = 'pedagogique' AND o.actif = 1
    ) THEN RAISE(ABORT, 'modèle hors organisation pédagogique') END;
END;
CREATE TRIGGER trg_version_modele_immuable
BEFORE UPDATE ON versions_modeles_exercice WHEN OLD.statut = 'publie' AND (
    NEW.plan_snapshot_json <> OLD.plan_snapshot_json
    OR NEW.soldes_initiaux_json <> OLD.soldes_initiaux_json
    OR NEW.donnees_initiales_json <> OLD.donnees_initiales_json
    OR NEW.consignes <> OLD.consignes
    OR NEW.solution_json <> OLD.solution_json
    OR NEW.regle_correction <> OLD.regle_correction
    OR NEW.valeur_correction <> OLD.valeur_correction
)
BEGIN SELECT RAISE(ABORT, 'version de modèle publiée immuable'); END;
CREATE TRIGGER trg_version_modele_immuable_delete
BEFORE DELETE ON versions_modeles_exercice WHEN OLD.statut = 'publie'
BEGIN SELECT RAISE(ABORT, 'version de modèle publiée non supprimable'); END;
CREATE TRIGGER trg_etape_modele_immuable_update BEFORE UPDATE ON etapes_exercice
WHEN (SELECT statut FROM versions_modeles_exercice WHERE id = OLD.version_modele_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'étape de modèle publiée immuable'); END;
CREATE TRIGGER trg_etape_modele_immuable_insert BEFORE INSERT ON etapes_exercice
WHEN (SELECT statut FROM versions_modeles_exercice WHERE id = NEW.version_modele_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'version publiée non extensible'); END;
CREATE TRIGGER trg_etape_modele_immuable_delete BEFORE DELETE ON etapes_exercice
WHEN (SELECT statut FROM versions_modeles_exercice WHERE id = OLD.version_modele_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'étape de modèle publiée non supprimable'); END;
CREATE TRIGGER trg_indice_modele_immuable_update BEFORE UPDATE ON indices_exercice
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = OLD.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'indice de modèle publié immuable'); END;
CREATE TRIGGER trg_indice_modele_immuable_insert BEFORE INSERT ON indices_exercice
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = NEW.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'indice non ajoutable à une version publiée'); END;
CREATE TRIGGER trg_indice_modele_immuable_delete BEFORE DELETE ON indices_exercice
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = OLD.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'indice de modèle publié non supprimable'); END;
CREATE TRIGGER trg_regle_modele_immuable_update BEFORE UPDATE ON regles_validation
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = OLD.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'règle de modèle publiée immuable'); END;
CREATE TRIGGER trg_regle_modele_immuable_insert BEFORE INSERT ON regles_validation
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = NEW.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'règle non ajoutable à une version publiée'); END;
CREATE TRIGGER trg_regle_modele_immuable_delete BEFORE DELETE ON regles_validation
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = OLD.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'règle de modèle publiée non supprimable'); END;
CREATE TRIGGER trg_groupe_pedagogique_scope BEFORE INSERT ON groupes_pedagogiques
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM organisations o WHERE o.id = NEW.organisation_id
          AND o.nature = 'pedagogique' AND o.actif = 1
    ) THEN RAISE(ABORT, 'groupe hors organisation pédagogique') END;
END;
CREATE TRIGGER trg_assignation_exercice_scope BEFORE INSERT ON assignations_exercice
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        JOIN versions_modeles_exercice v ON v.id = NEW.version_modele_id
        JOIN modeles_exercice m ON m.id = v.modele_id
        WHERE d.id = NEW.dossier_id AND d.organisation_id = NEW.organisation_id
          AND d.type = 'exercice' AND m.organisation_id = NEW.organisation_id
          AND v.statut = 'publie'
    ) THEN RAISE(ABORT, 'assignation pédagogique hors scope') END;
END;
CREATE TRIGGER trg_assignation_exercice_scope_update BEFORE UPDATE ON assignations_exercice
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        JOIN versions_modeles_exercice v ON v.id = NEW.version_modele_id
        JOIN modeles_exercice m ON m.id = v.modele_id
        WHERE d.id = NEW.dossier_id AND d.organisation_id = NEW.organisation_id
          AND d.type = 'exercice' AND m.organisation_id = NEW.organisation_id
          AND v.statut = 'publie'
    ) THEN RAISE(ABORT, 'assignation pédagogique hors scope') END;
END;
CREATE TRIGGER trg_tentative_immuable_update
BEFORE UPDATE ON tentatives_pedagogiques
BEGIN SELECT RAISE(ABORT, 'tentative pédagogique immuable'); END;
CREATE TRIGGER trg_tentative_immuable_delete
BEFORE DELETE ON tentatives_pedagogiques
BEGIN SELECT RAISE(ABORT, 'tentative pédagogique non supprimable'); END;

INSERT INTO permissions (code, libelle) VALUES
    ('pedagogie.view', 'Consulter un exercice pédagogique'),
    ('pedagogie.work', 'Travailler dans un exercice pédagogique'),
    ('pedagogie.manage', 'Gérer modèles, groupes et assignations'),
    ('pedagogie.correct', 'Valider les étapes et autoriser les corrections'),
    ('pedagogie.reset', 'Réinitialiser une copie d’exercice'),
    ('pedagogie.export', 'Exporter le suivi pédagogique');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'administrateur' AND p.code LIKE 'pedagogie.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'formateur' AND p.code LIKE 'pedagogie.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'apprenant'
  AND p.code IN ('pedagogie.view', 'pedagogie.work');
