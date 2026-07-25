-- La structure VEB historique relie chaque compte imputable à une ligne
-- structurelle par comptes.parent_id. Le numéro du compte n'est pas toujours
-- un préfixe du groupe (1020 appartient à 100, par exemple) : cette relation
-- explicite est donc la seule source fiable pour reprendre les rattachements.
UPDATE comptes AS compte
SET rubrique_id = (
    SELECT rubrique.id
    FROM comptes AS parent
    JOIN rubriques_comptables AS rubrique
      ON rubrique.organisation_id = compte.organisation_id
     AND rubrique.dossier_id = compte.dossier_id
     AND rubrique.code = parent.numero
     AND rubrique.actif = 1
    WHERE parent.id = compte.parent_id
      AND parent.organisation_id = compte.organisation_id
      AND parent.dossier_id = compte.dossier_id
      AND parent.imputable = 0
    LIMIT 1
)
WHERE compte.imputable = 1
  AND EXISTS (
      SELECT 1
      FROM comptes AS parent
      JOIN rubriques_comptables AS rubrique
        ON rubrique.organisation_id = compte.organisation_id
       AND rubrique.dossier_id = compte.dossier_id
       AND rubrique.code = parent.numero
       AND rubrique.actif = 1
      WHERE parent.id = compte.parent_id
        AND parent.organisation_id = compte.organisation_id
        AND parent.dossier_id = compte.dossier_id
        AND parent.imputable = 0
  );
