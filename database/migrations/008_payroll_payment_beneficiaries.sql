-- Les paiements salariaux conservent le bénéficiaire comptable précis.
-- Le type historique employé/organisme reste disponible pour compatibilité.

ALTER TABLE paiements_salaires
ADD COLUMN beneficiaire_code TEXT NOT NULL DEFAULT 'organisme'
CHECK (beneficiaire_code IN (
    'net', 'ocas', 'laa', 'lpp', 'impot_source', 'organisme'
));

UPDATE paiements_salaires
SET beneficiaire_code = CASE
    WHEN beneficiaire_type = 'employe' THEN 'net'
    ELSE 'organisme'
END;

-- Une saisie non allouée peut être réparée sans ambiguïté lorsque son montant
-- correspond exactement au solde d'une seule dette salariale du dossier.
UPDATE paiements_salaires AS p
SET beneficiaire_code = (
    SELECT d.type
    FROM dettes_salaires d
    JOIN fiches_salaires f ON f.id = d.fiche_salaire_id
    WHERE d.organisation_id = p.organisation_id
      AND d.dossier_id = p.dossier_id
      AND f.statut NOT IN ('brouillon', 'annulee')
      AND d.montant_centimes - COALESCE((
          SELECT SUM(a.montant_centimes)
          FROM allocations_salaires a
          WHERE a.dette_salaire_id = d.id AND a.statut = 'valide'
      ), 0) = p.montant_centimes
    ORDER BY d.id
    LIMIT 1
)
WHERE p.statut = 'valide'
  AND p.ecriture_id IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM allocations_salaires a
      WHERE a.paiement_salaire_id = p.id AND a.statut = 'valide'
  )
  AND 1 = (
      SELECT COUNT(*)
      FROM dettes_salaires d
      JOIN fiches_salaires f ON f.id = d.fiche_salaire_id
      WHERE d.organisation_id = p.organisation_id
        AND d.dossier_id = p.dossier_id
        AND f.statut NOT IN ('brouillon', 'annulee')
        AND d.montant_centimes - COALESCE((
            SELECT SUM(a.montant_centimes)
            FROM allocations_salaires a
            WHERE a.dette_salaire_id = d.id AND a.statut = 'valide'
        ), 0) = p.montant_centimes
  );

UPDATE paiements_salaires
SET beneficiaire_type = CASE
        WHEN beneficiaire_code = 'net' THEN 'employe'
        ELSE 'organisme'
    END,
    employe_id = CASE
        WHEN beneficiaire_code = 'net' THEN employe_id
        ELSE NULL
    END;

CREATE INDEX idx_paiements_salaires_beneficiaire
ON paiements_salaires(dossier_id, beneficiaire_code, statut, date_paiement);
