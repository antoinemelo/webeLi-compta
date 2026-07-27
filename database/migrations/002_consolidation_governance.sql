-- Lot 14e : distinguer agrégation interne et consolidation légale,
-- versionner le cycle de vie sans modifier les livres statutaires.

ALTER TABLE groupes_consolidation
    ADD COLUMN mode TEXT NOT NULL DEFAULT 'consolidation_legale'
    CHECK (mode IN ('agregation_interne', 'consolidation_legale'));

ALTER TABLE groupes_consolidation
    ADD COLUMN statut TEXT NOT NULL DEFAULT 'actif'
    CHECK (statut IN ('brouillon', 'actif', 'archive'));

ALTER TABLE membres_groupe_consolidation
    ADD COLUMN version INTEGER NOT NULL DEFAULT 1;

ALTER TABLE mappings_comptes_consolidation
    ADD COLUMN actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1));

ALTER TABLE mappings_comptes_consolidation
    ADD COLUMN date_debut TEXT NOT NULL DEFAULT '0001-01-01';

ALTER TABLE mappings_comptes_consolidation
    ADD COLUMN date_fin TEXT;

ALTER TABLE paires_comptes_interentites
    ADD COLUMN actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1));

ALTER TABLE paires_comptes_interentites
    ADD COLUMN date_debut TEXT NOT NULL DEFAULT '0001-01-01';

ALTER TABLE paires_comptes_interentites
    ADD COLUMN date_fin TEXT;

ALTER TABLE paires_comptes_interentites
    ADD COLUMN version INTEGER NOT NULL DEFAULT 1;

CREATE TABLE versions_mappings_consolidation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mapping_id INTEGER NOT NULL
        REFERENCES mappings_comptes_consolidation(id) ON DELETE RESTRICT,
    groupe_id INTEGER NOT NULL REFERENCES groupes_consolidation(id) ON DELETE RESTRICT,
    membre_id INTEGER NOT NULL
        REFERENCES membres_groupe_consolidation(id) ON DELETE RESTRICT,
    compte_source_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_cible TEXT NOT NULL COLLATE NOCASE,
    libelle_cible TEXT NOT NULL,
    type_cible TEXT NOT NULL CHECK (
        type_cible IN ('actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan')
    ),
    actif INTEGER NOT NULL CHECK (actif IN (0, 1)),
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    version INTEGER NOT NULL,
    remplacee_le TEXT NOT NULL DEFAULT (datetime('now')),
    remplacee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin >= date_debut),
    UNIQUE (mapping_id, version)
);

CREATE TABLE versions_paires_interentites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    paire_id INTEGER NOT NULL
        REFERENCES paires_comptes_interentites(id) ON DELETE RESTRICT,
    groupe_id INTEGER NOT NULL REFERENCES groupes_consolidation(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    membre_gauche_id INTEGER NOT NULL
        REFERENCES membres_groupe_consolidation(id) ON DELETE RESTRICT,
    compte_gauche_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    membre_droite_id INTEGER NOT NULL
        REFERENCES membres_groupe_consolidation(id) ON DELETE RESTRICT,
    compte_droite_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    actif INTEGER NOT NULL CHECK (actif IN (0, 1)),
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    version INTEGER NOT NULL,
    remplacee_le TEXT NOT NULL DEFAULT (datetime('now')),
    remplacee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin >= date_debut),
    UNIQUE (paire_id, version)
);

CREATE INDEX idx_versions_mappings_periode
    ON versions_mappings_consolidation
       (groupe_id, membre_id, compte_source_id, date_debut, date_fin);

CREATE INDEX idx_versions_paires_periode
    ON versions_paires_interentites (groupe_id, date_debut, date_fin);

CREATE TRIGGER trg_groupe_mode_fige
BEFORE UPDATE OF mode ON groupes_consolidation
WHEN NEW.mode <> OLD.mode AND EXISTS (
    SELECT 1 FROM periodes_consolidation p WHERE p.groupe_id = OLD.id
)
BEGIN SELECT RAISE(ABORT, 'mode du groupe figé après la première période'); END;

CREATE TRIGGER trg_version_mapping_immuable
BEFORE UPDATE ON versions_mappings_consolidation
BEGIN SELECT RAISE(ABORT, 'version de mapping immuable'); END;

CREATE TRIGGER trg_version_mapping_non_supprimable
BEFORE DELETE ON versions_mappings_consolidation
BEGIN SELECT RAISE(ABORT, 'version de mapping non supprimable'); END;

CREATE TRIGGER trg_version_paire_immuable
BEFORE UPDATE ON versions_paires_interentites
BEGIN SELECT RAISE(ABORT, 'version de paire immuable'); END;

CREATE TRIGGER trg_version_paire_non_supprimable
BEFORE DELETE ON versions_paires_interentites
BEGIN SELECT RAISE(ABORT, 'version de paire non supprimable'); END;
