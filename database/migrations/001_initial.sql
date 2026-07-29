-- COMPTA — schéma initial canonique
-- Généré depuis le modèle qualifié du lot 05bis.
-- Cette base est destinée aux installations neuves de développement.

-- TABLES

CREATE TABLE adresses_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    type TEXT NOT NULL DEFAULT 'facturation'
        CHECK (type IN ('facturation', 'livraison', 'correspondance')),
    ligne1 TEXT NOT NULL,
    ligne2 TEXT NOT NULL DEFAULT '',
    code_postal TEXT NOT NULL,
    localite TEXT NOT NULL,
    pays TEXT NOT NULL DEFAULT 'CH' CHECK (length(pays) = 2),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    paiement_id INTEGER REFERENCES paiements(id) ON DELETE RESTRICT,
    avoir_id INTEGER REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    montant_document_base_centimes INTEGER NOT NULL DEFAULT 0,
    montant_paiement_base_centimes INTEGER NOT NULL DEFAULT 0,
    ecart_change_realise_centimes INTEGER NOT NULL DEFAULT 0,
    ecriture_ecart_change_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'valide' CHECK (statut IN ('valide', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    annule_le TEXT,
    annule_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK ((paiement_id IS NOT NULL) <> (avoir_id IS NOT NULL))
);

CREATE TABLE allocations_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    paiement_salaire_id INTEGER NOT NULL REFERENCES paiements_salaires(id) ON DELETE RESTRICT,
    dette_salaire_id INTEGER NOT NULL REFERENCES dettes_salaires(id) ON DELETE RESTRICT,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    statut TEXT NOT NULL DEFAULT 'valide' CHECK (statut IN ('valide', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL
);

CREATE TABLE ajustements_fiscaux (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL CHECK (length(trim(libelle)) > 0),
    nature TEXT NOT NULL
        CHECK (nature IN ('augmentation', 'deduction', 'information')),
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes >= 0),
    note TEXT NOT NULL DEFAULT '',
    cle_idempotence TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'propose'
        CHECK (statut IN ('propose', 'valide', 'ecarte')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE archives_rapports_financiers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (type IN ('cloture', 'dossier_fiscal')),
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    parametres_json TEXT NOT NULL CHECK (json_valid(parametres_json)),
    empreinte_parametres TEXT NOT NULL CHECK (length(empreinte_parametres) = 64),
    empreinte_grand_livre TEXT NOT NULL CHECK (length(empreinte_grand_livre) = 64),
    contenu_json TEXT NOT NULL CHECK (json_valid(contenu_json)),
    empreinte_sha256 TEXT NOT NULL CHECK (length(empreinte_sha256) = 64),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (
        dossier_id, exercice_id, type, empreinte_sha256
    )
);

CREATE TABLE assignations_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    version_modele_id INTEGER NOT NULL
        REFERENCES versions_modeles_exercice(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    groupe_id INTEGER REFERENCES groupes_pedagogiques(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'en_cours'
        CHECK (statut IN ('en_cours', 'terminee', 'archivee')),
    correction_autorisee INTEGER NOT NULL DEFAULT 0
        CHECK (correction_autorisee IN (0, 1)),
    generation INTEGER NOT NULL DEFAULT 1 CHECK (generation > 0),
    assignee_le TEXT NOT NULL DEFAULT (datetime('now')),
    assignee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    terminee_le TEXT,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (
        (utilisateur_id IS NOT NULL AND groupe_id IS NULL)
        OR (utilisateur_id IS NULL AND groupe_id IS NOT NULL)
    )
);

CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    organisation_id INTEGER REFERENCES organisations(id) ON DELETE SET NULL,
    dossier_id INTEGER REFERENCES dossiers(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    cible_type TEXT NOT NULL DEFAULT '',
    cible_id TEXT NOT NULL DEFAULT '',
    resume_json TEXT NOT NULL DEFAULT '{}',
    ip TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE categories_immobilisations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    duree_defaut_mois INTEGER NOT NULL CHECK (duree_defaut_mois BETWEEN 1 AND 1200),
    compte_actif_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_amortissement_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_dotation_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_gain_cession_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_perte_cession_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, code)
);

CREATE TABLE certificats_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    employe_id INTEGER NOT NULL REFERENCES employes(id) ON DELETE RESTRICT,
    annee INTEGER NOT NULL CHECK (annee BETWEEN 2000 AND 9999),
    donnees_snapshot_json TEXT NOT NULL,
    xml_archive TEXT NOT NULL,
    empreinte_sha256 TEXT NOT NULL CHECK (length(empreinte_sha256) = 64),
    statut TEXT NOT NULL DEFAULT 'prepare'
        CHECK (statut IN ('prepare', 'controle', 'exporte', 'annule')),
    controle_le TEXT,
    controle_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    exporte_le TEXT,
    exporte_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    transmis INTEGER NOT NULL DEFAULT 0 CHECK (transmis = 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, employe_id, annee)
);

CREATE TABLE composants_fiche (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE CASCADE,
    code TEXT NOT NULL,
    libelle TEXT NOT NULL,
    categorie TEXT NOT NULL
        CHECK (categorie IN ('gain', 'retenue_employe', 'charge_employeur', 'net')),
    base_centimes INTEGER NOT NULL,
    taux_ppm INTEGER NOT NULL DEFAULT 0 CHECK (taux_ppm BETWEEN 0 AND 1000000),
    montant_centimes INTEGER NOT NULL,
    ordre INTEGER NOT NULL,
    UNIQUE (fiche_salaire_id, code)
);

CREATE TABLE contrats_salariaux (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    employe_id INTEGER NOT NULL REFERENCES employes(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (type IN ('horaire', 'mensuel')),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    taux_horaire_centimes INTEGER NOT NULL DEFAULT 0
        CHECK (taux_horaire_centimes >= 0),
    salaire_mensuel_centimes INTEGER NOT NULL DEFAULT 0
        CHECK (salaire_mensuel_centimes >= 0),
    heures_hebdo_milli INTEGER NOT NULL DEFAULT 40000
        CHECK (heures_hebdo_milli > 0),
    taux_activite_ppm INTEGER NOT NULL DEFAULT 1000000
        CHECK (taux_activite_ppm BETWEEN 1 AND 1000000),
    source TEXT NOT NULL,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    CHECK (
        (type = 'horaire' AND taux_horaire_centimes > 0
            AND salaire_mensuel_centimes = 0)
        OR
        (type = 'mensuel' AND salaire_mensuel_centimes > 0
            AND taux_horaire_centimes = 0)
    ),
    UNIQUE (employe_id, date_debut)
);

CREATE TABLE comptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    numero TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (
        type IN ('actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan')
    ),
    sens_normal TEXT NOT NULL CHECK (sens_normal IN ('debit', 'credit')),
    parent_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    niveau INTEGER NOT NULL DEFAULT 1 CHECK (niveau BETWEEN 1 AND 9),
    imputable INTEGER NOT NULL DEFAULT 1 CHECK (imputable IN (0, 1)),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    marque TEXT NOT NULL DEFAULT '',
    source_modele TEXT NOT NULL DEFAULT '',
    source_version TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1, sens_mode TEXT NOT NULL DEFAULT 'automatique'
    CHECK (sens_mode IN ('automatique', 'debit', 'credit')), rubrique_id INTEGER
    REFERENCES rubriques_comptables(id) ON DELETE RESTRICT, ordre INTEGER NOT NULL DEFAULT 0,
    UNIQUE (dossier_id, numero)
);

CREATE TABLE comptes_tresorerie (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_comptable_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('banque', 'poste', 'caisse', 'carte')),
    iban TEXT NOT NULL DEFAULT '',
    bic TEXT NOT NULL DEFAULT '',
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (length(monnaie) = 3),
    multiplicateur_comptable INTEGER NOT NULL DEFAULT 1
        CHECK (multiplicateur_comptable IN (-1, 1)),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    archive_le TEXT,
    archive_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, compte_comptable_id)
);

CREATE TABLE controles_cloture (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    code TEXT NOT NULL CHECK (
        code IN ('pieces', 'ajustements', 'revue_fiscale')
    ),
    statut TEXT NOT NULL DEFAULT 'a_faire'
        CHECK (statut IN ('a_faire', 'termine', 'non_applicable')),
    note TEXT NOT NULL DEFAULT '',
    modifie_le TEXT NOT NULL DEFAULT (datetime('now')),
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (exercice_id, code)
);

CREATE TABLE conditions_paiement (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    direction TEXT NOT NULL DEFAULT 'tous'
        CHECK (direction IN ('client', 'fournisseur', 'tous')),
    delai_jours INTEGER NOT NULL DEFAULT 30 CHECK (delai_jours BETWEEN 0 AND 3650),
    fin_de_mois INTEGER NOT NULL DEFAULT 0 CHECK (fin_de_mois IN (0, 1)),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (dossier_id, code, date_debut)
);

CREATE TABLE consultations_indices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignation_id INTEGER NOT NULL
        REFERENCES assignations_exercice(id) ON DELETE RESTRICT,
    indice_id INTEGER NOT NULL REFERENCES indices_exercice(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    consulte_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (assignation_id, indice_id, utilisateur_id)
);

CREATE TABLE contact_roles (
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('client', 'fournisseur', 'employe', 'autre')),
    PRIMARY KEY (contact_id, role)
);

CREATE TABLE contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    type_personne TEXT NOT NULL DEFAULT 'entreprise'
        CHECK (type_personne IN ('entreprise', 'personne')),
    entreprise_id INTEGER REFERENCES contacts(id) ON DELETE RESTRICT,
    raison_sociale TEXT NOT NULL DEFAULT '',
    prenom TEXT NOT NULL DEFAULT '',
    nom TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    telephone TEXT NOT NULL DEFAULT '',
    iban_paiement TEXT NOT NULL DEFAULT '',
    bic_paiement TEXT NOT NULL DEFAULT '',
    cle_idempotence TEXT NOT NULL DEFAULT '',
    langue TEXT NOT NULL DEFAULT 'fr' CHECK (langue IN ('fr', 'de', 'it', 'en')),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    archive_le TEXT,
    archive_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (
        (type_personne = 'entreprise' AND raison_sociale <> '')
        OR (type_personne = 'personne' AND (prenom <> '' OR nom <> ''))
    ),
    CHECK (
        (type_personne = 'entreprise' AND entreprise_id IS NULL)
        OR type_personne = 'personne'
    ),
    CHECK (entreprise_id IS NULL OR entreprise_id <> id)
);

CREATE TABLE contributions_pedagogiques (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    assignation_id INTEGER NOT NULL
        REFERENCES assignations_exercice(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE SET NULL,
    action TEXT NOT NULL CHECK (
        action IN ('creation', 'modification', 'validation', 'indice', 'tentative')
    ),
    resume_json TEXT NOT NULL DEFAULT '{}',
    cree_le TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE defauts_conditions_paiement (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    direction TEXT NOT NULL CHECK (direction IN ('client', 'fournisseur')),
    condition_id INTEGER NOT NULL REFERENCES conditions_paiement(id) ON DELETE RESTRICT,
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (dossier_id, direction, date_debut)
);

CREATE TABLE dettes_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (type IN ('net', 'ocas', 'laa', 'lpp', 'impot_source')),
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    compte_dette_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (fiche_salaire_id, type)
);

CREATE TABLE documents_financiers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (
        type IN (
            'facture_client', 'avoir_client',
            'facture_fournisseur', 'avoir_fournisseur'
        )
    ),
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN (
            'brouillon', 'a_approuver', 'approuve',
            'emis', 'comptabilise', 'annule'
        )),
    workflow TEXT NOT NULL DEFAULT 'facturation'
        CHECK (workflow IN ('facturation', 'depense')),
    cle_generation TEXT NOT NULL DEFAULT '',
    numero TEXT NOT NULL DEFAULT '',
    numero_externe TEXT NOT NULL DEFAULT '',
    date_document TEXT NOT NULL,
    date_echeance TEXT NOT NULL,
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (length(monnaie) = 3),
    devise_base TEXT NOT NULL DEFAULT 'CHF' CHECK (length(devise_base) = 3),
    taux_change_numerateur INTEGER NOT NULL DEFAULT 1
        CHECK (taux_change_numerateur > 0),
    taux_change_denominateur INTEGER NOT NULL DEFAULT 1
        CHECK (taux_change_denominateur > 0),
    taux_change_date TEXT NOT NULL DEFAULT '',
    taux_change_source TEXT NOT NULL DEFAULT 'devise_base',
    adresse_snapshot_json TEXT NOT NULL,
    contact_snapshot_json TEXT NOT NULL,
    total_net_centimes INTEGER NOT NULL DEFAULT 0,
    total_tva_centimes INTEGER NOT NULL DEFAULT 0,
    total_brut_centimes INTEGER NOT NULL DEFAULT 0,
    total_net_base_centimes INTEGER NOT NULL DEFAULT 0,
    total_tva_base_centimes INTEGER NOT NULL DEFAULT 0,
    total_brut_base_centimes INTEGER NOT NULL DEFAULT 0,
    compte_collectif_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    ecriture_annulation_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    document_origine_id INTEGER REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    justificatif_id INTEGER REFERENCES pieces_jointes(id) ON DELETE RESTRICT,
    reference_scor TEXT NOT NULL DEFAULT '',
    qr_payload TEXT NOT NULL DEFAULT '',
    pdf_archive BLOB,
    pdf_empreinte_sha256 TEXT NOT NULL DEFAULT '',
    emis_le TEXT,
    soumis_le TEXT,
    soumis_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    approuve_le TEXT,
    approuve_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    comptabilise_le TEXT,
    annule_le TEXT,
    annule_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1, condition_paiement_id INTEGER
        REFERENCES conditions_paiement(id) ON DELETE RESTRICT, condition_paiement_snapshot_json TEXT NOT NULL DEFAULT '{}',
    CHECK (date_echeance >= date_document),
    CHECK (total_brut_centimes = total_net_centimes + total_tva_centimes),
    CHECK (
        (statut = 'brouillon' AND numero = '')
        OR statut = 'annule'
        OR (statut NOT IN ('brouillon', 'annule') AND numero <> '')
    )
);

CREATE TABLE documents_commerciaux (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (
        type IN (
            'offre_client',
            'demande_offre_fournisseur',
            'reponse_offre_fournisseur',
            'commande_client',
            'commande_fournisseur'
        )
    ),
    statut TEXT NOT NULL DEFAULT 'brouillon' CHECK (
        statut IN (
            'brouillon', 'envoye', 'recu', 'accepte', 'refuse',
            'remplace', 'commande', 'facture', 'annule', 'archive'
        )
    ),
    numero TEXT NOT NULL DEFAULT '',
    numero_externe TEXT NOT NULL DEFAULT '',
    date_document TEXT NOT NULL,
    date_validite TEXT,
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (length(monnaie) = 3),
    adresse_snapshot_json TEXT NOT NULL DEFAULT '{}'
        CHECK (json_valid(adresse_snapshot_json)),
    contact_snapshot_json TEXT NOT NULL DEFAULT '{}'
        CHECK (json_valid(contact_snapshot_json)),
    total_net_centimes INTEGER NOT NULL DEFAULT 0,
    total_tva_centimes INTEGER NOT NULL DEFAULT 0,
    total_brut_centimes INTEGER NOT NULL DEFAULT 0,
    texte_entete TEXT NOT NULL DEFAULT '',
    texte_pied TEXT NOT NULL DEFAULT '',
    note_interne TEXT NOT NULL DEFAULT '',
    document_source_id INTEGER REFERENCES documents_commerciaux(id) ON DELETE RESTRICT,
    remplace_par_id INTEGER REFERENCES documents_commerciaux(id) ON DELETE RESTRICT,
    emis_le TEXT,
    accepte_le TEXT,
    refuse_le TEXT,
    archive_le TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_validite IS NULL OR date_validite >= date_document),
    CHECK (total_brut_centimes = total_net_centimes + total_tva_centimes),
    CHECK (remplace_par_id IS NULL OR remplace_par_id <> id)
);

CREATE TABLE modeles_depenses_recurrentes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    periodicite TEXT NOT NULL
        CHECK (periodicite IN ('hebdomadaire', 'mensuelle', 'trimestrielle', 'annuelle')),
    intervalle INTEGER NOT NULL DEFAULT 1 CHECK (intervalle BETWEEN 1 AND 120),
    prochaine_echeance TEXT NOT NULL,
    jour_reference INTEGER NOT NULL CHECK (jour_reference BETWEEN 1 AND 31),
    date_fin TEXT,
    jours_echeance INTEGER NOT NULL DEFAULT 30 CHECK (jours_echeance BETWEEN 0 AND 365),
    compte_collectif_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    numero_externe_prefixe TEXT NOT NULL DEFAULT 'REC',
    lignes_json TEXT NOT NULL CHECK (json_valid(lignes_json)),
    statut TEXT NOT NULL DEFAULT 'actif'
        CHECK (statut IN ('actif', 'pause', 'termine')),
    derniere_generation_le TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE generations_depenses_recurrentes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    modele_id INTEGER NOT NULL
        REFERENCES modeles_depenses_recurrentes(id) ON DELETE RESTRICT,
    date_generation TEXT NOT NULL,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (modele_id, date_generation),
    UNIQUE (document_id)
);

CREATE TABLE modeles_factures_recurrentes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
    type TEXT NOT NULL
        CHECK (type IN ('facture_client', 'facture_fournisseur')),
    libelle TEXT NOT NULL,
    periodicite TEXT NOT NULL
        CHECK (periodicite IN ('hebdomadaire', 'mensuelle', 'trimestrielle', 'annuelle')),
    intervalle INTEGER NOT NULL DEFAULT 1 CHECK (intervalle BETWEEN 1 AND 120),
    prochaine_echeance TEXT NOT NULL,
    jour_reference INTEGER NOT NULL CHECK (jour_reference BETWEEN 1 AND 31),
    date_fin TEXT,
    jours_echeance INTEGER NOT NULL DEFAULT 30 CHECK (jours_echeance BETWEEN 0 AND 365),
    compte_collectif_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    numero_externe_prefixe TEXT NOT NULL DEFAULT '',
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (monnaie IN ('CHF', 'EUR')),
    lignes_json TEXT NOT NULL CHECK (json_valid(lignes_json)),
    statut TEXT NOT NULL DEFAULT 'actif'
        CHECK (statut IN ('actif', 'pause', 'termine')),
    derniere_generation_le TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_fin IS NULL OR date_fin >= prochaine_echeance)
);

