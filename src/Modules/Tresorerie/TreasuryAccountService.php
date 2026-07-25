<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Compta\Core\Audit\AuditLogger;
use PDO;

final class TreasuryAccountService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, ?int $actorId = null): int
    {
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, ['banque', 'poste', 'caisse', 'carte'], true)) {
            throw new TreasuryException('Type de compte de trésorerie invalide.');
        }
        $label = trim((string) ($data['libelle'] ?? ''));
        $currency = strtoupper(trim((string) ($data['monnaie'] ?? 'CHF')));
        $iban = $this->iban((string) ($data['iban'] ?? ''));
        if ($label === '' || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new TreasuryException('Libellé ou monnaie invalide.');
        }
        if ($iban !== '' && preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
            throw new TreasuryException('IBAN invalide.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO comptes_tresorerie
             (organisation_id, dossier_id, compte_comptable_id, libelle, type,
              iban, bic, monnaie, multiplicateur_comptable, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $data['organisation_id'],
            (int) $data['dossier_id'],
            (int) $data['compte_comptable_id'],
            $label,
            $type,
            $iban,
            strtoupper(trim((string) ($data['bic'] ?? ''))),
            $currency,
            (int) ($data['multiplicateur_comptable'] ?? 1),
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'tresorerie.compte_cree',
            $actorId,
            (int) $data['organisation_id'],
            (int) $data['dossier_id'],
            'compte_tresorerie',
            (string) $id,
            ['type' => $type, 'iban' => $iban]
        );
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function list(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, c.numero AS numero_comptable
             FROM comptes_tresorerie t
             JOIN comptes c ON c.id = t.compte_comptable_id
             WHERE t.organisation_id = ? AND t.dossier_id = ?
             ORDER BY t.actif DESC, t.libelle, t.id'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return $stmt->fetchAll();
    }

    private function iban(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($value)));
    }
}
