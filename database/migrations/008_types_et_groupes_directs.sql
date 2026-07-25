CREATE TABLE types_comptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL CHECK (
        code IN ('actif', 'passif', 'produit', 'charge', 'hors_bilan')
    ),
    libelle TEXT NOT NULL CHECK (length(trim(libelle)) > 0),
    ordre INTEGER NOT NULL DEFAULT 0,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, code)
);

CREATE INDEX idx_types_comptes_scope
    ON types_comptes(dossier_id, actif, ordre, code);

CREATE TRIGGER trg_types_comptes_scope_insert
BEFORE INSERT ON types_comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de type de compte invalide') END;
END;

CREATE TRIGGER trg_types_comptes_scope_update
BEFORE UPDATE ON types_comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de type de compte invalide') END;
END;

INSERT INTO types_comptes
    (organisation_id, dossier_id, code, libelle, ordre)
SELECT organisation_id, id, 'actif', 'Actif', 10 FROM dossiers
UNION ALL
SELECT organisation_id, id, 'passif', 'Passif', 20 FROM dossiers
UNION ALL
SELECT organisation_id, id, 'produit', 'Produit', 30 FROM dossiers
UNION ALL
SELECT organisation_id, id, 'charge', 'Charge', 40 FROM dossiers
UNION ALL
SELECT organisation_id, id, 'hors_bilan', 'Hors bilan', 50 FROM dossiers;

-- Certains extraits historiques du modèle VEB passent directement d'une
-- classe au compte. Pour que chaque compte ait bien un groupe direct, les
-- niveaux manquants sont créés de manière explicite et restent éditables.
INSERT OR IGNORE INTO rubriques_comptables
    (organisation_id, dossier_id, code, libelle, niveau_structure,
     type, parent_id, ordre, source_modele)
SELECT DISTINCT
    groupe.organisation_id,
    groupe.dossier_id,
    substr(groupe.code, 1, 2),
    'Groupe principal ' || substr(groupe.code, 1, 2),
    'groupe_principal',
    classe.type,
    classe.id,
    CAST(substr(groupe.code, 1, 2) AS INTEGER) * 10,
    'migration-008'
FROM rubriques_comptables groupe
JOIN rubriques_comptables classe ON classe.id = groupe.parent_id
WHERE groupe.niveau_structure = 'groupe'
  AND classe.niveau_structure = 'classe';

UPDATE rubriques_comptables AS groupe
SET parent_id = (
    SELECT principal.id
    FROM rubriques_comptables principal
    WHERE principal.organisation_id = groupe.organisation_id
      AND principal.dossier_id = groupe.dossier_id
      AND principal.code = substr(groupe.code, 1, 2)
      AND principal.niveau_structure = 'groupe_principal'
    LIMIT 1
)
WHERE groupe.niveau_structure = 'groupe'
  AND EXISTS (
      SELECT 1 FROM rubriques_comptables parent
      WHERE parent.id = groupe.parent_id
        AND parent.niveau_structure = 'classe'
  );

INSERT OR IGNORE INTO rubriques_comptables
    (organisation_id, dossier_id, code, libelle, niveau_structure,
     type, parent_id, ordre, source_modele)
SELECT DISTINCT
    c.organisation_id,
    c.dossier_id,
    substr(c.numero, 1, 2),
    'Groupe principal ' || substr(c.numero, 1, 2),
    'groupe_principal',
    classe.type,
    classe.id,
    CAST(substr(c.numero, 1, 2) AS INTEGER) * 10,
    'migration-008'
FROM comptes c
JOIN rubriques_comptables directe ON directe.id = c.rubrique_id
JOIN rubriques_comptables classe
  ON classe.id = CASE
      WHEN directe.niveau_structure = 'classe' THEN directe.id
      WHEN directe.niveau_structure = 'groupe_principal' THEN directe.parent_id
      ELSE NULL
  END
WHERE c.imputable = 1
  AND directe.niveau_structure IN ('classe', 'groupe_principal');