CREATE TABLE generations_factures_recurrentes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    modele_id INTEGER NOT NULL
        REFERENCES modeles_factures_recurrentes(id) ON DELETE RESTRICT,
    date_generation TEXT NOT NULL,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (modele_id, date_generation),
    UNIQUE (document_id)
);

CREATE TABLE dossiers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    nom TEXT NOT NULL,
    slug TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('reel', 'demo', 'exercice')),
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (length(monnaie) = 3),
    compte_tresorerie_facturation_id INTEGER
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (organisation_id, slug)
);

CREATE TABLE devises_dossier (
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE CASCADE,
    code TEXT NOT NULL CHECK (length(code) = 3),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (dossier_id, code)
);

CREATE TABLE taux_change (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE CASCADE,
    devise_source TEXT NOT NULL CHECK (length(devise_source) = 3),
    devise_cible TEXT NOT NULL CHECK (length(devise_cible) = 3),
    date_taux TEXT NOT NULL,
    numerateur INTEGER NOT NULL CHECK (numerateur > 0),
    denominateur INTEGER NOT NULL CHECK (denominateur > 0),
    source TEXT NOT NULL CHECK (length(trim(source)) > 0),
    verifie_le TEXT NOT NULL,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (devise_source <> devise_cible),
    UNIQUE (dossier_id, devise_source, devise_cible, date_taux, source)
);

CREATE TABLE parametres_change (
    dossier_id INTEGER PRIMARY KEY REFERENCES dossiers(id) ON DELETE CASCADE,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    compte_gain_realise_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_perte_realisee_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_gain_latent_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_perte_latente_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    modifie_le TEXT NOT NULL DEFAULT (datetime('now')),
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE reevaluations_change (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    journal_id INTEGER NOT NULL REFERENCES journaux(id) ON DELETE RESTRICT,
    date_reevaluation TEXT NOT NULL,
    ecriture_id INTEGER NOT NULL UNIQUE REFERENCES ecritures(id) ON DELETE RESTRICT,
    ecriture_contrepassation_id INTEGER UNIQUE REFERENCES ecritures(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'comptabilisee'
        CHECK (statut IN ('comptabilisee', 'contre_passee')),
    cle_idempotence TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    contrepassee_le TEXT,
    contrepassee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, cle_idempotence)
);

CREATE TABLE lignes_reevaluation_change (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    reevaluation_id INTEGER NOT NULL REFERENCES reevaluations_change(id) ON DELETE CASCADE,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    devise TEXT NOT NULL CHECK (length(devise) = 3),
    montant_ouvert_centimes INTEGER NOT NULL CHECK (montant_ouvert_centimes > 0),
    valeur_historique_base_centimes INTEGER NOT NULL,
    valeur_reevaluee_base_centimes INTEGER NOT NULL,
    ecart_latent_centimes INTEGER NOT NULL,
    taux_change_numerateur INTEGER NOT NULL CHECK (taux_change_numerateur > 0),
    taux_change_denominateur INTEGER NOT NULL CHECK (taux_change_denominateur > 0),
    taux_change_date TEXT NOT NULL,
    taux_change_source TEXT NOT NULL,
    UNIQUE (reevaluation_id, document_id)
);

-- Référentiel public partagé entre toutes les organisations et tous les
-- dossiers. Ces valeurs analytiques ne remplacent jamais les snapshots de
-- change portés par les documents, paiements et écritures.
CREATE TABLE series_marche_publiques (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    jeu_donnees TEXT NOT NULL CHECK (jeu_donnees IN ('devkum', 'zimoma')),
    code_serie TEXT NOT NULL,
    categorie TEXT NOT NULL CHECK (categorie IN ('change', 'interet')),
    libelle TEXT NOT NULL,
    devise TEXT NOT NULL CHECK (length(devise) = 3),
    mode TEXT NOT NULL,
    unite_base INTEGER NOT NULL DEFAULT 1 CHECK (unite_base > 0),
    unite TEXT NOT NULL,
    url_source TEXT NOT NULL,
    metadonnees_json TEXT NOT NULL DEFAULT '{}',
    actualisee_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (jeu_donnees, code_serie)
);

CREATE TABLE valeurs_marche_mensuelles (
    serie_id INTEGER NOT NULL
        REFERENCES series_marche_publiques(id) ON DELETE CASCADE,
    periode TEXT NOT NULL CHECK (
        length(periode) = 7
        AND substr(periode, 5, 1) = '-'
    ),
    valeur_texte TEXT NOT NULL,
    valeur_echelle INTEGER NOT NULL,
    echelle INTEGER NOT NULL CHECK (echelle > 0),
    actualisee_le TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (serie_id, periode)
);

CREATE TABLE taux_change_publics_quotidiens (
    date_requise TEXT NOT NULL,
    date_publication TEXT NOT NULL,
    validite TEXT NOT NULL,
    devise TEXT NOT NULL CHECK (length(devise) = 3),
    unite_base INTEGER NOT NULL CHECK (unite_base > 0),
    valeur_texte TEXT NOT NULL,
    valeur_echelle INTEGER NOT NULL CHECK (valeur_echelle > 0),
    echelle INTEGER NOT NULL CHECK (echelle > 0),
    url_source TEXT NOT NULL,
    actualise_le TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (date_requise, devise)
);

CREATE TABLE actualisations_marche_publiques (
    jeu_donnees TEXT PRIMARY KEY
        CHECK (jeu_donnees IN ('devkum', 'zimoma', 'bazg_daily')),
    signature_besoin TEXT NOT NULL DEFAULT '',
    url_source TEXT NOT NULL,
    statut TEXT NOT NULL CHECK (statut IN ('succes', 'echec')),
    tente_le TEXT NOT NULL,
    reussie_le TEXT,
    erreur TEXT NOT NULL DEFAULT ''
);

CREATE TABLE echeances_amortissement (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    immobilisation_id INTEGER NOT NULL
        REFERENCES immobilisations(id) ON DELETE RESTRICT,
    ordre INTEGER NOT NULL CHECK (ordre > 0),
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    date_comptable TEXT NOT NULL,
    jours INTEGER NOT NULL CHECK (jours > 0),
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes >= 0),
    statut TEXT NOT NULL DEFAULT 'planifiee'
        CHECK (statut IN ('planifiee', 'comptabilisee', 'contre_passee', 'annulee')),
    ecriture_id INTEGER UNIQUE REFERENCES ecritures(id) ON DELETE RESTRICT,
    ecriture_contrepassation_id INTEGER UNIQUE
        REFERENCES ecritures(id) ON DELETE RESTRICT,
    comptabilisee_le TEXT,
    contrepassee_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_debut <= date_fin),
    UNIQUE (immobilisation_id, ordre),
    UNIQUE (immobilisation_id, date_debut, date_fin)
);

CREATE TABLE ecritures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    journal_id INTEGER NOT NULL REFERENCES journaux(id) ON DELETE RESTRICT,
    numero TEXT NOT NULL DEFAULT '',
    date_comptable TEXT NOT NULL,
    libelle TEXT NOT NULL,
    reference TEXT NOT NULL DEFAULT '',
    piece TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'validee', 'contre_passee')),
    source_type TEXT NOT NULL DEFAULT 'manuel',
    source_id TEXT NOT NULL DEFAULT '',
    source_action TEXT NOT NULL DEFAULT '',
    cle_idempotence TEXT,
    empreinte_commande TEXT,
    contrepassation_de_id INTEGER UNIQUE REFERENCES ecritures(id) ON DELETE RESTRICT,
    validee_le TEXT,
    validee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE emails_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE RESTRICT,
    destinataire TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'en_attente'
        CHECK (statut IN ('en_attente', 'envoye', 'echec')),
    erreur TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    traite_le TEXT
);

CREATE TABLE employes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    prenom TEXT NOT NULL,
    nom TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    rue TEXT NOT NULL DEFAULT '',
    npa TEXT NOT NULL DEFAULT '',
    localite TEXT NOT NULL DEFAULT '',
    numero_avs TEXT NOT NULL,
    numero_avs_normalise TEXT NOT NULL,
    date_naissance TEXT NOT NULL DEFAULT '',
    canton TEXT NOT NULL DEFAULT 'GE' CHECK (canton = 'GE'),
    procedure TEXT NOT NULL DEFAULT 'ordinaire'
        CHECK (procedure IN ('ordinaire', 'simplifiee', 'ordinaire_impot_source')),
    supplement_vacances_ppm INTEGER NOT NULL DEFAULT 83300
        CHECK (supplement_vacances_ppm BETWEEN 0 AND 1000000),
    impot_source_ppm INTEGER NOT NULL DEFAULT 0
        CHECK (impot_source_ppm BETWEEN 0 AND 1000000),
    lpp_ppm INTEGER CHECK (lpp_ppm IS NULL OR lpp_ppm BETWEEN 0 AND 1000000),
    emp_lpp_ppm INTEGER CHECK (emp_lpp_ppm IS NULL OR emp_lpp_ppm BETWEEN 0 AND 1000000),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, numero_avs_normalise)
);

CREATE TABLE employeurs_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    nom TEXT NOT NULL,
    rue TEXT NOT NULL DEFAULT '',
    npa TEXT NOT NULL DEFAULT '',
    localite TEXT NOT NULL DEFAULT '',
    pays TEXT NOT NULL DEFAULT 'CH' CHECK (length(pays) = 2),
    telephone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    heures_hebdo_milli INTEGER NOT NULL DEFAULT 40000 CHECK (heures_hebdo_milli > 0),
    contact_nom TEXT NOT NULL DEFAULT '',
    contact_telephone TEXT NOT NULL DEFAULT '',
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id)
);

CREATE TABLE etapes_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version_modele_id INTEGER NOT NULL
        REFERENCES versions_modeles_exercice(id) ON DELETE RESTRICT,
    code TEXT NOT NULL,
    titre TEXT NOT NULL,
    consigne TEXT NOT NULL,
    points INTEGER NOT NULL DEFAULT 100 CHECK (points > 0),
    ordre INTEGER NOT NULL DEFAULT 0,
    UNIQUE (version_modele_id, code)
);

CREATE TABLE exercices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'ouvert' CHECK (statut IN ('ouvert', 'ferme')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_debut <= date_fin),
    UNIQUE (dossier_id, date_debut, date_fin)
);

CREATE TABLE fiches_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    employe_id INTEGER NOT NULL REFERENCES employes(id) ON DELETE RESTRICT,
    annee INTEGER NOT NULL CHECK (annee BETWEEN 2000 AND 9999),
    mois INTEGER NOT NULL CHECK (mois BETWEEN 1 AND 12),
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'validee', 'comptabilisee', 'payee', 'annulee')),
    employe_snapshot_json TEXT NOT NULL,
    employeur_snapshot_json TEXT NOT NULL,
    contrat_snapshot_json TEXT NOT NULL DEFAULT '{}',
    variables_snapshot_json TEXT NOT NULL DEFAULT '[]',
    taux_snapshot_json TEXT NOT NULL,
    taux_source_annee INTEGER NOT NULL DEFAULT 0,
    nombre_heures_milli INTEGER NOT NULL CHECK (nombre_heures_milli >= 0),
    salaire_travail_centimes INTEGER NOT NULL CHECK (salaire_travail_centimes >= 0),
    supplement_centimes INTEGER NOT NULL CHECK (supplement_centimes >= 0),
    brut_centimes INTEGER NOT NULL CHECK (brut_centimes >= 0),
    ded_avs_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_avs_centimes >= 0),
    ded_ac_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_ac_centimes >= 0),
    ded_amat_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_amat_centimes >= 0),
    ded_laa_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_laa_centimes >= 0),
    ded_lpp_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_lpp_centimes >= 0),
    ded_impot_source_centimes INTEGER NOT NULL DEFAULT 0 CHECK (ded_impot_source_centimes >= 0),
    total_deductions_centimes INTEGER NOT NULL DEFAULT 0 CHECK (total_deductions_centimes >= 0),
    net_centimes INTEGER NOT NULL CHECK (net_centimes >= 0),
    emp_avs_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_avs_centimes >= 0),
    emp_ac_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_ac_centimes >= 0),
    emp_amat_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_amat_centimes >= 0),
    emp_af_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_af_centimes >= 0),
    emp_laa_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_laa_centimes >= 0),
    emp_frais_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_frais_centimes >= 0),
    emp_cpe_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_cpe_centimes >= 0),
    emp_lfp_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_lfp_centimes >= 0),
    emp_lpp_centimes INTEGER NOT NULL DEFAULT 0 CHECK (emp_lpp_centimes >= 0),
    total_charges_employeur_centimes INTEGER NOT NULL DEFAULT 0
        CHECK (total_charges_employeur_centimes >= 0),
    cout_total_centimes INTEGER NOT NULL CHECK (cout_total_centimes >= 0),
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    ecriture_annulation_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    validee_le TEXT,
    comptabilisee_le TEXT,
    payee_le TEXT,
    annulee_le TEXT,
    email_envoye_le TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (brut_centimes = salaire_travail_centimes + supplement_centimes),
    CHECK (net_centimes = brut_centimes - total_deductions_centimes),
    CHECK (cout_total_centimes = brut_centimes + total_charges_employeur_centimes)
);

CREATE TABLE elements_periode_salaire (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK (
        type IN ('heures', 'absence', 'prime', 'indemnite', 'ajustement')
    ),
    libelle TEXT NOT NULL,
    quantite_milli INTEGER NOT NULL DEFAULT 0,
    montant_unitaire_centimes INTEGER NOT NULL DEFAULT 0,
    montant_centimes INTEGER NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    ordre INTEGER NOT NULL DEFAULT 0,
    UNIQUE (fiche_salaire_id, ordre)
);

CREATE TABLE groupes_pedagogiques (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    nom TEXT NOT NULL,
    dossier_partage_id INTEGER REFERENCES dossiers(id) ON DELETE RESTRICT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (organisation_id, nom)
);

CREATE TABLE imports_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    format TEXT NOT NULL CHECK (format IN ('postfinance_csv', 'camt053', 'camt054')),
    namespace_xml TEXT NOT NULL DEFAULT '',
    nom_fichier TEXT NOT NULL,
    empreinte_source TEXT NOT NULL,
    contenu_source BLOB NOT NULL,
    iban_detecte TEXT NOT NULL DEFAULT '',
    monnaie_detectee TEXT NOT NULL DEFAULT '',
    date_debut TEXT NOT NULL DEFAULT '',
    date_fin TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'previsualise'
        CHECK (statut IN ('previsualise', 'confirme', 'erreur')),
    erreurs_json TEXT NOT NULL DEFAULT '[]',
    nb_total INTEGER NOT NULL DEFAULT 0 CHECK (nb_total >= 0),
    nb_importees INTEGER NOT NULL DEFAULT 0 CHECK (nb_importees >= 0),
    nb_doublons INTEGER NOT NULL DEFAULT 0 CHECK (nb_doublons >= 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    confirme_le TEXT,
    confirme_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (compte_tresorerie_id, empreinte_source)
);

CREATE TABLE imports_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    empreinte_sha256 TEXT NOT NULL CHECK (length(empreinte_sha256) = 64),
    resume_json TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, empreinte_sha256)
);

CREATE TABLE immobilisations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    categorie_id INTEGER NOT NULL
        REFERENCES categories_immobilisations(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    reference_piece TEXT NOT NULL,
    document_acquisition_id INTEGER
        REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    piece_acquisition_id INTEGER REFERENCES pieces_jointes(id) ON DELETE RESTRICT,
    date_acquisition TEXT NOT NULL,
    date_mise_service TEXT NOT NULL,
    valeur_acquisition_centimes INTEGER NOT NULL
        CHECK (valeur_acquisition_centimes > 0),
    valeur_residuelle_centimes INTEGER NOT NULL DEFAULT 0
        CHECK (valeur_residuelle_centimes >= 0
               AND valeur_residuelle_centimes < valeur_acquisition_centimes),
    duree_mois INTEGER NOT NULL CHECK (duree_mois BETWEEN 1 AND 1200),
    methode TEXT NOT NULL DEFAULT 'lineaire_30_360'
        CHECK (methode = 'lineaire_30_360'),
    regle_prorata TEXT NOT NULL DEFAULT 'jours_30_360'
        CHECK (regle_prorata = 'jours_30_360'),
    compte_actif_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_amortissement_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_dotation_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_gain_cession_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_perte_cession_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'actif'
        CHECK (statut IN ('actif', 'cede', 'mis_au_rebut')),
    date_sortie TEXT,
    note TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_acquisition <= date_mise_service),
    UNIQUE (dossier_id, code)
);

CREATE TABLE indices_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    etape_id INTEGER NOT NULL REFERENCES etapes_exercice(id) ON DELETE RESTRICT,
    niveau INTEGER NOT NULL CHECK (niveau > 0),
    contenu TEXT NOT NULL,
    UNIQUE (etape_id, niveau)
);

CREATE TABLE journaux (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (
        type IN ('general', 'achats', 'ventes', 'banque', 'caisse', 'salaires', 'ouverture')
    ),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, code)
);

CREATE TABLE lignes_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    import_id INTEGER NOT NULL REFERENCES imports_bancaires(id) ON DELETE RESTRICT,
    empreinte TEXT NOT NULL,
    rang_occurrence INTEGER NOT NULL DEFAULT 0 CHECK (rang_occurrence >= 0),
    identifiant_bancaire TEXT NOT NULL DEFAULT '',
    groupe_id TEXT NOT NULL DEFAULT '',
    date_comptabilisation TEXT NOT NULL,
    date_valeur TEXT NOT NULL DEFAULT '',
    libelle TEXT NOT NULL DEFAULT '',
    tiers TEXT NOT NULL DEFAULT '',
    communication TEXT NOT NULL DEFAULT '',
    type_reference TEXT NOT NULL DEFAULT '',
    reference TEXT NOT NULL DEFAULT '',
    iban_contrepartie TEXT NOT NULL DEFAULT '',
    code_transaction TEXT NOT NULL DEFAULT '',
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes <> 0),
    frais_centimes INTEGER NOT NULL DEFAULT 0 CHECK (frais_centimes >= 0),
    monnaie TEXT NOT NULL CHECK (length(monnaie) = 3),
    solde_apres_centimes INTEGER,
    donnees_brutes_json TEXT NOT NULL DEFAULT '{}',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (compte_tresorerie_id, empreinte)
);

