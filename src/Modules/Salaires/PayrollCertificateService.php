<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

use Compta\Core\Audit\AuditLogger;
use PDO;

final class PayrollCertificateService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function annualData(
        int $organisationId,
        int $dossierId,
        int $employeeId,
        int $year,
        bool $revealPii = false,
    ): array {
        $employee = $this->employee(
            $organisationId,
            $dossierId,
            $employeeId
        );
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS nombre_fiches,
                    COALESCE(SUM(brut_centimes), 0) AS brut_centimes,
                    COALESCE(SUM(net_centimes), 0) AS net_centimes,
                    COALESCE(SUM(ded_avs_centimes + ded_ac_centimes
                      + ded_amat_centimes + ded_laa_centimes), 0)
                      AS cotisations_sociales_centimes,
                    COALESCE(SUM(ded_lpp_centimes), 0) AS lpp_centimes,
                    COALESCE(SUM(ded_impot_source_centimes), 0)
                      AS impot_source_centimes
             FROM fiches_salaires
             WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?
               AND annee = ? AND statut IN ('validee', 'comptabilisee', 'payee')"
        );
        $stmt->execute([$organisationId, $dossierId, $employeeId, $year]);
        $totals = $stmt->fetch();
        if ($totals === false || (int) $totals['nombre_fiches'] === 0) {
            throw new PayrollException('Aucune fiche validée pour ce certificat.');
        }
        if (!$revealPii) {
            $employee['numero_avs'] = $this->maskAvs((string) $employee['numero_avs']);
            $employee['rue'] = '';
            $employee['date_naissance'] = '';
        }
        return [
            'annee' => $year,
            'employe' => $employee,
            'totaux' => $totals,
            'portee' => 'Genève',
            'format' => 'export interne portable non transmis',
        ];
    }

    public function generateXml(
        int $organisationId,
        int $dossierId,
        int $employeeId,
        int $year,
        ?int $actorId = null,
    ): string {
        $data = $this->annualData(
            $organisationId,
            $dossierId,
            $employeeId,
            $year,
            true
        );
        $employee = $data['employe'];
        $totals = $data['totaux'];
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<certificatSalaire version="interne-1" canton="GE" transmis="false">'
            . '<annee>' . (int) $year . '</annee>'
            . '<employe><prenom>' . $this->xml((string) $employee['prenom'])
            . '</prenom><nom>' . $this->xml((string) $employee['nom'])
            . '</nom><avs>' . $this->xml((string) $employee['numero_avs'])
            . '</avs></employe>'
            . '<montants devise="CHF" unite="centime">'
            . '<brut>' . (int) $totals['brut_centimes'] . '</brut>'
            . '<net>' . (int) $totals['net_centimes'] . '</net>'
            . '<cotisationsSociales>'
            . (int) $totals['cotisations_sociales_centimes']
            . '</cotisationsSociales><lpp>' . (int) $totals['lpp_centimes']
            . '</lpp><impotSource>' . (int) $totals['impot_source_centimes']
            . '</impotSource></montants>'
            . '<avertissement>Export interne à contrôler, non transmis et non certifié.</avertissement>'
            . '</certificatSalaire>';
        $snapshot = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO certificats_salaires
             (organisation_id, dossier_id, employe_id, annee,
              donnees_snapshot_json, xml_archive, empreinte_sha256, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (dossier_id, employe_id, annee) DO UPDATE SET
               donnees_snapshot_json = excluded.donnees_snapshot_json,
               xml_archive = excluded.xml_archive,
               empreinte_sha256 = excluded.empreinte_sha256,
               statut = \'prepare\', controle_le = NULL, controle_par = NULL,
               exporte_le = NULL, exporte_par = NULL, cree_le = datetime(\'now\'),
               cree_par = excluded.cree_par'
        );
        $stmt->execute([
            $organisationId, $dossierId, $employeeId, $year,
            $snapshot, $xml, hash('sha256', $xml), $actorId,
        ]);
        $this->audit->log(
            'salaires.certificat_genere',
            $actorId,
            $organisationId,
            $dossierId,
            'certificat_salaire',
            $employeeId . ':' . $year
        );
        return $xml;
    }

    public function control(
        int $organisationId,
        int $dossierId,
        int $employeeId,
        int $year,
        ?int $actorId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE certificats_salaires SET statut = 'controle',
                controle_le = datetime('now'), controle_par = ?
             WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?
               AND annee = ? AND statut IN ('prepare', 'controle')"
        );
        $stmt->execute([
            $actorId, $organisationId, $dossierId, $employeeId, $year,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new PayrollException('Certificat préparé absent ou déjà exporté.');
        }
        $this->audit->log(
            'salaires.certificat_controle',
            $actorId,
            $organisationId,
            $dossierId,
            'certificat_salaire',
            $employeeId . ':' . $year
        );
    }

    public function export(
        int $organisationId,
        int $dossierId,
        int $employeeId,
        int $year,
        ?int $actorId = null,
    ): string {
        $stmt = $this->pdo->prepare(
            "SELECT id, xml_archive FROM certificats_salaires
             WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?
               AND annee = ? AND statut IN ('controle', 'exporte')"
        );
        $stmt->execute([$organisationId, $dossierId, $employeeId, $year]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Le certificat doit être contrôlé avant export.');
        }
        $this->pdo->prepare(
            "UPDATE certificats_salaires SET statut = 'exporte',
                exporte_le = COALESCE(exporte_le, datetime('now')),
                exporte_par = COALESCE(exporte_par, ?)
             WHERE id = ?"
        )->execute([$actorId, (int) $row['id']]);
        $this->audit->log(
            'salaires.certificat_exporte_non_transmis',
            $actorId,
            $organisationId,
            $dossierId,
            'certificat_salaire',
            (string) $row['id']
        );
        return (string) $row['xml_archive'];
    }

    /** @return list<array<string,mixed>> */
    public function certificates(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.employe_id, c.annee, c.statut, c.transmis,
                    c.empreinte_sha256, c.cree_le, c.controle_le, c.exporte_le,
                    e.prenom, e.nom
             FROM certificats_salaires c
             JOIN employes e ON e.id = c.employe_id
             WHERE c.organisation_id = ? AND c.dossier_id = ?
             ORDER BY c.annee DESC, e.nom, e.prenom'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    private function employee(
        int $organisationId,
        int $dossierId,
        int $employeeId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM employes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$employeeId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Employé absent du dossier.');
        }
        return $row;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function maskAvs(string $avs): string
    {
        $digits = (string) preg_replace('/\D+/', '', $avs);
        return strlen($digits) === 13
            ? '756.****.****.' . substr($digits, -2)
            : '***';
    }
}
