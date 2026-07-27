ALTER TABLE employes ADD COLUMN contact_id INTEGER
    REFERENCES contacts(id) ON DELETE RESTRICT;

ALTER TABLE employes ADD COLUMN profil_incomplet INTEGER NOT NULL DEFAULT 0
    CHECK (profil_incomplet IN (0, 1));

CREATE UNIQUE INDEX uq_employes_contact
    ON employes(contact_id)
    WHERE contact_id IS NOT NULL;

CREATE TRIGGER trg_employes_contact_scope_insert
BEFORE INSERT ON employes
WHEN NEW.contact_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM contacts c
        WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact employe hors perimetre') END;
END;

CREATE TRIGGER trg_employes_contact_scope_update
BEFORE UPDATE OF contact_id, organisation_id, dossier_id ON employes
WHEN NEW.contact_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM contacts c
        WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact employe hors perimetre') END;
END;

INSERT INTO employes (
    organisation_id, dossier_id, contact_id, prenom, nom, email,
    rue, npa, localite, numero_avs, numero_avs_normalise,
    actif, profil_incomplet, cree_par
)
SELECT
    c.organisation_id,
    c.dossier_id,
    c.id,
    COALESCE(NULLIF(c.prenom, ''), '-'),
    COALESCE(NULLIF(c.nom, ''), '-'),
    c.email,
    COALESCE((
        SELECT a.ligne1 FROM adresses_contacts a
        WHERE a.contact_id = c.id
        ORDER BY a.actif DESC, a.id
        LIMIT 1
    ), ''),
    COALESCE((
        SELECT a.code_postal FROM adresses_contacts a
        WHERE a.contact_id = c.id
        ORDER BY a.actif DESC, a.id
        LIMIT 1
    ), ''),
    COALESCE((
        SELECT a.localite FROM adresses_contacts a
        WHERE a.contact_id = c.id
        ORDER BY a.actif DESC, a.id
        LIMIT 1
    ), ''),
    '',
    'contact:' || c.id,
    c.actif,
    1,
    c.cree_par
FROM contacts c
JOIN contact_roles cr ON cr.contact_id = c.id AND cr.role = 'employe'
WHERE c.type_personne = 'personne'
  AND NOT EXISTS (
      SELECT 1 FROM employes e WHERE e.contact_id = c.id
  );
