DROP TRIGGER trg_comptes_rubrique_insert;
DROP TRIGGER trg_comptes_rubrique_update;
DROP TRIGGER trg_rubriques_scope_insert;
DROP TRIGGER trg_rubriques_scope_update;

-- Revenir au parent structurel fourni par le modèle VEB. Cette relation est
-- conservée dans comptes.parent_id et ne dépend pas des niveaux artificiels
-- créés par la migration 008.
UPDATE comptes AS compte
SET rubrique_id = (
    SELECT rubrique.id
    FROM comptes parent
    JOIN rubriques_comptables rubrique
      ON rubrique.organisation_id = compte.organisation_id
     AND rubrique.dossier_id = compte.dossier_id
     AND rubrique.code = parent.numero
     AND rubrique.source_modele <> 'migration-008'
     AND rubrique.libelle NOT GLOB 'Groupe*'
    WHERE parent.id = compte.parent_id
      AND parent.imputable = 0
    LIMIT 1
)
WHERE compte.imputable = 1
  AND EXISTS (
      SELECT 1
      FROM comptes parent
      JOIN rubriques_comptables rubrique
        ON rubrique.organisation_id = compte.organisation_id
       AND rubrique.dossier_id = compte.dossier_id
       AND rubrique.code = parent.numero
       AND rubrique.source_modele <> 'migration-008'
       AND rubrique.libelle NOT GLOB 'Groupe*'
      WHERE parent.id = compte.parent_id
        AND parent.imputable = 0
  );

-- Les groupes VEB auxquels un groupe principal artificiel avait été ajouté
-- retrouvent leur classe comme parent.
UPDATE rubriques_comptables AS groupe
SET parent_id = (
    SELECT classe.id
    FROM rubriques_comptables classe
    WHERE classe.organisation_id = groupe.organisation_id
      AND classe.dossier_id = groupe.dossier_id
      AND classe.niveau_structure = 'classe'
      AND classe.code = substr(groupe.code, 1, 1)
    LIMIT 1
)
WHERE groupe.niveau_structure = 'groupe'
  AND groupe.source_modele <> 'migration-008'
  AND EXISTS (
      SELECT 1 FROM rubriques_comptables parent
      WHERE parent.id = groupe.parent_id
        AND parent.source_modele = 'migration-008'
  );

DELETE FROM rubriques_comptables
WHERE niveau_structure = 'groupe'
  AND (source_modele = 'migration-008' OR libelle GLOB 'Groupe*');

DELETE FROM rubriques_comptables
WHERE niveau_structure = 'groupe_principal'
  AND (
      source_modele = 'migration-008'
      OR libelle GLOB 'Groupe principal*'
      OR code NOT IN ('10', '14', '20', '24', '28')
  );

UPDATE rubriques_comptables AS enfant
SET type = (
    SELECT parent.type FROM rubriques_comptables parent
    WHERE parent.id = enfant.parent_id
)
WHERE enfant.niveau_structure IN ('groupe_principal', 'groupe', 'sous_groupe');

UPDATE comptes AS compte
SET type = (
    SELECT rubrique.type FROM rubriques_comptables rubrique
    WHERE rubrique.id = compte.rubrique_id
)
WHERE compte.imputable = 1 AND compte.rubrique_id IS NOT NULL;

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
            WHERE id = NEW.parent_id
              AND niveau_structure IN ('classe', 'groupe_principal')
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
            WHERE id = NEW.parent_id
              AND niveau_structure IN ('classe', 'groupe_principal')
        ))
        OR (NEW.niveau_structure = 'sous_groupe' AND NOT EXISTS (
            SELECT 1 FROM rubriques_comptables
            WHERE id = NEW.parent_id AND niveau_structure = 'groupe'
        ))
    THEN RAISE(ABORT, 'niveau du parent de rubrique invalide') END;
END;

CREATE TRIGGER trg_comptes_rubrique_insert
BEFORE INSERT ON comptes
WHEN NEW.rubrique_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.rubrique_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
          AND niveau_structure IN ('classe', 'groupe_principal', 'groupe')
          AND actif = 1
    ) THEN RAISE(ABORT, 'parent direct du compte invalide') END;
    SELECT CASE WHEN NEW.type <> (
        SELECT type FROM rubriques_comptables WHERE id = NEW.rubrique_id
    ) THEN RAISE(ABORT, 'le type du compte doit être celui de son parent') END;
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
          AND niveau_structure IN ('classe', 'groupe_principal', 'groupe')
          AND actif = 1
    ) THEN RAISE(ABORT, 'parent direct du compte invalide') END;
    SELECT CASE WHEN NEW.type <> (
        SELECT type FROM rubriques_comptables WHERE id = NEW.rubrique_id
    ) THEN RAISE(ABORT, 'le type du compte doit être celui de son parent') END;
END;
