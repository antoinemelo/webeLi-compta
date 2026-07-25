<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class Ech0217ExportService
{
    public const VERIFIED_ON = '2026-07-25';
    public const ESTV_UPLOAD_URL = 'https://www.estv.admin.ch/fr/decompter-la-tva-en-ligne';
    public const ECH_STANDARD_URL = 'https://www.ech.ch/fr/ech/ech-0217/2.0.0';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly Ech0217Validator $validator,
        private readonly string $applicationVersion,
    ) {
    }

    /** @return array{id:int,xml:string,schema_valid:bool,errors:list<string>,transmitted:bool} */
    public function export(
        int $organisationId,
        int $dossierId,
        int $statementId,
        ?int $actorId = null,
    ): array {
        $statement = $this->statement($organisationId, $dossierId, $statementId);
        if ($statement['statut'] !== 'controle') {
            throw new VatException('Le décompte doit être contrôlé avant export.');
        }
        $periodStmt = $this->pdo->prepare(
            'SELECT * FROM tva_periodes WHERE id = ?'
        );
        $periodStmt->execute([$statement['periode_tva_id']]);
        $period = $periodStmt->fetch();
        $aggregates = json_decode(
            (string) $statement['agregats_json'],
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $boxesStmt = $this->pdo->prepare(
            'SELECT chiffre_afc, montant_centimes
             FROM tva_decompte_cases WHERE decompte_tva_id = ?'
        );
        $boxesStmt->execute([$statementId]);
        $boxes = [];
        foreach ($boxesStmt->fetchAll() as $row) {
            $boxes[(string) $row['chiffre_afc']] = (int) $row['montant_centimes'];
        }
        $xml = $this->build($statement, $period, $aggregates, $boxes);
        $errors = $this->validator->validate($xml);
        if ($errors !== []) {
            throw new VatException(
                'Export eCH-0217 invalide : ' . implode(' | ', array_slice($errors, 0, 5))
            );
        }
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO tva_exports
                 (organisation_id, dossier_id, decompte_tva_id, contenu_xml,
                  empreinte_sha256, schema_valide, erreurs_json, cree_par)
                 VALUES (?, ?, ?, ?, ?, 1, \'[]\', ?)'
            );
            $insert->execute([
                $organisationId, $dossierId, $statementId, $xml,
                hash('sha256', $xml), $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $update = $this->pdo->prepare(
                "UPDATE tva_decomptes SET statut = 'exporte'
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = 'controle'"
            );
            $update->execute([$statementId, $organisationId, $dossierId]);
            $this->pdo->prepare(
                "UPDATE tva_periodes SET statut = 'exportee',
                 modifie_le = datetime('now'), version = version + 1
                 WHERE id = ?"
            )->execute([$statement['periode_tva_id']]);
            $this->audit->log(
                'tva.ech0217_exporte',
                $actorId,
                $organisationId,
                $dossierId,
                'export_tva',
                (string) $id,
                [
                    'version' => Ech0217Validator::VERSION,
                    'transmis' => false,
                    'portail' => self::ESTV_UPLOAD_URL,
                ]
            );
            $this->pdo->commit();
            return [
                'id' => $id,
                'xml' => $xml,
                'schema_valid' => true,
                'errors' => [],
                'transmitted' => false,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $aggregates @param array<string,int> $boxes */
    private function build(array $statement, array $period, array $aggregates, array $boxes): string
    {
        $uid = preg_replace('/(?:TVA|MWST|IVA)$/', '', (string) $statement['numero_tva_snapshot']);
        $form = $statement['mode_decompte_snapshot'] === 'convenues' ? '1' : '2';
        $generated = str_replace(' ', 'T', (string) $statement['date_arret']) . 'Z';
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<eCH-0217:VATDeclaration'
                . ' xmlns:eCH-0058="http://www.ech.ch/xmlns/eCH-0058/5"'
                . ' xmlns:eCH-0108="http://www.ech.ch/xmlns/eCH-0108/7"'
                . ' xmlns:eCH-0217="' . Ech0217Validator::NAMESPACE . '"'
                . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">',
            '  <eCH-0217:generalInformation>',
            '    <eCH-0217:uid>' . $this->escape((string) $uid) . '</eCH-0217:uid>',
            '    <eCH-0217:organisationName>'
                . $this->escape((string) $statement['nom_organisation_snapshot'])
                . '</eCH-0217:organisationName>',
            '    <eCH-0217:generationTime>' . $generated . '</eCH-0217:generationTime>',
            '    <eCH-0217:reportingPeriodFrom>' . $period['date_debut']
                . '</eCH-0217:reportingPeriodFrom>',
            '    <eCH-0217:reportingPeriodTill>' . $period['date_fin']
                . '</eCH-0217:reportingPeriodTill>',
            '    <eCH-0217:typeOfSubmission>' . $statement['type_soumission']
                . '</eCH-0217:typeOfSubmission>',
            '    <eCH-0217:formOfReporting>' . $form . '</eCH-0217:formOfReporting>',
            '    <eCH-0217:businessReferenceId>WEBELI-TVA-' . $statement['id']
                . '</eCH-0217:businessReferenceId>',
            '    <eCH-0217:sendingApplication>',
            '      <eCH-0058:manufacturer>WebeLi</eCH-0058:manufacturer>',
            '      <eCH-0058:product>Compta</eCH-0058:product>',
            '      <eCH-0058:productVersion>'
                . $this->escape(substr($this->applicationVersion, 0, 10))
                . '</eCH-0058:productVersion>',
            '    </eCH-0217:sendingApplication>',
            '  </eCH-0217:generalInformation>',
            '  <eCH-0217:turnoverComputation>',
            '    <eCH-0217:totalConsideration>'
                . $this->money((int) $aggregates['total_turnover_cents'])
                . '</eCH-0217:totalConsideration>',
        ];
        foreach ([
            '220' => 'suppliesToForeignCountries',
            '221' => 'suppliesAbroad',
            '230' => 'suppliesExemptFromTax',
            '235' => 'reductionOfConsideration',
        ] as $box => $element) {
            if (($boxes[$box] ?? 0) !== 0) {
                $lines[] = "    <eCH-0217:{$element}>"
                    . $this->money($boxes[$box])
                    . "</eCH-0217:{$element}>";
            }
        }
        $lines[] = '  </eCH-0217:turnoverComputation>';
        if ($statement['methode_snapshot'] === 'effective') {
            $lines[] = '  <eCH-0217:effectiveReportingMethod>';
            $lines[] = '    <eCH-0217:grossOrNet>1</eCH-0217:grossOrNet>';
            foreach ($aggregates['legal_rate_turnover'] as $rate => $turnover) {
                $lines[] = '    <eCH-0217:suppliesPerTaxRate>';
                $lines[] = '      <eCH-0217:taxRate>' . $this->rate((int) $rate)
                    . '</eCH-0217:taxRate>';
                $lines[] = '      <eCH-0217:turnover>' . $this->money((int) $turnover)
                    . '</eCH-0217:turnover>';
                $lines[] = '    </eCH-0217:suppliesPerTaxRate>';
            }
            $this->appendAcquisitions($lines, $aggregates['acquisition_turnover'] ?? []);
            foreach ([
                '400' => 'inputTaxMaterialAndServices',
                '405' => 'inputTaxInvestments',
                '410' => 'subsequentInputTaxDeduction',
                '415' => 'inputTaxCorrections',
                '420' => 'inputTaxReductions',
            ] as $box => $element) {
                if (($boxes[$box] ?? 0) !== 0) {
                    $lines[] = "    <eCH-0217:{$element}>"
                        . $this->money($boxes[$box])
                        . "</eCH-0217:{$element}>";
                }
            }
            $lines[] = '  </eCH-0217:effectiveReportingMethod>';
        } else {
            $lines[] = '  <eCH-0217:simpleTaxRateMethod>';
            foreach ($aggregates['tdfn_turnover'] as $key => $turnover) {
                [$activity, $rate] = explode(':', (string) $key, 2);
                $lines[] = '    <eCH-0217:suppliesPerTaxRate>';
                $lines[] = '      <eCH-0217:activityID>' . $this->escape($activity)
                    . '</eCH-0217:activityID>';
                $lines[] = '      <eCH-0217:taxRate>' . $this->rate((int) $rate)
                    . '</eCH-0217:taxRate>';
                $lines[] = '      <eCH-0217:turnover>' . $this->money((int) $turnover)
                    . '</eCH-0217:turnover>';
                $lines[] = '    </eCH-0217:suppliesPerTaxRate>';
            }
            $this->appendAcquisitions($lines, $aggregates['acquisition_turnover'] ?? []);
            if (($boxes['415'] ?? 0) !== 0) {
                $lines[] = '    <eCH-0217:inputTaxCorrections>'
                    . $this->money($boxes['415'])
                    . '</eCH-0217:inputTaxCorrections>';
            }
            $lines[] = '  </eCH-0217:simpleTaxRateMethod>';
        }
        $lines[] = '  <eCH-0217:payableTax>'
            . $this->money((int) $aggregates['payable_cents'])
            . '</eCH-0217:payableTax>';
        if (($boxes['900'] ?? 0) !== 0 || ($boxes['910'] ?? 0) !== 0) {
            $lines[] = '  <eCH-0217:otherFlowsOfFunds>';
            if (($boxes['900'] ?? 0) !== 0) {
                $lines[] = '    <eCH-0217:subsidies>' . $this->money($boxes['900'])
                    . '</eCH-0217:subsidies>';
            }
            if (($boxes['910'] ?? 0) !== 0) {
                $lines[] = '    <eCH-0217:donations>' . $this->money($boxes['910'])
                    . '</eCH-0217:donations>';
            }
            $lines[] = '  </eCH-0217:otherFlowsOfFunds>';
        }
        $lines[] = '</eCH-0217:VATDeclaration>';
        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $lines @param array<int|string,int> $acquisitions */
    private function appendAcquisitions(array &$lines, array $acquisitions): void
    {
        foreach ($acquisitions as $rate => $turnover) {
            $lines[] = '    <eCH-0217:acquisitionTax>';
            $lines[] = '      <eCH-0217:taxRate>' . $this->rate((int) $rate)
                . '</eCH-0217:taxRate>';
            $lines[] = '      <eCH-0217:turnover>' . $this->money((int) $turnover)
                . '</eCH-0217:turnover>';
            $lines[] = '    </eCH-0217:acquisitionTax>';
        }
    }

    /** @return array<string,mixed> */
    private function statement(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tva_decomptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new VatException('Décompte TVA introuvable dans ce dossier.');
        }
        return $row;
    }

    private function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        return $sign . intdiv($absolute, 100) . '.' . str_pad(
            (string) ($absolute % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
    }

    private function rate(int $basisPoints): string
    {
        return $this->money($basisPoints);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
