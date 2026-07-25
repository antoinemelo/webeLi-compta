DROP TRIGGER IF EXISTS trg_rubriques_scope_insert;
DROP TRIGGER IF EXISTS trg_rubriques_scope_update;

CREATE TABLE rubriques_comptables_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL DEFAULT '',
    libelle TEXT NOT NULL,
    niveau_structure TEXT NOT NULL CHECK (
        niveau_structure IN ('classe', 'groupe_principal', 'groupe', 'sous_groupe')
    ),
    type TEXT NOT NULL CHECK (
        type IN ('actif', 'passif', 'produit', 'charge', 'hors_bilan')
    ),
    parent_id INTEGER REFERENCES rubriques_comptables_new(id) ON DELETE RESTRICT,
    ordre INTEGER NOT NULL DEFAULT 0,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    source_modele TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (code = '' OR code NOT GLOB '*[^0-9]*'),
    CHECK (
        (niveau_structure = 'classe' AND length(code) = 1)
        OR (niveau_structure = 'groupe_principal' AND length(code) = 2)
        OR (niveau_structure = 'groupe' AND length(code) = 3)
        OR (niveau_structure = 'sous_groupe' AND code = '')
    )
);

INSERT INTO rubriques_comptables_new
    (id, organisation_id, dossier_id, code, libelle, niveau_structure,
     type, ordre, actif, source_modele, cree_le, cree_par, modifie_le, version)
SELECT id, organisation_id, dossier_id, prefixe, libelle,
       CASE length(prefixe)
         WHEN 1 THEN 'classe'
         WHEN 2 THEN 'groupe_principal'
         ELSE 'groupe'
       END,
       CASE type WHEN 'fonds_propres' THEN 'passif' ELSE type END,
       ordre, actif, source_modele, cree_le, cree_par, modifie_le, version
FROM rubriques_comptables;

DROP TABLE rubriques_comptables;
ALTER TABLE rubriques_comptables_new RENAME TO rubriques_comptables;

UPDATE rubriques_comptables AS enfant
SET parent_id = (
    SELECT parent.id
    FROM rubriques_comptables AS parent
    WHERE parent.dossier_id = enfant.dossier_id
      AND parent.organisation_id = enfant.organisation_id
      AND parent.code <> ''
      AND length(parent.code) < length(enfant.code)
      AND enfant.code LIKE parent.code || '%'
    ORDER BY length(parent.code) DESC, parent.ordre, parent.id
    LIMIT 1
)
WHERE enfant.code <> '';

CREATE UNIQUE INDEX uq_rubriques_code
    ON rubriques_comptables(dossier_id, code)
    WHERE code <> '';
CREATE INDEX idx_rubriques_scope
    ON rubriques_comptables(dossier_id, actif, niveau_structure, ordre, code);
CREATE INDEX idx_rubriques_parent
    ON rubriques_comptables(parent_id, ordre, id);

CREATE TRIGGER trg_rubriques_scope_insert
BEFORE INSERT ON rubriques_comptables
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de rubrique comptable invalide') END;
    SELECT CASE WHEN NEW.parent_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.parent_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'parent de rubrique hors scope') END;
    SELECT CASE WHEN
        (NEW.niveau_structure = 'classe' AND NEW.parent_id IS NOT NULL)
        OR (NEW.niveau_structure = 'groupe_principal' AND NOT EXISTS (
            SELECT 1 FROM rubriques_comptables
            WHERE id = NEW.parent_id AND niveau_structure = 'classe'
        ))
        OR (NEW.niveau_structure = 'groupe' AND NOT EXISTS (
            SELECT 1 FROM rubriques_comptables
            WHERE id = NEW.parent_id AND niveau_structure = 'groupe_principal'
        ))
        OR (NEW.niveau_structure = 'sous_groupe' AND NOT EXISTS (
            SELECT 1 FROM rubriques_comptables
            WHERE id = NEW.parent_id AND niveau_structure = 'groupe'
        ))
    THEN RAISE(ABORT, 'niveau du parent de rubrique invalide') END;
END;

CREATE TRIGGER trg_rubriques_scope_update
BEFORE UPDATE ON rubriques_comptables
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de rubrique comptable invalide') END;
    SELECT CASE WHEN NEW.parent_id = NEW.id
        THEN RAISE(ABORT, 'une rubrique ne peut pas être son propre parent') END;
    SELECT CASE WHEN NEW.parent_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.parent_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'parent de rubrique hors scope') END;
    SELECT CASE WHEN
        (NEW.niveau_structure = 'classe' AND NEW.parent_id IS NOT NULL)
        OR (NEW.niveau_structure = 'groupe_principal' AND NOT EXISTS (
            SELECT 1 FROM rubriques_comptables
            WHERE id = NEW.parent_id AND niveau_structure = 'classe'
        ))
        OR (NEW.niveau_structure = 'groupe' AND NOT EXISTS (
            SELECT 1 FROM rubriques_comptables
            WHERE id = NEW.parent_id AND niveau_structure = 'groupe_principal'
        ))
        OR (NEW.niveau_structure = 'sous_groupe' AND NOT EXISTS (
            SELECT 1 FROM rubriques_comptables
            WHERE id = NEW.parent_id AND niveau_structure = 'groupe'
        ))
    THEN RAISE(ABORT, 'niveau du parent de rubrique invalide') END;
END;

ALTER TABLE comptes ADD COLUMN rubrique_id INTEGER
    REFERENCES rubriques_comptables(id) ON DELETE RESTRICT;

UPDATE comptes AS compte
SET rubrique_id = (
    SELECT rubrique.id
    FROM rubriques_comptables AS rubrique
    WHERE rubrique.organisation_id = compte.organisation_id
      AND rubrique.dossier_id = compte.dossier_id
      AND rubrique.actif = 1
      AND rubrique.code <> ''
      AND compte.numero LIKE rubrique.code || '%'
    ORDER BY length(rubrique.code) DESC, rubrique.ordre, rubrique.id
    LIMIT 1
)
WHERE compte.imputable = 1;

CREATE INDEX idx_comptes_rubrique ON comptes(rubrique_id, actif, numero);

CREATE TRIGGER trg_comptes_rubrique_insert
BEFORE INSERT ON comptes
WHEN NEW.rubrique_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.rubrique_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
          AND actif = 1
    ) THEN RAISE(ABORT, 'rubrique de compte hors scope ou inactive') END;
END;

CREATE TRIGGER trg_comptes_rubrique_update
BEFORE UPDATE OF rubrique_id, dossier_id, organisation_id ON comptes
WHEN NEW.rubrique_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.rubrique_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
          AND actif = 1
    ) THEN RAISE(ABORT, 'rubrique de compte hors scope ou inactive') END;
END;

UPDATE comptes SET type = 'passif' WHERE type = 'fonds_propres';
UPDATE modele_comptes SET type = 'passif' WHERE type = 'fonds_propres';
