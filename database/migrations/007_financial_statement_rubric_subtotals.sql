ALTER TABLE rubriques_comptables
ADD COLUMN afficher_sous_total INTEGER NOT NULL DEFAULT 0
CHECK (afficher_sous_total IN (0, 1));