CREATE TABLE lignes_document (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE CASCADE,
    ordre INTEGER NOT NULL,
    libelle TEXT NOT NULL,
    quantite_milli INTEGER NOT NULL CHECK (quantite_milli > 0),
    prix_unitaire_centimes INTEGER NOT NULL CHECK (prix_unitaire_centimes >= 0),
    mode_saisie TEXT NOT NULL CHECK (mode_saisie IN ('net', 'brut')),
    compte_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    code_tva_id INTEGER REFERENCES tva_codes(id) ON DELETE RESTRICT,
    date_prestation TEXT NOT NULL,
    deduction_bp INTEGER CHECK (deduction_bp IS NULL OR deduction_bp BETWEEN 0 AND 10000),
    motif_correction TEXT NOT NULL DEFAULT '',
    tdfn_id INTEGER REFERENCES tva_tdfn(id) ON DELETE RESTRICT,
    base_nette_centimes INTEGER NOT NULL,
    tva_centimes INTEGER NOT NULL,
    total_brut_centimes INTEGER NOT NULL,
    taux_tva_snapshot_bp INTEGER NOT NULL CHECK (taux_tva_snapshot_bp BETWEEN 0 AND 10000),
    code_tva_snapshot TEXT NOT NULL,
    traitement_tva_snapshot TEXT NOT NULL,
    nature_tva_snapshot TEXT NOT NULL,
    chiffre_afc_snapshot TEXT NOT NULL DEFAULT '',
    deduction_snapshot_bp INTEGER NOT NULL CHECK (deduction_snapshot_bp BETWEEN 0 AND 10000),
    tva_deductible_centimes INTEGER NOT NULL DEFAULT 0,
    activite_tdfn_snapshot TEXT NOT NULL DEFAULT '',
    taux_tdfn_snapshot_bp INTEGER,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (total_brut_centimes = base_nette_centimes + tva_centimes),
    UNIQUE (document_id, ordre)
);

CREATE TABLE lignes_document_commercial (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES documents_commerciaux(id) ON DELETE CASCADE,
    ordre INTEGER NOT NULL CHECK (ordre > 0),
    libelle TEXT NOT NULL CHECK (length(trim(libelle)) > 0),
    quantite_milli INTEGER NOT NULL CHECK (quantite_milli > 0),
    prix_unitaire_centimes INTEGER NOT NULL CHECK (prix_unitaire_centimes >= 0),
    mode_saisie TEXT NOT NULL DEFAULT 'net' CHECK (mode_saisie IN ('net', 'brut')),
    compte_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    code_tva_id INTEGER REFERENCES tva_codes(id) ON DELETE RESTRICT,
    base_nette_centimes INTEGER NOT NULL DEFAULT 0,
    tva_centimes INTEGER NOT NULL DEFAULT 0,
    total_brut_centimes INTEGER NOT NULL DEFAULT 0,
    taux_tva_snapshot_bp INTEGER NOT NULL DEFAULT 0
        CHECK (taux_tva_snapshot_bp BETWEEN 0 AND 10000),
    code_tva_snapshot TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (total_brut_centimes = base_nette_centimes + tva_centimes),
    UNIQUE (document_id, ordre)
);

CREATE TABLE conversions_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    document_source_id INTEGER NOT NULL
        REFERENCES documents_commerciaux(id) ON DELETE RESTRICT,
    document_cible_commercial_id INTEGER
        REFERENCES documents_commerciaux(id) ON DELETE RESTRICT,
    document_cible_financier_id INTEGER
        REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    type_lien TEXT NOT NULL CHECK (
        type_lien IN ('reponse', 'remplacement', 'commande', 'facture')
    ),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (
        (document_cible_commercial_id IS NOT NULL)
        <> (document_cible_financier_id IS NOT NULL)
    )
);

CREATE TABLE conversions_lignes_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversion_id INTEGER NOT NULL
        REFERENCES conversions_documents(id) ON DELETE CASCADE,
    ligne_source_id INTEGER NOT NULL
        REFERENCES lignes_document_commercial(id) ON DELETE RESTRICT,
    ligne_cible_commercial_id INTEGER
        REFERENCES lignes_document_commercial(id) ON DELETE RESTRICT,
    ligne_cible_financiere_id INTEGER
        REFERENCES lignes_document(id) ON DELETE RESTRICT,
    quantite_milli INTEGER NOT NULL CHECK (quantite_milli > 0),
    CHECK (
        (ligne_cible_commercial_id IS NOT NULL)
        <> (ligne_cible_financiere_id IS NOT NULL)
    ),
    UNIQUE (conversion_id, ligne_source_id)
);

CREATE TABLE lignes_ecriture (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ecriture_id INTEGER NOT NULL REFERENCES ecritures(id) ON DELETE CASCADE,
    compte_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL DEFAULT '',
    debit_centimes INTEGER NOT NULL DEFAULT 0 CHECK (debit_centimes >= 0),
    credit_centimes INTEGER NOT NULL DEFAULT 0 CHECK (credit_centimes >= 0),
    devise_origine TEXT NOT NULL DEFAULT '',
    montant_origine_centimes INTEGER,
    devise_base TEXT NOT NULL DEFAULT '',
    taux_change_numerateur INTEGER,
    taux_change_denominateur INTEGER,
    taux_change_date TEXT NOT NULL DEFAULT '',
    taux_change_source TEXT NOT NULL DEFAULT '',
    montant_base_centimes INTEGER,
    ecart_arrondi_centimes INTEGER NOT NULL DEFAULT 0,
    ordre INTEGER NOT NULL DEFAULT 0,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (
        (debit_centimes > 0 AND credit_centimes = 0)
        OR (credit_centimes > 0 AND debit_centimes = 0)
    ),
    UNIQUE (ecriture_id, ordre)
);

CREATE TABLE lignes_prestation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fiche_salaire_id INTEGER NOT NULL REFERENCES fiches_salaires(id) ON DELETE CASCADE,
    ordre INTEGER NOT NULL,
    libelle TEXT NOT NULL,
    unite_libelle_snapshot TEXT NOT NULL,
    heures_unite_milli INTEGER NOT NULL CHECK (heures_unite_milli > 0),
    quantite_milli INTEGER NOT NULL CHECK (quantite_milli > 0),
    taux_horaire_centimes INTEGER NOT NULL CHECK (taux_horaire_centimes > 0),
    nombre_heures_milli INTEGER NOT NULL CHECK (nombre_heures_milli > 0),
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    UNIQUE (fiche_salaire_id, ordre)
);

CREATE TABLE mapping_comptes_salaires (
    dossier_id INTEGER PRIMARY KEY REFERENCES dossiers(id) ON DELETE RESTRICT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    charge_salaires_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    charge_ocas_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    charge_laa_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    charge_lpp_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_net_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_ocas_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_laa_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_lpp_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    dette_impot_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE membres_groupes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    groupe_id INTEGER NOT NULL REFERENCES groupes_pedagogiques(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    role_groupe TEXT NOT NULL DEFAULT 'membre'
        CHECK (role_groupe IN ('membre', 'coordinateur')),
    adhesion_le TEXT NOT NULL DEFAULT (datetime('now')),
    retrait_le TEXT,
    ajoute_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE modele_comptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    modele_id INTEGER NOT NULL REFERENCES modeles_plan_comptable(id) ON DELETE CASCADE,
    variante TEXT NOT NULL DEFAULT 'commun',
    numero TEXT NOT NULL,
    libelle TEXT NOT NULL,
    type TEXT NOT NULL CHECK (
        type IN ('actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan')
    ),
    sens_normal TEXT NOT NULL CHECK (sens_normal IN ('debit', 'credit')),
    parent_numero TEXT,
    niveau INTEGER NOT NULL CHECK (niveau BETWEEN 1 AND 9),
    imputable INTEGER NOT NULL DEFAULT 1 CHECK (imputable IN (0, 1)),
    marque TEXT NOT NULL DEFAULT '',
    parametre_requis TEXT NOT NULL DEFAULT '',
    ordre INTEGER NOT NULL DEFAULT 0,
    UNIQUE (modele_id, variante, numero)
);

CREATE TABLE modeles_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    titre TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    competence TEXT NOT NULL DEFAULT 'debit_credit' CHECK (
        competence IN (
            'debit_credit', 'tva', 'facturation', 'salaires',
            'rapprochement', 'cloture', 'lecture_etats'
        )
    ),
    niveau TEXT NOT NULL DEFAULT 'debutant'
        CHECK (niveau IN ('debutant', 'intermediaire', 'avance')),
    duree_minutes INTEGER NOT NULL DEFAULT 30 CHECK (duree_minutes BETWEEN 5 AND 480),
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'publie', 'archive')),
    version_courante INTEGER NOT NULL DEFAULT 0 CHECK (version_courante >= 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE modeles_plan_comptable (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL,
    version TEXT NOT NULL,
    libelle TEXT NOT NULL,
    source_url TEXT NOT NULL,
    attribution TEXT NOT NULL,
    est_overlay INTEGER NOT NULL DEFAULT 0 CHECK (est_overlay IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (code, version)
);

CREATE TABLE modules_application (
    code TEXT PRIMARY KEY CHECK (
        code IN ('apprentissage', 'liquidites', 'facturation', 'comptabilite', 'salaires')
    ),
    libelle TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    ordre INTEGER NOT NULL DEFAULT 0,
    actif_global INTEGER NOT NULL DEFAULT 1 CHECK (actif_global IN (0, 1))
);

CREATE TABLE modules_dossier (
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE CASCADE,
    module_code TEXT NOT NULL REFERENCES modules_application(code) ON DELETE RESTRICT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (dossier_id, module_code)
);

CREATE TABLE organisations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nom TEXT NOT NULL,
    nature TEXT NOT NULL CHECK (nature IN ('reelle', 'pedagogique')),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1
, raison_sociale TEXT NOT NULL DEFAULT '', forme_juridique TEXT NOT NULL DEFAULT '', numero_ide TEXT NOT NULL DEFAULT '', adresse_ligne1 TEXT NOT NULL DEFAULT '', adresse_ligne2 TEXT NOT NULL DEFAULT '', code_postal TEXT NOT NULL DEFAULT '', localite TEXT NOT NULL DEFAULT '', canton TEXT NOT NULL DEFAULT '', pays TEXT NOT NULL DEFAULT 'CH', telephone TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', site_web TEXT NOT NULL DEFAULT '', modifie_le TEXT);

CREATE TABLE paiements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
    sens TEXT NOT NULL CHECK (sens IN ('encaissement', 'decaissement')),
    date_paiement TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (length(monnaie) = 3),
    devise_base TEXT NOT NULL DEFAULT 'CHF' CHECK (length(devise_base) = 3),
    taux_change_numerateur INTEGER NOT NULL DEFAULT 1
        CHECK (taux_change_numerateur > 0),
    taux_change_denominateur INTEGER NOT NULL DEFAULT 1
        CHECK (taux_change_denominateur > 0),
    taux_change_date TEXT NOT NULL DEFAULT '',
    taux_change_source TEXT NOT NULL DEFAULT 'devise_base',
    montant_base_centimes INTEGER NOT NULL DEFAULT 0,
    reference TEXT NOT NULL DEFAULT '',
    compte_tresorerie_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    ligne_bancaire_id INTEGER REFERENCES lignes_bancaires(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'valide' CHECK (statut IN ('valide', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    annule_le TEXT,
    annule_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL
);

CREATE TABLE lots_paiements_sortants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    message_id TEXT NOT NULL,
    date_execution TEXT NOT NULL,
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (monnaie IN ('CHF', 'EUR')),
    nombre_ordres INTEGER NOT NULL CHECK (nombre_ordres > 0),
    total_centimes INTEGER NOT NULL CHECK (total_centimes > 0),
    statut TEXT NOT NULL DEFAULT 'prepare'
        CHECK (statut IN ('prepare', 'exporte', 'confirme')),
    version_pain TEXT NOT NULL DEFAULT 'pain.001.001.09.ch.03',
    contenu_pain001 BLOB,
    empreinte_sha256 TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    exporte_le TEXT,
    exporte_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    confirme_le TEXT,
    confirme_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    ligne_bancaire_id INTEGER REFERENCES lignes_bancaires(id) ON DELETE RESTRICT,
    rapprochement_id INTEGER REFERENCES rapprochements_bancaires(id) ON DELETE RESTRICT,
    frais_centimes INTEGER NOT NULL DEFAULT 0 CHECK (frais_centimes >= 0),
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, message_id),
    CHECK (
        (statut = 'prepare' AND contenu_pain001 IS NULL AND empreinte_sha256 = '')
        OR (statut IN ('exporte', 'confirme')
            AND contenu_pain001 IS NOT NULL AND length(empreinte_sha256) = 64)
    )
);

CREATE TABLE ordres_paiement_sortants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lot_id INTEGER NOT NULL REFERENCES lots_paiements_sortants(id) ON DELETE RESTRICT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE RESTRICT,
    beneficiaire_snapshot TEXT NOT NULL,
    adresse_snapshot_json TEXT NOT NULL CHECK (json_valid(adresse_snapshot_json)),
    iban_snapshot TEXT NOT NULL,
    bic_snapshot TEXT NOT NULL DEFAULT '',
    reference TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    monnaie TEXT NOT NULL DEFAULT 'CHF' CHECK (monnaie IN ('CHF', 'EUR')),
    statut TEXT NOT NULL DEFAULT 'prepare'
        CHECK (statut IN ('prepare', 'exporte', 'confirme')),
    paiement_id INTEGER REFERENCES paiements(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (lot_id, document_id)
);

CREATE TABLE paiements_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    beneficiaire_type TEXT NOT NULL CHECK (beneficiaire_type IN ('employe', 'organisme')),
    employe_id INTEGER REFERENCES employes(id) ON DELETE RESTRICT,
    date_paiement TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL CHECK (montant_centimes > 0),
    reference TEXT NOT NULL DEFAULT '',
    compte_tresorerie_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'valide' CHECK (statut IN ('valide', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (
        (beneficiaire_type = 'employe' AND employe_id IS NOT NULL)
        OR (beneficiaire_type = 'organisme' AND employe_id IS NULL)
    )
);

CREATE TABLE parametres_dossier (
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE CASCADE,
    cle TEXT NOT NULL,
    valeur TEXT NOT NULL,
    PRIMARY KEY (dossier_id, cle)
);

CREATE TABLE parametres_organisation (
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    cle TEXT NOT NULL,
    valeur TEXT NOT NULL,
    PRIMARY KEY (organisation_id, cle)
);

CREATE TABLE periodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'ouverte' CHECK (statut IN ('ouverte', 'fermee')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_debut <= date_fin),
    UNIQUE (exercice_id, date_debut, date_fin)
);

CREATE TABLE permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    libelle TEXT NOT NULL
);

CREATE TABLE pieces_jointes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    nom_fichier TEXT NOT NULL,
    type_mime TEXT NOT NULL,
    taille_octets INTEGER NOT NULL CHECK (taille_octets >= 0),
    empreinte_sha256 TEXT NOT NULL CHECK (length(empreinte_sha256) = 64),
    contenu BLOB NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, empreinte_sha256)
);

CREATE TABLE progressions_etapes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    assignation_id INTEGER NOT NULL
        REFERENCES assignations_exercice(id) ON DELETE RESTRICT,
    etape_id INTEGER NOT NULL REFERENCES etapes_exercice(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'a_faire'
        CHECK (statut IN ('a_faire', 'vue', 'validee')),
    vue_le TEXT,
    validee_le TEXT,
    validee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (assignation_id, etape_id)
);

CREATE TABLE rappels_factures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    document_id INTEGER NOT NULL REFERENCES documents_financiers(id) ON DELETE RESTRICT,
    niveau INTEGER NOT NULL DEFAULT 1 CHECK (niveau BETWEEN 1 AND 9),
    canal TEXT NOT NULL CHECK (canal IN ('courrier', 'email', 'telephone', 'autre')),
    note TEXT NOT NULL DEFAULT '',
    rappele_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL
);

CREATE TABLE rapprochement_lignes_bancaires (
    rapprochement_id INTEGER NOT NULL
        REFERENCES rapprochements_bancaires(id) ON DELETE RESTRICT,
    ligne_bancaire_id INTEGER NOT NULL
        REFERENCES lignes_bancaires(id) ON DELETE RESTRICT,
    montant_centimes INTEGER NOT NULL,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    PRIMARY KEY (rapprochement_id, ligne_bancaire_id)
);

CREATE TABLE rapprochement_lignes_comptables (
    rapprochement_id INTEGER NOT NULL
        REFERENCES rapprochements_bancaires(id) ON DELETE RESTRICT,
    ligne_ecriture_id INTEGER NOT NULL
        REFERENCES lignes_ecriture(id) ON DELETE RESTRICT,
    montant_centimes INTEGER NOT NULL,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    PRIMARY KEY (rapprochement_id, ligne_ecriture_id)
);

CREATE TABLE rapprochements_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL DEFAULT '',
    total_banque_centimes INTEGER NOT NULL,
    total_comptable_centimes INTEGER NOT NULL,
    difference_centimes INTEGER NOT NULL,
    tolerance_centimes INTEGER NOT NULL DEFAULT 0 CHECK (tolerance_centimes >= 0),
    statut TEXT NOT NULL DEFAULT 'confirme' CHECK (statut IN ('confirme', 'annule')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    annule_le TEXT,
    annule_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (difference_centimes = total_banque_centimes - total_comptable_centimes),
    CHECK (
        difference_centimes BETWEEN -tolerance_centimes AND tolerance_centimes
    )
);

CREATE TABLE regles_sens_comptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    prefixe TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, prefixe),
    CHECK (length(prefixe) BETWEEN 1 AND 20),
    CHECK (prefixe NOT GLOB '*[^0-9]*')
);

