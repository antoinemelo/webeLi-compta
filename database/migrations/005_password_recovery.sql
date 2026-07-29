CREATE TABLE demandes_reinitialisation_mot_de_passe (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE CASCADE,
    email_hash TEXT NOT NULL CHECK (length(email_hash) = 64),
    ip_hash TEXT NOT NULL CHECK (length(ip_hash) = 64),
    selecteur TEXT UNIQUE,
    jeton_hash TEXT,
    expire_le INTEGER,
    consomme_le INTEGER,
    cree_le INTEGER NOT NULL,
    CHECK (
        (
            selecteur IS NULL
            AND jeton_hash IS NULL
            AND expire_le IS NULL
            AND utilisateur_id IS NULL
        )
        OR (
            selecteur IS NOT NULL
            AND length(selecteur) = 32
            AND jeton_hash IS NOT NULL
            AND length(jeton_hash) = 64
            AND expire_le IS NOT NULL
            AND expire_le > cree_le
            AND utilisateur_id IS NOT NULL
        )
    ),
    CHECK (consomme_le IS NULL OR consomme_le >= cree_le)
);

CREATE INDEX idx_reinitialisation_email
    ON demandes_reinitialisation_mot_de_passe(email_hash, cree_le);

CREATE INDEX idx_reinitialisation_ip
    ON demandes_reinitialisation_mot_de_passe(ip_hash, cree_le);

CREATE INDEX idx_reinitialisation_utilisateur
    ON demandes_reinitialisation_mot_de_passe(
        utilisateur_id,
        expire_le,
        consomme_le
    );
