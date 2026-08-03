<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class PayrollConfigurationService
{
    public const RATE_FIELDS = [
        'avs_ppm', 'ac_ppm', 'amat_ppm', 'laa_reduit_ppm', 'laa_plein_ppm',
        'lpp_ppm', 'emp_avs_ppm', 'emp_ac_ppm', 'emp_amat_ppm', 'emp_af_ppm',
        'emp_laa_reduit_ppm', 'emp_laa_plein_ppm', 'emp_frais_ppm',
        'emp_cpe_ppm', 'emp_lfp_ppm', 'emp_lpp_ppm',
    ];

    public const MAPPING_FIELDS = [
        'charge_salaires_id', 'charge_ocas_id', 'charge_laa_id', 'charge_lpp_id',
        'dette_net_id', 'dette_ocas_id', 'dette_laa_id', 'dette_lpp_id',
        'dette_impot_id',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function saveEmployer(
        int $organisationId,
        int $dossierId,
        array $data,
        ?int $actorId = null,
    ): int {
        $identity = $this->employerSuggestion($organisationId, $dossierId);
        $name = trim((string) $identity['nom']);
        if ($name === '') {
            throw new PayrollException('Le nom de l’employeur est requis.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO employeurs_salaires
             (organisation_id, dossier_id, nom, rue, npa, localite, pays,
              telephone, email, heures_hebdo_milli, contact_nom, contact_telephone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (dossier_id) DO UPDATE SET
               nom = excluded.nom, rue = excluded.rue, npa = excluded.npa,
               localite = excluded.localite, pays = excluded.pays,
               telephone = excluded.telephone, email = excluded.email,
               heures_hebdo_milli = excluded.heures_hebdo_milli,
               contact_nom = excluded.contact_nom,
               contact_telephone = excluded.contact_telephone,
               modifie_le = datetime(\'now\'), version = version + 1'
        );
        $stmt->execute([
            $organisationId, $dossierId, $name,
            trim((string) $identity['rue']),
            trim((string) $identity['npa']),
            trim((string) $identity['localite']),
            strtoupper(trim((string) $identity['pays'])),
            trim((string) $identity['telephone']),
            trim((string) $identity['email']),
            (int) ($data['heures_hebdo_milli'] ?? 40000),
            trim((string) ($data['contact_nom'] ?? '')),
            trim((string) ($data['contact_telephone'] ?? '')),
        ]);
        $id = (int) $this->pdo->query(
            'SELECT id FROM employeurs_salaires WHERE dossier_id = ' . $dossierId
        )->fetchColumn();
        $this->audit->log(
            'salaires.employeur_enregistre',
            $actorId,
            $organisationId,
            $dossierId,
            'employeur_salaire',
            (string) $id
        );
        return $id;
    }

    /** @return array<string,mixed> */
    public function employer(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM employeurs_salaires
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException(
                'Employeur salarial non configuré. Enregistrez-le dans '
                . 'Configuration → Salaires avant de calculer une fiche.'
            );
        }
        $identity = $this->employerSuggestion($organisationId, $dossierId);
        $identity['heures_hebdo_milli'] = (int) $row['heures_hebdo_milli'];
        return array_replace($row, $identity);
    }

    /** @return array<string,mixed> */
    public function employerSuggestion(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.nom, o.raison_sociale, o.adresse_ligne1,
                    o.adresse_ligne2, o.code_postal, o.localite, o.pays,
                    o.telephone, o.email
             FROM organisations o
             JOIN dossiers d ON d.organisation_id = o.id
             WHERE o.id = ? AND d.id = ?'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Identité légale du dossier introuvable.');
        }
        $address = trim((string) $row['adresse_ligne1']);
        $addressLine2 = trim((string) $row['adresse_ligne2']);
        if ($addressLine2 !== '') {
            $address .= ($address === '' ? '' : ', ') . $addressLine2;
        }
        $legalName = trim((string) $row['raison_sociale']);
        return [
            'nom' => $legalName !== '' ? $legalName : (string) $row['nom'],
            'rue' => $address,
            'npa' => (string) $row['code_postal'],
            'localite' => (string) $row['localite'],
            'pays' => (string) ($row['pays'] ?: 'CH'),
            'telephone' => (string) $row['telephone'],
            'email' => (string) $row['email'],
            'heures_hebdo_milli' => 40000,
            'source' => 'Identité légale de l’organisation',
        ];
    }

    /** @param array<string,mixed> $data */
    public function saveRates(
        int $organisationId,
        int $dossierId,
        int $year,
        array $data,
        ?int $actorId = null,
    ): int {
        if ($year < 2000 || $year > 9999) {
            throw new PayrollException('Année de taux invalide.');
        }
        $values = [];
        foreach (self::RATE_FIELDS as $field) {
            $value = (int) ($data[$field] ?? -1);
            if ($value < 0 || $value > 1_000_000) {
                throw new PayrollException("Taux {$field} invalide.");
            }
            $values[$field] = $value;
        }
        $existing = $this->exactRates($organisationId, $dossierId, $year);
        if ($existing !== null) {
            $sameRates = true;
            foreach (self::RATE_FIELDS as $field) {
                if ((int) $existing[$field] !== $values[$field]) {
                    $sameRates = false;
                    break;
                }
            }
            if (
                $sameRates
                && (string) $existing['source']
                    === trim((string) ($data['source'] ?? ''))
                && (string) $existing['verifie_le']
                    === trim((string) ($data['verifie_le'] ?? ''))
            ) {
                return (int) $existing['id'];
            }
        }
        if ($existing !== null && $this->yearHasFrozenPayroll($dossierId, $year)) {
            foreach (self::RATE_FIELDS as $field) {
                if ((int) $existing[$field] !== $values[$field]) {
                    throw new PayrollException(
                        "Les taux {$year} ont déjà servi à une fiche validée et sont immuables."
                    );
                }
            }
            return (int) $existing['id'];
        }
        $columns = implode(', ', self::RATE_FIELDS);
        $marks = implode(', ', array_fill(0, count(self::RATE_FIELDS), '?'));
        $updates = implode(', ', array_map(
            static fn (string $field): string => "{$field} = excluded.{$field}",
            self::RATE_FIELDS
        ));
        $stmt = $this->pdo->prepare(
            "INSERT INTO taux_salaires_annuels
             (organisation_id, dossier_id, annee, {$columns}, source,
              source_annee, source_empreinte, importe_le, verifie_le, cree_par)
             VALUES (?, ?, ?, {$marks}, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (dossier_id, annee) DO UPDATE SET
               {$updates}, source = excluded.source,
               source_annee = excluded.source_annee,
               source_empreinte = excluded.source_empreinte,
               importe_le = excluded.importe_le,
               verifie_le = excluded.verifie_le,
               modifie_le = datetime('now'), version = version + 1"
        );
        $stmt->execute([
            $organisationId, $dossierId, $year, ...array_values($values),
            trim((string) ($data['source'] ?? '')),
            (int) ($data['source_annee'] ?? $year),
            trim((string) ($data['source_empreinte'] ?? '')),
            ($data['importe_le'] ?? null) === null
                ? null
                : trim((string) $data['importe_le']),
            trim((string) ($data['verifie_le'] ?? '')),
            $actorId,
        ]);
        $idStmt = $this->pdo->prepare(
            'SELECT id FROM taux_salaires_annuels WHERE dossier_id = ? AND annee = ?'
        );
        $idStmt->execute([$dossierId, $year]);
        $id = (int) $idStmt->fetchColumn();
        $this->audit->log(
            'salaires.taux_annuels_enregistres',
            $actorId,
            $organisationId,
            $dossierId,
            'taux_salaires',
            (string) $id,
            ['annee' => $year]
        );
        return $id;
    }

    /** @return array<string,mixed> */
    public function rates(int $organisationId, int $dossierId, int $year): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM taux_salaires_annuels
             WHERE organisation_id = ? AND dossier_id = ? AND annee <= ?
             ORDER BY annee DESC LIMIT 1'
        );
        $stmt->execute([$organisationId, $dossierId, $year]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException(
                "Aucun taux salarial contrôlé n’est disponible pour {$year}."
            );
        }
        $row['_requested_year'] = $year;
        $row['_fallback'] = (int) $row['annee'] !== $year;
        return $row;
    }

    /** @param array<string,mixed> $data */
    public function saveContract(
        int $organisationId,
        int $dossierId,
        array $data,
        ?int $actorId = null,
    ): int {
        $employeeId = (int) ($data['employe_id'] ?? 0);
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $owner = $this->pdo->prepare(
                'SELECT employe_id FROM contrats_salariaux
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $owner->execute([$id, $organisationId, $dossierId]);
            $storedEmployeeId = $owner->fetchColumn();
            if ($storedEmployeeId === false) {
                throw new PayrollException('Contrat absent du dossier.');
            }
            if ((int) $storedEmployeeId !== $employeeId) {
                throw new PayrollException(
                    'L’employé d’un contrat existant ne peut pas être remplacé.'
                );
            }
        } else {
            $this->employee($organisationId, $dossierId, $employeeId);
        }
        $type = (string) ($data['type'] ?? '');
        $start = trim((string) ($data['date_debut'] ?? ''));
        $end = trim((string) ($data['date_fin'] ?? ''));
        $hourly = (int) ($data['taux_horaire_centimes'] ?? 0);
        $monthly = (int) ($data['salaire_mensuel_centimes'] ?? 0);
        if (
            !in_array($type, ['horaire', 'mensuel'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1
            || ($end !== '' && ($end < $start
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1))
            || ($type === 'horaire' && ($hourly < 1 || $monthly !== 0))
            || ($type === 'mensuel' && ($monthly < 1 || $hourly !== 0))
        ) {
            throw new PayrollException('Contrat salarial invalide.');
        }
        if ($id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE contrats_salariaux SET type = ?, date_debut = ?,
                 date_fin = ?, taux_horaire_centimes = ?,
                 salaire_mensuel_centimes = ?, heures_hebdo_milli = ?,
                 taux_activite_ppm = ?, source = ?, actif = ?,
                 modifie_le = datetime(\'now\'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ?'
            );
            $stmt->execute([
                $type, $start, $end === '' ? null : $end, $hourly, $monthly,
                (int) ($data['heures_hebdo_milli'] ?? 40000),
                (int) ($data['taux_activite_ppm'] ?? 1_000_000),
                trim((string) ($data['source'] ?? '')), (int) ($data['actif'] ?? 1),
                $id, $organisationId, $dossierId, (int) ($data['version'] ?? 0),
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollException('Contrat modifié simultanément.');
            }
            $action = 'salaires.contrat_modifie';
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO contrats_salariaux
                 (organisation_id, dossier_id, employe_id, type, date_debut,
                  date_fin, taux_horaire_centimes, salaire_mensuel_centimes,
                  heures_hebdo_milli, taux_activite_ppm, source, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $employeeId, $type, $start,
                $end === '' ? null : $end, $hourly, $monthly,
                (int) ($data['heures_hebdo_milli'] ?? 40000),
                (int) ($data['taux_activite_ppm'] ?? 1_000_000),
                trim((string) ($data['source'] ?? '')), $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $action = 'salaires.contrat_cree';
        }
        $this->audit->log(
            $action,
            $actorId,
            $organisationId,
            $dossierId,
            'contrat_salarial',
            (string) $id,
            ['employe_id' => $employeeId, 'type' => $type, 'date_debut' => $start]
        );
        return $id;
    }

    public function deleteContract(
        int $organisationId,
        int $dossierId,
        int $contractId,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $contractId,
            $expectedVersion,
            $actorId
        ): void {
            $stmt = $this->pdo->prepare(
                'SELECT employe_id, type, date_debut, version
                 FROM contrats_salariaux
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $stmt->execute([$contractId, $organisationId, $dossierId]);
            $contract = $stmt->fetch();
            if ($contract === false) {
                throw new PayrollException('Contrat absent du dossier.');
            }
            if ((int) $contract['version'] !== $expectedVersion) {
                throw new PayrollException('Contrat modifié simultanément.');
            }
            $used = $this->pdo->prepare(
                "SELECT 1 FROM fiches_salaires
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND CAST(json_extract(
                     contrat_snapshot_json, '$.id'
                   ) AS INTEGER) = ?
                 LIMIT 1"
            );
            $used->execute([$organisationId, $dossierId, $contractId]);
            if ($used->fetchColumn() !== false) {
                throw new PayrollException(
                    'Ce contrat a déjà servi à calculer une fiche. Désactivez-le '
                    . 'pour préserver la traçabilité.'
                );
            }
            $delete = $this->pdo->prepare(
                'DELETE FROM contrats_salariaux
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ?'
            );
            $delete->execute([
                $contractId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($delete->rowCount() !== 1) {
                throw new PayrollException('Contrat modifié simultanément.');
            }
            $this->audit->log(
                'salaires.contrat_supprime',
                $actorId,
                $organisationId,
                $dossierId,
                'contrat_salarial',
                (string) $contractId,
                [
                    'employe_id' => (int) $contract['employe_id'],
                    'type' => (string) $contract['type'],
                    'date_debut' => (string) $contract['date_debut'],
                ]
            );
        });
    }

    /** @return array<string,mixed> */
    public function contractForPeriod(
        int $organisationId,
        int $dossierId,
        int $employeeId,
        int $year,
        int $month,
    ): array {
        $date = sprintf('%04d-%02d-01', $year, $month);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM contrats_salariaux
             WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?
               AND actif = 1 AND date_debut <= ?
               AND (date_fin IS NULL OR date_fin >= ?)
             ORDER BY date_debut DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$organisationId, $dossierId, $employeeId, $date, $date]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Aucun contrat actif pour cette période.');
        }
        return $row;
    }

    /** @param array<string,mixed> $data */
    public function createEmployee(
        int $organisationId,
        int $dossierId,
        array $data,
        ?int $actorId = null,
    ): int {
        $data['id'] = 0;
        return $this->saveEmployee(
            $organisationId,
            $dossierId,
            $data,
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function saveEmployee(
        int $organisationId,
        int $dossierId,
        array $data,
        ?int $actorId = null,
    ): int {
        $firstName = trim((string) ($data['prenom'] ?? ''));
        $lastName = trim((string) ($data['nom'] ?? ''));
        $avs = $this->normalizeAvs((string) ($data['numero_avs'] ?? ''));
        $procedure = (string) ($data['procedure'] ?? 'ordinaire');
        if (
            $firstName === ''
            || $lastName === ''
            || !in_array($procedure, [
                'ordinaire', 'simplifiee', 'ordinaire_impot_source',
            ], true)
        ) {
            throw new PayrollException('Identité ou procédure de l’employé invalide.');
        }
        $values = [
            $firstName,
            $lastName,
            trim((string) ($data['email'] ?? '')),
            trim((string) ($data['rue'] ?? '')),
            trim((string) ($data['npa'] ?? '')),
            trim((string) ($data['localite'] ?? '')),
            $this->formatAvs($avs), $avs,
            trim((string) ($data['date_naissance'] ?? '')),
            $procedure,
            (int) ($data['supplement_vacances_ppm'] ?? 83300),
            (int) ($data['impot_source_ppm'] ?? 0),
            ($data['lpp_ppm'] ?? null) === null ? null : (int) $data['lpp_ppm'],
            ($data['emp_lpp_ppm'] ?? null) === null
                ? null
                : (int) $data['emp_lpp_ppm'],
            (int) ($data['actif'] ?? 1),
        ];
        $id = (int) ($data['id'] ?? 0);
        if ($id > 0) {
            $link = $this->pdo->prepare(
                'SELECT contact_id FROM employes
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $link->execute([$id, $organisationId, $dossierId]);
            $contactId = $link->fetchColumn();
            $stmt = $this->pdo->prepare(
                "UPDATE employes SET prenom = ?, nom = ?, email = ?, rue = ?,
                    npa = ?, localite = ?, numero_avs = ?,
                    numero_avs_normalise = ?, date_naissance = ?,
                    procedure = ?, supplement_vacances_ppm = ?,
                    impot_source_ppm = ?, lpp_ppm = ?, emp_lpp_ppm = ?,
                    actif = ?, profil_incomplet = 0,
                    modifie_le = datetime('now'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ?"
            );
            $stmt->execute([
                ...$values,
                $id,
                $organisationId,
                $dossierId,
                (int) ($data['version'] ?? 0),
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new PayrollException('Employé modifié simultanément.');
            }
            $action = 'salaires.employe_modifie';
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO employes
                 (organisation_id, dossier_id, prenom, nom, email, rue, npa,
                  localite, numero_avs, numero_avs_normalise, date_naissance,
                  canton, procedure, supplement_vacances_ppm, impot_source_ppm,
                  lpp_ppm, emp_lpp_ppm, actif, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'GE\', ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId,
                $dossierId,
                ...$values,
                $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $action = 'salaires.employe_cree';
        }
        $this->audit->log(
            $action,
            $actorId,
            $organisationId,
            $dossierId,
            'employe',
            (string) $id
        );
        return $id;
    }

    /**
     * Rend immédiatement disponible dans Salaires un contact portant le rôle
     * « employé ». Le profil reste explicitement incomplet jusqu'à la saisie
     * d'un véritable numéro AVS depuis l'écran Salaires.
     *
     * @param array<string,mixed> $contact
     */
    public function syncContactEmployee(
        int $organisationId,
        int $dossierId,
        int $contactId,
        array $contact,
        bool $hasEmployeeRole,
        ?int $actorId = null,
    ): void {
        $existing = $this->pdo->prepare(
            'SELECT id, profil_incomplet FROM employes
             WHERE organisation_id = ? AND dossier_id = ? AND contact_id = ?'
        );
        $existing->execute([$organisationId, $dossierId, $contactId]);
        $employee = $existing->fetch();
        if (!$hasEmployeeRole) {
            if ($employee !== false) {
                $this->pdo->prepare(
                    "UPDATE employes SET actif = 0, modifie_le = datetime('now'),
                            version = version + 1
                     WHERE id = ?"
                )->execute([(int) $employee['id']]);
            }
            return;
        }
        if (($contact['type_personne'] ?? '') !== 'personne') {
            throw new PayrollException(
                'Le rôle employé ne peut être attribué qu’à une personne.'
            );
        }
        $firstName = trim((string) ($contact['prenom'] ?? ''));
        $lastName = trim((string) ($contact['nom'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            throw new PayrollException(
                'Le prénom et le nom sont requis pour créer le profil salarié.'
            );
        }
        if ($employee === false) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO employes
                 (organisation_id, dossier_id, contact_id, prenom, nom, email,
                  rue, npa, localite, numero_avs, numero_avs_normalise,
                  canton, actif, profil_incomplet, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'\', ?, \'GE\', 1, 1, ?)'
            );
            $stmt->execute([
                $organisationId,
                $dossierId,
                $contactId,
                $firstName,
                $lastName,
                trim((string) ($contact['email'] ?? '')),
                trim((string) ($contact['rue'] ?? '')),
                trim((string) ($contact['npa'] ?? '')),
                trim((string) ($contact['localite'] ?? '')),
                'contact:' . $contactId,
                $actorId,
            ]);
            return;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE employes
             SET prenom = ?, nom = ?, email = ?, rue = ?, npa = ?, localite = ?,
                 actif = 1, modifie_le = datetime('now'), version = version + 1
             WHERE id = ?"
        );
        $stmt->execute([
            $firstName,
            $lastName,
            trim((string) ($contact['email'] ?? '')),
            trim((string) ($contact['rue'] ?? '')),
            trim((string) ($contact['npa'] ?? '')),
            trim((string) ($contact['localite'] ?? '')),
            (int) $employee['id'],
        ]);
    }

    public function deleteEmployee(
        int $organisationId,
        int $dossierId,
        int $employeeId,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $employeeId,
            $expectedVersion,
            $actorId
        ): void {
            $stmt = $this->pdo->prepare(
                'SELECT prenom, nom, version, contact_id FROM employes
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $stmt->execute([$employeeId, $organisationId, $dossierId]);
            $employee = $stmt->fetch();
            if ($employee === false) {
                throw new PayrollException('Employé absent du dossier.');
            }
            if ((int) $employee['version'] !== $expectedVersion) {
                throw new PayrollException('Employé modifié simultanément.');
            }
            if ($employee['contact_id'] !== null) {
                throw new PayrollException(
                    'Cet employé provient des référentiels. Retirez son rôle '
                    . '« employé » dans Configuration → Débiteurs et créanciers.'
                );
            }
            $used = $this->pdo->prepare(
                'SELECT 1 FROM fiches_salaires
                 WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?
                 LIMIT 1'
            );
            $used->execute([$organisationId, $dossierId, $employeeId]);
            if ($used->fetchColumn() !== false) {
                throw new PayrollException(
                    'Cet employé possède des fiches de salaire. Désactivez-le '
                    . 'pour préserver son historique.'
                );
            }
            $this->pdo->prepare(
                'DELETE FROM contrats_salariaux
                 WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?'
            )->execute([$organisationId, $dossierId, $employeeId]);
            $delete = $this->pdo->prepare(
                'DELETE FROM employes
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ?'
            );
            $delete->execute([
                $employeeId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($delete->rowCount() !== 1) {
                throw new PayrollException('Employé modifié simultanément.');
            }
            $this->audit->log(
                'salaires.employe_supprime',
                $actorId,
                $organisationId,
                $dossierId,
                'employe',
                (string) $employeeId,
                ['nom' => trim($employee['prenom'] . ' ' . $employee['nom'])]
            );
        });
    }

    /** @return list<array<string,mixed>> */
    public function employees(
        int $organisationId,
        int $dossierId,
        bool $revealPii = false,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM employes
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY actif DESC, nom, prenom'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $rows = $stmt->fetchAll();
        if (!$revealPii) {
            foreach ($rows as &$row) {
                $row['numero_avs'] = $this->maskAvs((string) $row['numero_avs']);
                $row['email'] = '';
                $row['rue'] = '';
                $row['date_naissance'] = '';
            }
            unset($row);
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public function employee(
        int $organisationId,
        int $dossierId,
        int $employeeId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM employes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ? AND actif = 1'
        );
        $stmt->execute([$employeeId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Employé absent du dossier.');
        }
        return $row;
    }

    public function addUnit(
        int $organisationId,
        int $dossierId,
        string $label,
        int $hoursMilli,
    ): int {
        if (trim($label) === '' || $hoursMilli <= 0) {
            throw new PayrollException('Unité de prestation invalide.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO unites_prestation
             (organisation_id, dossier_id, libelle, heures_milli, ordre)
             VALUES (?, ?, ?, ?, COALESCE((
                 SELECT MAX(ordre) + 1 FROM unites_prestation WHERE dossier_id = ?
             ), 0))'
        );
        $stmt->execute([
            $organisationId, $dossierId, trim($label), $hoursMilli, $dossierId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function addTariff(
        int $organisationId,
        int $dossierId,
        string $label,
        int $hourlyCents,
    ): int {
        if (trim($label) === '' || $hourlyCents <= 0) {
            throw new PayrollException('Tarif salarial invalide.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO tarifs_salaires
             (organisation_id, dossier_id, libelle, montant_horaire_centimes, ordre)
             VALUES (?, ?, ?, ?, COALESCE((
                 SELECT MAX(ordre) + 1 FROM tarifs_salaires WHERE dossier_id = ?
             ), 0))'
        );
        $stmt->execute([
            $organisationId, $dossierId, trim($label), $hourlyCents, $dossierId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,int> $mapping */
    public function saveMapping(
        int $organisationId,
        int $dossierId,
        array $mapping,
        ?int $actorId = null,
    ): void {
        $values = [];
        foreach (self::MAPPING_FIELDS as $field) {
            $values[$field] = (int) ($mapping[$field] ?? 0);
            if ($values[$field] < 1) {
                throw new PayrollException("Compte de mapping {$field} requis.");
            }
        }
        $columns = implode(', ', self::MAPPING_FIELDS);
        $marks = implode(', ', array_fill(0, count(self::MAPPING_FIELDS), '?'));
        $updates = implode(', ', array_map(
            static fn (string $field): string => "{$field} = excluded.{$field}",
            self::MAPPING_FIELDS
        ));
        $this->pdo->prepare(
            "INSERT INTO mapping_comptes_salaires
             (dossier_id, organisation_id, {$columns})
             VALUES (?, ?, {$marks})
             ON CONFLICT (dossier_id) DO UPDATE SET
               {$updates}, modifie_le = datetime('now'), version = version + 1"
        )->execute([$dossierId, $organisationId, ...array_values($values)]);
        $this->audit->log(
            'salaires.mapping_comptable_enregistre',
            $actorId,
            $organisationId,
            $dossierId,
            'mapping_salaires',
            (string) $dossierId
        );
    }

    /** @return array<string,mixed> */
    public function mapping(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mapping_comptes_salaires
             WHERE organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Mapping comptable des salaires non configuré.');
        }
        return $row;
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function catalog(int $organisationId, int $dossierId): array
    {
        $units = $this->pdo->prepare(
            'SELECT * FROM unites_prestation
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
             ORDER BY ordre, id'
        );
        $units->execute([$organisationId, $dossierId]);
        $tariffs = $this->pdo->prepare(
            'SELECT * FROM tarifs_salaires
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
             ORDER BY ordre, montant_horaire_centimes'
        );
        $tariffs->execute([$organisationId, $dossierId]);
        $accounts = $this->pdo->prepare(
            'SELECT id, numero, libelle FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1
             ORDER BY length(numero), numero'
        );
        $accounts->execute([$organisationId, $dossierId]);
        $rates = $this->pdo->prepare(
            'SELECT * FROM taux_salaires_annuels
             WHERE organisation_id = ? AND dossier_id = ? ORDER BY annee DESC'
        );
        $rates->execute([$organisationId, $dossierId]);
        $exercises = $this->pdo->prepare(
            'SELECT id, libelle, date_debut, date_fin FROM exercices
             WHERE dossier_id = ? ORDER BY date_debut DESC'
        );
        $exercises->execute([$dossierId]);
        $journals = $this->pdo->prepare(
            'SELECT id, code, libelle FROM journaux
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
             ORDER BY code'
        );
        $journals->execute([$organisationId, $dossierId]);
        $contracts = $this->pdo->prepare(
            'SELECT * FROM contrats_salariaux
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY date_debut DESC, id DESC'
        );
        $contracts->execute([$organisationId, $dossierId]);
        $treasury = $this->pdo->prepare(
            "SELECT t.id, t.compte_comptable_id AS ledger_account_id,
                    c.numero, t.libelle, t.type, t.monnaie
             FROM comptes_tresorerie t
             JOIN comptes c ON c.id = t.compte_comptable_id
             WHERE t.organisation_id = ? AND t.dossier_id = ?
               AND t.actif = 1 AND c.actif = 1 AND c.imputable = 1
             ORDER BY t.libelle COLLATE NOCASE, t.id"
        );
        $treasury->execute([$organisationId, $dossierId]);
        return [
            'units' => $units->fetchAll(),
            'tariffs' => $tariffs->fetchAll(),
            'accounts' => $accounts->fetchAll(),
            'rates' => $rates->fetchAll(),
            'exercises' => $exercises->fetchAll(),
            'journals' => $journals->fetchAll(),
            'contracts' => $contracts->fetchAll(),
            'treasury_accounts' => $treasury->fetchAll(),
        ];
    }

    /** @return ?array<string,mixed> */
    private function exactRates(
        int $organisationId,
        int $dossierId,
        int $year,
    ): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM taux_salaires_annuels
             WHERE organisation_id = ? AND dossier_id = ? AND annee = ?'
        );
        $stmt->execute([$organisationId, $dossierId, $year]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function yearHasFrozenPayroll(int $dossierId, int $year): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM fiches_salaires
             WHERE dossier_id = ? AND annee = ?
               AND statut IN ('validee', 'comptabilisee', 'payee', 'annulee')
             LIMIT 1"
        );
        $stmt->execute([$dossierId, $year]);
        return $stmt->fetchColumn() !== false;
    }

    private function normalizeAvs(string $avs): string
    {
        $digits = (string) preg_replace('/\D+/', '', $avs);
        if (preg_match('/^756\d{10}$/', $digits) !== 1) {
            throw new PayrollException('Numéro AVS invalide.');
        }
        return $digits;
    }

    private function formatAvs(string $digits): string
    {
        return substr($digits, 0, 3) . '.' . substr($digits, 3, 4)
            . '.' . substr($digits, 7, 4) . '.' . substr($digits, 11, 2);
    }

    private function maskAvs(string $avs): string
    {
        $digits = (string) preg_replace('/\D+/', '', $avs);
        return strlen($digits) === 13
            ? '756.****.****.' . substr($digits, -2)
            : '***';
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