CREATE TABLE regles_validation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    etape_id INTEGER NOT NULL REFERENCES etapes_exercice(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (
        type IN ('comptes', 'sens', 'montants', 'ecriture_equivalente',
                 'soldes', 'rapport')
    ),
    configuration_json TEXT NOT NULL,
    message_succes TEXT NOT NULL DEFAULT 'Étape validée.',
    message_echec TEXT NOT NULL DEFAULT 'La réponse comptable ne satisfait pas la règle.',
    ordre INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE role_permissions (
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

CREATE TABLE roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    libelle TEXT NOT NULL
);

CREATE TABLE "rubriques_comptables" (
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
    parent_id INTEGER REFERENCES "rubriques_comptables"(id) ON DELETE RESTRICT,
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

CREATE TABLE sequences_documents (
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    annee INTEGER NOT NULL CHECK (annee BETWEEN 2000 AND 9999),
    prefixe TEXT NOT NULL,
    dernier_numero INTEGER NOT NULL DEFAULT 0 CHECK (dernier_numero >= 0),
    PRIMARY KEY (dossier_id, annee, prefixe)
);

CREATE TABLE sequences_journaux (
    exercice_id INTEGER NOT NULL REFERENCES exercices(id) ON DELETE RESTRICT,
    journal_id INTEGER NOT NULL REFERENCES journaux(id) ON DELETE RESTRICT,
    dernier_numero INTEGER NOT NULL DEFAULT 0 CHECK (dernier_numero >= 0),
    PRIMARY KEY (exercice_id, journal_id)
);

CREATE TABLE soldes_bancaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    compte_tresorerie_id INTEGER NOT NULL
        REFERENCES comptes_tresorerie(id) ON DELETE RESTRICT,
    import_id INTEGER NOT NULL REFERENCES imports_bancaires(id) ON DELETE RESTRICT,
    type TEXT NOT NULL,
    date_solde TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL,
    monnaie TEXT NOT NULL CHECK (length(monnaie) = 3),
    empreinte TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (compte_tresorerie_id, empreinte)
);

CREATE TABLE sorties_immobilisations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    immobilisation_id INTEGER NOT NULL
        REFERENCES immobilisations(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (type IN ('cession', 'mise_au_rebut')),
    date_sortie TEXT NOT NULL,
    produit_cession_centimes INTEGER NOT NULL DEFAULT 0
        CHECK (produit_cession_centimes >= 0),
    compte_produit_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    valeur_brute_centimes INTEGER NOT NULL CHECK (valeur_brute_centimes > 0),
    amortissement_cumule_centimes INTEGER NOT NULL
        CHECK (amortissement_cumule_centimes >= 0),
    valeur_nette_centimes INTEGER NOT NULL CHECK (valeur_nette_centimes >= 0),
    resultat_cession_centimes INTEGER NOT NULL,
    ecriture_id INTEGER NOT NULL UNIQUE REFERENCES ecritures(id) ON DELETE RESTRICT,
    ecriture_contrepassation_id INTEGER UNIQUE
        REFERENCES ecritures(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL DEFAULT 'comptabilisee'
        CHECK (statut IN ('comptabilisee', 'contre_passee')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    contrepassee_le TEXT
);

CREATE TABLE suggestions_comptabilisation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    ligne_bancaire_id INTEGER NOT NULL
        REFERENCES lignes_bancaires(id) ON DELETE RESTRICT,
    compte_contrepartie_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    confiance INTEGER NOT NULL DEFAULT 0 CHECK (confiance BETWEEN 0 AND 100),
    raison TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'proposee'
        CHECK (statut IN ('proposee', 'acceptee', 'rejetee')),
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    decidee_le TEXT,
    decidee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (ligne_bancaire_id, compte_contrepartie_id)
);

CREATE TABLE tarifs_salaires (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    montant_horaire_centimes INTEGER NOT NULL CHECK (montant_horaire_centimes > 0),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    ordre INTEGER NOT NULL DEFAULT 0,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE taux_salaires_annuels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    annee INTEGER NOT NULL CHECK (annee BETWEEN 2000 AND 9999),
    avs_ppm INTEGER NOT NULL CHECK (avs_ppm BETWEEN 0 AND 1000000),
    ac_ppm INTEGER NOT NULL CHECK (ac_ppm BETWEEN 0 AND 1000000),
    amat_ppm INTEGER NOT NULL CHECK (amat_ppm BETWEEN 0 AND 1000000),
    laa_reduit_ppm INTEGER NOT NULL CHECK (laa_reduit_ppm BETWEEN 0 AND 1000000),
    laa_plein_ppm INTEGER NOT NULL CHECK (laa_plein_ppm BETWEEN 0 AND 1000000),
    lpp_ppm INTEGER NOT NULL CHECK (lpp_ppm BETWEEN 0 AND 1000000),
    emp_avs_ppm INTEGER NOT NULL CHECK (emp_avs_ppm BETWEEN 0 AND 1000000),
    emp_ac_ppm INTEGER NOT NULL CHECK (emp_ac_ppm BETWEEN 0 AND 1000000),
    emp_amat_ppm INTEGER NOT NULL CHECK (emp_amat_ppm BETWEEN 0 AND 1000000),
    emp_af_ppm INTEGER NOT NULL CHECK (emp_af_ppm BETWEEN 0 AND 1000000),
    emp_laa_reduit_ppm INTEGER NOT NULL CHECK (emp_laa_reduit_ppm BETWEEN 0 AND 1000000),
    emp_laa_plein_ppm INTEGER NOT NULL CHECK (emp_laa_plein_ppm BETWEEN 0 AND 1000000),
    emp_frais_ppm INTEGER NOT NULL DEFAULT 0 CHECK (emp_frais_ppm BETWEEN 0 AND 1000000),
    emp_cpe_ppm INTEGER NOT NULL DEFAULT 0 CHECK (emp_cpe_ppm BETWEEN 0 AND 1000000),
    emp_lfp_ppm INTEGER NOT NULL DEFAULT 0 CHECK (emp_lfp_ppm BETWEEN 0 AND 1000000),
    emp_lpp_ppm INTEGER NOT NULL CHECK (emp_lpp_ppm BETWEEN 0 AND 1000000),
    source TEXT NOT NULL DEFAULT '',
    source_annee INTEGER NOT NULL DEFAULT 0,
    source_empreinte TEXT NOT NULL DEFAULT '',
    importe_le TEXT,
    verifie_le TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (dossier_id, annee)
);

CREATE TABLE tentatives_connexion (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL COLLATE NOCASE,
    ip TEXT NOT NULL,
    tente_le INTEGER NOT NULL
);

CREATE TABLE tentatives_pedagogiques (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    assignation_id INTEGER NOT NULL
        REFERENCES assignations_exercice(id) ON DELETE RESTRICT,
    etape_id INTEGER NOT NULL REFERENCES etapes_exercice(id) ON DELETE RESTRICT,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE RESTRICT,
    ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE SET NULL,
    reussie INTEGER NOT NULL CHECK (reussie IN (0, 1)),
    resultat_json TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE tva_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    traitement TEXT NOT NULL CHECK (
        traitement IN (
            'normal', 'reduit', 'special', 'exonere', 'exclu',
            'hors_champ', 'acquisition', 'import', 'correction'
        )
    ),
    nature TEXT NOT NULL CHECK (
        nature IN ('collectee', 'prealable', 'acquisition', 'non_taxable', 'correction')
    ),
    taux_legal_id INTEGER REFERENCES tva_taux_legaux(id) ON DELETE RESTRICT,
    droit_deduction INTEGER NOT NULL DEFAULT 0 CHECK (droit_deduction IN (0, 1)),
    deduction_defaut_bp INTEGER NOT NULL DEFAULT 0 CHECK (
        deduction_defaut_bp BETWEEN 0 AND 10000
    ),
    chiffre_afc TEXT NOT NULL DEFAULT '',
    compte_tva_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    CHECK (
        (traitement IN ('normal', 'reduit', 'special', 'acquisition', 'import')
            AND taux_legal_id IS NOT NULL)
        OR (traitement NOT IN ('normal', 'reduit', 'special', 'acquisition', 'import')
            AND taux_legal_id IS NULL)
    ),
    UNIQUE (dossier_id, code, date_debut)
);

CREATE TABLE tva_decompte_cases (
    decompte_tva_id INTEGER NOT NULL REFERENCES tva_decomptes(id) ON DELETE RESTRICT,
    chiffre_afc TEXT NOT NULL,
    libelle TEXT NOT NULL,
    montant_centimes INTEGER NOT NULL,
    PRIMARY KEY (decompte_tva_id, chiffre_afc)
);

CREATE TABLE tva_decompte_sources (
    decompte_tva_id INTEGER NOT NULL REFERENCES tva_decomptes(id) ON DELETE RESTRICT,
    tva_ligne_id INTEGER NOT NULL REFERENCES tva_lignes(id) ON DELETE RESTRICT,
    encaissement_id INTEGER REFERENCES tva_encaissements(id) ON DELETE RESTRICT,
    proportion_bp INTEGER NOT NULL DEFAULT 10000 CHECK (proportion_bp BETWEEN -10000 AND 10000),
    base_centimes INTEGER NOT NULL,
    tva_centimes INTEGER NOT NULL,
    tva_deductible_centimes INTEGER NOT NULL,
    correction_centimes INTEGER NOT NULL DEFAULT 0,
    brut_centimes INTEGER NOT NULL,
    code_snapshot TEXT NOT NULL,
    chiffre_afc_snapshot TEXT NOT NULL,
    UNIQUE (decompte_tva_id, tva_ligne_id, encaissement_id)
);

CREATE TABLE tva_decomptes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    periode_tva_id INTEGER NOT NULL REFERENCES tva_periodes(id) ON DELETE RESTRICT,
    rectifie_de_id INTEGER REFERENCES tva_decomptes(id) ON DELETE RESTRICT,
    numero_correction INTEGER NOT NULL DEFAULT 0 CHECK (numero_correction >= 0),
    type_soumission INTEGER NOT NULL DEFAULT 1 CHECK (type_soumission BETWEEN 1 AND 3),
    statut TEXT NOT NULL DEFAULT 'prepare'
        CHECK (statut IN ('prepare', 'controle', 'exporte', 'declare', 'paye', 'rembourse', 'corrige')),
    methode_snapshot TEXT NOT NULL CHECK (methode_snapshot IN ('effective', 'tdfn')),
    mode_decompte_snapshot TEXT NOT NULL CHECK (mode_decompte_snapshot IN ('convenues', 'recues')),
    numero_tva_snapshot TEXT NOT NULL,
    nom_organisation_snapshot TEXT NOT NULL,
    date_arret TEXT NOT NULL,
    parametres_json TEXT NOT NULL,
    agregats_json TEXT NOT NULL,
    total_chiffre_affaires_centimes INTEGER NOT NULL,
    tva_due_centimes INTEGER NOT NULL,
    impot_prealable_centimes INTEGER NOT NULL,
    corrections_centimes INTEGER NOT NULL,
    solde_centimes INTEGER NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    controle_le TEXT,
    controle_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    declare_le TEXT,
    declare_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (
        solde_centimes = tva_due_centimes - impot_prealable_centimes + corrections_centimes
    ),
    UNIQUE (periode_tva_id, numero_correction)
);

CREATE TABLE tva_encaissements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    tva_ligne_id INTEGER NOT NULL REFERENCES tva_lignes(id) ON DELETE RESTRICT,
    date_paiement TEXT NOT NULL,
    montant_brut_centimes INTEGER NOT NULL CHECK (montant_brut_centimes <> 0),
    source_type TEXT NOT NULL,
    source_id TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (dossier_id, source_type, source_id, tva_ligne_id)
);

CREATE TABLE tva_exports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    decompte_tva_id INTEGER NOT NULL REFERENCES tva_decomptes(id) ON DELETE RESTRICT,
    format TEXT NOT NULL DEFAULT 'ech-0217',
    version_schema TEXT NOT NULL DEFAULT '2.0.0',
    contenu_xml BLOB NOT NULL,
    empreinte_sha256 TEXT NOT NULL,
    schema_valide INTEGER NOT NULL CHECK (schema_valide IN (0, 1)),
    erreurs_json TEXT NOT NULL DEFAULT '[]',
    transmis INTEGER NOT NULL DEFAULT 0 CHECK (transmis = 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (decompte_tva_id, empreinte_sha256)
);

CREATE TABLE tva_lignes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    ligne_ecriture_id INTEGER NOT NULL UNIQUE
        REFERENCES lignes_ecriture(id) ON DELETE RESTRICT,
    code_tva_id INTEGER NOT NULL REFERENCES tva_codes(id) ON DELETE RESTRICT,
    date_prestation TEXT NOT NULL,
    mode_saisie TEXT NOT NULL CHECK (mode_saisie IN ('net', 'brut')),
    base_nette_centimes INTEGER NOT NULL,
    tva_centimes INTEGER NOT NULL,
    total_brut_centimes INTEGER NOT NULL,
    taux_legal_snapshot_bp INTEGER NOT NULL CHECK (
        taux_legal_snapshot_bp BETWEEN 0 AND 10000
    ),
    code_snapshot TEXT NOT NULL,
    traitement_snapshot TEXT NOT NULL,
    nature_snapshot TEXT NOT NULL,
    chiffre_afc_snapshot TEXT NOT NULL DEFAULT '',
    deduction_bp INTEGER NOT NULL DEFAULT 0 CHECK (deduction_bp BETWEEN 0 AND 10000),
    tva_deductible_centimes INTEGER NOT NULL DEFAULT 0,
    correction_centimes INTEGER NOT NULL DEFAULT 0,
    motif_correction TEXT NOT NULL DEFAULT '',
    tdfn_id INTEGER REFERENCES tva_tdfn(id) ON DELETE RESTRICT,
    activite_id_snapshot TEXT NOT NULL DEFAULT '',
    taux_tdfn_snapshot_bp INTEGER CHECK (
        taux_tdfn_snapshot_bp IS NULL
        OR taux_tdfn_snapshot_bp BETWEEN 0 AND 10000
    ),
    document_type TEXT NOT NULL DEFAULT '',
    document_id TEXT NOT NULL DEFAULT '',
    document_ligne_id TEXT NOT NULL DEFAULT '',
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (total_brut_centimes = base_nette_centimes + tva_centimes),
    CHECK (
        (correction_centimes = 0 AND motif_correction = '')
        OR (correction_centimes <> 0 AND motif_correction <> '')
    )
);

CREATE TABLE tva_periodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    regime_tva_id INTEGER NOT NULL REFERENCES tva_regimes(id) ON DELETE RESTRICT,
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'ouverte'
        CHECK (statut IN ('ouverte', 'preparee', 'controlee', 'exportee', 'declaree', 'payee', 'remboursee', 'corrigee')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_fin >= date_debut),
    UNIQUE (dossier_id, date_debut, date_fin)
);

CREATE TABLE tva_regimes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    statut TEXT NOT NULL CHECK (statut IN ('non_assujetti', 'assujetti', 'volontaire')),
    numero_tva TEXT NOT NULL DEFAULT '',
    methode TEXT NOT NULL CHECK (methode IN ('effective', 'tdfn')),
    mode_decompte TEXT NOT NULL CHECK (mode_decompte IN ('convenues', 'recues')),
    periodicite TEXT NOT NULL CHECK (
        periodicite IN ('mensuelle', 'trimestrielle', 'semestrielle', 'annuelle')
    ),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    compte_impot_prealable_materiel_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_impot_prealable_investissements_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_tva_due_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_decompte_tva_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_corrections_id INTEGER REFERENCES comptes(id) ON DELETE RESTRICT,
    source_reglementaire TEXT NOT NULL,
    verifie_le TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    CHECK (
        (statut = 'non_assujetti' AND numero_tva = '')
        OR (statut <> 'non_assujetti' AND numero_tva <> '')
    )
);

CREATE TABLE tva_taux_legaux (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    categorie TEXT NOT NULL CHECK (categorie IN ('normal', 'reduit', 'special', 'zero')),
    libelle TEXT NOT NULL,
    taux_bp INTEGER NOT NULL CHECK (taux_bp BETWEEN 0 AND 10000),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    source_url TEXT NOT NULL,
    verifie_le TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (categorie, date_debut)
);

CREATE TABLE tva_tdfn (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    activite_id TEXT NOT NULL CHECK (length(activite_id) = 5),
    activite TEXT NOT NULL,
    taux_bp INTEGER NOT NULL CHECK (taux_bp BETWEEN 0 AND 10000),
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    seuil_chiffre_affaires_centimes INTEGER,
    autorisation_reference TEXT NOT NULL,
    source_url TEXT NOT NULL,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (dossier_id, activite_id, date_debut)
);

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

CREATE TABLE unites_prestation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    heures_milli INTEGER NOT NULL CHECK (heures_milli > 0),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    ordre INTEGER NOT NULL DEFAULT 0,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE utilisateur_roles_dossier (
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (utilisateur_id, dossier_id, role_id)
);

CREATE TABLE utilisateur_roles_installation (
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (utilisateur_id, role_id)
);

CREATE TABLE utilisateur_roles_organisation (
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    PRIMARY KEY (utilisateur_id, organisation_id, role_id)
);

CREATE TABLE utilisateurs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    mot_de_passe TEXT NOT NULL,
    prenom TEXT NOT NULL DEFAULT '',
    nom TEXT NOT NULL DEFAULT '',
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    mode_connexion TEXT NOT NULL DEFAULT 'password'
        CHECK (mode_connexion IN ('password', 'email', 'totp')),
    secret_totp_protege TEXT,
    codes_recuperation_json TEXT NOT NULL DEFAULT '[]'
        CHECK (json_valid(codes_recuperation_json)),
    mfa_active_le TEXT,
    version_securite INTEGER NOT NULL DEFAULT 1 CHECK (version_securite > 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    derniere_connexion_le TEXT,
    CHECK (mode_connexion <> 'totp' OR secret_totp_protege IS NOT NULL)
);

CREATE TABLE defis_mfa_email (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    selecteur TEXT NOT NULL UNIQUE,
    utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
    but TEXT NOT NULL CHECK (but IN ('connexion', 'activation')),
    code_hash TEXT NOT NULL,
    ip TEXT NOT NULL DEFAULT '',
    agent_utilisateur TEXT NOT NULL DEFAULT '',
    tentatives INTEGER NOT NULL DEFAULT 0 CHECK (tentatives BETWEEN 0 AND 5),
    expire_le INTEGER NOT NULL,
    consomme_le INTEGER,
    cree_le INTEGER NOT NULL,
    CHECK (expire_le > cree_le),
    CHECK (consomme_le IS NULL OR consomme_le >= cree_le)
);

CREATE TABLE versions_modeles_exercice (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    modele_id INTEGER NOT NULL REFERENCES modeles_exercice(id) ON DELETE RESTRICT,
    numero_version INTEGER NOT NULL CHECK (numero_version > 0),
    plan_snapshot_json TEXT NOT NULL,
    soldes_initiaux_json TEXT NOT NULL DEFAULT '[]',
    donnees_initiales_json TEXT NOT NULL DEFAULT '[]',
    consignes TEXT NOT NULL,
    solution_json TEXT NOT NULL DEFAULT '{}',
    regle_correction TEXT NOT NULL DEFAULT 'manuelle'
        CHECK (regle_correction IN ('manuelle', 'apres_tentatives', 'date')),
    valeur_correction TEXT NOT NULL DEFAULT '',
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'publie', 'archive')),
    publie_le TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (modele_id, numero_version)
);

