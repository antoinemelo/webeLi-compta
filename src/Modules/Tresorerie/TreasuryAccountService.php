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
        $this->assertCompatibleLedgerGroup(
            (int) $data['organisation_id'],
            (int) $data['dossier_id'],
            (int) $data['compte_comptable_id'],
            $currency,
            (int) ($data['multiplicateur_comptable'] ?? 1)
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO comptes_tresorerie
             (organisation_id, dossier_id, compte_comptable_id, libelle, type,
              iban, bic, monnaie, multiplicateur_comptable, actif, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            (bool) ($data['actif'] ?? true) ? 1 : 0,
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->adoptUnallocatedHistory(
            (int) $data['organisation_id'],
            (int) $data['dossier_id'],
            (int) $data['compte_comptable_id'],
            $id
        );
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

    /**
     * Lors du premier rattachement d'un compte général, les mouvements
     * historiques ne pouvaient appartenir qu'à ce compte opérationnel. Ils
     * sont donc ventilés immédiatement. L'ajout ultérieur d'un second compte
     * ne réaffecte jamais cet historique au hasard.
     */
    private function adoptUnallocatedHistory(
        int $organisationId,
        int $dossierId,
        int $ledgerAccountId,
        int $treasuryAccountId,
    ): void {
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM comptes_tresorerie
             WHERE organisation_id = ? AND dossier_id = ?
               AND compte_comptable_id = ?'
        );
        $count->execute([$organisationId, $dossierId, $ledgerAccountId]);
        if ((int) $count->fetchColumn() !== 1) {
            return;
        }
        $lines = $this->pdo->prepare(
            'UPDATE lignes_ecriture
             SET compte_tresorerie_operationnel_id = ?
             WHERE compte_id = ? AND compte_tresorerie_operationnel_id IS NULL
               AND ecriture_id IN (
                 SELECT id FROM ecritures
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND statut = \'brouillon\'
               )'
        );
        $lines->execute([
            $treasuryAccountId,
            $ledgerAccountId,
            $organisationId,
            $dossierId,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function update(
        int $organisationId,
        int $dossierId,
        int $accountId,
        int $expectedVersion,
        array $data,
        ?int $actorId = null,
    ): void {
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, ['banque', 'poste', 'caisse', 'carte'], true)) {
            throw new TreasuryException('Type de compte de trésorerie invalide.');
        }
        $label = trim((string) ($data['libelle'] ?? ''));
        $currency = strtoupper(trim((string) ($data['monnaie'] ?? 'CHF')));
        $iban = $this->iban((string) ($data['iban'] ?? ''));
        $ledgerAccountId = (int) ($data['compte_comptable_id'] ?? 0);
        $multiplier = (int) ($data['multiplicateur_comptable'] ?? 1);
        $active = (bool) ($data['actif'] ?? true);
        if (
            $accountId < 1
            || $expectedVersion < 1
            || $ledgerAccountId < 1
            || $label === ''
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || !in_array($multiplier, [-1, 1], true)
        ) {
            throw new TreasuryException('Données du compte de trésorerie invalides.');
        }
        if ($iban !== '' && preg_match('/^[A-Z]{2}[0-9A-Z]{13,32}$/', $iban) !== 1) {
            throw new TreasuryException('IBAN invalide.');
        }
        if (!$active) {
            $this->assertNotBillingAccount($dossierId, $accountId);
        }
        $current = $this->pdo->prepare(
            'SELECT compte_comptable_id FROM comptes_tresorerie
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $current->execute([$accountId, $organisationId, $dossierId]);
        $currentLedgerId = $current->fetchColumn();
        if ($currentLedgerId === false) {
            throw new TreasuryException('Compte de trésorerie absent du dossier.');
        }
        if ((int) $currentLedgerId !== $ledgerAccountId) {
            $this->assertLedgerMappingChangeAllowed($accountId);
        }
        $this->assertCompatibleLedgerGroup(
            $organisationId,
            $dossierId,
            $ledgerAccountId,
            $currency,
            $multiplier,
            $accountId
        );
        $stmt = $this->pdo->prepare(
            'UPDATE comptes_tresorerie
             SET compte_comptable_id = ?, libelle = ?, type = ?, iban = ?,
                 bic = ?, monnaie = ?, multiplicateur_comptable = ?, actif = ?,
                 archive_le = CASE WHEN ? = 1 THEN NULL ELSE datetime(\'now\') END,
                 archive_par = CASE WHEN ? = 1 THEN NULL ELSE ? END,
                 modifie_le = datetime(\'now\'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND version = ?'
        );
        $stmt->execute([
            $ledgerAccountId,
            $label,
            $type,
            $iban,
            strtoupper(trim((string) ($data['bic'] ?? ''))),
            $currency,
            $multiplier,
            $active ? 1 : 0,
            $active ? 1 : 0,
            $active ? 1 : 0,
            $actorId,
            $accountId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new TreasuryException(
                'Compte absent ou modifié par un autre utilisateur.'
            );
        }
        $this->audit->log(
            'tresorerie.compte_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'compte_tresorerie',
            (string) $accountId,
            ['type' => $type, 'actif' => $active]
        );
    }

    /**
     * Supprime un compte jamais utilisé, sinon l’archive.
     *
     * @return array{action:'deleted'|'archived',dependencies:list<string>}
     */
    public function remove(
        int $organisationId,
        int $dossierId,
        int $accountId,
        int $expectedVersion,
        ?int $actorId = null,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT libelle, version, actif
             FROM comptes_tresorerie
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$accountId, $organisationId, $dossierId]);
        $account = $stmt->fetch();
        if ($account === false) {
            throw new TreasuryException('Compte de trésorerie absent du dossier.');
        }
        if ((int) $account['version'] !== $expectedVersion) {
            throw new TreasuryException(
                'Le compte a été modifié par une autre session. Rechargez la page.'
            );
        }
        $this->assertNotBillingAccount($dossierId, $accountId);
        $dependencies = [];
        foreach ([
            'imports_bancaires' => 'import bancaire',
            'lignes_bancaires' => 'ligne bancaire',
            'lots_paiements_sortants' => 'lot de paiements',
            'rapprochements_bancaires' => 'rapprochement bancaire',
            'soldes_bancaires' => 'solde bancaire',
            'lignes_ecriture' => 'ligne comptable ventilée',
            'paiements' => 'paiement',
            'paiements_salaires' => 'paiement salarial',
        ] as $table => $label) {
            $column = in_array(
                $table,
                ['lignes_ecriture', 'paiements', 'paiements_salaires'],
                true
            ) ? 'compte_tresorerie_operationnel_id' : 'compte_tresorerie_id';
            if ($table === 'lignes_ecriture') {
                $count = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM lignes_ecriture l
                     JOIN ecritures e ON e.id = l.ecriture_id
                     WHERE e.organisation_id = ? AND e.dossier_id = ?
                       AND l.{$column} = ?"
                );
            } else {
                $count = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE organisation_id = ? AND dossier_id = ?
                       AND {$column} = ?"
                );
            }
            $count->execute([$organisationId, $dossierId, $accountId]);
            $total = (int) $count->fetchColumn();
            if ($total > 0) {
                $dependencies[] = "{$total} {$label}" . ($total > 1 ? 's' : '');
            }
        }
        if ($dependencies !== []) {
            if ((int) $account['actif'] !== 1) {
                throw new TreasuryException('Ce compte est déjà archivé.');
            }
            $archive = $this->pdo->prepare(
                "UPDATE comptes_tresorerie
                 SET actif = 0, archive_le = datetime('now'), archive_par = ?,
                     modifie_le = datetime('now'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ? AND actif = 1"
            );
            $archive->execute([
                $actorId,
                $accountId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($archive->rowCount() !== 1) {
                throw new TreasuryException('Conflit pendant l’archivage du compte.');
            }
            $this->audit->log(
                'tresorerie.compte_archive',
                $actorId,
                $organisationId,
                $dossierId,
                'compte_tresorerie',
                (string) $accountId,
                ['libelle' => $account['libelle'], 'dependances' => $dependencies]
            );
            return ['action' => 'archived', 'dependencies' => $dependencies];
        }
        $delete = $this->pdo->prepare(
            'DELETE FROM comptes_tresorerie
             WHERE id = ? AND organisation_id = ? AND dossier_id = ? AND version = ?'
        );
        $delete->execute([
            $accountId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($delete->rowCount() !== 1) {
            throw new TreasuryException('Conflit pendant la suppression du compte.');
        }
        $this->audit->log(
            'tresorerie.compte_supprime',
            $actorId,
            $organisationId,
            $dossierId,
            'compte_tresorerie',
            (string) $accountId,
            ['libelle' => $account['libelle']]
        );
        return ['action' => 'deleted', 'dependencies' => []];
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

    private function assertNotBillingAccount(int $dossierId, int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM dossiers
             WHERE id = ? AND compte_tresorerie_facturation_id = ?'
        );
        $stmt->execute([$dossierId, $accountId]);
        if ($stmt->fetchColumn() !== false) {
            throw new TreasuryException(
                'Ce compte fournit l’IBAN de facturation. '
                . 'Choisissez d’abord un autre compte sous Configuration → Entité.'
            );
        }
    }

    private function assertCompatibleLedgerGroup(
        int $organisationId,
        int $dossierId,
        int $ledgerAccountId,
        string $currency,
        int $multiplier,
        ?int $excludedId = null,
    ): void {
        $sql = 'SELECT 1 FROM comptes_tresorerie
                WHERE organisation_id = ? AND dossier_id = ?
                  AND compte_comptable_id = ?
                  AND (monnaie <> ? OR multiplicateur_comptable <> ?)';
        $parameters = [
            $organisationId,
            $dossierId,
            $ledgerAccountId,
            $currency,
            $multiplier,
        ];
        if ($excludedId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $excludedId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($parameters);
        if ($stmt->fetchColumn() !== false) {
            throw new TreasuryException(
                'Les comptes rattachés au même compte comptable doivent partager la devise et le sens.'
            );
        }
    }

    private function assertLedgerMappingChangeAllowed(int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 WHERE
               EXISTS (SELECT 1 FROM lignes_ecriture
                       WHERE compte_tresorerie_operationnel_id = ?)
               OR EXISTS (SELECT 1 FROM imports_bancaires
                          WHERE compte_tresorerie_id = ?)
               OR EXISTS (SELECT 1 FROM lots_paiements_sortants
                          WHERE compte_tresorerie_id = ?)
               OR EXISTS (SELECT 1 FROM paiements
                          WHERE compte_tresorerie_operationnel_id = ?)
               OR EXISTS (SELECT 1 FROM paiements_salaires
                          WHERE compte_tresorerie_operationnel_id = ?)'
        );
        $stmt->execute(array_fill(0, 5, $accountId));
        if ($stmt->fetchColumn() !== false) {
            throw new TreasuryException(
                'Le compte comptable ne peut plus être modifié après utilisation.'
            );
        }
    }
}
