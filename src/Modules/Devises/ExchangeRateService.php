<?php
declare(strict_types=1);

namespace Compta\Modules\Devises;

use Compta\Core\Audit\AuditLogger;
use DateTimeImmutable;
use PDO;

final class ExchangeRateService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Le ratio exprime des unités mineures de la devise de base par unité
     * mineure de la devise d’origine. Le calcul reste entièrement entier.
     */
    public static function convert(int $amountCents, int $numerator, int $denominator): int
    {
        if ($numerator < 1 || $denominator < 1) {
            throw new ExchangeRateException('Ratio de change incohérent.');
        }
        $negative = $amountCents < 0;
        $absolute = abs($amountCents);
        $quotient = intdiv($absolute, $denominator);
        $remainder = $absolute % $denominator;
        $converted = ($quotient * $numerator)
            + intdiv(($remainder * $numerator) + intdiv($denominator, 2), $denominator);
        return $negative ? -$converted : $converted;
    }

    /**
     * @return array{
     *   currency:string,base_currency:string,numerator:int,denominator:int,
     *   rate_date:string,source:string,rate_id:?int
     * }
     */
    public function snapshot(
        int $organisationId,
        int $dossierId,
        string $currency,
        string $transactionDate,
        ?int $rateId = null,
    ): array {
        $currency = strtoupper(trim($currency));
        if (!$this->validCurrency($currency) || !$this->validDate($transactionDate)) {
            throw new ExchangeRateException('Devise ou date de change invalide.');
        }
        $base = $this->baseCurrency($organisationId, $dossierId);
        if ($currency === $base) {
            if ($rateId !== null) {
                throw new ExchangeRateException(
                    'Aucun taux externe ne doit être fourni pour la devise de base.'
                );
            }
            return [
                'currency' => $currency,
                'base_currency' => $base,
                'numerator' => 1,
                'denominator' => 1,
                'rate_date' => $transactionDate,
                'source' => 'devise_base',
                'rate_id' => null,
            ];
        }
        $allowed = $this->pdo->prepare(
            'SELECT 1 FROM devises_dossier
             WHERE organisation_id = ? AND dossier_id = ? AND code = ? AND actif = 1'
        );
        $allowed->execute([$organisationId, $dossierId, $currency]);
        if ($allowed->fetchColumn() === false) {
            throw new ExchangeRateException(
                "La devise {$currency} n’est pas activée pour ce dossier."
            );
        }
        if ($rateId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM taux_change
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND devise_source = ? AND devise_cible = ?
                   AND actif = 1 AND date_taux <= ?
                 ORDER BY date_taux DESC, id DESC LIMIT 1'
            );
            $stmt->execute([
                $organisationId, $dossierId, $currency, $base, $transactionDate,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM taux_change
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND devise_source = ? AND devise_cible = ? AND actif = 1'
            );
            $stmt->execute([
                $rateId, $organisationId, $dossierId, $currency, $base,
            ]);
        }
        $rate = $stmt->fetch();
        if ($rate === false) {
            throw new ExchangeRateException(
                "Aucun taux {$currency}/{$base} applicable n’est disponible."
            );
        }
        if ((string) $rate['date_taux'] > $transactionDate) {
            throw new ExchangeRateException(
                'Un taux futur ne peut pas être appliqué à cette opération.'
            );
        }
        if (
            (int) $rate['numerateur'] < 1
            || (int) $rate['denominateur'] < 1
            || trim((string) $rate['source']) === ''
            || !$this->validDate((string) $rate['verifie_le'])
        ) {
            throw new ExchangeRateException('Le taux choisi est incomplet ou incohérent.');
        }
        return [
            'currency' => $currency,
            'base_currency' => $base,
            'numerator' => (int) $rate['numerateur'],
            'denominator' => (int) $rate['denominateur'],
            'rate_date' => (string) $rate['date_taux'],
            'source' => (string) $rate['source'],
            'rate_id' => (int) $rate['id'],
        ];
    }

    /** @return array<string,mixed> */
    public function configuration(int $organisationId, int $dossierId): array
    {
        $base = $this->baseCurrency($organisationId, $dossierId);
        $currencies = $this->pdo->prepare(
            'SELECT code, actif, version FROM devises_dossier
             WHERE organisation_id = ? AND dossier_id = ? ORDER BY code'
        );
        $currencies->execute([$organisationId, $dossierId]);
        $items = [[
            'code' => $base,
            'active' => true,
            'is_base' => true,
            'version' => 1,
        ]];
        foreach ($currencies->fetchAll() as $row) {
            if ((string) $row['code'] === $base) {
                continue;
            }
            $items[] = [
                'code' => (string) $row['code'],
                'active' => (int) $row['actif'] === 1,
                'is_base' => false,
                'version' => (int) $row['version'],
            ];
        }
        $rates = $this->pdo->prepare(
            'SELECT id, devise_source, devise_cible, date_taux, numerateur,
                    denominateur, source, verifie_le, actif, version
             FROM taux_change
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY date_taux DESC, id DESC'
        );
        $rates->execute([$organisationId, $dossierId]);
        $settings = $this->pdo->prepare(
            'SELECT * FROM parametres_change
             WHERE organisation_id = ? AND dossier_id = ?'
        );
        $settings->execute([$organisationId, $dossierId]);
        $mapping = $settings->fetch();
        return [
            'base_currency' => $base,
            'currencies' => $items,
            'rates' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'source_currency' => (string) $row['devise_source'],
                'target_currency' => (string) $row['devise_cible'],
                'rate_date' => (string) $row['date_taux'],
                'numerator' => (int) $row['numerateur'],
                'denominator' => (int) $row['denominateur'],
                'source' => (string) $row['source'],
                'verified_on' => (string) $row['verifie_le'],
                'active' => (int) $row['actif'] === 1,
                'version' => (int) $row['version'],
            ], $rates->fetchAll()),
            'mapping' => $mapping === false ? null : [
                'realized_gain_account_id' => (int) $mapping['compte_gain_realise_id'],
                'realized_loss_account_id' => (int) $mapping['compte_perte_realisee_id'],
                'unrealized_gain_account_id' => (int) $mapping['compte_gain_latent_id'],
                'unrealized_loss_account_id' => (int) $mapping['compte_perte_latente_id'],
                'version' => (int) $mapping['version'],
            ],
        ];
    }

    public function saveCurrency(
        int $organisationId,
        int $dossierId,
        string $currency,
        bool $active,
        ?int $actorId = null,
    ): void {
        $currency = strtoupper(trim($currency));
        $base = $this->baseCurrency($organisationId, $dossierId);
        if (!$this->validCurrency($currency) || $currency === $base) {
            throw new ExchangeRateException(
                'La devise de base est toujours active et ne se modifie pas ici.'
            );
        }
        if (!$active) {
            $used = $this->pdo->prepare(
                'SELECT
                   (SELECT COUNT(*) FROM documents_financiers
                    WHERE dossier_id = ? AND monnaie = ? AND statut <> \'annule\')
                 + (SELECT COUNT(*) FROM paiements
                    WHERE dossier_id = ? AND monnaie = ? AND statut = \'valide\')'
            );
            $used->execute([$dossierId, $currency, $dossierId, $currency]);
            if ((int) $used->fetchColumn() > 0) {
                throw new ExchangeRateException(
                    'Une devise utilisée par une opération active ne peut pas être désactivée.'
                );
            }
        }
        $this->pdo->prepare(
            'INSERT INTO devises_dossier
             (organisation_id, dossier_id, code, actif, cree_par, modifie_par)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(dossier_id, code) DO UPDATE SET
               actif = excluded.actif, modifie_le = datetime(\'now\'),
               modifie_par = excluded.modifie_par, version = version + 1'
        )->execute([
            $organisationId, $dossierId, $currency, $active ? 1 : 0,
            $actorId, $actorId,
        ]);
        $this->audit->log(
            'devises.devise_configuree',
            $actorId,
            $organisationId,
            $dossierId,
            'devise',
            $currency,
            ['active' => $active]
        );
    }

    /** @param array<string,mixed> $data */
    public function saveRate(
        int $organisationId,
        int $dossierId,
        array $data,
        ?int $actorId = null,
    ): int {
        $currency = strtoupper(trim((string) ($data['source_currency'] ?? '')));
        $base = $this->baseCurrency($organisationId, $dossierId);
        $date = (string) ($data['rate_date'] ?? '');
        $verified = (string) ($data['verified_on'] ?? '');
        $source = trim((string) ($data['source'] ?? ''));
        $numerator = (int) ($data['numerator'] ?? 0);
        $denominator = (int) ($data['denominator'] ?? 0);
        if (
            !$this->validCurrency($currency)
            || $currency === $base
            || !$this->validDate($date)
            || !$this->validDate($verified)
            || $verified < $date
            || $source === ''
            || $numerator < 1
            || $denominator < 1
        ) {
            throw new ExchangeRateException(
                'Devise, ratio, source ou dates du taux de change incohérents.'
            );
        }
        $allowed = $this->pdo->prepare(
            'SELECT 1 FROM devises_dossier
             WHERE organisation_id = ? AND dossier_id = ? AND code = ? AND actif = 1'
        );
        $allowed->execute([$organisationId, $dossierId, $currency]);
        if ($allowed->fetchColumn() === false) {
            throw new ExchangeRateException('Activez la devise avant de saisir son taux.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO taux_change
             (organisation_id, dossier_id, devise_source, devise_cible,
              date_taux, numerateur, denominateur, source, verifie_le,
              actif, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $currency, $base, $date,
            $numerator, $denominator, $source, $verified,
            ($data['active'] ?? true) ? 1 : 0, $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'devises.taux_cree',
            $actorId,
            $organisationId,
            $dossierId,
            'taux_change',
            (string) $id,
            [
                'devise_source' => $currency,
                'devise_cible' => $base,
                'date_taux' => $date,
                'numerateur' => $numerator,
                'denominateur' => $denominator,
                'source' => $source,
            ]
        );
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function saveMapping(
        int $organisationId,
        int $dossierId,
        array $data,
        ?int $actorId = null,
    ): void {
        $ids = [
            (int) ($data['realized_gain_account_id'] ?? 0),
            (int) ($data['realized_loss_account_id'] ?? 0),
            (int) ($data['unrealized_gain_account_id'] ?? 0),
            (int) ($data['unrealized_loss_account_id'] ?? 0),
        ];
        if (min($ids) < 1 || count(array_unique($ids)) < 2) {
            throw new ExchangeRateException('Comptes de différences de change incomplets.');
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1 AND id IN ({$marks})"
        );
        $stmt->execute([$organisationId, $dossierId, ...$ids]);
        if ((int) $stmt->fetchColumn() !== count(array_unique($ids))) {
            throw new ExchangeRateException('Compte de change absent ou hors du dossier.');
        }
        $this->pdo->prepare(
            'INSERT INTO parametres_change
             (dossier_id, organisation_id, compte_gain_realise_id,
              compte_perte_realisee_id, compte_gain_latent_id,
              compte_perte_latente_id, modifie_par)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(dossier_id) DO UPDATE SET
               compte_gain_realise_id = excluded.compte_gain_realise_id,
               compte_perte_realisee_id = excluded.compte_perte_realisee_id,
               compte_gain_latent_id = excluded.compte_gain_latent_id,
               compte_perte_latente_id = excluded.compte_perte_latente_id,
               modifie_le = datetime(\'now\'), modifie_par = excluded.modifie_par,
               version = version + 1'
        )->execute([$dossierId, $organisationId, ...$ids, $actorId]);
        $this->audit->log(
            'devises.comptes_configures',
            $actorId,
            $organisationId,
            $dossierId,
            'parametres_change',
            (string) $dossierId
        );
    }

    /** @return array<string,int> */
    public function mapping(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM parametres_change
             WHERE organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ExchangeRateException(
                'Configurez les comptes de gains et pertes de change.'
            );
        }
        return [
            'realized_gain' => (int) $row['compte_gain_realise_id'],
            'realized_loss' => (int) $row['compte_perte_realisee_id'],
            'unrealized_gain' => (int) $row['compte_gain_latent_id'],
            'unrealized_loss' => (int) $row['compte_perte_latente_id'],
        ];
    }

    private function baseCurrency(int $organisationId, int $dossierId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT monnaie FROM dossiers WHERE id = ? AND organisation_id = ?'
        );
        $stmt->execute([$dossierId, $organisationId]);
        $base = $stmt->fetchColumn();
        if (!is_string($base) || !$this->validCurrency($base)) {
            throw new ExchangeRateException('Devise de base du dossier invalide.');
        }
        return strtoupper($base);
    }

    private function validCurrency(string $currency): bool
    {
        return preg_match('/^[A-Z]{3}$/', $currency) === 1;
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