-- INDEXS

CREATE INDEX idx_adresses_contact ON adresses_contacts(contact_id, actif, type);

CREATE INDEX idx_allocations_avoir ON allocations(avoir_id, statut);

CREATE INDEX idx_allocations_document ON allocations(document_id, statut);

CREATE INDEX idx_allocations_paiement ON allocations(paiement_id, statut);

CREATE INDEX idx_allocations_salaires_dette
    ON allocations_salaires(dette_salaire_id, statut);

CREATE INDEX idx_allocations_salaires_paiement
    ON allocations_salaires(paiement_salaire_id, statut);

CREATE INDEX idx_assignations_cible
    ON assignations_exercice(organisation_id, utilisateur_id, groupe_id, statut);

CREATE INDEX idx_audit_scope ON audit_events(organisation_id, dossier_id, cree_le);

CREATE INDEX idx_comptes_ordre
    ON comptes(dossier_id, imputable, actif, ordre, numero);

CREATE INDEX idx_comptes_rubrique ON comptes(rubrique_id, actif, numero);

CREATE INDEX idx_comptes_scope_type ON comptes(dossier_id, type, actif, numero);

CREATE INDEX idx_comptes_tresorerie_scope
    ON comptes_tresorerie(organisation_id, dossier_id, actif, type);

CREATE INDEX idx_contrats_salariaux_periode
    ON contrats_salariaux(employe_id, date_debut, date_fin, actif);

CREATE INDEX idx_categories_immobilisations_scope
    ON categories_immobilisations(dossier_id, actif, code);

CREATE INDEX idx_conditions_paiement_scope_date
    ON conditions_paiement(dossier_id, direction, actif, date_debut, date_fin);

CREATE INDEX idx_contacts_scope_nom
    ON contacts(dossier_id, actif, raison_sociale, nom, prenom);

CREATE INDEX idx_contacts_entreprise
    ON contacts(dossier_id, entreprise_id, actif, nom, prenom);

CREATE UNIQUE INDEX idx_contacts_idempotence_unique
    ON contacts(dossier_id, cle_idempotence)
    WHERE cle_idempotence <> '';

CREATE INDEX idx_contributions_auteur
    ON contributions_pedagogiques(assignation_id, utilisateur_id, cree_le);

CREATE INDEX idx_defauts_conditions_scope_date
    ON defauts_conditions_paiement(dossier_id, direction, date_debut, date_fin);

CREATE INDEX idx_dettes_salaires_scope ON dettes_salaires(dossier_id, type, fiche_salaire_id);

CREATE INDEX idx_documents_condition_paiement
    ON documents_financiers(dossier_id, condition_paiement_id);

CREATE INDEX idx_documents_scope_etat
    ON documents_financiers(dossier_id, type, statut, date_echeance, contact_id);

CREATE INDEX idx_documents_commerciaux_scope
    ON documents_commerciaux(dossier_id, type, statut, date_document, contact_id);
CREATE UNIQUE INDEX idx_documents_commerciaux_numero
    ON documents_commerciaux(dossier_id, numero)
    WHERE numero <> '';

CREATE INDEX idx_conversions_documents_source
    ON conversions_documents(document_source_id, type_lien);

CREATE UNIQUE INDEX idx_documents_generation_unique
    ON documents_financiers(dossier_id, cle_generation)
    WHERE cle_generation <> '';

CREATE INDEX idx_depenses_scope_etat
    ON documents_financiers(dossier_id, workflow, statut, date_echeance);

CREATE INDEX idx_modeles_depenses_echeance
    ON modeles_depenses_recurrentes(dossier_id, statut, prochaine_echeance);

CREATE INDEX idx_modeles_factures_echeance
    ON modeles_factures_recurrentes(dossier_id, statut, prochaine_echeance);

CREATE INDEX idx_dossiers_organisation ON dossiers(organisation_id);

CREATE INDEX idx_archives_rapports_scope
    ON archives_rapports_financiers(dossier_id, exercice_id, type, cree_le);

CREATE INDEX idx_ajustements_fiscaux_scope
    ON ajustements_fiscaux(dossier_id, exercice_id, statut);

CREATE UNIQUE INDEX idx_ajustements_fiscaux_idempotence
    ON ajustements_fiscaux(dossier_id, cle_idempotence)
    WHERE cle_idempotence <> '';

CREATE INDEX idx_ecritures_journal
    ON ecritures(dossier_id, exercice_id, date_comptable, journal_id, statut);

CREATE INDEX idx_echeances_amortissement_date
    ON echeances_amortissement(immobilisation_id, date_comptable, statut);

CREATE INDEX idx_employes_scope_nom ON employes(dossier_id, actif, nom, prenom);

CREATE INDEX idx_elements_periode_fiche
    ON elements_periode_salaire(fiche_salaire_id, ordre);

CREATE INDEX idx_fiches_salaires_scope
    ON fiches_salaires(dossier_id, annee, mois, statut, employe_id);

CREATE INDEX idx_imports_bancaires_scope
    ON imports_bancaires(dossier_id, statut, cree_le);

CREATE INDEX idx_immobilisations_scope
    ON immobilisations(dossier_id, statut, date_mise_service, code);

CREATE INDEX idx_lignes_bancaires_compte_date
    ON lignes_bancaires(compte_tresorerie_id, date_comptabilisation, id);

CREATE INDEX idx_lignes_bancaires_reference
    ON lignes_bancaires(dossier_id, type_reference, reference);

CREATE INDEX idx_lignes_compte ON lignes_ecriture(compte_id, ecriture_id);

CREATE INDEX idx_lignes_document ON lignes_document(document_id, ordre);

CREATE INDEX idx_lignes_document_commercial
    ON lignes_document_commercial(document_id, ordre);

CREATE INDEX idx_modele_comptes_ordre
    ON modele_comptes(modele_id, variante, ordre, numero);

CREATE INDEX idx_modeles_exercice_scope
    ON modeles_exercice(organisation_id, statut, titre);

CREATE INDEX idx_modules_dossier_scope
    ON modules_dossier(organisation_id, dossier_id, actif, module_code);

CREATE INDEX idx_paiements_scope ON paiements(dossier_id, contact_id, date_paiement);

CREATE INDEX idx_periodes_scope ON periodes(dossier_id, exercice_id, date_debut, date_fin);

CREATE INDEX idx_rappels_document ON rappels_factures(document_id, rappele_le);

CREATE INDEX idx_rapprochements_scope
    ON rapprochements_bancaires(dossier_id, compte_tresorerie_id, cree_le);

CREATE INDEX idx_regles_sens_scope
    ON regles_sens_comptes(dossier_id, prefixe);

CREATE INDEX idx_rubriques_parent
    ON rubriques_comptables(parent_id, ordre, id);

CREATE INDEX idx_rubriques_scope
    ON rubriques_comptables(dossier_id, actif, niveau_structure, ordre, code);

CREATE INDEX idx_soldes_bancaires_date
    ON soldes_bancaires(compte_tresorerie_id, date_solde, id);

CREATE INDEX idx_series_marche_selection
    ON series_marche_publiques(categorie, devise, mode, code_serie);

CREATE INDEX idx_valeurs_marche_periode
    ON valeurs_marche_mensuelles(periode, serie_id);

CREATE INDEX idx_sorties_immobilisations_scope
    ON sorties_immobilisations(dossier_id, date_sortie, statut);

CREATE INDEX idx_tentatives_connexion ON tentatives_connexion(email, ip, tente_le);

CREATE INDEX idx_defis_mfa_email_utilisateur
    ON defis_mfa_email(utilisateur_id, but, expire_le, consomme_le);

CREATE INDEX idx_tentatives_progression
    ON tentatives_pedagogiques(assignation_id, etape_id, cree_le);

CREATE INDEX idx_tva_codes_scope_dates ON tva_codes(dossier_id, code, date_debut, date_fin);

CREATE INDEX idx_tva_encaissements_date ON tva_encaissements(dossier_id, date_paiement);

CREATE INDEX idx_tva_lignes_scope_date ON tva_lignes(dossier_id, date_prestation, nature_snapshot);

CREATE INDEX idx_tva_regimes_scope_dates
    ON tva_regimes(dossier_id, date_debut, date_fin);

CREATE INDEX idx_types_comptes_scope
    ON types_comptes(dossier_id, actif, ordre, code);

CREATE UNIQUE INDEX uq_assignation_dossier_active
    ON assignations_exercice(dossier_id) WHERE statut <> 'archivee';

CREATE UNIQUE INDEX uq_comptes_tresorerie_iban
    ON comptes_tresorerie(dossier_id, iban)
    WHERE iban <> '';

CREATE UNIQUE INDEX uq_documents_numero
    ON documents_financiers(dossier_id, numero) WHERE numero <> '';

CREATE UNIQUE INDEX uq_documents_numero_fournisseur
    ON documents_financiers(dossier_id, contact_id, numero_externe)
    WHERE type IN ('facture_fournisseur', 'avoir_fournisseur')
      AND numero_externe <> '';

CREATE UNIQUE INDEX uq_ecritures_idempotence
    ON ecritures(dossier_id, cle_idempotence)
    WHERE cle_idempotence IS NOT NULL;

CREATE UNIQUE INDEX uq_ecritures_numero
    ON ecritures(dossier_id, numero)
    WHERE numero <> '';

CREATE UNIQUE INDEX uq_ecritures_source
    ON ecritures(dossier_id, source_type, source_id, source_action)
    WHERE source_type <> 'manuel' AND source_id <> '';

CREATE UNIQUE INDEX uq_fiche_salaire_periode_active
    ON fiches_salaires(employe_id, annee, mois) WHERE statut <> 'annulee';

CREATE UNIQUE INDEX uq_membre_groupe_actif
    ON membres_groupes(groupe_id, utilisateur_id) WHERE retrait_le IS NULL;

CREATE UNIQUE INDEX uq_organisations_numero_ide
    ON organisations(numero_ide)
    WHERE numero_ide <> '';

CREATE UNIQUE INDEX uq_rapprochement_ligne_banque_active
    ON rapprochement_lignes_bancaires(ligne_bancaire_id)
    WHERE actif = 1;

CREATE UNIQUE INDEX uq_rapprochement_ligne_comptable_active
    ON rapprochement_lignes_comptables(ligne_ecriture_id)
    WHERE actif = 1;

CREATE UNIQUE INDEX uq_ordre_paiement_document_actif
    ON ordres_paiement_sortants(document_id)
    WHERE statut IN ('prepare', 'exporte');

CREATE UNIQUE INDEX uq_sortie_immobilisation_active
    ON sorties_immobilisations(immobilisation_id)
    WHERE statut = 'comptabilisee';

CREATE UNIQUE INDEX uq_rubriques_code
    ON rubriques_comptables(dossier_id, code)
    WHERE code <> '';

-- TRIGGERS

CREATE TRIGGER documents_financiers_base_insert
AFTER INSERT ON documents_financiers
WHEN NEW.monnaie = NEW.devise_base
 AND NEW.taux_change_numerateur = 1
 AND NEW.taux_change_denominateur = 1
BEGIN
    UPDATE documents_financiers
    SET total_net_base_centimes = NEW.total_net_centimes,
        total_tva_base_centimes = NEW.total_tva_centimes,
        total_brut_base_centimes = NEW.total_brut_centimes
    WHERE id = NEW.id;
END;

CREATE TRIGGER documents_financiers_base_update
AFTER UPDATE OF total_net_centimes, total_tva_centimes, total_brut_centimes
ON documents_financiers
WHEN NEW.monnaie = NEW.devise_base
 AND NEW.taux_change_numerateur = 1
 AND NEW.taux_change_denominateur = 1
BEGIN
    UPDATE documents_financiers
    SET total_net_base_centimes = NEW.total_net_centimes,
        total_tva_base_centimes = NEW.total_tva_centimes,
        total_brut_base_centimes = NEW.total_brut_centimes
    WHERE id = NEW.id;
END;

CREATE TRIGGER paiements_base_insert
AFTER INSERT ON paiements
WHEN NEW.monnaie = NEW.devise_base
 AND NEW.taux_change_numerateur = 1
 AND NEW.taux_change_denominateur = 1
BEGIN
    UPDATE paiements
    SET montant_base_centimes = NEW.montant_centimes
    WHERE id = NEW.id;
END;

CREATE TRIGGER allocations_base_insert
AFTER INSERT ON allocations
WHEN NEW.montant_document_base_centimes = 0
BEGIN
    UPDATE allocations
    SET montant_document_base_centimes = NEW.montant_centimes,
        montant_paiement_base_centimes = NEW.montant_centimes
    WHERE id = NEW.id
      AND EXISTS (
        SELECT 1 FROM documents_financiers d
        WHERE d.id = NEW.document_id
          AND d.monnaie = d.devise_base
          AND d.taux_change_numerateur = 1
          AND d.taux_change_denominateur = 1
      );
END;

CREATE TRIGGER trg_archives_rapports_immutable_delete
BEFORE DELETE ON archives_rapports_financiers
BEGIN SELECT RAISE(ABORT, 'archive financière non supprimable'); END;

CREATE TRIGGER trg_archives_rapports_immutable_update
BEFORE UPDATE ON archives_rapports_financiers
BEGIN SELECT RAISE(ABORT, 'archive financière immuable'); END;

CREATE TRIGGER trg_allocations_salaires_scope BEFORE INSERT ON allocations_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM paiements_salaires p JOIN dettes_salaires d
          ON d.id = NEW.dette_salaire_id
        WHERE p.id = NEW.paiement_salaire_id
          AND p.organisation_id = NEW.organisation_id
          AND p.dossier_id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND d.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'allocation salaire hors scope') END;
END;

CREATE TRIGGER trg_allocations_scope_insert BEFORE INSERT ON allocations
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM documents_financiers d WHERE d.id = NEW.document_id
          AND d.organisation_id = NEW.organisation_id AND d.dossier_id = NEW.dossier_id
          AND d.statut IN ('emis', 'comptabilise')
    ) THEN RAISE(ABORT, 'document cible hors scope ou non émis') END;
    SELECT CASE WHEN NEW.paiement_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM paiements p WHERE p.id = NEW.paiement_id
          AND p.organisation_id = NEW.organisation_id AND p.dossier_id = NEW.dossier_id
          AND p.statut = 'valide'
    ) THEN RAISE(ABORT, 'paiement source hors scope') END;
    SELECT CASE WHEN NEW.avoir_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM documents_financiers a WHERE a.id = NEW.avoir_id
          AND a.organisation_id = NEW.organisation_id AND a.dossier_id = NEW.dossier_id
          AND a.type IN ('avoir_client', 'avoir_fournisseur')
          AND a.statut IN ('emis', 'comptabilise')
    ) THEN RAISE(ABORT, 'avoir source hors scope') END;
END;

CREATE TRIGGER trg_assignation_exercice_scope BEFORE INSERT ON assignations_exercice
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        JOIN versions_modeles_exercice v ON v.id = NEW.version_modele_id
        JOIN modeles_exercice m ON m.id = v.modele_id
        WHERE d.id = NEW.dossier_id AND d.organisation_id = NEW.organisation_id
          AND d.type = 'exercice' AND m.organisation_id = NEW.organisation_id
          AND v.statut = 'publie'
    ) THEN RAISE(ABORT, 'assignation pédagogique hors scope') END;
END;

CREATE TRIGGER trg_assignation_exercice_scope_update BEFORE UPDATE ON assignations_exercice
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        JOIN versions_modeles_exercice v ON v.id = NEW.version_modele_id
        JOIN modeles_exercice m ON m.id = v.modele_id
        WHERE d.id = NEW.dossier_id AND d.organisation_id = NEW.organisation_id
          AND d.type = 'exercice' AND m.organisation_id = NEW.organisation_id
          AND v.statut = 'publie'
    ) THEN RAISE(ABORT, 'assignation pédagogique hors scope') END;
END;

CREATE TRIGGER trg_composants_fiche_delete BEFORE DELETE ON composants_fiche
BEGIN
    SELECT CASE WHEN (SELECT statut FROM fiches_salaires WHERE id = OLD.fiche_salaire_id) <> 'brouillon'
        THEN RAISE(ABORT, 'composants validés immuables') END;
END;

CREATE TRIGGER trg_composants_fiche_update BEFORE UPDATE ON composants_fiche
BEGIN
    SELECT CASE WHEN (SELECT statut FROM fiches_salaires WHERE id = OLD.fiche_salaire_id) <> 'brouillon'
        THEN RAISE(ABORT, 'composants validés immuables') END;
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

CREATE TRIGGER trg_comptes_scope_insert
BEFORE INSERT ON comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de compte invalide') END;
    SELECT CASE WHEN NEW.parent_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes
        WHERE id = NEW.parent_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'parent de compte hors scope') END;
END;

CREATE TRIGGER trg_comptes_scope_update
BEFORE UPDATE ON comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de compte invalide') END;
    SELECT CASE WHEN NEW.parent_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes
        WHERE id = NEW.parent_id
          AND dossier_id = NEW.dossier_id
          AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'parent de compte hors scope') END;
END;

CREATE TRIGGER trg_comptes_tresorerie_scope_insert
BEFORE INSERT ON comptes_tresorerie
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN comptes c ON c.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND c.id = NEW.compte_comptable_id
          AND c.organisation_id = NEW.organisation_id
          AND c.imputable = 1
    ) THEN RAISE(ABORT, 'scope de compte de trésorerie invalide') END;
END;

CREATE TRIGGER trg_comptes_tresorerie_scope_update
BEFORE UPDATE ON comptes_tresorerie
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN comptes c ON c.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND c.id = NEW.compte_comptable_id
          AND c.organisation_id = NEW.organisation_id
          AND c.imputable = 1
    ) THEN RAISE(ABORT, 'scope de compte de trésorerie invalide') END;
END;

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

CREATE TRIGGER trg_conditions_paiement_scope_insert
BEFORE INSERT ON conditions_paiement
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de condition de paiement invalide') END;
END;

CREATE TRIGGER trg_contacts_scope_insert BEFORE INSERT ON contacts
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de contact invalide') END;
    SELECT CASE WHEN NEW.entreprise_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM contacts e
        WHERE e.id = NEW.entreprise_id
          AND e.organisation_id = NEW.organisation_id
          AND e.dossier_id = NEW.dossier_id
          AND e.type_personne = 'entreprise'
          AND e.actif = 1
    ) THEN RAISE(ABORT, 'entreprise du contact invalide') END;
