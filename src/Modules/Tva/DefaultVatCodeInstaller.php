<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use Compta\Core\Audit\AuditLogger;
use PDO;
use RuntimeException;
use Throwable;

final class DefaultVatCodeInstaller
{
    public const VALID_FROM = '2024-01-01';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    public function install(
        int $organisationId,
        int $dossierId,
        ?int $actorId = null,
    ): int {
        $this->assertScope($organisationId, $dossierId);
        $rates = $this->legalRates();
        $accounts = [
            'input_material' => $this->account($organisationId, $dossierId, '1170'),
            'input_investment' => $this->account($organisationId, $dossierId, '1171'),
            'vat_due' => $this->account($organisationId, $dossierId, '2200'),
        ];
        $definitions = [
            ['VN81', 'Ventes 8,1 %', 'normal', 'collectee', $rates['normal'], false, 0, '', $accounts['vat_due']],
            ['VR26', 'Ventes 2,6 %', 'reduit', 'collectee', $rates['reduit'], false, 0, '', $accounts['vat_due']],
            ['VS38', 'Hébergement 3,8 %', 'special', 'collectee', $rates['special'], false, 0, '', $accounts['vat_due']],
            ['AM81', 'Achats matériel 8,1 %', 'normal', 'prealable', $rates['normal'], true, 10000, '400', $accounts['input_material']],
            ['AM26', 'Achats matériel 2,6 %', 'reduit', 'prealable', $rates['reduit'], true, 10000, '400', $accounts['input_material']],
            ['AI81', 'Investissements 8,1 %', 'normal', 'prealable', $rates['normal'], true, 10000, '405', $accounts['input_investment']],
            ['AI26', 'Investissements 2,6 %', 'reduit', 'prealable', $rates['reduit'], true, 10000, '405', $accounts['input_investment']],
            ['EXO', 'Exonéré', 'exonere', 'non_taxable', null, false, 0, '220', null],
            ['EXC', 'Exclu du champ de l’impôt', 'exclu', 'non_taxable', null, false, 0, '230', null],
            ['HCH', 'Hors champ', 'hors_champ', 'non_taxable', null, false, 0, '221', null],
        ];
        $exists = $this->pdo->prepare(
            'SELECT 1 FROM tva_codes
             WHERE organisation_id = ? AND dossier_id = ? AND code = ?
             LIMIT 1'
        );
        $configuration = new VatConfigurationService($this->pdo, $this->audit);
        $inserted = 0;

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($definitions as $definition) {
                [$code, $label, $treatment, $nature, $rateId, $deductible,
                    $deduction, $afcBox, $accountId] = $definition;
                $exists->execute([$organisationId, $dossierId, $code]);
                if ($exists->fetchColumn() !== false) {
                    continue;
                }
                $configuration->addCode([
                    'organisation_id' => $organisationId,
                    'dossier_id' => $dossierId,
                    'code' => $code,
                    'libelle' => $label,
                    'traitement' => $treatment,
                    'nature' => $nature,
                    'taux_legal_id' => $rateId,
                    'droit_deduction' => $deductible,
                    'deduction_defaut_bp' => $deduction,
                    'chiffre_afc' => $afcBox,
                    'compte_tva_id' => $accountId,
                    'date_debut' => self::VALID_FROM,
                ], $actorId);
                $inserted++;
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $inserted;
    }

    private function assertScope(int $organisationId, int $dossierId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM dossiers WHERE id = ? AND organisation_id = ?'
        );
        $stmt->execute([$dossierId, $organisationId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('Dossier absent ou hors de l’organisation.');
        }
    }

    /** @return array{normal:int,reduit:int,special:int} */
    private function legalRates(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM tva_taux_legaux
             WHERE categorie = ? AND date_debut <= ?
               AND COALESCE(date_fin, '9999-12-31') >= ?
             ORDER BY date_debut DESC LIMIT 1"
        );
        $rates = [];
        foreach (['normal', 'reduit', 'special'] as $category) {
            $stmt->execute([$category, self::VALID_FROM, self::VALID_FROM]);
            $id = $stmt->fetchColumn();
            if ($id === false) {
                throw new RuntimeException("Taux légal TVA {$category} absent.");
            }
            $rates[$category] = (int) $id;
        }
        return $rates;
    }

    private function account(
        int $organisationId,
        int $dossierId,
        string $number,
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM comptes
             WHERE organisation_id = ? AND dossier_id = ? AND numero = ?
               AND actif = 1 AND imputable = 1'
        );
        $stmt->execute([$organisationId, $dossierId, $number]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException(
                "Compte TVA {$number} absent ou non imputable. Installez d’abord le plan comptable."
            );
        }
        return (int) $id;
    }
}
