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
        $name = trim((string) ($data['nom'] ?? ''));
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
            trim((string) ($data['rue'] ?? '')),
            trim((string) ($data['npa'] ?? '')),
            trim((string) ($data['localite'] ?? '')),
            strtoupper(trim((string) ($data['pays'] ?? 'CH'))),
            trim((string) ($data['telephone'] ?? '')),
            trim((string) ($data['email'] ?? '')),
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
            throw new PayrollException('Employeur salarial non configuré.');
        }
        return $row;
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
        $columns = implode(', ', self::RATE_FIELDS);
        $marks = implode(', ', array_fill(0, count(self::RATE_FIELDS), '?'));
        $updates = implode(', ', array_map(
            static fn (string $field): string => "{$field} = excluded.{$field}",
            self::RATE_FIELDS
        ));
        $stmt = $this->pdo->prepare(
            "INSERT INTO taux_salaires_annuels
             (organisation_id, dossier_id, annee, {$columns}, source, verifie_le, cree_par)
             VALUES (?, ?, ?, {$marks}, ?, ?, ?)
             ON CONFLICT (dossier_id, annee) DO UPDATE SET
               {$updates}, source = excluded.source, verifie_le = excluded.verifie_le,
               modifie_le = datetime('now'), version = version + 1"
        );
        $stmt->execute([
            $organisationId, $dossierId, $year, ...array_values($values),
            trim((string) ($data['source'] ?? '')),
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
             WHERE organisation_id = ? AND dossier_id = ? AND annee = ?'
        );
        $stmt->execute([$organisationId, $dossierId, $year]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException(
                "Les taux salariaux {$year} doivent être configurés explicitement."
            );
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
        $stmt = $this->pdo->prepare(
            'INSERT INTO employes
             (organisation_id, dossier_id, prenom, nom, email, rue, npa,
              localite, numero_avs, numero_avs_normalise, date_naissance,
              canton, procedure, supplement_vacances_ppm, impot_source_ppm,
              cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'GE\', ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $firstName, $lastName,
            trim((string) ($data['email'] ?? '')),
            trim((string) ($data['rue'] ?? '')),
            trim((string) ($data['npa'] ?? '')),
            trim((string) ($data['localite'] ?? '')),
            $this->formatAvs($avs), $avs,
            trim((string) ($data['date_naissance'] ?? '')),
            $procedure,
            (int) ($data['supplement_vacances_ppm'] ?? 83300),
            (int) ($data['impot_source_ppm'] ?? 0),
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'salaires.employe_cree',
            $actorId,
            $organisationId,
            $dossierId,
            'employe',
            (string) $id
        );
        return $id;
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
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
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
        return [
            'units' => $units->fetchAll(),
            'tariffs' => $tariffs->fetchAll(),
            'accounts' => $accounts->fetchAll(),
            'rates' => $rates->fetchAll(),
            'exercises' => $exercises->fetchAll(),
            'journals' => $journals->fetchAll(),
        ];
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
}