INSERT OR IGNORE INTO rubriques_comptables
    (organisation_id, dossier_id, code, libelle, niveau_structure,
     type, parent_id, ordre, source_modele)
SELECT DISTINCT
    c.organisation_id,
    c.dossier_id,
    substr(c.numero, 1, 3),
    'Groupe ' || substr(c.numero, 1, 3),
    'groupe',
    principal.type,
    principal.id,
    CAST(substr(c.numero, 1, 3) AS INTEGER) * 10,
    'migration-008'
FROM comptes c
JOIN rubriques_comptables directe ON directe.id = c.rubrique_id
JOIN rubriques_comptables principal
  ON principal.organisation_id = c.organisation_id
 AND principal.dossier_id = c.dossier_id
 AND principal.code = substr(c.numero, 1, 2)
 AND principal.niveau_structure = 'groupe_principal'
WHERE c.imputable = 1
  AND directe.niveau_structure IN ('classe', 'groupe_principal');

UPDATE comptes AS compte
SET rubrique_id = (
    SELECT groupe.id
    FROM rubriques_comptables groupe
    WHERE groupe.organisation_id = compte.organisation_id
      AND groupe.dossier_id = compte.dossier_id
      AND groupe.niveau_structure = 'groupe'
      AND groupe.code = substr(compte.numero, 1, 3)
    LIMIT 1
)
WHERE compte.imputable = 1
  AND EXISTS (
      SELECT 1
      FROM rubriques_comptables directe
      WHERE directe.id = compte.rubrique_id
        AND directe.niveau_structure IN ('classe', 'groupe_principal')
  );

-- Les types des niveaux inférieurs et des comptes sont toujours hérités.
UPDATE rubriques_comptables AS enfant
SET type = (
    SELECT parent.type
    FROM rubriques_comptables parent
    WHERE parent.id = enfant.parent_id
)
WHERE enfant.niveau_structure = 'groupe_principal';

UPDATE rubriques_comptables AS enfant
SET type = (
    SELECT parent.type
    FROM rubriques_comptables parent
    WHERE parent.id = enfant.parent_id
)
WHERE enfant.niveau_structure = 'groupe';

UPDATE rubriques_comptables AS enfant
SET type = (
    SELECT parent.type
    FROM rubriques_comptables parent
    WHERE parent.id = enfant.parent_id
)
WHERE enfant.niveau_structure = 'sous_groupe';

UPDATE comptes AS compte
SET type = (
    SELECT groupe.type
    FROM rubriques_comptables groupe
    WHERE groupe.id = compte.rubrique_id
)
WHERE compte.imputable = 1 AND compte.rubrique_id IS NOT NULL;

DROP TRIGGER trg_comptes_rubrique_insert;
DROP TRIGGER trg_comptes_rubrique_update;

CREATE TRIGGER trg_comptes_rubrique_insert
BEFORE INSERT ON comptes
WHEN NEW.rubrique_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.rubrique_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
          AND niveau_structure = 'groupe'
          AND actif = 1
    ) THEN RAISE(ABORT, 'le parent direct du compte doit être un groupe actif') END;
    SELECT CASE WHEN NEW.type <> (
        SELECT type FROM rubriques_comptables WHERE id = NEW.rubrique_id
    ) THEN RAISE(ABORT, 'le type du compte doit être celui de son groupe') END;
END;

CREATE TRIGGER trg_comptes_rubrique_update
BEFORE UPDATE OF rubrique_id, type, dossier_id, organisation_id ON comptes
WHEN NEW.rubrique_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.rubrique_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
          AND niveau_structure = 'groupe'
          AND actif = 1
    ) THEN RAISE(ABORT, 'le parent direct du compte doit être un groupe actif') END;
    SELECT CASE WHEN NEW.type <> (
        SELECT type FROM rubriques_comptables WHERE id = NEW.rubrique_id
    ) THEN RAISE(ABORT, 'le type du compte doit être celui de son groupe') END;
END;
