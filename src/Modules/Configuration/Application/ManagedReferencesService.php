<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Application;

use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Tva\VatConfigurationService;
use PDO;

final class ManagedReferencesService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ContactService $contacts,
        private readonly VatConfigurationService $vat,
        private readonly PayrollConfigurationService $payroll,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(int $organisationId, int $dossierId): array
    {
        return [
            'contacts' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'type' => (string) $row['type_personne'],
                    'company' => (string) $row['raison_sociale'],
                    'first_name' => (string) $row['prenom'],
                    'last_name' => (string) $row['nom'],
                    'email' => (string) $row['email'],
                    'phone' => (string) $row['telephone'],
                    'language' => (string) $row['langue'],
                    'roles' => array_values(array_filter(
                        explode(',', (string) $row['roles'])
                    )),
                    'address_line1' => (string) ($row['ligne1'] ?? ''),
                    'address_line2' => (string) ($row['ligne2'] ?? ''),
                    'postal_code' => (string) ($row['code_postal'] ?? ''),
                    'city' => (string) ($row['localite'] ?? ''),
                    'country' => (string) ($row['pays'] ?? 'CH'),
                    'version' => (int) $row['version'],
                ],
                $this->contacts->all($organisationId, $dossierId)
            ),
            'vat' => [
                'codes' => $this->vatCodes($organisationId, $dossierId),
                'legal_rates' => $this->legalVatRates(),
                'accounts' => $this->accounts($organisationId, $dossierId),
            ],
            'payroll' => [
                'rates' => $this->payrollRates($organisationId, $dossierId),
                'fields' => PayrollConfigurationService::RATE_FIELDS,
                'suggested_rates' => [
                    'year' => 2026,
                    'avs_ppm' => 53000,
                    'ac_ppm' => 11000,
                    'amat_ppm' => 290,
                    'laa_reduit_ppm' => 5300,
                    'laa_plein_ppm' => 9600,
                    'lpp_ppm' => 70000,
                    'emp_avs_ppm' => 53000,
                    'emp_ac_ppm' => 11000,
                    'emp_amat_ppm' => 290,
                    'emp_af_ppm' => 22200,
                    'emp_laa_reduit_ppm' => 5300,
                    'emp_laa_plein_ppm' => 9600,
                    'emp_frais_ppm' => 0,
                    'emp_cpe_ppm' => 700,
                    'emp_lfp_ppm' => 820,
                    'emp_lpp_ppm' => 80000,
                    'source' => 'Lasso — TAUX_DEFAUT / OCAS Genève 2026',
                    'verified_on' => '2026-07-25',
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $data */
    public function createContact(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        if ($data['id'] > 0) {
            $this->contacts->update(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                [
                    'type_personne' => $data['type'],
                    'raison_sociale' => $data['company'],
                    'prenom' => $data['first_name'],
                    'nom' => $data['last_name'],
                    'email' => $data['email'],
                    'telephone' => $data['phone'],
                    'langue' => $data['language'],
                ],
                $data['roles'],
                [
                    'ligne1' => $data['address_line1'],
                    'ligne2' => $data['address_line2'],
                    'code_postal' => $data['postal_code'],
                    'localite' => $data['city'],
                    'pays' => $data['country'],
                ],
                $actorId
            );
            return $data['id'];
        }
        return $this->contacts->create(
            $organisationId,
            $dossierId,
            [
                'type_personne' => $data['type'],
                'raison_sociale' => $data['company'],
                'prenom' => $data['first_name'],
                'nom' => $data['last_name'],
                'email' => $data['email'],
                'telephone' => $data['phone'],
                'langue' => $data['language'],
            ],
            $data['roles'],
            [
                'ligne1' => $data['address_line1'],
                'ligne2' => $data['address_line2'],
                'code_postal' => $data['postal_code'],
                'localite' => $data['city'],
                'pays' => $data['country'],
                'type' => 'facturation',
            ],
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function createVatCode(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        return $this->vat->addCode([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'code' => $data['code'],
            'libelle' => $data['label'],
            'traitement' => $data['treatment'],
            'nature' => $data['nature'],
            'taux_legal_id' => $data['legal_rate_id'],
            'droit_deduction' => $data['deduction_right'],
            'deduction_defaut_bp' => $data['default_deduction_bp'],
            'chiffre_afc' => $data['afc_box'],
            'compte_tva_id' => $data['account_id'],
            'date_debut' => $data['valid_from'],
            'date_fin' => $data['valid_until'],
        ], $actorId);
    }

    /** @param array<string,mixed> $data */
    public function savePayrollRates(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        return $this->payroll->saveRates(
            $organisationId,
            $dossierId,
            $data['year'],
            $data,
            $actorId
        );
    }

    /** @return list<array<string,mixed>> */
    private function vatCodes(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, r.libelle AS taux_libelle, r.taux_bp,
                    a.numero AS compte_numero, a.libelle AS compte_libelle
             FROM tva_codes c
             LEFT JOIN tva_taux_legaux r ON r.id = c.taux_legal_id
             LEFT JOIN comptes a ON a.id = c.compte_tva_id
             WHERE c.organisation_id = ? AND c.dossier_id = ?
             ORDER BY c.actif DESC, c.code, c.date_debut DESC'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
            'treatment' => (string) $row['traitement'],
            'nature' => (string) $row['nature'],
            'legal_rate_id' => $row['taux_legal_id'] === null
                ? null
                : (int) $row['taux_legal_id'],
            'legal_rate_label' => (string) ($row['taux_libelle'] ?? ''),
            'rate_bp' => $row['taux_bp'] === null ? null : (int) $row['taux_bp'],
            'deduction_right' => (int) $row['droit_deduction'] === 1,
            'default_deduction_bp' => (int) $row['deduction_defaut_bp'],
            'afc_box' => (string) $row['chiffre_afc'],
            'account_id' => $row['compte_tva_id'] === null
                ? null
                : (int) $row['compte_tva_id'],
            'account' => trim(
                (string) ($row['compte_numero'] ?? '') . ' '
                . (string) ($row['compte_libelle'] ?? '')
            ),
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null
                ? null
                : (string) $row['date_fin'],
            'active' => (int) $row['actif'] === 1,
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function legalVatRates(): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'category' => (string) $row['categorie'],
            'label' => (string) $row['libelle'],
            'rate_bp' => (int) $row['taux_bp'],
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null
                ? null
                : (string) $row['date_fin'],
            'source_url' => (string) $row['source_url'],
            'verified_on' => (string) $row['verifie_le'],
        ], $this->pdo->query(
            'SELECT * FROM tva_taux_legaux
             ORDER BY date_debut DESC, taux_bp DESC'
        )->fetchAll());
    }

    /** @return list<array{id:int,number:string,label:string}> */
    private function accounts(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, numero, libelle FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1
             ORDER BY numero'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'number' => (string) $row['numero'],
            'label' => (string) $row['libelle'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function payrollRates(int $organisationId, int $dossierId): array
    {
        $columns = implode(', ', PayrollConfigurationService::RATE_FIELDS);
        $stmt = $this->pdo->prepare(
            "SELECT id, annee, {$columns}, source, verifie_le, cree_le,
                    modifie_le, version
             FROM taux_salaires_annuels
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY annee DESC"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static function (array $row): array {
            $result = [
                'id' => (int) $row['id'],
                'year' => (int) $row['annee'],
                'source' => (string) $row['source'],
                'verified_on' => (string) $row['verifie_le'],
                'created_at' => (string) $row['cree_le'],
                'updated_at' => $row['modifie_le'] === null
                    ? null
                    : (string) $row['modifie_le'],
                'version' => (int) $row['version'],
            ];
            foreach (PayrollConfigurationService::RATE_FIELDS as $field) {
                $result[$field] = (int) $row[$field];
            }
            return $result;
        }, $stmt->fetchAll());
    }
}