END;

CREATE TRIGGER trg_contacts_scope_update BEFORE UPDATE ON contacts
BEGIN
    SELECT CASE WHEN NEW.organisation_id <> OLD.organisation_id
        OR NEW.dossier_id <> OLD.dossier_id
        THEN RAISE(ABORT, 'scope de contact immuable') END;
    SELECT CASE WHEN NEW.entreprise_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM contacts e
        WHERE e.id = NEW.entreprise_id
          AND e.organisation_id = NEW.organisation_id
          AND e.dossier_id = NEW.dossier_id
          AND e.type_personne = 'entreprise'
          AND e.actif = 1
    ) THEN RAISE(ABORT, 'entreprise du contact invalide') END;
END;

CREATE TRIGGER trg_dossier_compte_facturation_insert
BEFORE INSERT ON dossiers
WHEN NEW.compte_tresorerie_facturation_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id = NEW.compte_tresorerie_facturation_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.id
          AND t.actif = 1
          AND t.iban <> ''
    ) THEN RAISE(ABORT, 'compte de facturation invalide') END;
END;

CREATE TRIGGER trg_dossier_compte_facturation_update
BEFORE UPDATE OF compte_tresorerie_facturation_id ON dossiers
WHEN NEW.compte_tresorerie_facturation_id IS NOT NULL
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id = NEW.compte_tresorerie_facturation_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.id
          AND t.actif = 1
          AND t.iban <> ''
    ) THEN RAISE(ABORT, 'compte de facturation invalide') END;
END;

CREATE TRIGGER trg_defauts_conditions_scope_insert
BEFORE INSERT ON defauts_conditions_paiement
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM conditions_paiement c
        WHERE c.id = NEW.condition_id
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
          AND c.actif = 1
          AND c.date_debut <= NEW.date_debut
          AND (c.date_fin IS NULL OR c.date_fin >= NEW.date_debut)
          AND (c.direction = 'tous' OR c.direction = NEW.direction)
    ) THEN RAISE(ABORT, 'condition par défaut invalide') END;
END;

CREATE TRIGGER trg_documents_condition_paiement_immuable
BEFORE UPDATE ON documents_financiers
WHEN OLD.statut <> 'brouillon'
 AND (
    NEW.condition_paiement_id IS NOT OLD.condition_paiement_id
    OR NEW.condition_paiement_snapshot_json <> OLD.condition_paiement_snapshot_json
 )
BEGIN
    SELECT RAISE(ABORT, 'condition de paiement d''un document émis immuable');
END;

CREATE TRIGGER trg_documents_emis_immuables
BEFORE UPDATE ON documents_financiers WHEN OLD.statut <> 'brouillon'
BEGIN
    SELECT CASE WHEN NEW.contact_id <> OLD.contact_id
        OR NEW.type <> OLD.type
        OR NEW.numero <> OLD.numero
        OR NEW.numero_externe <> OLD.numero_externe
        OR NEW.date_document <> OLD.date_document
        OR NEW.date_echeance <> OLD.date_echeance
        OR NEW.monnaie <> OLD.monnaie
        OR NEW.devise_base <> OLD.devise_base
        OR NEW.taux_change_numerateur <> OLD.taux_change_numerateur
        OR NEW.taux_change_denominateur <> OLD.taux_change_denominateur
        OR NEW.taux_change_date <> OLD.taux_change_date
        OR NEW.taux_change_source <> OLD.taux_change_source
        OR NEW.adresse_snapshot_json <> OLD.adresse_snapshot_json
        OR NEW.contact_snapshot_json <> OLD.contact_snapshot_json
        OR NEW.total_net_centimes <> OLD.total_net_centimes
        OR NEW.total_tva_centimes <> OLD.total_tva_centimes
        OR NEW.total_brut_centimes <> OLD.total_brut_centimes
        OR COALESCE(NEW.compte_collectif_id, 0) <> COALESCE(OLD.compte_collectif_id, 0)
        THEN RAISE(ABORT, 'contenu du document émis immuable') END;
    SELECT CASE WHEN (
        NEW.total_net_base_centimes <> OLD.total_net_base_centimes
        OR NEW.total_tva_base_centimes <> OLD.total_tva_base_centimes
        OR NEW.total_brut_base_centimes <> OLD.total_brut_base_centimes
    ) AND NOT (
        OLD.monnaie = OLD.devise_base
        AND OLD.taux_change_numerateur = 1
        AND OLD.taux_change_denominateur = 1
        AND OLD.total_net_base_centimes = 0
        AND OLD.total_tva_base_centimes = 0
        AND OLD.total_brut_base_centimes = 0
        AND NEW.total_net_base_centimes = OLD.total_net_centimes
        AND NEW.total_tva_base_centimes = OLD.total_tva_centimes
        AND NEW.total_brut_base_centimes = OLD.total_brut_centimes
    ) THEN RAISE(ABORT, 'conversion du document émis immuable') END;
END;

CREATE TRIGGER trg_documents_historique_delete
BEFORE DELETE ON documents_financiers WHEN OLD.statut <> 'brouillon'
BEGIN SELECT RAISE(ABORT, 'document émis immuable'); END;

CREATE TRIGGER trg_documents_scope_insert BEFORE INSERT ON documents_financiers
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM contacts c WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact du document hors scope') END;
    SELECT CASE WHEN NEW.compte_collectif_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes c WHERE c.id = NEW.compte_collectif_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'compte collectif hors scope') END;
END;

CREATE TRIGGER trg_documents_scope_update BEFORE UPDATE ON documents_financiers
BEGIN
    SELECT CASE WHEN NEW.organisation_id <> OLD.organisation_id
        OR NEW.dossier_id <> OLD.dossier_id
        THEN RAISE(ABORT, 'scope de document immuable') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM contacts c WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact du document hors scope') END;
END;

CREATE TRIGGER trg_dossiers_modules_insert
AFTER INSERT ON dossiers
BEGIN
    INSERT INTO modules_dossier (organisation_id, dossier_id, module_code)
    SELECT NEW.organisation_id, NEW.id, code
    FROM modules_application
    WHERE actif_global = 1;
END;

CREATE TRIGGER trg_dossiers_plan_configuration_defaults
AFTER INSERT ON dossiers
BEGIN
    INSERT INTO regles_sens_comptes
        (organisation_id, dossier_id, prefixe)
    VALUES
        (NEW.organisation_id, NEW.id, '2'),
        (NEW.organisation_id, NEW.id, '3');
    INSERT INTO parametres_dossier (dossier_id, cle, valeur)
    VALUES (NEW.id, 'plan_sens_initialise', '1');
END;

CREATE TRIGGER trg_ecritures_contenu_immuable
BEFORE UPDATE ON ecritures
WHEN OLD.statut <> 'brouillon' AND (
    NEW.organisation_id <> OLD.organisation_id
    OR NEW.dossier_id <> OLD.dossier_id
    OR NEW.exercice_id <> OLD.exercice_id
    OR NEW.journal_id <> OLD.journal_id
    OR NEW.numero <> OLD.numero
    OR NEW.date_comptable <> OLD.date_comptable
    OR NEW.libelle <> OLD.libelle
    OR NEW.reference <> OLD.reference
    OR NEW.piece <> OLD.piece
    OR NEW.source_type <> OLD.source_type
    OR NEW.source_id <> OLD.source_id
    OR NEW.source_action <> OLD.source_action
    OR COALESCE(NEW.cle_idempotence, '') <> COALESCE(OLD.cle_idempotence, '')
    OR COALESCE(NEW.empreinte_commande, '') <> COALESCE(OLD.empreinte_commande, '')
    OR COALESCE(NEW.contrepassation_de_id, 0) <> COALESCE(OLD.contrepassation_de_id, 0)
)
BEGIN
    SELECT RAISE(ABORT, 'contenu d''écriture validée immuable');
END;

CREATE TRIGGER trg_ecritures_delete
BEFORE DELETE ON ecritures
WHEN OLD.statut <> 'brouillon'
BEGIN
    SELECT RAISE(ABORT, 'écriture validée immuable');
END;

CREATE TRIGGER trg_ecritures_scope_insert
BEFORE INSERT ON ecritures
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN exercices x ON x.dossier_id = d.id
        JOIN journaux j ON j.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND x.id = NEW.exercice_id
          AND j.id = NEW.journal_id
          AND j.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope d''écriture invalide') END;
END;

CREATE TRIGGER trg_ecritures_scope_update
BEFORE UPDATE ON ecritures
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN exercices x ON x.dossier_id = d.id
        JOIN journaux j ON j.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND x.id = NEW.exercice_id
          AND j.id = NEW.journal_id
          AND j.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope d''écriture invalide') END;
END;

CREATE TRIGGER trg_ecritures_transition
BEFORE UPDATE OF statut ON ecritures
WHEN NOT (
    NEW.statut = OLD.statut
    OR (OLD.statut = 'brouillon' AND NEW.statut = 'validee')
    OR (OLD.statut = 'validee' AND NEW.statut = 'contre_passee')
)
BEGIN
    SELECT RAISE(ABORT, 'transition de statut interdite');
END;

CREATE TRIGGER trg_employes_scope BEFORE INSERT ON employes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope employé invalide') END;
END;

CREATE TRIGGER trg_employeur_salaire_scope BEFORE INSERT ON employeurs_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope employeur invalide') END;
END;

CREATE TRIGGER trg_etape_modele_immuable_delete BEFORE DELETE ON etapes_exercice
WHEN (SELECT statut FROM versions_modeles_exercice WHERE id = OLD.version_modele_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'étape de modèle publiée non supprimable'); END;

CREATE TRIGGER trg_etape_modele_immuable_insert BEFORE INSERT ON etapes_exercice
WHEN (SELECT statut FROM versions_modeles_exercice WHERE id = NEW.version_modele_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'version publiée non extensible'); END;

CREATE TRIGGER trg_etape_modele_immuable_update BEFORE UPDATE ON etapes_exercice
WHEN (SELECT statut FROM versions_modeles_exercice WHERE id = OLD.version_modele_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'étape de modèle publiée immuable'); END;

CREATE TRIGGER trg_fiches_salaires_contenu_immuable
BEFORE UPDATE ON fiches_salaires WHEN OLD.statut <> 'brouillon'
BEGIN
    SELECT CASE WHEN NEW.employe_id <> OLD.employe_id
        OR NEW.annee <> OLD.annee OR NEW.mois <> OLD.mois
        OR NEW.employe_snapshot_json <> OLD.employe_snapshot_json
        OR NEW.employeur_snapshot_json <> OLD.employeur_snapshot_json
        OR NEW.taux_snapshot_json <> OLD.taux_snapshot_json
        OR NEW.brut_centimes <> OLD.brut_centimes
        OR NEW.net_centimes <> OLD.net_centimes
        OR NEW.total_deductions_centimes <> OLD.total_deductions_centimes
        OR NEW.total_charges_employeur_centimes <> OLD.total_charges_employeur_centimes
        THEN RAISE(ABORT, 'fiche validée immuable') END;
END;

CREATE TRIGGER trg_fiches_salaires_delete
BEFORE DELETE ON fiches_salaires WHEN OLD.statut <> 'brouillon'
BEGIN SELECT RAISE(ABORT, 'fiche validée non supprimable'); END;

CREATE TRIGGER trg_fiches_salaires_scope BEFORE INSERT ON fiches_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM employes e WHERE e.id = NEW.employe_id
          AND e.organisation_id = NEW.organisation_id AND e.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'employé de fiche hors scope') END;
END;

CREATE TRIGGER trg_groupe_pedagogique_scope BEFORE INSERT ON groupes_pedagogiques
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM organisations o WHERE o.id = NEW.organisation_id
          AND o.nature = 'pedagogique' AND o.actif = 1
    ) THEN RAISE(ABORT, 'groupe hors organisation pédagogique') END;
END;

CREATE TRIGGER trg_imports_bancaires_scope_insert
BEFORE INSERT ON imports_bancaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM comptes_tresorerie t
        WHERE t.id = NEW.compte_tresorerie_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope d''import bancaire invalide') END;
END;

CREATE TRIGGER trg_indice_modele_immuable_delete BEFORE DELETE ON indices_exercice
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = OLD.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'indice de modèle publié non supprimable'); END;

CREATE TRIGGER trg_indice_modele_immuable_insert BEFORE INSERT ON indices_exercice
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = NEW.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'indice non ajoutable à une version publiée'); END;

CREATE TRIGGER trg_indice_modele_immuable_update BEFORE UPDATE ON indices_exercice
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = OLD.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'indice de modèle publié immuable'); END;

CREATE TRIGGER trg_journaux_scope_insert
BEFORE INSERT ON journaux
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de journal invalide') END;
END;

CREATE TRIGGER trg_journaux_scope_update
BEFORE UPDATE ON journaux
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de journal invalide') END;
END;

CREATE TRIGGER trg_lignes_bancaires_immuables_delete
BEFORE DELETE ON lignes_bancaires
BEGIN
    SELECT RAISE(ABORT, 'ligne bancaire immuable');
END;

CREATE TRIGGER trg_lignes_bancaires_immuables_update
BEFORE UPDATE ON lignes_bancaires
BEGIN
    SELECT RAISE(ABORT, 'ligne bancaire immuable');
END;

CREATE TRIGGER trg_lignes_bancaires_scope_insert
BEFORE INSERT ON lignes_bancaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM imports_bancaires i
        JOIN comptes_tresorerie t ON t.id = i.compte_tresorerie_id
        WHERE i.id = NEW.import_id
          AND i.organisation_id = NEW.organisation_id
          AND i.dossier_id = NEW.dossier_id
          AND t.id = NEW.compte_tresorerie_id
    ) THEN RAISE(ABORT, 'scope de ligne bancaire invalide') END;
END;

CREATE TRIGGER trg_lignes_delete
BEFORE DELETE ON lignes_ecriture
WHEN (SELECT statut FROM ecritures WHERE id = OLD.ecriture_id) <> 'brouillon'
BEGIN
    SELECT RAISE(ABORT, 'écriture validée immuable');
END;

CREATE TRIGGER trg_lignes_document_delete BEFORE DELETE ON lignes_document
BEGIN
    SELECT CASE WHEN (SELECT statut FROM documents_financiers WHERE id = OLD.document_id) <> 'brouillon'
        THEN RAISE(ABORT, 'lignes du document émises immuables') END;
END;

CREATE TRIGGER trg_lignes_document_scope_insert BEFORE INSERT ON lignes_document
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM documents_financiers d
        JOIN comptes c ON c.id = NEW.compte_id
        LEFT JOIN tva_codes t ON t.id = NEW.code_tva_id
        WHERE d.id = NEW.document_id AND d.statut = 'brouillon'
          AND c.organisation_id = d.organisation_id AND c.dossier_id = d.dossier_id
          AND (
              (
                  NEW.code_tva_id IS NULL
                  AND EXISTS (
                      SELECT 1 FROM tva_regimes r
                      WHERE r.organisation_id = d.organisation_id
                        AND r.dossier_id = d.dossier_id
                        AND r.statut = 'non_assujetti'
                        AND r.date_debut <= NEW.date_prestation
                        AND COALESCE(r.date_fin, '9999-12-31') >= NEW.date_prestation
                  )
              )
              OR (
                  t.organisation_id = d.organisation_id
                  AND t.dossier_id = d.dossier_id
              )
          )
    ) THEN RAISE(ABORT, 'ligne de document hors scope ou document figé') END;
END;

CREATE TRIGGER trg_lignes_document_update BEFORE UPDATE ON lignes_document
BEGIN
    SELECT CASE WHEN (SELECT statut FROM documents_financiers WHERE id = OLD.document_id) <> 'brouillon'
        THEN RAISE(ABORT, 'lignes du document émises immuables') END;
END;

CREATE TRIGGER trg_lignes_prestation_delete BEFORE DELETE ON lignes_prestation
BEGIN
    SELECT CASE WHEN (SELECT statut FROM fiches_salaires WHERE id = OLD.fiche_salaire_id) <> 'brouillon'
        THEN RAISE(ABORT, 'prestations validées immuables') END;
END;

CREATE TRIGGER trg_lignes_prestation_update BEFORE UPDATE ON lignes_prestation
BEGIN
    SELECT CASE WHEN (SELECT statut FROM fiches_salaires WHERE id = OLD.fiche_salaire_id) <> 'brouillon'
        THEN RAISE(ABORT, 'prestations validées immuables') END;
END;

CREATE TRIGGER trg_lignes_scope_insert
BEFORE INSERT ON lignes_ecriture
BEGIN
    SELECT CASE WHEN (SELECT statut FROM ecritures WHERE id = NEW.ecriture_id) <> 'brouillon'
        THEN RAISE(ABORT, 'écriture validée immuable') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM ecritures e
        JOIN comptes c
          ON c.id = NEW.compte_id
         AND c.dossier_id = e.dossier_id
         AND c.organisation_id = e.organisation_id
        WHERE e.id = NEW.ecriture_id
    ) THEN RAISE(ABORT, 'compte hors scope de l''écriture') END;
END;

CREATE TRIGGER trg_lignes_scope_update
BEFORE UPDATE ON lignes_ecriture
BEGIN
    SELECT CASE WHEN (SELECT statut FROM ecritures WHERE id = OLD.ecriture_id) <> 'brouillon'
        THEN RAISE(ABORT, 'écriture validée immuable') END;
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM ecritures e
        JOIN comptes c
          ON c.id = NEW.compte_id
         AND c.dossier_id = e.dossier_id
         AND c.organisation_id = e.organisation_id
        WHERE e.id = NEW.ecriture_id
    ) THEN RAISE(ABORT, 'compte hors scope de l''écriture') END;
END;

CREATE TRIGGER trg_mapping_salaires_scope BEFORE INSERT ON mapping_comptes_salaires
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM (
            SELECT NEW.charge_salaires_id AS id UNION ALL SELECT NEW.charge_ocas_id
            UNION ALL SELECT NEW.charge_laa_id UNION ALL SELECT NEW.charge_lpp_id
            UNION ALL SELECT NEW.dette_net_id UNION ALL SELECT NEW.dette_ocas_id
            UNION ALL SELECT NEW.dette_laa_id UNION ALL SELECT NEW.dette_lpp_id
            UNION ALL SELECT NEW.dette_impot_id
        ) ids LEFT JOIN comptes c ON c.id = ids.id
        WHERE c.id IS NULL OR c.organisation_id <> NEW.organisation_id
          OR c.dossier_id <> NEW.dossier_id
    ) THEN RAISE(ABORT, 'mapping salaires hors scope') END;
