<?php
declare(strict_types=1);

namespace Compta\Modules\Immobilisations;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\AccountingException;
use Compta\Modules\Compta\EntryService;
use DateTimeImmutable;
use PDO;
use Throwable;

final class AssetService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function saveCategory(
        int $organisationId,
        int $dossierId,
        array $data,
        ?int $actorId = null,
    ): int {
        $id = (int) ($data['id'] ?? 0);
        $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));
        $duration = (int) ($data['default_duration_months'] ?? 0);
        $active = !array_key_exists('active', $data) || !empty($data['active']);
        if (
            preg_match('/^[A-Z0-9_-]{1,20}$/', $code) !== 1
            || $label === ''
            || $duration < 1
            || $duration > 1200
        ) {
            throw new AssetException('Catégorie d’immobilisation invalide.');
        }
        $accounts = $this->categoryAccounts($data);
        $this->assertAccounts($organisationId, $dossierId, $accounts);
        if ($id < 1) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO categories_immobilisations
                 (organisation_id, dossier_id, code, libelle,
                  duree_defaut_mois, compte_actif_id,
                  compte_amortissement_id, compte_dotation_id,
                  compte_gain_cession_id, compte_perte_cession_id,
                  actif, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $code, $label, $duration,
                $accounts['asset'], $accounts['accumulated'],
                $accounts['expense'], $accounts['gain'], $accounts['loss'],
                $active ? 1 : 0, $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $action = 'immobilisations.categorie_creee';
        } else {
            $version = (int) ($data['version'] ?? 0);
            $stmt = $this->pdo->prepare(
                'UPDATE categories_immobilisations
                 SET code = ?, libelle = ?, duree_defaut_mois = ?,
                     compte_actif_id = ?, compte_amortissement_id = ?,
                     compte_dotation_id = ?, compte_gain_cession_id = ?,
                     compte_perte_cession_id = ?, actif = ?,
                     modifie_le = datetime(\'now\'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ?'
            );
            $stmt->execute([
                $code, $label, $duration, $accounts['asset'],
                $accounts['accumulated'], $accounts['expense'],
                $accounts['gain'], $accounts['loss'], $active ? 1 : 0,
                $id, $organisationId, $dossierId, $version,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new AssetException(
                    'Catégorie absente, hors du dossier ou modifiée.'
                );
            }
            $action = 'immobilisations.categorie_modifiee';
        }
        $this->audit->log(
            $action,
            $actorId,
            $organisationId,
            $dossierId,
            'categorie_immobilisation',
            (string) $id,
            ['code' => $code, 'actif' => $active]
        );
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function createAsset(
        int $organisationId,
        int $dossierId,
        array $data,
        ?int $actorId = null,
    ): int {
        $categoryId = (int) ($data['category_id'] ?? 0);
        $category = $this->category(
            $organisationId,
            $dossierId,
            $categoryId,
            true
        );
        $values = $this->validatedAssetData(
            $organisationId,
            $dossierId,
            $data,
            $category
        );
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $categoryId,
            $values,
            $actorId
        ): int {
            $stmt = $this->pdo->prepare(
                'INSERT INTO immobilisations
                 (organisation_id, dossier_id, categorie_id, code, libelle,
                  reference_piece, document_acquisition_id,
                  piece_acquisition_id, date_acquisition, date_mise_service,
                  valeur_acquisition_centimes, valeur_residuelle_centimes,
                  duree_mois, compte_actif_id, compte_amortissement_id,
                  compte_dotation_id, compte_gain_cession_id,
                  compte_perte_cession_id, note, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $categoryId, $values['code'],
                $values['label'], $values['acquisition_reference'],
                $values['acquisition_document_id'],
                $values['acquisition_attachment_id'],
                $values['acquisition_date'], $values['in_service_date'],
                $values['acquisition_value_cents'],
                $values['residual_value_cents'], $values['duration_months'],
                $values['accounts']['asset'],
                $values['accounts']['accumulated'],
                $values['accounts']['expense'],
                $values['accounts']['gain'], $values['accounts']['loss'],
                $values['note'], $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->insertInitialSchedule(
                $id,
                $values['in_service_date'],
                $values['duration_months'],
                $values['acquisition_value_cents']
                    - $values['residual_value_cents']
            );
            $this->audit->log(
                'immobilisations.actif_cree',
                $actorId,
                $organisationId,
                $dossierId,
                'immobilisation',
                (string) $id,
                [
                    'code' => $values['code'],
                    'base_amortissable_centimes' =>
                        $values['acquisition_value_cents']
                        - $values['residual_value_cents'],
                    'methode' => 'lineaire_journaliere',
                    'prorata' => 'jours_reels',
                ]
            );
            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateAsset(
        int $organisationId,
        int $dossierId,
        int $assetId,
        int $expectedVersion,
        array $data,
        ?int $actorId = null,
    ): void {
        $asset = $this->asset($organisationId, $dossierId, $assetId);
        if (
            $asset['statut'] !== 'actif'
            || (int) $asset['version'] !== $expectedVersion
        ) {
            throw new AssetException(
                'Immobilisation sortie ou modifiée par un autre utilisateur.'
            );
        }
        $history = $this->pdo->prepare(
            'SELECT 1 FROM echeances_amortissement
             WHERE immobilisation_id = ? AND ecriture_id IS NOT NULL LIMIT 1'
        );
        $history->execute([$assetId]);
        if ($history->fetchColumn() !== false) {
            throw new AssetException(
                'Une immobilisation déjà comptabilisée se corrige par contre-passation.'
            );
        }
        $categoryId = (int) ($data['category_id'] ?? $asset['categorie_id']);
        $category = $this->category(
            $organisationId,
            $dossierId,
            $categoryId,
            true
        );
        $values = $this->validatedAssetData(
            $organisationId,
            $dossierId,
            $data,
            $category
        );
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $assetId,
            $expectedVersion,
            $categoryId,
            $values,
            $actorId
        ): void {
            $stmt = $this->pdo->prepare(
                'UPDATE immobilisations
                 SET categorie_id = ?, code = ?, libelle = ?,
                     reference_piece = ?, document_acquisition_id = ?,
                     piece_acquisition_id = ?, date_acquisition = ?,
                     date_mise_service = ?, valeur_acquisition_centimes = ?,
                     valeur_residuelle_centimes = ?, duree_mois = ?,
                     compte_actif_id = ?, compte_amortissement_id = ?,
                     compte_dotation_id = ?, compte_gain_cession_id = ?,
                     compte_perte_cession_id = ?, note = ?,
                     modifie_le = datetime(\'now\'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = \'actif\' AND version = ?'
            );
            $stmt->execute([
                $categoryId, $values['code'], $values['label'],
                $values['acquisition_reference'],
                $values['acquisition_document_id'],
                $values['acquisition_attachment_id'],
                $values['acquisition_date'], $values['in_service_date'],
                $values['acquisition_value_cents'],
                $values['residual_value_cents'], $values['duration_months'],
                $values['accounts']['asset'],
                $values['accounts']['accumulated'],
                $values['accounts']['expense'],
                $values['accounts']['gain'], $values['accounts']['loss'],
                $values['note'], $assetId, $organisationId, $dossierId,
                $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new AssetException('Conflit pendant la correction de la fiche.');
            }
            $this->pdo->prepare(
                'DELETE FROM echeances_amortissement
                 WHERE immobilisation_id = ?'
            )->execute([$assetId]);
            $this->insertInitialSchedule(
                $assetId,
                $values['in_service_date'],
                $values['duration_months'],
                $values['acquisition_value_cents']
                    - $values['residual_value_cents']
            );
            $this->audit->log(
                'immobilisations.actif_corrige',
                $actorId,
                $organisationId,
                $dossierId,
                'immobilisation',
                (string) $assetId
            );
        });
    }

    public function postDepreciation(
        int $organisationId,
        int $dossierId,
        int $scheduleId,
        int $exerciseId,
        int $journalId,
        ?int $actorId = null,
    ): int {
        return $this->transaction(fn (): int => $this->postScheduleInside(
            $organisationId,
            $dossierId,
            $scheduleId,
            $exerciseId,
            $journalId,
            $actorId
        ));
    }

    public function reverseDepreciation(
        int $organisationId,
        int $dossierId,
        int $scheduleId,
        string $date,
        ?int $actorId = null,
    ): int {
        $this->assertDate($date);
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $scheduleId,
            $date,
            $actorId
        ): int {
            $schedule = $this->schedule(
                $organisationId,
                $dossierId,
                $scheduleId
            );
            if (
                $schedule['statut'] === 'planifiee'
                && $schedule['ecriture_contrepassation_id'] !== null
            ) {
                return (int) $schedule['ecriture_contrepassation_id'];
            }
            if (
                $schedule['statut'] !== 'comptabilisee'
                || $schedule['ecriture_id'] === null
            ) {
                throw new AssetException(
                    'Seule une dotation comptabilisée peut être contre-passée.'
                );
            }
            try {
                $reversalId = $this->entries->reverse(
                    $organisationId,
                    $dossierId,
                    (int) $schedule['ecriture_id'],
                    $date,
                    'Correction amortissement ' . $schedule['code'],
                    $actorId
                );
            } catch (AccountingException $exception) {
                throw new AssetException($exception->getMessage(), previous: $exception);
            }
            $this->pdo->prepare(
                'UPDATE echeances_amortissement
                 SET statut = \'planifiee\', ecriture_id = NULL,
                     ecriture_contrepassation_id = ?,
                     contrepassee_le = datetime(\'now\'), version = version + 1
                 WHERE id = ?'
            )->execute([$reversalId, $scheduleId]);
            $this->audit->log(
                'immobilisations.dotation_contrepassee',
                $actorId,
                $organisationId,
                $dossierId,
                'echeance_amortissement',
                (string) $scheduleId,
                ['ecriture_contrepassation_id' => $reversalId]
            );
            return $reversalId;
        });
    }

    public function dispose(
        int $organisationId,
        int $dossierId,
        int $assetId,
        string $type,
        string $date,
        int $proceedsCents,
        ?int $proceedsAccountId,
        int $exerciseId,
        int $journalId,
        ?int $actorId = null,
    ): int {
        $this->assertDate($date);
        if (
            !in_array($type, ['cession', 'mise_au_rebut'], true)
            || $proceedsCents < 0
            || ($type === 'mise_au_rebut' && $proceedsCents !== 0)
            || ($proceedsCents > 0 && $proceedsAccountId === null)
        ) {
            throw new AssetException('Paramètres de sortie invalides.');
        }
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $assetId,
            $type,
            $date,
            $proceedsCents,
            $proceedsAccountId,
            $exerciseId,
            $journalId,
            $actorId
        ): int {
            $asset = $this->asset($organisationId, $dossierId, $assetId);
            if ($asset['statut'] !== 'actif') {
                $existing = $this->pdo->prepare(
                    'SELECT ecriture_id, type, date_sortie,
                            produit_cession_centimes
                     FROM sorties_immobilisations
                     WHERE immobilisation_id = ? AND statut = \'comptabilisee\''
                );
                $existing->execute([$assetId]);
                $exit = $existing->fetch();
                if (
                    $exit !== false
                    && $exit['type'] === $type
                    && $exit['date_sortie'] === $date
                    && (int) $exit['produit_cession_centimes'] === $proceedsCents
                ) {
                    return (int) $exit['ecriture_id'];
                }
                throw new AssetException('Cette immobilisation est déjà sortie.');
            }
            if ($date < (string) $asset['date_mise_service']) {
                throw new AssetException(
                    'La sortie ne peut pas précéder la mise en service.'
                );
            }
            if ($proceedsAccountId !== null) {
                $this->assertPostingAccount(
                    $organisationId,
                    $dossierId,
                    $proceedsAccountId
                );
            }
            $futurePosted = $this->pdo->prepare(
                'SELECT 1 FROM echeances_amortissement
                 WHERE immobilisation_id = ? AND statut = \'comptabilisee\'
                   AND date_comptable > ? LIMIT 1'
            );
            $futurePosted->execute([$assetId, $date]);
            if ($futurePosted->fetchColumn() !== false) {
                throw new AssetException(
                    'Contre-passez les dotations postérieures à la date de sortie.'
                );
            }
            $overdue = $this->pdo->prepare(
                'SELECT 1 FROM echeances_amortissement
                 WHERE immobilisation_id = ? AND statut = \'planifiee\'
                   AND montant_centimes > 0 AND date_fin < ? LIMIT 1'
            );
            $overdue->execute([$assetId, $date]);
            if ($overdue->fetchColumn() !== false) {
                throw new AssetException(
                    'Comptabilisez les dotations échues avant la sortie.'
                );
            }
            $current = $this->pdo->prepare(
                'SELECT id, date_debut, date_fin
                 FROM echeances_amortissement
                 WHERE immobilisation_id = ? AND statut = \'planifiee\'
                   AND date_debut <= ? AND date_fin >= ?
                 ORDER BY ordre LIMIT 1'
            );
            $current->execute([$assetId, $date, $date]);
            $currentRow = $current->fetch();
            if ($currentRow !== false) {
                $amount = $this->depreciationThrough(
                    $asset,
                    $date,
                    $assetId
                );
                $days = $this->daysInclusive(
                    (string) $currentRow['date_debut'],
                    $date
                );
                $this->pdo->prepare(
                    'UPDATE echeances_amortissement
                     SET date_fin = ?, date_comptable = ?, jours = ?,
                         montant_centimes = ?, version = version + 1
                     WHERE id = ?'
                )->execute([
                    $date, $date, $days, $amount, (int) $currentRow['id'],
                ]);
                if ($amount > 0) {
                    $this->postScheduleInside(
                        $organisationId,
                        $dossierId,
                        (int) $currentRow['id'],
                        $exerciseId,
                        $journalId,
                        $actorId
                    );
                } else {
                    $this->pdo->prepare(
                        'UPDATE echeances_amortissement
                         SET statut = \'annulee\' WHERE id = ?'
                    )->execute([(int) $currentRow['id']]);
                }
            }
            $this->pdo->prepare(
                'UPDATE echeances_amortissement
                 SET statut = \'annulee\', version = version + 1
                 WHERE immobilisation_id = ? AND statut = \'planifiee\'
                   AND date_debut > ?'
            )->execute([$assetId, $date]);
            $accumulated = $this->postedDepreciation($assetId);
            $gross = (int) $asset['valeur_acquisition_centimes'];
            $net = max(0, $gross - $accumulated);
            $result = $proceedsCents - $net;
            $lines = [];
            if ($accumulated > 0) {
                $lines[] = [
                    'compte_id' => (int) $asset['compte_amortissement_id'],
                    'libelle' => 'Solde amortissements cumulés',
                    'debit_centimes' => $accumulated,
                    'credit_centimes' => 0,
                ];
            }
            if ($proceedsCents > 0) {
                $lines[] = [
                    'compte_id' => (int) $proceedsAccountId,
                    'libelle' => 'Produit de cession',
                    'debit_centimes' => $proceedsCents,
                    'credit_centimes' => 0,
                ];
            }
            if ($result < 0) {
                $lines[] = [
                    'compte_id' => (int) $asset['compte_perte_cession_id'],
                    'libelle' => 'Perte sur sortie d’immobilisation',
                    'debit_centimes' => abs($result),
                    'credit_centimes' => 0,
                ];
            } elseif ($result > 0) {
                $lines[] = [
                    'compte_id' => (int) $asset['compte_gain_cession_id'],
                    'libelle' => 'Gain sur sortie d’immobilisation',
                    'debit_centimes' => 0,
                    'credit_centimes' => $result,
                ];
            }
            $lines[] = [
                'compte_id' => (int) $asset['compte_actif_id'],
                'libelle' => 'Sortie de la valeur brute',
                'debit_centimes' => 0,
                'credit_centimes' => $gross,
            ];
            $this->assertPostingContext(
                $organisationId,
                $dossierId,
                $exerciseId,
                $journalId,
                $date
            );
            try {
                $entryId = $this->entries->postGenerated([
                    'organisation_id' => $organisationId,
                    'dossier_id' => $dossierId,
                    'exercice_id' => $exerciseId,
                    'journal_id' => $journalId,
                    'date_comptable' => $date,
                    'libelle' => ($type === 'cession' ? 'Cession ' : 'Mise au rebut ')
                        . $asset['code'] . ' — ' . $asset['libelle'],
                    'reference' => (string) $asset['reference_piece'],
                    'source_type' => 'immobilisation',
                    'source_id' => 'actif:' . $assetId,
                    'source_action' => 'sortie:v' . $asset['version'],
                    'lignes' => $lines,
                ], 'immobilisation:sortie:' . $assetId . ':v' . $asset['version'], $actorId);
            } catch (AccountingException $exception) {
                throw new AssetException($exception->getMessage(), previous: $exception);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO sorties_immobilisations
                 (organisation_id, dossier_id, immobilisation_id, type,
                  date_sortie, produit_cession_centimes, compte_produit_id,
                  valeur_brute_centimes, amortissement_cumule_centimes,
                  valeur_nette_centimes, resultat_cession_centimes,
                  ecriture_id, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $assetId, $type, $date,
                $proceedsCents, $proceedsAccountId, $gross, $accumulated,
                $net, $result, $entryId, $actorId,
            ]);
            $exitId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'UPDATE immobilisations
                 SET statut = ?, date_sortie = ?, modifie_le = datetime(\'now\'),
                     version = version + 1
                 WHERE id = ?'
            )->execute([
                $type === 'cession' ? 'cede' : 'mis_au_rebut',
                $date,
                $assetId,
            ]);
            $this->audit->log(
                'immobilisations.actif_sorti',
                $actorId,
                $organisationId,
                $dossierId,
                'immobilisation',
                (string) $assetId,
                [
                    'sortie_id' => $exitId,
                    'type' => $type,
                    'ecriture_id' => $entryId,
                    'valeur_nette_centimes' => $net,
                    'resultat_cession_centimes' => $result,
                ]
            );
            return $entryId;
        });
    }

    public function reverseDisposal(
        int $organisationId,
        int $dossierId,
        int $assetId,
        string $date,
        ?int $actorId = null,
    ): int {
        $this->assertDate($date);
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $assetId,
            $date,
            $actorId
        ): int {
            $asset = $this->asset($organisationId, $dossierId, $assetId);
            $stmt = $this->pdo->prepare(
                'SELECT * FROM sorties_immobilisations
                 WHERE immobilisation_id = ? AND organisation_id = ?
                   AND dossier_id = ? AND statut = \'comptabilisee\''
            );
            $stmt->execute([$assetId, $organisationId, $dossierId]);
            $exit = $stmt->fetch();
            if ($exit === false) {
                $latest = $this->pdo->prepare(
                    'SELECT ecriture_contrepassation_id
                     FROM sorties_immobilisations
                     WHERE immobilisation_id = ? AND statut = \'contre_passee\'
                     ORDER BY id DESC LIMIT 1'
                );
                $latest->execute([$assetId]);
                $existing = $latest->fetchColumn();
                if ($existing !== false && $existing !== null) {
                    return (int) $existing;
                }
                throw new AssetException('Aucune sortie à contre-passer.');
            }
            try {
                $reversalId = $this->entries->reverse(
                    $organisationId,
                    $dossierId,
                    (int) $exit['ecriture_id'],
                    $date,
                    'Contre-passation sortie ' . $asset['code'],
                    $actorId
                );
            } catch (AccountingException $exception) {
                throw new AssetException($exception->getMessage(), previous: $exception);
            }
            $this->pdo->prepare(
                'UPDATE sorties_immobilisations
                 SET statut = \'contre_passee\', ecriture_contrepassation_id = ?,
                     contrepassee_le = datetime(\'now\')
                 WHERE id = ?'
            )->execute([$reversalId, (int) $exit['id']]);
            $this->pdo->prepare(
                'UPDATE immobilisations
                 SET statut = \'actif\', date_sortie = NULL,
                     modifie_le = datetime(\'now\'), version = version + 1
                 WHERE id = ?'
            )->execute([$assetId]);
            $this->rebuildFutureAfter(
                $asset,
                (string) $exit['date_sortie'],
                $assetId
            );
            $this->audit->log(
                'immobilisations.sortie_contrepassee',
                $actorId,
                $organisationId,
                $dossierId,
                'immobilisation',
                (string) $assetId,
                ['ecriture_contrepassation_id' => $reversalId]
            );
            return $reversalId;
        });
    }

    /** @return array<string,mixed> */
    public function read(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        ?int $assetId = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $exercise = $this->exercise(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $totalStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM immobilisations
             WHERE organisation_id = ? AND dossier_id = ?'
        );
        $totalStmt->execute([$organisationId, $dossierId]);
        $total = (int) $totalStmt->fetchColumn();
        $assets = $this->pdo->prepare(
            'SELECT i.*, c.code AS categorie_code, c.libelle AS categorie,
                    COALESCE((
                        SELECT SUM(e.montant_centimes)
                        FROM echeances_amortissement e
                        WHERE e.immobilisation_id = i.id
                          AND e.statut = \'comptabilisee\'
                    ), 0) AS amortissement_comptabilise_centimes
             FROM immobilisations i
             JOIN categories_immobilisations c ON c.id = i.categorie_id
             WHERE i.organisation_id = ? AND i.dossier_id = ?
             ORDER BY CASE i.statut WHEN \'actif\' THEN 0 ELSE 1 END,
                      i.date_mise_service DESC, i.code
             LIMIT ? OFFSET ?'
        );
        $assets->execute([
            $organisationId,
            $dossierId,
            $perPage,
            ($page - 1) * $perPage,
        ]);
        $assetRows = array_map(
            fn (array $row): array => $this->assetPayload($row),
            $assets->fetchAll()
        );
        if ($assetId === null && $assetRows !== []) {
            $assetId = (int) $assetRows[0]['id'];
        }
        $selected = null;
        if ($assetId !== null) {
            $row = $this->asset($organisationId, $dossierId, $assetId);
            $schedule = $this->pdo->prepare(
                'SELECT id, ordre, date_debut, date_fin, date_comptable,
                        jours, montant_centimes, statut, ecriture_id,
                        ecriture_contrepassation_id, version
                 FROM echeances_amortissement
                 WHERE immobilisation_id = ?
                 ORDER BY ordre'
            );
            $schedule->execute([$assetId]);
            $scheduleRows = array_map(
                static fn (array $item): array => [
                    'id' => (int) $item['id'],
                    'order' => (int) $item['ordre'],
                    'start_date' => (string) $item['date_debut'],
                    'end_date' => (string) $item['date_fin'],
                    'posting_date' => (string) $item['date_comptable'],
                    'days' => (int) $item['jours'],
                    'amount_cents' => (int) $item['montant_centimes'],
                    'status' => (string) $item['statut'],
                    'entry_id' => $item['ecriture_id'] === null
                        ? null : (int) $item['ecriture_id'],
                    'reversal_entry_id' =>
                        $item['ecriture_contrepassation_id'] === null
                            ? null
                            : (int) $item['ecriture_contrepassation_id'],
                    'version' => (int) $item['version'],
                ],
                $schedule->fetchAll()
            );
            $posted = array_sum(array_column(
                array_filter(
                    $scheduleRows,
                    static fn (array $item): bool =>
                        $item['status'] === 'comptabilisee'
                ),
                'amount_cents'
            ));
            $base = (int) $row['valeur_acquisition_centimes']
                - (int) $row['valeur_residuelle_centimes'];
            $exitStmt = $this->pdo->prepare(
                'SELECT id, type, date_sortie, produit_cession_centimes,
                        valeur_brute_centimes,
                        amortissement_cumule_centimes,
                        valeur_nette_centimes, resultat_cession_centimes,
                        ecriture_id, ecriture_contrepassation_id, statut
                 FROM sorties_immobilisations
                 WHERE immobilisation_id = ? ORDER BY id DESC'
            );
            $exitStmt->execute([$assetId]);
            $selected = $this->assetPayload($row) + [
                'schedule' => $scheduleRows,
                'totals' => [
                    'depreciable_base_cents' => $base,
                    'posted_depreciation_cents' => $posted,
                    'remaining_depreciable_cents' => max(0, $base - $posted),
                    'net_book_value_cents' => max(
                        0,
                        (int) $row['valeur_acquisition_centimes'] - $posted
                    ),
                    'schedule_cents' => array_sum(array_column(
                        array_filter(
                            $scheduleRows,
                            static fn (array $item): bool =>
                                $item['status'] !== 'annulee'
                        ),
                        'amount_cents'
                    )),
                ],
                'exits' => array_map(
                    static fn (array $exit): array => [
                        'id' => (int) $exit['id'],
                        'type' => (string) $exit['type'],
                        'date' => (string) $exit['date_sortie'],
                        'proceeds_cents' =>
                            (int) $exit['produit_cession_centimes'],
                        'gross_cents' => (int) $exit['valeur_brute_centimes'],
                        'accumulated_cents' =>
                            (int) $exit['amortissement_cumule_centimes'],
                        'net_cents' => (int) $exit['valeur_nette_centimes'],
                        'result_cents' =>
                            (int) $exit['resultat_cession_centimes'],
                        'entry_id' => (int) $exit['ecriture_id'],
                        'reversal_entry_id' =>
                            $exit['ecriture_contrepassation_id'] === null
                                ? null
                                : (int) $exit['ecriture_contrepassation_id'],
                        'status' => (string) $exit['statut'],
                    ],
                    $exitStmt->fetchAll()
                ),
            ];
        }
        return [
            'exercise' => [
                'id' => (int) $exercise['id'],
                'label' => (string) $exercise['libelle'],
                'start_date' => (string) $exercise['date_debut'],
                'end_date' => (string) $exercise['date_fin'],
                'status' => (string) $exercise['statut'],
            ],
            'categories' => $this->categories($organisationId, $dossierId),
            'assets' => $assetRows,
            'selected_asset' => $selected,
            'reconciliation' => $this->reconciliation(
                $organisationId,
                $dossierId,
                (string) $exercise['date_fin']
            ),
            'catalog' => $this->catalog($organisationId, $dossierId),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $perPage)),
            ],
            'definitions' => [
                'method' =>
                    'Linéaire journalier : la base amortissable est répartie '
                    . 'selon les jours calendaires réels, sans flottants.',
                'correction' =>
                    'Une fiche sans écriture peut être corrigée. Toute dotation '
                    . 'ou sortie validée est corrigée par contre-passation.',
                'reconciliation' =>
                    'Le registre est comparé aux soldes cumulés du grand livre '
                    . 'à la fin de l’exercice sélectionné.',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function linearSchedule(
        string $startDate,
        int $durationMonths,
        int $depreciableBaseCents,
    ): array {
        $start = self::parsedDate($startDate);
        if (
            $durationMonths < 1
            || $durationMonths > 1200
            || $depreciableBaseCents < 0
            || $depreciableBaseCents > 1_000_000_000_000
        ) {
            throw new AssetException('Paramètres du plan d’amortissement invalides.');
        }
        $end = self::addMonthsClamped($start, $durationMonths)->modify('-1 day');
        return self::segments($start, $end, $depreciableBaseCents, 1);
    }

    /** @return array<string,int> */
    private function categoryAccounts(array $data): array
    {
        return [
            'asset' => (int) ($data['asset_account_id'] ?? 0),
            'accumulated' =>
                (int) ($data['accumulated_depreciation_account_id'] ?? 0),
            'expense' => (int) ($data['depreciation_expense_account_id'] ?? 0),
            'gain' => (int) ($data['disposal_gain_account_id'] ?? 0),
            'loss' => (int) ($data['disposal_loss_account_id'] ?? 0),
        ];
    }

    /** @param array<string,int> $accounts */
    private function assertAccounts(
        int $organisationId,
        int $dossierId,
        array $accounts,
    ): void {
        $roles = [
            'asset' => ['actif', 'debit'],
            'accumulated' => ['actif', 'credit'],
            'expense' => ['charge', 'debit'],
            'gain' => [null, 'credit'],
            'loss' => [null, 'debit'],
        ];
        $stmt = $this->pdo->prepare(
            'SELECT type, sens_normal FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1'
        );
        foreach ($roles as $key => [$type, $side]) {
            $accountId = $accounts[$key] ?? 0;
            $stmt->execute([$accountId, $organisationId, $dossierId]);
            $account = $stmt->fetch();
            if (
                $account === false
                || ($type !== null && $account['type'] !== $type)
                || $account['sens_normal'] !== $side
            ) {
                throw new AssetException(
                    'Les comptes de la catégorie sont invalides ou hors du dossier.'
                );
            }
        }
        if (count(array_unique($accounts)) !== count($accounts)) {
            throw new AssetException('Les cinq comptes de la catégorie doivent être distincts.');
        }
    }

    /** @return array<string,mixed> */
    private function validatedAssetData(
        int $organisationId,
        int $dossierId,
        array $data,
        array $category,
    ): array {
        $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));
        $reference = trim((string) ($data['acquisition_reference'] ?? ''));
        $acquisitionDate = (string) ($data['acquisition_date'] ?? '');
        $serviceDate = (string) ($data['in_service_date'] ?? '');
        $value = (int) ($data['acquisition_value_cents'] ?? -1);
        $residual = (int) ($data['residual_value_cents'] ?? -1);
        $duration = (int) (
            $data['duration_months'] ?? $category['duree_defaut_mois']
        );
        if (
            preg_match('/^[A-Z0-9_-]{1,30}$/', $code) !== 1
            || $label === ''
            || $reference === ''
            || !$this->validDate($acquisitionDate)
            || !$this->validDate($serviceDate)
            || $acquisitionDate > $serviceDate
            || $value < 1
            || $value > 1_000_000_000_000
            || $residual < 0
            || $residual >= $value
            || $duration < 1
            || $duration > 1200
        ) {
            throw new AssetException('Fiche d’immobilisation invalide.');
        }
        $accounts = [
            'asset' => (int) $category['compte_actif_id'],
            'accumulated' => (int) $category['compte_amortissement_id'],
            'expense' => (int) $category['compte_dotation_id'],
            'gain' => (int) $category['compte_gain_cession_id'],
            'loss' => (int) $category['compte_perte_cession_id'],
        ];
        $this->assertAccounts($organisationId, $dossierId, $accounts);
        $documentId = isset($data['acquisition_document_id'])
            && $data['acquisition_document_id'] !== null
            ? (int) $data['acquisition_document_id']
            : null;
        $attachmentId = isset($data['acquisition_attachment_id'])
            && $data['acquisition_attachment_id'] !== null
            ? (int) $data['acquisition_attachment_id']
            : null;
        $this->assertAcquisitionReferences(
            $organisationId,
            $dossierId,
            $documentId,
            $attachmentId
        );
        return [
            'code' => $code,
            'label' => $label,
            'acquisition_reference' => $reference,
            'acquisition_document_id' => $documentId,
            'acquisition_attachment_id' => $attachmentId,
            'acquisition_date' => $acquisitionDate,
            'in_service_date' => $serviceDate,
            'acquisition_value_cents' => $value,
            'residual_value_cents' => $residual,
            'duration_months' => $duration,
            'accounts' => $accounts,
            'note' => trim((string) ($data['note'] ?? '')),
        ];
    }

    private function assertAcquisitionReferences(
        int $organisationId,
        int $dossierId,
        ?int $documentId,
        ?int $attachmentId,
    ): void {
        foreach ([
            [$documentId, 'documents_financiers'],
            [$attachmentId, 'pieces_jointes'],
        ] as [$id, $table]) {
            if ($id === null) {
                continue;
            }
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM {$table}
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?"
            );
            $stmt->execute([$id, $organisationId, $dossierId]);
            if ($stmt->fetchColumn() === false) {
                throw new AssetException('Pièce d’acquisition absente du dossier.');
            }
        }
    }

    private function insertInitialSchedule(
        int $assetId,
        string $startDate,
        int $durationMonths,
        int $baseCents,
    ): void {
        foreach (self::linearSchedule(
            $startDate,
            $durationMonths,
            $baseCents
        ) as $row) {
            $this->insertScheduleRow($assetId, $row);
        }
    }

    /** @param array<string,mixed> $row */
    private function insertScheduleRow(int $assetId, array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO echeances_amortissement
             (immobilisation_id, ordre, date_debut, date_fin,
              date_comptable, jours, montant_centimes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $assetId, $row['order'], $row['start_date'], $row['end_date'],
            $row['posting_date'], $row['days'], $row['amount_cents'],
        ]);
    }

    private function postScheduleInside(
        int $organisationId,
        int $dossierId,
        int $scheduleId,
        int $exerciseId,
        int $journalId,
        ?int $actorId,
    ): int {
        $schedule = $this->schedule($organisationId, $dossierId, $scheduleId);
        if (
            $schedule['statut'] === 'comptabilisee'
            && $schedule['ecriture_id'] !== null
        ) {
            return (int) $schedule['ecriture_id'];
        }
        if (
            $schedule['statut'] !== 'planifiee'
            || $schedule['asset_status'] !== 'actif'
            || (int) $schedule['montant_centimes'] < 1
        ) {
            throw new AssetException('Échéance non comptabilisable.');
        }
        $date = (string) $schedule['date_comptable'];
        $this->assertPostingContext(
            $organisationId,
            $dossierId,
            $exerciseId,
            $journalId,
            $date
        );
        $version = (int) $schedule['version'];
        try {
            $entryId = $this->entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => $date,
                'libelle' => 'Dotation ' . $schedule['code']
                    . ' — ' . $schedule['asset_label'],
                'reference' => (string) $schedule['reference_piece'],
                'source_type' => 'immobilisation',
                'source_id' => 'echeance:' . $scheduleId,
                'source_action' => 'dotation:v' . $version,
                'lignes' => [
                    [
                        'compte_id' => (int) $schedule['compte_dotation_id'],
                        'libelle' => 'Dotation aux amortissements',
                        'debit_centimes' => (int) $schedule['montant_centimes'],
                        'credit_centimes' => 0,
                    ],
                    [
                        'compte_id' =>
                            (int) $schedule['compte_amortissement_id'],
                        'libelle' => 'Amortissements cumulés',
                        'debit_centimes' => 0,
                        'credit_centimes' =>
                            (int) $schedule['montant_centimes'],
                    ],
                ],
            ], 'immobilisation:dotation:' . $scheduleId . ':v' . $version, $actorId);
        } catch (AccountingException $exception) {
            throw new AssetException($exception->getMessage(), previous: $exception);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE echeances_amortissement
             SET statut = \'comptabilisee\', ecriture_id = ?,
                 comptabilisee_le = datetime(\'now\'), version = version + 1
             WHERE id = ? AND statut = \'planifiee\' AND version = ?'
        );
        $stmt->execute([$entryId, $scheduleId, $version]);
        if ($stmt->rowCount() !== 1) {
            throw new AssetException('Conflit pendant la dotation.');
        }
        $this->audit->log(
            'immobilisations.dotation_comptabilisee',
            $actorId,
            $organisationId,
            $dossierId,
            'echeance_amortissement',
            (string) $scheduleId,
            ['ecriture_id' => $entryId]
        );
        return $entryId;
    }

    private function assertPostingContext(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        int $journalId,
        string $date,
    ): void {
        $exercise = $this->pdo->prepare(
            'SELECT 1 FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             WHERE x.id = ? AND x.dossier_id = ?
               AND d.organisation_id = ? AND x.statut = \'ouvert\'
               AND ? BETWEEN x.date_debut AND x.date_fin'
        );
        $exercise->execute([
            $exerciseId, $dossierId, $organisationId, $date,
        ]);
        $journal = $this->pdo->prepare(
            'SELECT 1 FROM journaux
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1'
        );
        $journal->execute([$journalId, $organisationId, $dossierId]);
        $period = $this->pdo->prepare(
            'SELECT statut FROM periodes
             WHERE organisation_id = ? AND dossier_id = ?
               AND exercice_id = ? AND ? BETWEEN date_debut AND date_fin'
        );
        $period->execute([
            $organisationId, $dossierId, $exerciseId, $date,
        ]);
        $periodStatus = $period->fetchColumn();
        if (
            $exercise->fetchColumn() === false
            || $journal->fetchColumn() === false
            || $periodStatus === false
        ) {
            throw new AssetException(
                'Exercice, journal ou période absent pour cette dotation.'
            );
        }
        if ($periodStatus !== 'ouverte') {
            throw new AssetException('La période comptable est fermée.');
        }
    }

    /** @return array<string,mixed> */
    private function category(
        int $organisationId,
        int $dossierId,
        int $categoryId,
        bool $activeOnly = false,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM categories_immobilisations
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            . ($activeOnly ? ' AND actif = 1' : '')
        );
        $stmt->execute([$categoryId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AssetException('Catégorie absente ou hors du dossier.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function asset(
        int $organisationId,
        int $dossierId,
        int $assetId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, c.code AS categorie_code, c.libelle AS categorie,
                    COALESCE((
                        SELECT SUM(e.montant_centimes)
                        FROM echeances_amortissement e
                        WHERE e.immobilisation_id = i.id
                          AND e.statut = \'comptabilisee\'
                    ), 0) AS amortissement_comptabilise_centimes
             FROM immobilisations i
             JOIN categories_immobilisations c ON c.id = i.categorie_id
             WHERE i.id = ? AND i.organisation_id = ? AND i.dossier_id = ?'
        );
        $stmt->execute([$assetId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AssetException('Immobilisation absente ou hors du dossier.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function schedule(
        int $organisationId,
        int $dossierId,
        int $scheduleId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, i.code, i.libelle AS asset_label,
                    i.reference_piece, i.statut AS asset_status,
                    i.compte_dotation_id, i.compte_amortissement_id
             FROM echeances_amortissement e
             JOIN immobilisations i ON i.id = e.immobilisation_id
             WHERE e.id = ? AND i.organisation_id = ? AND i.dossier_id = ?'
        );
        $stmt->execute([$scheduleId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AssetException('Échéance absente ou hors du dossier.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function exercise(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT x.* FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             WHERE x.id = ? AND x.dossier_id = ? AND d.organisation_id = ?'
        );
        $stmt->execute([$exerciseId, $dossierId, $organisationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AssetException('Exercice absent ou hors du dossier.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function categories(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*,
                    a.numero || \' — \' || a.libelle AS compte_actif,
                    ac.numero || \' — \' || ac.libelle AS compte_amortissement,
                    d.numero || \' — \' || d.libelle AS compte_dotation,
                    g.numero || \' — \' || g.libelle AS compte_gain,
                    p.numero || \' — \' || p.libelle AS compte_perte
             FROM categories_immobilisations c
             JOIN comptes a ON a.id = c.compte_actif_id
             JOIN comptes ac ON ac.id = c.compte_amortissement_id
             JOIN comptes d ON d.id = c.compte_dotation_id
             JOIN comptes g ON g.id = c.compte_gain_cession_id
             JOIN comptes p ON p.id = c.compte_perte_cession_id
             WHERE c.organisation_id = ? AND c.dossier_id = ?
             ORDER BY c.actif DESC, c.code'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
            'default_duration_months' => (int) $row['duree_defaut_mois'],
            'asset_account_id' => (int) $row['compte_actif_id'],
            'accumulated_depreciation_account_id' =>
                (int) $row['compte_amortissement_id'],
            'depreciation_expense_account_id' =>
                (int) $row['compte_dotation_id'],
            'disposal_gain_account_id' =>
                (int) $row['compte_gain_cession_id'],
            'disposal_loss_account_id' =>
                (int) $row['compte_perte_cession_id'],
            'accounts' => [
                'asset' => (string) $row['compte_actif'],
                'accumulated' => (string) $row['compte_amortissement'],
                'expense' => (string) $row['compte_dotation'],
                'gain' => (string) $row['compte_gain'],
                'loss' => (string) $row['compte_perte'],
            ],
            'active' => (int) $row['actif'] === 1,
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    private function catalog(int $organisationId, int $dossierId): array
    {
        $accounts = $this->pdo->prepare(
            'SELECT id, numero, libelle, type, sens_normal
             FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1
             ORDER BY length(numero), numero'
        );
        $accounts->execute([$organisationId, $dossierId]);
        $journals = $this->pdo->prepare(
            'SELECT id, code, libelle FROM journaux
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
             ORDER BY code'
        );
        $journals->execute([$organisationId, $dossierId]);
        $documents = $this->pdo->prepare(
            'SELECT id, numero, numero_externe, date_document,
                    total_brut_centimes
             FROM documents_financiers
             WHERE organisation_id = ? AND dossier_id = ?
               AND type = \'facture_fournisseur\'
             ORDER BY date_document DESC, id DESC LIMIT 100'
        );
        $documents->execute([$organisationId, $dossierId]);
        return [
            'accounts' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'label' => (string) $row['libelle'],
                'type' => (string) $row['type'],
                'normal_side' => (string) $row['sens_normal'],
            ], $accounts->fetchAll()),
            'journals' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'label' => (string) $row['libelle'],
            ], $journals->fetchAll()),
            'acquisition_documents' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'number' => trim((string) $row['numero']) !== ''
                        ? (string) $row['numero']
                        : (string) $row['numero_externe'],
                    'date' => (string) $row['date_document'],
                    'gross_cents' => (int) $row['total_brut_centimes'],
                ],
                $documents->fetchAll()
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function reconciliation(
        int $organisationId,
        int $dossierId,
        string $asOfDate,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT i.compte_actif_id, i.compte_amortissement_id,
                    a.numero AS actif_numero, a.libelle AS actif_libelle,
                    ac.numero AS amort_numero, ac.libelle AS amort_libelle,
                    COALESCE((
                        SELECT SUM(x.valeur_acquisition_centimes)
                        FROM immobilisations x
                        WHERE x.organisation_id = ? AND x.dossier_id = ?
                          AND x.compte_actif_id = i.compte_actif_id
                          AND x.compte_amortissement_id =
                              i.compte_amortissement_id
                          AND x.date_mise_service <= ?
                          AND (x.date_sortie IS NULL OR x.date_sortie > ?)
                    ), 0) AS registre_brut,
                    COALESCE((
                        SELECT SUM(e.montant_centimes)
                        FROM echeances_amortissement e
                        JOIN immobilisations x
                          ON x.id = e.immobilisation_id
                        WHERE x.organisation_id = ? AND x.dossier_id = ?
                          AND x.compte_actif_id = i.compte_actif_id
                          AND x.compte_amortissement_id =
                              i.compte_amortissement_id
                          AND e.statut = \'comptabilisee\'
                          AND e.date_comptable <= ?
                          AND (x.date_sortie IS NULL OR x.date_sortie > ?)
                    ), 0) AS registre_amortissement
             FROM immobilisations i
             JOIN comptes a ON a.id = i.compte_actif_id
             JOIN comptes ac ON ac.id = i.compte_amortissement_id
             WHERE i.organisation_id = ? AND i.dossier_id = ?
             GROUP BY i.compte_actif_id, i.compte_amortissement_id,
                      a.numero, a.libelle, ac.numero, ac.libelle
             ORDER BY a.numero, ac.numero'
        );
        $stmt->execute([
            $organisationId, $dossierId, $asOfDate, $asOfDate,
            $organisationId, $dossierId, $asOfDate, $asOfDate,
            $organisationId, $dossierId,
        ]);
        $ledger = $this->pdo->prepare(
            'SELECT COALESCE(SUM(
                    CASE WHEN l.compte_id = ?
                         THEN l.debit_centimes - l.credit_centimes ELSE 0 END
                  ), 0) AS actif,
                    COALESCE(SUM(
                    CASE WHEN l.compte_id = ?
                         THEN l.credit_centimes - l.debit_centimes ELSE 0 END
                  ), 0) AS amortissement
             FROM lignes_ecriture l
             JOIN ecritures e ON e.id = l.ecriture_id
             WHERE e.organisation_id = ? AND e.dossier_id = ?
               AND e.statut IN (\'validee\', \'contre_passee\')
               AND e.date_comptable <= ?'
        );
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $ledger->execute([
                (int) $row['compte_actif_id'],
                (int) $row['compte_amortissement_id'],
                $organisationId,
                $dossierId,
                $asOfDate,
            ]);
            $balances = $ledger->fetch();
            $registerGross = (int) $row['registre_brut'];
            $registerAccumulated = (int) $row['registre_amortissement'];
            $ledgerGross = (int) ($balances['actif'] ?? 0);
            $ledgerAccumulated = (int) ($balances['amortissement'] ?? 0);
            $rows[] = [
                'asset_account_id' => (int) $row['compte_actif_id'],
                'asset_account' => $row['actif_numero'] . ' — '
                    . $row['actif_libelle'],
                'accumulated_account_id' =>
                    (int) $row['compte_amortissement_id'],
                'accumulated_account' => $row['amort_numero'] . ' — '
                    . $row['amort_libelle'],
                'register_gross_cents' => $registerGross,
                'ledger_gross_cents' => $ledgerGross,
                'gross_difference_cents' => $ledgerGross - $registerGross,
                'register_accumulated_cents' => $registerAccumulated,
                'ledger_accumulated_cents' => $ledgerAccumulated,
                'accumulated_difference_cents' =>
                    $ledgerAccumulated - $registerAccumulated,
                'reconciled' => $ledgerGross === $registerGross
                    && $ledgerAccumulated === $registerAccumulated,
                'as_of_date' => $asOfDate,
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function assetPayload(array $row): array
    {
        $posted = (int) ($row['amortissement_comptabilise_centimes'] ?? 0);
        return [
            'id' => (int) $row['id'],
            'category_id' => (int) $row['categorie_id'],
            'category_code' => (string) $row['categorie_code'],
            'category' => (string) $row['categorie'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
            'acquisition_reference' => (string) $row['reference_piece'],
            'acquisition_document_id' =>
                $row['document_acquisition_id'] === null
                    ? null
                    : (int) $row['document_acquisition_id'],
            'acquisition_attachment_id' =>
                $row['piece_acquisition_id'] === null
                    ? null
                    : (int) $row['piece_acquisition_id'],
            'acquisition_date' => (string) $row['date_acquisition'],
            'in_service_date' => (string) $row['date_mise_service'],
            'acquisition_value_cents' =>
                (int) $row['valeur_acquisition_centimes'],
            'residual_value_cents' =>
                (int) $row['valeur_residuelle_centimes'],
            'duration_months' => (int) $row['duree_mois'],
            'method' => (string) $row['methode'],
            'prorata_rule' => (string) $row['regle_prorata'],
            'asset_account_id' => (int) $row['compte_actif_id'],
            'accumulated_depreciation_account_id' =>
                (int) $row['compte_amortissement_id'],
            'depreciation_expense_account_id' =>
                (int) $row['compte_dotation_id'],
            'disposal_gain_account_id' =>
                (int) $row['compte_gain_cession_id'],
            'disposal_loss_account_id' =>
                (int) $row['compte_perte_cession_id'],
            'status' => (string) $row['statut'],
            'exit_date' => $row['date_sortie'] === null
                ? null : (string) $row['date_sortie'],
            'posted_depreciation_cents' => $posted,
            'net_book_value_cents' => max(
                0,
                (int) $row['valeur_acquisition_centimes'] - $posted
            ),
            'note' => (string) $row['note'],
            'version' => (int) $row['version'],
        ];
    }

    private function depreciationThrough(
        array $asset,
        string $date,
        int $assetId,
    ): int {
        $fullPlan = self::linearSchedule(
            (string) $asset['date_mise_service'],
            (int) $asset['duree_mois'],
            (int) $asset['valeur_acquisition_centimes']
                - (int) $asset['valeur_residuelle_centimes']
        );
        $target = 0;
        foreach ($fullPlan as $row) {
            if ($row['end_date'] <= $date) {
                $target += (int) $row['amount_cents'];
                continue;
            }
            if ($row['start_date'] <= $date) {
                $totalDays = (int) $row['days'];
                $elapsed = $this->daysInclusive(
                    (string) $row['start_date'],
                    $date
                );
                $target += intdiv(
                    (int) $row['amount_cents'] * $elapsed,
                    $totalDays
                );
            }
            break;
        }
        return max(0, $target - $this->postedDepreciation($assetId));
    }

    private function postedDepreciation(int $assetId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(montant_centimes), 0)
             FROM echeances_amortissement
             WHERE immobilisation_id = ? AND statut = \'comptabilisee\''
        );
        $stmt->execute([$assetId]);
        return (int) $stmt->fetchColumn();
    }

    private function rebuildFutureAfter(
        array $asset,
        string $exitDate,
        int $assetId,
    ): void {
        $this->pdo->prepare(
            'DELETE FROM echeances_amortissement
             WHERE immobilisation_id = ? AND statut = \'annulee\''
        )->execute([$assetId]);
        $lifeEnd = self::addMonthsClamped(
            self::parsedDate((string) $asset['date_mise_service']),
            (int) $asset['duree_mois']
        )->modify('-1 day');
        $start = self::parsedDate($exitDate)->modify('+1 day');
        if ($start > $lifeEnd) {
            return;
        }
        $base = (int) $asset['valeur_acquisition_centimes']
            - (int) $asset['valeur_residuelle_centimes'];
        $remaining = max(0, $base - $this->postedDepreciation($assetId));
        if ($remaining === 0) {
            return;
        }
        $maxOrder = $this->pdo->prepare(
            'SELECT COALESCE(MAX(ordre), 0)
             FROM echeances_amortissement WHERE immobilisation_id = ?'
        );
        $maxOrder->execute([$assetId]);
        $order = (int) $maxOrder->fetchColumn() + 1;
        foreach (self::segments($start, $lifeEnd, $remaining, $order) as $row) {
            $this->insertScheduleRow($assetId, $row);
        }
    }

    private function assertPostingAccount(
        int $organisationId,
        int $dossierId,
        int $accountId,
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1'
        );
        $stmt->execute([$accountId, $organisationId, $dossierId]);
        if ($stmt->fetchColumn() === false) {
            throw new AssetException('Compte de produit hors du dossier.');
        }
    }

    private function daysInclusive(string $start, string $end): int
    {
        return self::parsedDate($start)->diff(self::parsedDate($end))->days + 1;
    }

    private function assertDate(string $date): void
    {
        self::parsedDate($date);
    }

    private function validDate(string $date): bool
    {
        try {
            self::parsedDate($date);
            return true;
        } catch (AssetException) {
            return false;
        }
    }

    private static function parsedDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new AssetException('Date d’immobilisation invalide.');
        }
        return $parsed;
    }

    private static function addMonthsClamped(
        DateTimeImmutable $date,
        int $months,
    ): DateTimeImmutable {
        $monthIndex = ((int) $date->format('Y')) * 12
            + ((int) $date->format('n') - 1)
            + $months;
        $year = intdiv($monthIndex, 12);
        $month = ($monthIndex % 12) + 1;
        $first = $date->setDate($year, $month, 1);
        $day = min((int) $date->format('j'), (int) $first->format('t'));
        return $first->setDate($year, $month, $day);
    }

    /** @return list<array<string,mixed>> */
    private static function segments(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $amountCents,
        int $firstOrder,
    ): array {
        if ($start > $end) {
            return [];
        }
        $totalDays = $start->diff($end)->days + 1;
        $rows = [];
        $current = $start;
        $cumulativeDays = 0;
        $allocated = 0;
        $order = $firstOrder;
        while ($current <= $end) {
            $monthEnd = $current->modify('last day of this month');
            $segmentEnd = $monthEnd < $end ? $monthEnd : $end;
            $days = $current->diff($segmentEnd)->days + 1;
            $cumulativeDays += $days;
            $cumulativeAmount = intdiv(
                $amountCents * $cumulativeDays,
                $totalDays
            );
            $segmentAmount = $cumulativeAmount - $allocated;
            $rows[] = [
                'order' => $order++,
                'start_date' => $current->format('Y-m-d'),
                'end_date' => $segmentEnd->format('Y-m-d'),
                'posting_date' => $segmentEnd->format('Y-m-d'),
                'days' => $days,
                'amount_cents' => $segmentAmount,
            ];
            $allocated = $cumulativeAmount;
            $current = $segmentEnd->modify('+1 day');
        }
        return $rows;
    }

    private function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
