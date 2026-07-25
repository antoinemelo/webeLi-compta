CREATE TABLE utilisateurs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    mot_de_passe TEXT NOT NULL,
    prenom TEXT NOT NULL DEFAULT '',
    nom TEXT NOT NULL DEFAULT '',
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    derniere_connexion_le TEXT
);

CREATE TABLE tentatives_connexion (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL COLLATE NOCASE,
    ip TEXT NOT NULL,
    tente_le INTEGER NOT NULL
);
CREATE INDEX idx_tentatives_connexion ON tentatives_connexion(email, ip, tente_le);

CREATE TABLE organisations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom TEXT NOT NULL,
    nature TEXT NOT NULL CHECK (nature IN ('reelle', 'pedagogique')),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE dossiers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    nom TEXT NOT NULL,
    slug TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('reel', 'demo', 'exercice')),
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (length(monnaie) = 3),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (organisation_id, slug)
);
CREATE INDEX idx_dossiers_organisation ON dossiers(organisation_id);

CREATE TABLE exercices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'ouvert' CHECK (statut IN ('ouvert', 'ferme')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_debut <= date_fin),
    UNIQUE (dossier_id, date_debut, date_fin)
);

CREATE TABLE parametres_organisation (
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    cle TEXT NOT NULL,
    valeur TEXT NOT NULL,
    PRIMARY KEY (organisation_id, cle)
);

CREATE TABLE parametres_dossier (
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE CASCADE,
    cle TEXT NOT NULL,
    valeur TEXT NOT NULL,
    PRIMARY KEY (dossier_id, cle)
);

CREATE TABLE roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    libelle TEXT NOT NULL
);

CREATE TABLE permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    libelle TEXT NOT NULL
);

CREATE TABLE role_permissions (
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE utilisateur_roles_installation (
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (utilisateur_id, role_id)
);

CREATE TABLE utilisateur_roles_organisation (
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (utilisateur_id, organisation_id, role_id)
);

CREATE TABLE utilisateur_roles_dossier (
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (utilisateur_id, dossier_id, role_id)
);

CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    organisation_id INTEGER REFERENCES organisations(id) ON DELETE SET NULL,
    dossier_id INTEGER REFERENCES dossiers(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    cible_type TEXT NOT NULL DEFAULT '',
    cible_id TEXT NOT NULL DEFAULT '',
    resume_json TEXT NOT NULL DEFAULT '{}',
    ip TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_audit_scope ON audit_events(organisation_id, dossier_id, cree_le);

INSERT INTO roles (code, libelle) VALUES
    ('administrateur', 'Administrateur'),
    ('comptable', 'Comptable'),
    ('gestionnaire_paie', 'Gestionnaire de paie'),
    ('formateur', 'Formateur'),
    ('apprenant', 'Apprenant'),
    ('lecteur', 'Lecteur / auditeur');

INSERT INTO permissions (code, libelle) VALUES
    ('installation.admin', 'Administrer l’installation'),
    ('organisation.view', 'Voir une organisation'),
    ('organisation.manage', 'Gérer une organisation'),
    ('dossier.view', 'Voir un dossier'),
    ('dossier.manage', 'Gérer un dossier'),
    ('exercice.view', 'Voir un exercice'),
    ('exercice.manage', 'Gérer un exercice'),
    ('audit.view', 'Consulter l’audit');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'administrateur';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('comptable', 'formateur')
  AND p.code IN ('organisation.view', 'dossier.view', 'dossier.manage', 'exercice.view', 'exercice.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('gestionnaire_paie', 'apprenant', 'lecteur')
  AND p.code IN ('organisation.view', 'dossier.view', 'exercice.view');
