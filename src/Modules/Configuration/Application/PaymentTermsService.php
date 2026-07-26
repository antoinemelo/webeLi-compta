<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Application;

use DateTimeImmutable;
use PDO;

final class PaymentTermsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   condition_id:int,due_date:string,snapshot:array<string,mixed>
     * }|null
     */
    public function resolveDefault(
        int $organisationId,
        int $dossierId,
        string $direction,
        string $documentDate,
    ): ?array {
        if (!in_array($direction, ['client', 'fournisseur'], true)) {
            throw new ConfigurationException('Direction de paiement invalide.');
        }
        $date = $this->date($documentDate);
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.code, c.libelle, c.direction, c.delai_jours,
                    c.fin_de_mois, c.date_debut, c.date_fin
             FROM defauts_conditions_paiement d
             JOIN conditions_paiement c ON c.id = d.condition_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND d.direction = ?
               AND d.date_debut <= ?
               AND (d.date_fin IS NULL OR d.date_fin >= ?)
               AND c.actif = 1
               AND c.date_debut <= ?
               AND (c.date_fin IS NULL OR c.date_fin >= ?)
             ORDER BY d.date_debut DESC, d.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            $organisationId, $dossierId, $direction,
            $documentDate, $documentDate, $documentDate, $documentDate,
        ]);
        $condition = $stmt->fetch();
        if ($condition === false) {
            return null;
        }
        $due = $date->modify('+' . (int) $condition['delai_jours'] . ' days');
        if ((int) $condition['fin_de_mois'] === 1) {
            $due = $due->modify('last day of this month');
        }
        $snapshot = [
            'code' => (string) $condition['code'],
            'label' => (string) $condition['libelle'],
            'direction' => (string) $condition['direction'],
            'days' => (int) $condition['delai_jours'],
            'end_of_month' => (int) $condition['fin_de_mois'] === 1,
            'valid_from' => (string) $condition['date_debut'],
            'valid_until' => $condition['date_fin'] === null
                ? null
                : (string) $condition['date_fin'],
            'document_date' => $documentDate,
            'due_date' => $due->format('Y-m-d'),
            'calculation' => 'document_date + days, then end of resulting month when enabled',
        ];
        return [
            'condition_id' => (int) $condition['id'],
            'due_date' => $due->format('Y-m-d'),
            'snapshot' => $snapshot,
        ];
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ConfigurationException('Date invalide.');
        }
        return $date;
    }
}