END;

CREATE TRIGGER trg_mapping_salaires_scope_update BEFORE UPDATE ON mapping_comptes_salaires
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM (
            SELECT NEW.charge_salaires_id AS id UNION ALL SELECT NEW.charge_ocas_id
            UNION ALL SELECT NEW.charge_laa_id UNION ALL SELECT NEW.charge_lpp_id
            UNION ALL SELECT NEW.dette_net_id UNION ALL SELECT NEW.dette_ocas_id
            UNION ALL SELECT NEW.dette_laa_id UNION ALL SELECT NEW.dette_lpp_id
            UNION ALL SELECT NEW.dette_impot_id
        ) ids LEFT JOIN comptes c ON c.id = ids.id
        WHERE c.id IS NULL OR c.organisation_id <> NEW.organisation_id
          OR c.dossier_id <> NEW.dossier_id
    ) THEN RAISE(ABORT, 'mapping salaires hors scope') END;
END;

CREATE TRIGGER trg_modele_exercice_scope BEFORE INSERT ON modeles_exercice
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM organisations o WHERE o.id = NEW.organisation_id
          AND o.nature = 'pedagogique' AND o.actif = 1
    ) THEN RAISE(ABORT, 'modèle hors organisation pédagogique') END;
END;

CREATE TRIGGER trg_modules_dossier_scope_insert
BEFORE INSERT ON modules_dossier
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de module invalide') END;
END;

CREATE TRIGGER trg_modules_dossier_scope_update
BEFORE UPDATE ON modules_dossier
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de module invalide') END;
END;

CREATE TRIGGER trg_paiements_salaires_scope BEFORE INSERT ON paiements_salaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        JOIN comptes c ON c.id = NEW.compte_tresorerie_id
        WHERE d.id = NEW.dossier_id AND d.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
          AND c.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'paiement salaire hors scope') END;
    SELECT CASE WHEN NEW.employe_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM employes e WHERE e.id = NEW.employe_id
          AND e.dossier_id = NEW.dossier_id
          AND e.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'employé du paiement hors scope') END;
END;

CREATE TRIGGER trg_paiements_scope_insert BEFORE INSERT ON paiements
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM contacts c WHERE c.id = NEW.contact_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'contact du paiement hors scope') END;
    SELECT CASE WHEN NEW.compte_tresorerie_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes c WHERE c.id = NEW.compte_tresorerie_id
          AND c.organisation_id = NEW.organisation_id AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'compte de trésorerie hors scope') END;
END;

CREATE TRIGGER trg_paiements_snapshot_immutable
BEFORE UPDATE ON paiements
WHEN NEW.organisation_id <> OLD.organisation_id
  OR NEW.dossier_id <> OLD.dossier_id
  OR NEW.contact_id <> OLD.contact_id
  OR NEW.sens <> OLD.sens
  OR NEW.date_paiement <> OLD.date_paiement
  OR NEW.montant_centimes <> OLD.montant_centimes
  OR NEW.monnaie <> OLD.monnaie
  OR NEW.devise_base <> OLD.devise_base
  OR NEW.taux_change_numerateur <> OLD.taux_change_numerateur
  OR NEW.taux_change_denominateur <> OLD.taux_change_denominateur
  OR NEW.taux_change_date <> OLD.taux_change_date
  OR NEW.taux_change_source <> OLD.taux_change_source
  OR (
    NEW.montant_base_centimes <> OLD.montant_base_centimes
    AND NOT (
      OLD.montant_base_centimes = 0
      AND NEW.montant_base_centimes = OLD.montant_centimes
      AND OLD.monnaie = OLD.devise_base
    )
  )
BEGIN
    SELECT RAISE(ABORT, 'snapshot du paiement immuable');
END;

CREATE TRIGGER trg_allocations_change_immutable
BEFORE UPDATE ON allocations
WHEN NEW.organisation_id <> OLD.organisation_id
  OR NEW.dossier_id <> OLD.dossier_id
  OR COALESCE(NEW.paiement_id, 0) <> COALESCE(OLD.paiement_id, 0)
  OR COALESCE(NEW.avoir_id, 0) <> COALESCE(OLD.avoir_id, 0)
  OR NEW.document_id <> OLD.document_id
  OR NEW.montant_centimes <> OLD.montant_centimes
  OR (
    NEW.montant_document_base_centimes <> OLD.montant_document_base_centimes
    OR NEW.montant_paiement_base_centimes <> OLD.montant_paiement_base_centimes
    OR NEW.ecart_change_realise_centimes <> OLD.ecart_change_realise_centimes
  ) AND NOT (
    OLD.montant_document_base_centimes = 0
    AND OLD.montant_paiement_base_centimes = 0
    AND OLD.ecart_change_realise_centimes = 0
    AND NEW.montant_document_base_centimes = OLD.montant_centimes
    AND NEW.montant_paiement_base_centimes = OLD.montant_centimes
    AND NEW.ecart_change_realise_centimes = 0
  )
BEGIN
    SELECT RAISE(ABORT, 'montants du lettrage immuables');
END;

CREATE TRIGGER trg_periodes_scope_insert
BEFORE INSERT ON periodes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN exercices x ON x.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND x.id = NEW.exercice_id
          AND NEW.date_debut >= x.date_debut
          AND NEW.date_fin <= x.date_fin
    ) THEN RAISE(ABORT, 'scope ou dates de période invalides') END;
END;

CREATE TRIGGER trg_periodes_scope_update
BEFORE UPDATE ON periodes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM dossiers d
        JOIN exercices x ON x.dossier_id = d.id
        WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
          AND x.id = NEW.exercice_id
          AND NEW.date_debut >= x.date_debut
          AND NEW.date_fin <= x.date_fin
    ) THEN RAISE(ABORT, 'scope ou dates de période invalides') END;
END;

CREATE TRIGGER trg_pieces_jointes_scope_insert BEFORE INSERT ON pieces_jointes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de justificatif invalide') END;
END;

CREATE TRIGGER trg_rappels_scope_insert BEFORE INSERT ON rappels_factures
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM documents_financiers d WHERE d.id = NEW.document_id
          AND d.organisation_id = NEW.organisation_id AND d.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'rappel hors scope') END;
END;

CREATE TRIGGER trg_rapprochement_ligne_banque_scope
BEFORE INSERT ON rapprochement_lignes_bancaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM rapprochements_bancaires r
        JOIN lignes_bancaires l
          ON l.compte_tresorerie_id = r.compte_tresorerie_id
         AND l.organisation_id = r.organisation_id
         AND l.dossier_id = r.dossier_id
        WHERE r.id = NEW.rapprochement_id
          AND l.id = NEW.ligne_bancaire_id
          AND l.montant_centimes = NEW.montant_centimes
    ) THEN RAISE(ABORT, 'ligne bancaire hors rapprochement') END;
END;

CREATE TRIGGER trg_rapprochement_ligne_comptable_scope
BEFORE INSERT ON rapprochement_lignes_comptables
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM rapprochements_bancaires r
        JOIN comptes_tresorerie t ON t.id = r.compte_tresorerie_id
        JOIN lignes_ecriture l ON l.compte_id = t.compte_comptable_id
        JOIN ecritures e ON e.id = l.ecriture_id
        WHERE r.id = NEW.rapprochement_id
          AND l.id = NEW.ligne_ecriture_id
          AND e.organisation_id = r.organisation_id
          AND e.dossier_id = r.dossier_id
          AND e.statut IN ('validee', 'contre_passee')
          AND (l.debit_centimes - l.credit_centimes) = NEW.montant_centimes
    ) THEN RAISE(ABORT, 'ligne comptable hors rapprochement') END;
END;

CREATE TRIGGER trg_regle_modele_immuable_delete BEFORE DELETE ON regles_validation
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = OLD.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'règle de modèle publiée non supprimable'); END;

CREATE TRIGGER trg_regle_modele_immuable_insert BEFORE INSERT ON regles_validation
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = NEW.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'règle non ajoutable à une version publiée'); END;

CREATE TRIGGER trg_regle_modele_immuable_update BEFORE UPDATE ON regles_validation
WHEN (SELECT v.statut FROM versions_modeles_exercice v JOIN etapes_exercice e
      ON e.version_modele_id = v.id WHERE e.id = OLD.etape_id) = 'publie'
BEGIN SELECT RAISE(ABORT, 'règle de modèle publiée immuable'); END;

CREATE TRIGGER trg_regles_sens_scope_insert
BEFORE INSERT ON regles_sens_comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de règle de sens invalide') END;
END;

CREATE TRIGGER trg_regles_sens_scope_update
BEFORE UPDATE ON regles_sens_comptes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers
        WHERE id = NEW.dossier_id AND organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope de règle de sens invalide') END;
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

CREATE TRIGGER trg_soldes_bancaires_immuables_delete
BEFORE DELETE ON soldes_bancaires
BEGIN
    SELECT RAISE(ABORT, 'solde bancaire immuable');
END;

CREATE TRIGGER trg_soldes_bancaires_immuables_update
BEFORE UPDATE ON soldes_bancaires
BEGIN
    SELECT RAISE(ABORT, 'solde bancaire immuable');
END;

CREATE TRIGGER trg_soldes_bancaires_scope_insert
BEFORE INSERT ON soldes_bancaires
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM imports_bancaires i
        WHERE i.id = NEW.import_id
          AND i.compte_tresorerie_id = NEW.compte_tresorerie_id
          AND i.organisation_id = NEW.organisation_id
          AND i.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope de solde bancaire invalide') END;
END;

CREATE TRIGGER trg_suggestions_comptabilisation_scope_insert
BEFORE INSERT ON suggestions_comptabilisation
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM lignes_bancaires l
        JOIN comptes c ON c.id = NEW.compte_contrepartie_id
        WHERE l.id = NEW.ligne_bancaire_id
          AND l.organisation_id = NEW.organisation_id
          AND l.dossier_id = NEW.dossier_id
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
          AND c.imputable = 1
    ) THEN RAISE(ABORT, 'scope de suggestion invalide') END;
END;

CREATE TRIGGER trg_taux_salaires_scope BEFORE INSERT ON taux_salaires_annuels
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope taux salaires invalide') END;
END;

CREATE TRIGGER trg_tentative_immuable_delete
BEFORE DELETE ON tentatives_pedagogiques
BEGIN SELECT RAISE(ABORT, 'tentative pédagogique non supprimable'); END;

CREATE TRIGGER trg_tentative_immuable_update
BEFORE UPDATE ON tentatives_pedagogiques
BEGIN SELECT RAISE(ABORT, 'tentative pédagogique immuable'); END;

CREATE TRIGGER trg_tva_codes_scope_insert
BEFORE INSERT ON tva_codes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope du code TVA invalide') END;
    SELECT CASE WHEN NEW.compte_tva_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM comptes c WHERE c.id = NEW.compte_tva_id
          AND c.dossier_id = NEW.dossier_id AND c.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'compte du code TVA hors scope') END;
END;

CREATE TRIGGER trg_tva_decompte_cases_immuables_delete
BEFORE DELETE ON tva_decompte_cases
BEGIN SELECT RAISE(ABORT, 'case du décompte TVA immuable'); END;

CREATE TRIGGER trg_tva_decompte_cases_immuables_update
BEFORE UPDATE ON tva_decompte_cases
BEGIN SELECT RAISE(ABORT, 'case du décompte TVA immuable'); END;

CREATE TRIGGER trg_tva_decompte_sources_immuables_delete
BEFORE DELETE ON tva_decompte_sources
BEGIN SELECT RAISE(ABORT, 'source du décompte TVA immuable'); END;

CREATE TRIGGER trg_tva_decompte_sources_immuables_update
BEFORE UPDATE ON tva_decompte_sources
BEGIN SELECT RAISE(ABORT, 'source du décompte TVA immuable'); END;

CREATE TRIGGER trg_tva_decomptes_immuables_update
BEFORE UPDATE OF methode_snapshot, mode_decompte_snapshot, numero_tva_snapshot,
    parametres_json, agregats_json, total_chiffre_affaires_centimes,
    tva_due_centimes, impot_prealable_centimes, corrections_centimes, solde_centimes
ON tva_decomptes
BEGIN SELECT RAISE(ABORT, 'snapshot du décompte TVA immuable'); END;

CREATE TRIGGER trg_tva_encaissements_immuables_delete BEFORE DELETE ON tva_encaissements
BEGIN SELECT RAISE(ABORT, 'encaissement TVA immuable'); END;

CREATE TRIGGER trg_tva_encaissements_immuables_update BEFORE UPDATE ON tva_encaissements
BEGIN SELECT RAISE(ABORT, 'encaissement TVA immuable'); END;

CREATE TRIGGER trg_tva_encaissements_scope_insert
BEFORE INSERT ON tva_encaissements
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM tva_lignes t WHERE t.id = NEW.tva_ligne_id
          AND t.organisation_id = NEW.organisation_id
          AND t.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope d''encaissement TVA invalide') END;
END;

CREATE TRIGGER trg_tva_exports_immuables_delete BEFORE DELETE ON tva_exports
BEGIN SELECT RAISE(ABORT, 'export TVA immuable'); END;

CREATE TRIGGER trg_tva_exports_immuables_update BEFORE UPDATE ON tva_exports
BEGIN SELECT RAISE(ABORT, 'export TVA immuable'); END;

CREATE TRIGGER trg_tva_exports_scope_insert
BEFORE INSERT ON tva_exports
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM tva_decomptes d WHERE d.id = NEW.decompte_tva_id
          AND d.organisation_id = NEW.organisation_id
          AND d.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope d''export TVA invalide') END;
END;

CREATE TRIGGER trg_tva_lignes_immuables_delete BEFORE DELETE ON tva_lignes
BEGIN SELECT RAISE(ABORT, 'snapshot TVA immuable'); END;

CREATE TRIGGER trg_tva_lignes_immuables_update BEFORE UPDATE ON tva_lignes
BEGIN SELECT RAISE(ABORT, 'snapshot TVA immuable'); END;

CREATE TRIGGER trg_tva_lignes_scope_insert
BEFORE INSERT ON tva_lignes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM lignes_ecriture l
        JOIN ecritures e ON e.id = l.ecriture_id
        JOIN tva_codes c ON c.id = NEW.code_tva_id
        WHERE l.id = NEW.ligne_ecriture_id
          AND e.organisation_id = NEW.organisation_id
          AND e.dossier_id = NEW.dossier_id
          AND e.statut IN ('validee', 'contre_passee')
          AND c.organisation_id = NEW.organisation_id
          AND c.dossier_id = NEW.dossier_id
    ) THEN RAISE(ABORT, 'scope de ligne TVA invalide') END;
END;

CREATE TRIGGER trg_tva_periodes_scope_insert
BEFORE INSERT ON tva_periodes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM tva_regimes r WHERE r.id = NEW.regime_tva_id
          AND r.organisation_id = NEW.organisation_id
          AND r.dossier_id = NEW.dossier_id
          AND NEW.date_debut >= r.date_debut
          AND NEW.date_fin <= COALESCE(r.date_fin, '9999-12-31')
    ) THEN RAISE(ABORT, 'scope ou dates de période TVA invalides') END;
END;

CREATE TRIGGER trg_tva_regimes_historique_update
BEFORE UPDATE ON tva_regimes
BEGIN
    SELECT CASE WHEN
        NEW.organisation_id <> OLD.organisation_id
        OR NEW.dossier_id <> OLD.dossier_id
        OR NEW.statut <> OLD.statut
        OR NEW.numero_tva <> OLD.numero_tva
        OR NEW.methode <> OLD.methode
        OR NEW.mode_decompte <> OLD.mode_decompte
        OR NEW.periodicite <> OLD.periodicite
        OR NEW.date_debut <> OLD.date_debut
        OR NEW.compte_impot_prealable_materiel_id IS NOT OLD.compte_impot_prealable_materiel_id
        OR NEW.compte_impot_prealable_investissements_id IS NOT OLD.compte_impot_prealable_investissements_id
        OR NEW.compte_tva_due_id IS NOT OLD.compte_tva_due_id
        OR NEW.compte_decompte_tva_id IS NOT OLD.compte_decompte_tva_id
        OR NEW.compte_corrections_id IS NOT OLD.compte_corrections_id
        OR OLD.date_fin IS NOT NULL
        OR NEW.date_fin IS NULL
        OR NEW.date_fin < NEW.date_debut
    THEN RAISE(ABORT, 'historique du régime TVA immuable') END;
END;

CREATE TRIGGER trg_tva_regimes_scope_insert
BEFORE INSERT ON tva_regimes
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d WHERE d.id = NEW.dossier_id
          AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope du régime TVA invalide') END;
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM tva_regimes r WHERE r.dossier_id = NEW.dossier_id
          AND COALESCE(r.date_fin, '9999-12-31') >= NEW.date_debut
          AND COALESCE(NEW.date_fin, '9999-12-31') >= r.date_debut
    ) THEN RAISE(ABORT, 'chevauchement de régimes TVA') END;
END;

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

CREATE TRIGGER trg_version_modele_immuable
BEFORE UPDATE ON versions_modeles_exercice WHEN OLD.statut = 'publie' AND (
    NEW.plan_snapshot_json <> OLD.plan_snapshot_json
    OR NEW.soldes_initiaux_json <> OLD.soldes_initiaux_json
    OR NEW.donnees_initiales_json <> OLD.donnees_initiales_json
    OR NEW.consignes <> OLD.consignes
    OR NEW.solution_json <> OLD.solution_json
    OR NEW.regle_correction <> OLD.regle_correction
    OR NEW.valeur_correction <> OLD.valeur_correction
)
BEGIN SELECT RAISE(ABORT, 'version de modèle publiée immuable'); END;

CREATE TRIGGER trg_version_modele_immuable_delete
BEFORE DELETE ON versions_modeles_exercice WHEN OLD.statut = 'publie'
BEGIN SELECT RAISE(ABORT, 'version de modèle publiée non supprimable'); END;

-- ENTITÉS LÉGALES ET CONSOLIDATION

CREATE TABLE attributs_juridiques_organisation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    raison_sociale TEXT NOT NULL,
    forme_juridique TEXT NOT NULL DEFAULT '',
    numero_ide TEXT NOT NULL DEFAULT '',
    adresse_json TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(adresse_json)),
    source TEXT NOT NULL CHECK (length(trim(source)) > 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (organisation_id, date_debut)
);

