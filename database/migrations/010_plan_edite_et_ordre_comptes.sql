ALTER TABLE comptes ADD COLUMN ordre INTEGER NOT NULL DEFAULT 0;

UPDATE comptes AS compte
SET ordre = (
    SELECT COUNT(*) * 10
    FROM comptes precedent
    WHERE precedent.dossier_id = compte.dossier_id
      AND (
          precedent.numero < compte.numero
          OR (precedent.numero = compte.numero AND precedent.id <= compte.id)
      )
);

CREATE INDEX idx_comptes_ordre
    ON comptes(dossier_id, imputable, actif, ordre, numero);

-- Reprendre dans chaque dossier les rubriques structurantes ajoutées au plan
-- réel et destinées à devenir le seed de référence.
WITH definitions(code, libelle, classe_code, type, ordre) AS (
    VALUES
        ('30', 'Produits bruts  d''exploitation', '3', 'produit', 300),
        ('38', 'Ajustements sur les produits bruts d''exploitation', '3', 'produit', 380),
        ('39', 'Variations des stocks', '3', 'produit', 390),
        ('40', 'Coûts d''achat des produits vendus', '4', 'charge', 400),
        ('50', 'Charges du personnel', '5', 'charge', 500),
        ('59', 'Commissions', '5', 'charge', 590),
        ('60', 'Diverses charges d''exploitation', '6', 'charge', 600),
        ('68', 'Amortissements', '6', 'charge', 680),
        ('69', 'Résultat financier', '6', 'charge', 690),
        ('70', 'Résultat des activités annexes diverses', '7', 'charge', 700),
        ('75', 'Résultat des immeubles d''exploitation', '7', 'charge', 750),
        ('80', 'Résultat des activités hors exploitation diverses', '8', 'charge', 800),
        ('85', 'Résultat des activités extraordinaires', '8', 'charge', 850),
        ('89', 'Impôts', '8', 'charge', 890),
        ('92', 'Résultat de l''exercice', '9', 'hors_bilan', 920)
)
INSERT OR IGNORE INTO rubriques_comptables
    (organisation_id, dossier_id, code, libelle, niveau_structure,
     type, parent_id, ordre, source_modele)
SELECT classe.organisation_id, classe.dossier_id, definition.code,
       definition.libelle, 'groupe_principal', definition.type,
       classe.id, definition.ordre, 'veb-pme-fr'
FROM definitions definition
JOIN rubriques_comptables classe
  ON classe.niveau_structure = 'classe'
 AND classe.code = definition.classe_code;

UPDATE rubriques_comptables
SET libelle = CASE code
    WHEN '10' THEN 'Actifs circulants'
    WHEN '14' THEN 'Actifs immobilisés'
    WHEN '28' THEN 'Capitaux propres'
    ELSE libelle
END
WHERE niveau_structure = 'groupe_principal'
  AND code IN ('10', '14', '28');

UPDATE rubriques_comptables
SET type = CASE code
    WHEN '7' THEN 'charge'
    WHEN '9' THEN 'hors_bilan'
    ELSE type
END
WHERE niveau_structure = 'classe' AND code IN ('7', '9');

-- Les groupes VEB appartiennent au groupe principal chronologiquement le plus
-- proche de la même classe.
UPDATE rubriques_comptables AS groupe
SET parent_id = (
    SELECT principal.id
    FROM rubriques_comptables principal
    JOIN rubriques_comptables classe ON classe.id = principal.parent_id
    WHERE principal.organisation_id = groupe.organisation_id
      AND principal.dossier_id = groupe.dossier_id
      AND principal.niveau_structure = 'groupe_principal'
      AND classe.code = substr(groupe.code, 1, 1)
      AND CAST(principal.code AS INTEGER) <= CAST(substr(groupe.code, 1, 2) AS INTEGER)
    ORDER BY CAST(principal.code AS INTEGER) DESC
    LIMIT 1
)
WHERE groupe.niveau_structure = 'groupe';