CREATE TABLE groupes_consolidation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organisation_pilote_id INTEGER NOT NULL
        REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_pilote_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    code TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL,
    devise TEXT NOT NULL CHECK (length(devise) = 3),
    actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN (0, 1)),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (organisation_pilote_id, code)
);

CREATE TABLE membres_groupe_consolidation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    groupe_id INTEGER NOT NULL REFERENCES groupes_consolidation(id) ON DELETE RESTRICT,
    organisation_id INTEGER NOT NULL REFERENCES organisations(id) ON DELETE RESTRICT,
    dossier_id INTEGER NOT NULL REFERENCES dossiers(id) ON DELETE RESTRICT,
    date_debut TEXT NOT NULL,
    date_fin TEXT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (date_fin IS NULL OR date_fin >= date_debut),
    UNIQUE (groupe_id, dossier_id)
);

CREATE TABLE periodes_consolidation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    groupe_id INTEGER NOT NULL REFERENCES groupes_consolidation(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    date_debut TEXT NOT NULL,
    date_fin TEXT NOT NULL,
    statut TEXT NOT NULL DEFAULT 'ouverte'
        CHECK (statut IN ('ouverte', 'cloturee')),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    cloturee_le TEXT,
    cloturee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    CHECK (date_fin >= date_debut),
    UNIQUE (groupe_id, date_debut, date_fin)
);

CREATE TABLE conversions_membres_consolidation (
    periode_id INTEGER NOT NULL
        REFERENCES periodes_consolidation(id) ON DELETE RESTRICT,
    membre_id INTEGER NOT NULL
        REFERENCES membres_groupe_consolidation(id) ON DELETE RESTRICT,
    devise_source TEXT NOT NULL CHECK (length(devise_source) = 3),
    devise_cible TEXT NOT NULL CHECK (length(devise_cible) = 3),
    numerateur INTEGER NOT NULL CHECK (numerateur > 0),
    denominateur INTEGER NOT NULL CHECK (denominateur > 0),
    date_taux TEXT NOT NULL,
    source TEXT NOT NULL CHECK (length(trim(source)) > 0),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    PRIMARY KEY (periode_id, membre_id)
);

CREATE TABLE mappings_comptes_consolidation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    groupe_id INTEGER NOT NULL REFERENCES groupes_consolidation(id) ON DELETE RESTRICT,
    membre_id INTEGER NOT NULL
        REFERENCES membres_groupe_consolidation(id) ON DELETE RESTRICT,
    compte_source_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    compte_cible TEXT NOT NULL COLLATE NOCASE,
    libelle_cible TEXT NOT NULL,
    type_cible TEXT NOT NULL CHECK (
        type_cible IN ('actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan')
    ),
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    modifie_le TEXT,
    modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    version INTEGER NOT NULL DEFAULT 1,
    UNIQUE (groupe_id, membre_id, compte_source_id)
);

CREATE TABLE paires_comptes_interentites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    groupe_id INTEGER NOT NULL REFERENCES groupes_consolidation(id) ON DELETE RESTRICT,
    libelle TEXT NOT NULL,
    membre_gauche_id INTEGER NOT NULL
        REFERENCES membres_groupe_consolidation(id) ON DELETE RESTRICT,
    compte_gauche_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    membre_droite_id INTEGER NOT NULL
        REFERENCES membres_groupe_consolidation(id) ON DELETE RESTRICT,
    compte_droite_id INTEGER NOT NULL REFERENCES comptes(id) ON DELETE RESTRICT,
    cree_le TEXT NOT NULL DEFAULT (datetime('now')),
    cree_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    CHECK (membre_gauche_id <> membre_droite_id),
    UNIQUE (
        groupe_id, membre_gauche_id, compte_gauche_id,
        membre_droite_id, compte_droite_id
    )
);

CREATE TABLE eliminations_consolidation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    periode_id INTEGER NOT NULL
        REFERENCES periodes_consolidation(id) ON DELETE RESTRICT,
    reference TEXT NOT NULL,
    libelle TEXT NOT NULL,
    justification TEXT NOT NULL CHECK (length(trim(justification)) > 0),
    statut TEXT NOT NULL DEFAULT 'brouillon'
        CHECK (statut IN ('brouillon', 'validee')),
    validee_le TEXT,
    validee_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
    UNIQUE (periode_id, reference)
);

CREATE TABLE lignes_elimination_consolidation (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    elimination_id INTEGER NOT NULL
        REFERENCES eliminations_consolidation(id) ON DELETE RESTRICT,
    compte_cible TEXT NOT NULL COLLATE NOCASE,
    libelle TEXT NOT NULL DEFAULT '',
    debit_centimes INTEGER NOT NULL DEFAULT 0 CHECK (debit_centimes >= 0),
    credit_centimes INTEGER NOT NULL DEFAULT 0 CHECK (credit_centimes >= 0),
    ordre INTEGER NOT NULL CHECK (ordre > 0),
    CHECK (
        (debit_centimes > 0 AND credit_centimes = 0)
        OR (credit_centimes > 0 AND debit_centimes = 0)
    ),
    UNIQUE (elimination_id, ordre)
);

CREATE INDEX idx_membres_consolidation_scope
    ON membres_groupe_consolidation (groupe_id, organisation_id, dossier_id);
CREATE INDEX idx_mappings_consolidation_cible
    ON mappings_comptes_consolidation (groupe_id, compte_cible);
CREATE INDEX idx_eliminations_consolidation_periode
    ON eliminations_consolidation (periode_id);

CREATE TRIGGER trg_attributs_juridiques_chevauchement
BEFORE INSERT ON attributs_juridiques_organisation
BEGIN
    SELECT CASE WHEN EXISTS (
        SELECT 1 FROM attributs_juridiques_organisation a
        WHERE a.organisation_id = NEW.organisation_id
          AND COALESCE(a.date_fin, '9999-12-31') >= NEW.date_debut
          AND COALESCE(NEW.date_fin, '9999-12-31') >= a.date_debut
    ) THEN RAISE(ABORT, 'chevauchement d’attributs juridiques') END;
END;

CREATE TRIGGER trg_attributs_juridiques_immuables
BEFORE UPDATE ON attributs_juridiques_organisation
WHEN
    NEW.organisation_id <> OLD.organisation_id
    OR NEW.date_debut <> OLD.date_debut
    OR OLD.date_fin IS NOT NULL
    OR NEW.date_fin IS NULL
    OR NEW.date_fin < NEW.date_debut
    OR NEW.raison_sociale <> OLD.raison_sociale
    OR NEW.forme_juridique <> OLD.forme_juridique
    OR NEW.numero_ide <> OLD.numero_ide
    OR NEW.adresse_json <> OLD.adresse_json
    OR NEW.source <> OLD.source
BEGIN SELECT RAISE(ABORT, 'attribut juridique daté immuable'); END;

CREATE TRIGGER trg_attributs_juridiques_non_supprimables
BEFORE DELETE ON attributs_juridiques_organisation
BEGIN SELECT RAISE(ABORT, 'attribut juridique daté non supprimable'); END;

CREATE TRIGGER trg_groupe_consolidation_scope
BEFORE INSERT ON groupes_consolidation
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        WHERE d.id = NEW.dossier_pilote_id
          AND d.organisation_id = NEW.organisation_pilote_id
    ) THEN RAISE(ABORT, 'scope du groupe de consolidation invalide') END;
END;

CREATE TRIGGER trg_membre_consolidation_scope
BEFORE INSERT ON membres_groupe_consolidation
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1 FROM dossiers d
        WHERE d.id = NEW.dossier_id AND d.organisation_id = NEW.organisation_id
    ) THEN RAISE(ABORT, 'scope du membre de consolidation invalide') END;
END;

CREATE TRIGGER trg_conversion_consolidation_scope
BEFORE INSERT ON conversions_membres_consolidation
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM periodes_consolidation p
        JOIN groupes_consolidation g ON g.id = p.groupe_id
        JOIN membres_groupe_consolidation m
          ON m.id = NEW.membre_id AND m.groupe_id = g.id
        JOIN dossiers d ON d.id = m.dossier_id
        WHERE p.id = NEW.periode_id
          AND d.monnaie = NEW.devise_source
          AND g.devise = NEW.devise_cible
    ) THEN RAISE(ABORT, 'conversion de consolidation hors scope') END;
END;

CREATE TRIGGER trg_mapping_consolidation_scope
BEFORE INSERT ON mappings_comptes_consolidation
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM membres_groupe_consolidation m
        JOIN comptes c ON c.id = NEW.compte_source_id
        WHERE m.id = NEW.membre_id AND m.groupe_id = NEW.groupe_id
          AND c.organisation_id = m.organisation_id
          AND c.dossier_id = m.dossier_id
    ) THEN RAISE(ABORT, 'mapping de consolidation hors scope') END;
END;

CREATE TRIGGER trg_elimination_consolidation_immuable
BEFORE UPDATE ON eliminations_consolidation WHEN OLD.statut = 'validee'
BEGIN SELECT RAISE(ABORT, 'élimination validée immuable'); END;

CREATE TRIGGER trg_elimination_consolidation_non_supprimable
BEFORE DELETE ON eliminations_consolidation
BEGIN SELECT RAISE(ABORT, 'élimination validée non supprimable'); END;

CREATE TRIGGER trg_ligne_elimination_consolidation_immuable
BEFORE UPDATE ON lignes_elimination_consolidation WHEN EXISTS (
    SELECT 1 FROM eliminations_consolidation e
    WHERE e.id = OLD.elimination_id AND e.statut = 'validee'
)
BEGIN SELECT RAISE(ABORT, 'ligne d’élimination validée immuable'); END;

CREATE TRIGGER trg_ligne_elimination_consolidation_non_supprimable
BEFORE DELETE ON lignes_elimination_consolidation WHEN EXISTS (
    SELECT 1 FROM eliminations_consolidation e
    WHERE e.id = OLD.elimination_id AND e.statut = 'validee'
)
BEGIN SELECT RAISE(ABORT, 'ligne d’élimination validée non supprimable'); END;

CREATE TRIGGER trg_ligne_elimination_consolidation_non_ajoutable
BEFORE INSERT ON lignes_elimination_consolidation WHEN EXISTS (
    SELECT 1 FROM eliminations_consolidation e
    WHERE e.id = NEW.elimination_id AND e.statut = 'validee'
)
BEGIN SELECT RAISE(ABORT, 'élimination validée non extensible'); END;

-- RÉFÉRENTIELS INITIAUX

INSERT INTO "roles" ("id", "code", "libelle") VALUES (1, 'administrateur', 'Administrateur');
INSERT INTO "roles" ("id", "code", "libelle") VALUES (2, 'comptable', 'Comptable');
INSERT INTO "roles" ("id", "code", "libelle") VALUES (3, 'gestionnaire_paie', 'Gestionnaire de paie');
INSERT INTO "roles" ("id", "code", "libelle") VALUES (4, 'formateur', 'Formateur');
INSERT INTO "roles" ("id", "code", "libelle") VALUES (5, 'apprenant', 'Apprenant');
INSERT INTO "roles" ("id", "code", "libelle") VALUES (6, 'lecteur', 'Lecteur / auditeur');

INSERT INTO "permissions" ("id", "code", "libelle") VALUES (1, 'installation.admin', 'Administrer l’installation');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (2, 'organisation.view', 'Voir une organisation');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (3, 'organisation.manage', 'Gérer une organisation');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (4, 'dossier.view', 'Voir un dossier');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (5, 'dossier.manage', 'Gérer un dossier');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (6, 'exercice.view', 'Voir un exercice');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (7, 'exercice.manage', 'Gérer un exercice');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (8, 'audit.view', 'Consulter l’audit');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (9, 'compta.view', 'Consulter la comptabilité');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (10, 'compta.edit', 'Saisir des écritures comptables');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (11, 'compta.validate', 'Valider et contre-passer des écritures');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (12, 'compta.setup', 'Configurer le plan, les périodes et les journaux');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (13, 'compta.export', 'Exporter les rapports comptables');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (14, 'tresorerie.view', 'Consulter la trésorerie');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (15, 'tresorerie.import', 'Importer des relevés bancaires');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (16, 'tresorerie.reconcile', 'Confirmer les rapprochements bancaires');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (17, 'tresorerie.setup', 'Configurer les comptes de trésorerie');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (18, 'tva.view', 'Consulter la TVA');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (19, 'tva.setup', 'Configurer la TVA');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (20, 'tva.prepare', 'Préparer un décompte TVA');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (21, 'tva.control', 'Contrôler un décompte TVA');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (22, 'tva.export', 'Exporter un décompte e-TVA');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (23, 'tva.declare', 'Marquer un décompte TVA déclaré');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (24, 'facturation.view', 'Consulter les débiteurs et créanciers');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (25, 'facturation.manage', 'Gérer contacts et brouillons');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (26, 'facturation.issue', 'Émettre des factures clients');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (27, 'facturation.post', 'Comptabiliser les documents');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (28, 'facturation.pay', 'Gérer paiements et allocations');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (29, 'facturation.remind', 'Tracer les rappels');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (30, 'salaires.view', 'Consulter les totaux salariaux');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (31, 'salaires.pii', 'Consulter les données personnelles salariales');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (32, 'salaires.manage', 'Gérer employés, paramètres et brouillons');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (33, 'salaires.validate', 'Valider les fiches de salaire');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (34, 'salaires.post', 'Comptabiliser les fiches de salaire');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (35, 'salaires.pay', 'Gérer paiements et allocations de salaires');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (36, 'salaires.export', 'Imprimer et exporter les certificats salariaux');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (37, 'pedagogie.view', 'Consulter un exercice pédagogique');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (38, 'pedagogie.work', 'Travailler dans un exercice pédagogique');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (39, 'pedagogie.manage', 'Gérer modèles, groupes et assignations');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (40, 'pedagogie.correct', 'Valider les étapes et autoriser les corrections');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (41, 'pedagogie.reset', 'Réinitialiser une copie d’exercice');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (42, 'pedagogie.export', 'Exporter le suivi pédagogique');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (43, 'depenses.view', 'Consulter les dépenses');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (44, 'depenses.manage', 'Gérer les brouillons et récurrences de dépenses');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (45, 'depenses.approve', 'Approuver les dépenses');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (46, 'depenses.post', 'Comptabiliser et contre-passer les dépenses');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (47, 'paiements.prepare', 'Préparer des lots de paiements sortants');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (48, 'paiements.export', 'Générer et télécharger les fichiers pain.001');
INSERT INTO "permissions" ("id", "code", "libelle") VALUES (49, 'paiements.confirm', 'Confirmer les paiements depuis un relevé bancaire');

INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 1);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 2);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 3);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 4);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 5);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 6);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 7);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 8);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 9);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 10);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 11);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 12);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 13);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 14);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 15);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 16);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 17);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 18);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 19);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 20);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 21);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 22);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 23);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 24);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 25);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 26);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 27);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 28);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 29);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 30);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 31);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 32);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 33);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 34);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 35);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 36);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 37);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 38);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 39);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 40);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 41);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 42);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 2);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 4);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 5);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 6);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 7);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 9);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 10);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 11);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 12);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 13);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 14);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 15);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 16);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 17);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 18);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 19);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 20);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 21);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 22);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 24);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 25);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 26);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 27);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 28);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 29);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 30);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 31);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 32);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 33);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 34);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 35);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 36);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (3, 2);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (3, 4);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (3, 6);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 2);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 4);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 5);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 6);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 7);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 9);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 10);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 11);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 12);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 13);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 14);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 15);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 16);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 17);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 18);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 19);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 20);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 21);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 22);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 24);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 25);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 26);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 27);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 28);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 29);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 30);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 32);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 33);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 36);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 37);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 38);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 39);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 40);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 41);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 42);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 2);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 4);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 6);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 9);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 13);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 14);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 18);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 24);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 30);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 37);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (5, 38);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 2);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 4);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 6);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 9);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 13);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 14);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 18);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 24);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 30);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 43);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 44);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 45);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 46);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 43);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 44);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 45);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 46);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 43);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 44);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 45);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 46);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 47);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 48);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (1, 49);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 47);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 48);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (2, 49);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 47);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 48);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (4, 49);
INSERT INTO "role_permissions" ("role_id", "permission_id") VALUES (6, 43);

INSERT INTO "tva_taux_legaux" ("id", "categorie", "libelle", "taux_bp", "date_debut", "date_fin", "source_url", "verifie_le", "cree_le") VALUES (1, 'normal', 'Taux normal', 810, '2024-01-01', NULL, 'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse', '2026-07-25', '2026-07-25 00:00:00');
INSERT INTO "tva_taux_legaux" ("id", "categorie", "libelle", "taux_bp", "date_debut", "date_fin", "source_url", "verifie_le", "cree_le") VALUES (2, 'reduit', 'Taux réduit', 260, '2024-01-01', NULL, 'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse', '2026-07-25', '2026-07-25 00:00:00');
INSERT INTO "tva_taux_legaux" ("id", "categorie", "libelle", "taux_bp", "date_debut", "date_fin", "source_url", "verifie_le", "cree_le") VALUES (3, 'special', 'Taux spécial hébergement', 380, '2024-01-01', NULL, 'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse', '2026-07-25', '2026-07-25 00:00:00');
INSERT INTO "tva_taux_legaux" ("id", "categorie", "libelle", "taux_bp", "date_debut", "date_fin", "source_url", "verifie_le", "cree_le") VALUES (4, 'zero', 'Taux zéro', 0, '2024-01-01', NULL, 'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse', '2026-07-25', '2026-07-25 00:00:00');

INSERT INTO "modules_application" ("code", "libelle", "description", "ordre", "actif_global") VALUES ('apprentissage', 'Apprentissage', 'Exercices et suivi pédagogique ciblé.', 10, 1);
INSERT INTO "modules_application" ("code", "libelle", "description", "ordre", "actif_global") VALUES ('comptabilite', 'Comptabilité', 'Journal, comptes et documents financiers.', 40, 1);
INSERT INTO "modules_application" ("code", "libelle", "description", "ordre", "actif_global") VALUES ('facturation', 'Facturation', 'Factures, avoirs, contacts et échéancier.', 30, 1);
INSERT INTO "modules_application" ("code", "libelle", "description", "ordre", "actif_global") VALUES ('liquidites', 'Liquidités', 'Dépenses, banque, lettrage et paiements.', 20, 1);
INSERT INTO "modules_application" ("code", "libelle", "description", "ordre", "actif_global") VALUES ('salaires', 'Salaires', 'Employés, calculs, fiches et décomptes annuels.', 50, 1);