-- Les comptes qui pointaient directement sur une classe utilisent désormais
-- le groupe principal chronologiquement le plus proche.
UPDATE comptes AS compte
SET rubrique_id = (
    SELECT principal.id
    FROM rubriques_comptables principal
    WHERE principal.organisation_id = compte.organisation_id
      AND principal.dossier_id = compte.dossier_id
      AND principal.niveau_structure = 'groupe_principal'
      AND principal.parent_id = compte.rubrique_id
      AND CAST(principal.code AS INTEGER) <= CAST(substr(compte.numero, 1, 2) AS INTEGER)
    ORDER BY CAST(principal.code AS INTEGER) DESC
    LIMIT 1
)
WHERE compte.imputable = 1
  AND EXISTS (
      SELECT 1 FROM rubriques_comptables classe
      WHERE classe.id = compte.rubrique_id
        AND classe.niveau_structure = 'classe'
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

UPDATE comptes SET type = 'hors_bilan' WHERE numero LIKE '9%';

UPDATE modele_comptes
SET type = CASE
    WHEN numero LIKE '7%' THEN 'charge'
    WHEN numero LIKE '8%' THEN 'charge'
    WHEN numero LIKE '9%' THEN 'hors_bilan'
    ELSE type
END;

DROP TRIGGER trg_rubriques_scope_insert;
DROP TRIGGER trg_rubriques_scope_update;
DROP TRIGGER trg_comptes_rubrique_insert;
DROP TRIGGER trg_comptes_rubrique_update;

CREATE TRIGGER trg_comptes_type_9_insert
BEFORE INSERT ON comptes
WHEN NEW.numero LIKE '9%' AND NEW.type <> 'hors_bilan'
BEGIN
    SELECT RAISE(ABORT, 'un compte 9 doit être hors bilan');
END;

CREATE TRIGGER trg_comptes_type_9_update
BEFORE UPDATE OF numero, type ON comptes
WHEN NEW.numero LIKE '9%' AND NEW.type <> 'hors_bilan'
BEGIN
    SELECT RAISE(ABORT, 'un compte 9 doit être hors bilan');
END;

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
    SELECT CASE WHEN NEW.code LIKE '9%' AND NEW.type <> 'hors_bilan'
        THEN RAISE(ABORT, 'une rubrique 9 doit être hors bilan') END;
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
    SELECT CASE WHEN NEW.code LIKE '9%' AND NEW.type <> 'hors_bilan'
        THEN RAISE(ABORT, 'une rubrique 9 doit être hors bilan') END;
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

CREATE TRIGGER trg_comptes_rubrique_insert
BEFORE INSERT ON comptes
WHEN NEW.rubrique_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NEW.numero LIKE '9%' AND NEW.type <> 'hors_bilan'
        THEN RAISE(ABORT, 'un compte 9 doit être hors bilan') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.rubrique_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
          AND niveau_structure IN ('groupe_principal', 'groupe')
          AND actif = 1
    ) THEN RAISE(ABORT, 'parent direct du compte invalide') END;
    SELECT CASE WHEN NEW.type <> (
        SELECT type FROM rubriques_comptables WHERE id = NEW.rubrique_id
    ) THEN RAISE(ABORT, 'le type du compte doit être celui de son parent') END;
END;

CREATE TRIGGER trg_comptes_rubrique_update
BEFORE UPDATE OF numero, rubrique_id, type, dossier_id, organisation_id ON comptes
WHEN NEW.rubrique_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NEW.numero LIKE '9%' AND NEW.type <> 'hors_bilan'
        THEN RAISE(ABORT, 'un compte 9 doit être hors bilan') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM rubriques_comptables
        WHERE id = NEW.rubrique_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
          AND niveau_structure IN ('groupe_principal', 'groupe')
          AND actif = 1
    ) THEN RAISE(ABORT, 'parent direct du compte invalide') END;
    SELECT CASE WHEN NEW.type <> (
        SELECT type FROM rubriques_comptables WHERE id = NEW.rubrique_id
    ) THEN RAISE(ABORT, 'le type du compte doit être celui de son parent') END;
END;
