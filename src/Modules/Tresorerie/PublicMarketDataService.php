<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use DateInterval;
use DateTimeImmutable;
use JsonException;
use PDO;
use Throwable;

final class PublicMarketDataService
{
    private const SNB_EXCHANGE_URL = 'https://data.snb.ch/api/cube/devkum/data/json/fr';
    private const SNB_INTEREST_URL = 'https://data.snb.ch/api/cube/zimoma/data/json/fr';
    private const BAZG_DAILY_URL = 'https://www.backend-rates.bazg.admin.ch/api/xmldaily';
    private const SCALE = 100000000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PublicMarketHttpClient $http,
        private readonly ?DateTimeImmutable $today = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function exchangeHistory(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $scope = $this->scope($organisationId, $dossierId, $exerciseId);
        $requirements = $this->monthlyRequirements($exerciseId, true);
        $this->pruneMonthly('devkum', $requirements);
        $warning = $this->ensureMonthly('devkum', $requirements);
        $this->pruneDaily($this->definedCurrencies());
        $dailyWarning = $this->ensureDaily($scope['currencies']);
        return [
            'kind' => 'exchange',
            'exercise' => $scope['exercise'],
            'window' => $scope['window'],
            'periods' => $this->periods($scope['window']['start'], $scope['window']['end']),
            'currencies' => $scope['currencies'],
            'quote_currency' => 'CHF',
            'series' => $this->monthlySeries(
                'change',
                $scope['currencies'],
                $scope['window']['start'],
                $scope['window']['end']
            ),
            'daily' => $this->dailyRates($scope['currencies']),
            'refresh' => [
                'monthly' => $this->refreshState('devkum', $warning),
                'daily' => $this->refreshState('bazg_daily', $dailyWarning),
            ],
            'definitions' => [
                'monthly' => 'Cours BNS à 11 h en CHF, moyenne mensuelle ou fin de mois.',
                'daily' => 'Taux de vente OFDF du jour demandé ou du dernier jour disponible.',
                'accounting' => 'Ces valeurs publiques sont analytiques. Une écriture conserve toujours son propre taux figé.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function interestHistory(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $scope = $this->scope($organisationId, $dossierId, $exerciseId);
        $requirements = $this->monthlyRequirements($exerciseId, false);
        $this->pruneMonthly('zimoma', $requirements);
        $warning = $this->ensureMonthly('zimoma', $requirements);
        return [
            'kind' => 'interest',
            'exercise' => $scope['exercise'],
            'window' => $scope['window'],
            'periods' => $this->periods($scope['window']['start'], $scope['window']['end']),
            'currencies' => $scope['currencies'],
            'series' => $this->monthlySeries(
                'interet',
                $scope['currencies'],
                $scope['window']['start'],
                $scope['window']['end']
            ),
            'refresh' => [
                'monthly' => $this->refreshState('zimoma', $warning),
            ],
            'definitions' => [
                'monthly' => 'Taux du marché monétaire publiés mensuellement par la BNS, en pour-cent.',
                'scope' => 'Les séries correspondent aux monnaies actives du dossier sélectionné.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function scope(int $organisationId, int $dossierId, int $exerciseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT x.id, x.libelle, x.date_debut, x.date_fin, x.statut,
                    d.monnaie
             FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             WHERE x.id = ? AND x.dossier_id = ? AND d.organisation_id = ?'
        );
        $stmt->execute([$exerciseId, $dossierId, $organisationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new TreasuryException('Exercice absent du dossier sélectionné.');
        }
        $currencies = [(string) $row['monnaie']];
        $configured = $this->pdo->prepare(
            'SELECT code FROM devises_dossier
             WHERE dossier_id = ? AND organisation_id = ? AND actif = 1
             ORDER BY code'
        );
        $configured->execute([$dossierId, $organisationId]);
        foreach ($configured->fetchAll(PDO::FETCH_COLUMN) as $currency) {
            $currencies[] = (string) $currency;
        }
        $currencies = array_values(array_unique(array_map('strtoupper', $currencies)));
        sort($currencies, SORT_STRING);
        $start = (new DateTimeImmutable((string) $row['date_debut']))
            ->sub(new DateInterval('P12M'))->format('Y-m');
        $end = (new DateTimeImmutable((string) $row['date_fin']))->format('Y-m');
        return [
            'exercise' => [
                'id' => (int) $row['id'],
                'label' => (string) $row['libelle'],
                'start_date' => (string) $row['date_debut'],
                'end_date' => (string) $row['date_fin'],
                'status' => (string) $row['statut'],
            ],
            'window' => ['start' => $start, 'end' => $end],
            'currencies' => $currencies,
        ];
    }

    /**
     * Conserve l’union des besoins des exercices ouverts et de l’exercice
     * explicitement consulté. Une valeur partagée n’est donc supprimée que si
     * aucun dossier courant n’en a encore besoin.
     *
     * @return array<string,list<array{start:string,end:string}>>
     */
    private function monthlyRequirements(
        int $requestedExerciseId,
        bool $exchangeOnly,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT x.id, x.date_debut, x.date_fin,
                    d.organisation_id, d.id AS dossier_id, d.monnaie,
                    dd.code AS devise_configuree
             FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             LEFT JOIN devises_dossier dd
               ON dd.organisation_id = d.organisation_id
              AND dd.dossier_id = d.id
              AND dd.actif = 1
             WHERE x.statut = \'ouvert\' OR x.id = ?
             ORDER BY x.id, dd.code'
        );
        $stmt->execute([$requestedExerciseId]);
        $latestAvailable = $this->now()
            ->modify('first day of this month -1 month')->format('Y-m');
        $requirements = [];
        foreach ($stmt->fetchAll() as $row) {
            $start = (new DateTimeImmutable((string) $row['date_debut']))
                ->sub(new DateInterval('P12M'))->format('Y-m');
            $end = min(
                (new DateTimeImmutable((string) $row['date_fin']))->format('Y-m'),
                $latestAvailable
            );
            if ($start > $end) {
                continue;
            }
            $currencies = [(string) $row['monnaie']];
            if ($row['devise_configuree'] !== null) {
                $currencies[] = (string) $row['devise_configuree'];
            }
            foreach (array_unique(array_map('strtoupper', $currencies)) as $currency) {
                if ($exchangeOnly && $currency === 'CHF') {
                    continue;
                }
                $requirements[$currency][] = ['start' => $start, 'end' => $end];
            }
        }
        ksort($requirements, SORT_STRING);
        foreach ($requirements as $currency => $intervals) {
            usort(
                $intervals,
                static fn (array $left, array $right): int
                    => $left['start'] <=> $right['start']
            );
            $merged = [];
            foreach ($intervals as $interval) {
                $last = count($merged) - 1;
                if (
                    $last >= 0
                    && $interval['start'] <= (new DateTimeImmutable(
                        $merged[$last]['end'] . '-01'
                    ))->modify('+1 month')->format('Y-m')
                ) {
                    $merged[$last]['end'] = max(
                        $merged[$last]['end'],
                        $interval['end']
                    );
                    continue;
                }
                $merged[] = $interval;
            }
            $requirements[$currency] = $merged;
        }
        return $requirements;
    }

    /** @return list<string> */
    private function definedCurrencies(): array
    {
        $currencies = $this->pdo->query(
            'SELECT monnaie AS code FROM dossiers
             UNION
             SELECT code FROM devises_dossier WHERE actif = 1
             ORDER BY code'
        )->fetchAll(PDO::FETCH_COLUMN);
        $currencies = array_values(array_unique(array_map(
            static fn (mixed $currency): string => strtoupper((string) $currency),
            $currencies
        )));
        sort($currencies, SORT_STRING);
        return $currencies;
    }

    /**
     * @param array<string,list<array{start:string,end:string}>> $requirements
     */
    private function pruneMonthly(string $dataset, array $requirements): void
    {
        $this->pdo->exec(
            'CREATE TEMP TABLE IF NOT EXISTS besoins_marche_mensuels (
                devise TEXT NOT NULL,
                periode_debut TEXT NOT NULL,
                periode_fin TEXT NOT NULL
            )'
        );
        $this->pdo->exec('DELETE FROM besoins_marche_mensuels');
        $insert = $this->pdo->prepare(
            'INSERT INTO besoins_marche_mensuels
             (devise, periode_debut, periode_fin) VALUES (?, ?, ?)'
        );
        foreach ($requirements as $currency => $intervals) {
            foreach ($intervals as $interval) {
                $insert->execute([$currency, $interval['start'], $interval['end']]);
            }
        }
        $this->pdo->prepare(
            'DELETE FROM valeurs_marche_mensuelles
             WHERE serie_id IN (
                 SELECT id FROM series_marche_publiques WHERE jeu_donnees = ?
             )
             AND NOT EXISTS (
                 SELECT 1
                 FROM series_marche_publiques s
                 JOIN besoins_marche_mensuels b ON b.devise = s.devise
                 WHERE s.id = valeurs_marche_mensuelles.serie_id
                   AND valeurs_marche_mensuelles.periode
                       BETWEEN b.periode_debut AND b.periode_fin
             )'
        )->execute([$dataset]);
        $this->pdo->prepare(
            'DELETE FROM series_marche_publiques
             WHERE jeu_donnees = ?
               AND NOT EXISTS (
                   SELECT 1 FROM valeurs_marche_mensuelles v
                   WHERE v.serie_id = series_marche_publiques.id
               )'
        )->execute([$dataset]);
    }

    /** @param list<string> $currencies */
    private function pruneDaily(array $currencies): void
    {
        $currencies = array_values(array_diff($currencies, ['CHF']));
        if ($currencies === []) {
            $this->pdo->exec('DELETE FROM taux_change_publics_quotidiens');
            return;
        }
        $marks = implode(',', array_fill(0, count($currencies), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM taux_change_publics_quotidiens
             WHERE date_requise <> ? OR devise NOT IN ({$marks})"
        );
        $stmt->execute([$this->now()->format('Y-m-d'), ...$currencies]);
    }

    /**
     * @param array<string,list<array{start:string,end:string}>> $requirements
     */
    private function monthlyDataMissing(string $dataset, array $requirements): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.devise, s.mode, v.periode
             FROM series_marche_publiques s
             JOIN valeurs_marche_mensuelles v ON v.serie_id = s.id
             WHERE s.jeu_donnees = ?'
        );
        $stmt->execute([$dataset]);
        $coverage = [];
        foreach ($stmt->fetchAll() as $row) {
            $coverage[(string) $row['devise']][(string) $row['mode']]
                [(string) $row['periode']] = true;
        }
        foreach ($requirements as $currency => $intervals) {
            foreach ($intervals as $interval) {
                foreach ($this->periods($interval['start'], $interval['end']) as $period) {
                    if ($dataset === 'devkum') {
                        foreach (['moyenne', 'fin_mois'] as $mode) {
                            if (!isset($coverage[$currency][$mode][$period])) {
                                return true;
                            }
                        }
                        continue;
                    }
                    $available = false;
                    foreach (($coverage[$currency] ?? []) as $periods) {
                        if (isset($periods[$period])) {
                            $available = true;
                            break;
                        }
                    }
                    if (!$available) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @param array<string,list<array{start:string,end:string}>> $requirements
     */
    private function requiredPeriod(
        array $requirements,
        string $currency,
        string $period,
    ): bool {
        foreach (($requirements[$currency] ?? []) as $interval) {
            if ($period >= $interval['start'] && $period <= $interval['end']) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,list<array{start:string,end:string}>> $requirements
     */
    private function requirementSignature(string $dataset, array $requirements): string
    {
        return hash(
            'sha256',
            $dataset . '|' . json_encode(
                $requirements,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
    }

    /** @param list<string> $currencies @return list<string> */
    private function missingDailyCurrencies(array $currencies, string $requested): array
    {
        if ($currencies === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($currencies), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT devise FROM taux_change_publics_quotidiens
             WHERE date_requise = ? AND devise IN ({$marks})"
        );
        $stmt->execute([$requested, ...$currencies]);
        return array_values(array_diff(
            $currencies,
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        ));
    }

    /**
     * @param array<string,list<array{start:string,end:string}>> $requirements
     */
    private function ensureMonthly(string $dataset, array $requirements): string
    {
        if ($requirements === [] || !$this->monthlyDataMissing($dataset, $requirements)) {
            return '';
        }
        $today = $this->now();
        $signature = $this->requirementSignature($dataset, $requirements);
        $lastAttempt = $this->pdo->prepare(
            'SELECT substr(tente_le, 1, 10) AS date_tentative, statut,
                    signature_besoin
             FROM actualisations_marche_publiques
             WHERE jeu_donnees = ?'
        );
        $lastAttempt->execute([$dataset]);
        $attempt = $lastAttempt->fetch();
        if (
            $attempt !== false
            && (string) $attempt['date_tentative'] === $today->format('Y-m-d')
            && hash_equals((string) $attempt['signature_besoin'], $signature)
        ) {
            return (string) $attempt['statut'] === 'echec'
                ? 'Actualisation BNS impossible : les dernières données conservées sont affichées.'
                : 'Certaines données BNS demandées ne sont pas encore publiées.';
        }
        $url = $dataset === 'devkum' ? self::SNB_EXCHANGE_URL : self::SNB_INTEREST_URL;
        try {
            $this->importSnb($dataset, $url, $this->http->get($url), $requirements);
            $this->recordRefresh($dataset, $signature, $url, 'succes');
            return $this->monthlyDataMissing($dataset, $requirements)
                ? 'Certaines données BNS demandées ne sont pas encore publiées.'
                : '';
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 300);
            $this->recordRefresh($dataset, $signature, $url, 'echec', $message);
            return 'Actualisation BNS impossible : les dernières données conservées sont affichées.';
        }
    }

    /** @param list<string> $currencies */
    private function ensureDaily(array $currencies): string
    {
        $currencies = array_values(array_diff($currencies, ['CHF']));
        sort($currencies, SORT_STRING);
        if ($currencies === []) {
            return '';
        }
        $requested = $this->now()->format('Y-m-d');
        $missing = $this->missingDailyCurrencies($currencies, $requested);
        if ($missing === []) {
            return '';
        }
        $signature = hash('sha256', $requested . '|' . implode(',', $currencies));
        $lastAttempt = $this->pdo->prepare(
            'SELECT substr(tente_le, 1, 10) AS date_tentative, statut,
                    signature_besoin
             FROM actualisations_marche_publiques
             WHERE jeu_donnees = \'bazg_daily\''
        );
        $lastAttempt->execute();
        $attempt = $lastAttempt->fetch();
        if (
            $attempt !== false
            && (string) $attempt['date_tentative'] === $requested
            && hash_equals((string) $attempt['signature_besoin'], $signature)
        ) {
            return (string) $attempt['statut'] === 'echec'
                ? 'Actualisation OFDF impossible : le dernier taux quotidien conservé est affiché.'
                : 'Certains taux OFDF demandés ne sont pas disponibles.';
        }
        $error = 'Aucun taux quotidien disponible.';
        for ($offset = 0; $offset <= 7; $offset++) {
            $date = $this->now()->sub(new DateInterval("P{$offset}D"));
            $url = self::BAZG_DAILY_URL . '?d=' . $date->format('Ymd') . '&locale=fr';
            try {
                $rates = $this->parseDailyXml($this->http->get($url));
                $this->storeDaily($requested, $url, $rates, $missing);
                $this->recordRefresh('bazg_daily', $signature, $url, 'succes');
                return $this->missingDailyCurrencies($currencies, $requested) === []
                    ? '' : 'Certains taux OFDF demandés ne sont pas disponibles.';
            } catch (Throwable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 300);
            }
        }
        $this->recordRefresh(
            'bazg_daily',
            $signature,
            self::BAZG_DAILY_URL,
            'echec',
            $error
        );
        return 'Actualisation OFDF impossible : le dernier taux quotidien conservé est affiché.';
    }

    /**
     * @param array<string,list<array{start:string,end:string}>> $requirements
     */
    private function importSnb(
        string $dataset,
        string $url,
        string $body,
        array $requirements,
    ): void
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TreasuryException('Réponse JSON BNS invalide.');
        }
        if (!is_array($payload['timeseries'] ?? null)) {
            throw new TreasuryException('La réponse BNS ne contient aucune série.');
        }
        $category = $dataset === 'devkum' ? 'change' : 'interet';
        $this->pdo->beginTransaction();
        try {
            foreach ($payload['timeseries'] as $series) {
                if (!is_array($series)) {
                    continue;
                }
                $parsed = $category === 'change'
                    ? $this->exchangeSeries($series)
                    : $this->interestSeries($series);
                if ($parsed === null) {
                    continue;
                }
                $currency = (string) $parsed['currency'];
                if (!isset($requirements[$currency])) {
                    continue;
                }
                $values = array_values(array_filter(
                    is_array($series['values'] ?? null) ? $series['values'] : [],
                    fn (mixed $value): bool => is_array($value)
                        && preg_match(
                            '/^\d{4}-\d{2}$/',
                            (string) ($value['date'] ?? '')
                        ) === 1
                        && $this->requiredPeriod(
                            $requirements,
                            $currency,
                            (string) $value['date']
                        )
                ));
                if ($values === []) {
                    continue;
                }
                $seriesId = $this->storeSeries($dataset, $category, $url, $parsed);
                $valueStatement = $this->pdo->prepare(
                    'INSERT INTO valeurs_marche_mensuelles
                     (serie_id, periode, valeur_texte, valeur_echelle, echelle)
                     VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT(serie_id, periode) DO UPDATE SET
                       valeur_texte = excluded.valeur_texte,
                       valeur_echelle = excluded.valeur_echelle,
                       echelle = excluded.echelle,
                       actualisee_le = datetime(\'now\')'
                );
                foreach ($values as $value) {
                    if (
                        !is_array($value)
                        || preg_match('/^\d{4}-\d{2}$/', (string) ($value['date'] ?? '')) !== 1
                        || !is_int($value['value'] ?? null)
                            && !is_float($value['value'] ?? null)
                    ) {
                        continue;
                    }
                    $sourceValue = is_int($value['value'])
                        ? (string) $value['value']
                        : number_format((float) $value['value'], 8, '.', '');
                    [$text, $scaled] = $this->decimal($sourceValue);
                    $valueStatement->execute([
                        $seriesId, (string) $value['date'], $text, $scaled, self::SCALE,
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $series @return null|array<string,mixed> */
    private function exchangeSeries(array $series): ?array
    {
        $key = (string) ($series['metadata']['key'] ?? '');
        if (preg_match('/\{(M[01]),([A-Z]{3})(\d+)\}$/', $key, $match) !== 1) {
            return null;
        }
        $headers = $series['header'] ?? [];
        return [
            'code' => $key,
            'label' => (string) ($headers[1]['dimItem'] ?? $match[2]),
            'currency' => $match[2],
            'mode' => $match[1] === 'M0' ? 'moyenne' : 'fin_mois',
            'base_unit' => (int) $match[3],
            'unit' => (string) ($series['metadata']['unit'] ?? 'CHF'),
            'metadata' => $series['metadata'] ?? [],
        ];
    }

    /** @param array<string,mixed> $series @return null|array<string,mixed> */
    private function interestSeries(array $series): ?array
    {
        $key = (string) ($series['metadata']['key'] ?? '');
        $label = (string) ($series['header'][0]['dimItem'] ?? '');
        if (
            $key === ''
            || preg_match('/ - ([A-Z]{3}) - /', $label, $match) !== 1
        ) {
            return null;
        }
        return [
            'code' => $key,
            'label' => $label,
            'currency' => $match[1],
            'mode' => 'fin_mois',
            'base_unit' => 1,
            'unit' => (string) ($series['metadata']['unit'] ?? 'En pour-cent'),
            'metadata' => $series['metadata'] ?? [],
        ];
    }

    /** @param array<string,mixed> $parsed */
    private function storeSeries(
        string $dataset,
        string $category,
        string $url,
        array $parsed,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO series_marche_publiques
             (jeu_donnees, code_serie, categorie, libelle, devise, mode,
              unite_base, unite, url_source, metadonnees_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(jeu_donnees, code_serie) DO UPDATE SET
               libelle = excluded.libelle, devise = excluded.devise,
               mode = excluded.mode, unite_base = excluded.unite_base,
               unite = excluded.unite, url_source = excluded.url_source,
               metadonnees_json = excluded.metadonnees_json,
               actualisee_le = datetime(\'now\')'
        )->execute([
            $dataset, $parsed['code'], $category, $parsed['label'],
            $parsed['currency'], $parsed['mode'], $parsed['base_unit'],
            $parsed['unit'], $url,
            json_encode($parsed['metadata'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM series_marche_publiques
             WHERE jeu_donnees = ? AND code_serie = ?'
        );
        $stmt->execute([$dataset, $parsed['code']]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function parseDailyXml(string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new TreasuryException('XML OFDF non sûr.');
        }
        $publication = $this->xmlTag($xml, 'datum');
        $validity = $this->xmlTag($xml, 'gueltigkeit');
        $publicationDate = DateTimeImmutable::createFromFormat('!d.m.Y', $publication);
        if ($publicationDate === false) {
            throw new TreasuryException('Date de publication OFDF invalide.');
        }
        $validDates = [];
        foreach (explode(',', $validity) as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!d.m.Y', trim($date));
            if ($parsed !== false) {
                $validDates[] = $parsed->format('Y-m-d');
            }
        }
        preg_match_all(
            '/<devise\s+code="([a-z]{3})">(.*?)<\/devise>/si',
            $xml,
            $matches,
            PREG_SET_ORDER
        );
        $items = [];
        foreach ($matches as $match) {
            $unitLabel = $this->xmlTag($match[2], 'waehrung');
            $rate = $this->xmlTag($match[2], 'kurs');
            if (
                preg_match('/^(\d+)\s+([A-Z]{3})$/', $unitLabel, $unit) !== 1
                || preg_match('/^\d+(?:\.\d+)?$/', $rate) !== 1
            ) {
                continue;
            }
            [$text, $scaled] = $this->decimal($rate);
            $items[] = [
                'currency' => strtoupper($match[1]),
                'base_unit' => (int) $unit[1],
                'value' => $text,
                'value_scaled' => $scaled,
            ];
        }
        if ($items === []) {
            throw new TreasuryException('Aucun cours exploitable dans la réponse OFDF.');
        }
        return [
            'publication_date' => $publicationDate->format('Y-m-d'),
            'validity' => implode(',', $validDates),
            'items' => $items,
        ];
    }

    private function xmlTag(string $xml, string $tag): string
    {
        if (preg_match(
            '/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/si',
            $xml,
            $match
        ) !== 1) {
            throw new TreasuryException("Champ XML OFDF absent : {$tag}.");
        }
        return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** @param array<string,mixed> $rates @param list<string> $currencies */
    private function storeDaily(
        string $requested,
        string $url,
        array $rates,
        array $currencies,
    ): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO taux_change_publics_quotidiens
             (date_requise, date_publication, validite, devise, unite_base,
              valeur_texte, valeur_echelle, echelle, url_source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(date_requise, devise) DO UPDATE SET
               date_publication = excluded.date_publication,
               validite = excluded.validite, unite_base = excluded.unite_base,
               valeur_texte = excluded.valeur_texte,
               valeur_echelle = excluded.valeur_echelle,
               echelle = excluded.echelle, url_source = excluded.url_source,
               actualise_le = datetime(\'now\')'
        );
        foreach ($rates['items'] as $rate) {
            if (!in_array($rate['currency'], $currencies, true)) {
                continue;
            }
            $stmt->execute([
                $requested, $rates['publication_date'], $rates['validity'],
                $rate['currency'], $rate['base_unit'], $rate['value'],
                $rate['value_scaled'], self::SCALE, $url,
            ]);
        }
    }

    /**
     * @param list<string> $currencies
     * @return list<array<string,mixed>>
     */
    private function monthlySeries(
        string $category,
        array $currencies,
        string $start,
        string $end,
    ): array {
        if ($currencies === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($currencies), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.code_serie, s.libelle, s.devise, s.mode,
                    s.unite_base, s.unite, s.url_source, s.actualisee_le,
                    v.periode, v.valeur_texte, v.valeur_echelle, v.echelle
             FROM series_marche_publiques s
             LEFT JOIN valeurs_marche_mensuelles v
               ON v.serie_id = s.id AND v.periode BETWEEN ? AND ?
             WHERE s.categorie = ? AND s.devise IN ({$marks})
             ORDER BY s.devise, s.mode, s.code_serie, v.periode"
        );
        $stmt->execute([$start, $end, $category, ...$currencies]);
        $series = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            if (!isset($series[$id])) {
                $series[$id] = [
                    'id' => $id,
                    'code' => (string) $row['code_serie'],
                    'label' => (string) $row['libelle'],
                    'currency' => (string) $row['devise'],
                    'mode' => (string) $row['mode'],
                    'base_unit' => (int) $row['unite_base'],
                    'unit' => (string) $row['unite'],
                    'source_url' => (string) $row['url_source'],
                    'updated_at' => (string) $row['actualisee_le'],
                    'values' => [],
                ];
            }
            if ($row['periode'] !== null) {
                $series[$id]['values'][] = [
                    'period' => (string) $row['periode'],
                    'value' => (string) $row['valeur_texte'],
                    'per_unit' => $this->scaledText(
                        $this->divideRounded(
                            (int) $row['valeur_echelle'],
                            (int) $row['unite_base']
                        )
                    ),
                ];
            }
        }
        return array_values($series);
    }

    /** @param list<string> $currencies @return list<array<string,mixed>> */
    private function dailyRates(array $currencies): array
    {
        $currencies = array_values(array_filter(
            $currencies,
            static fn (string $currency): bool => $currency !== 'CHF'
        ));
        if ($currencies === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($currencies), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM taux_change_publics_quotidiens
             WHERE devise IN ({$marks})
             ORDER BY date_requise DESC, devise
             LIMIT 200"
        );
        $stmt->execute($currencies);
        $latest = [];
        foreach ($stmt->fetchAll() as $row) {
            $currency = (string) $row['devise'];
            if (isset($latest[$currency])) {
                continue;
            }
            $latest[$currency] = [
                'requested_date' => (string) $row['date_requise'],
                'publication_date' => (string) $row['date_publication'],
                'validity' => $row['validite'] === ''
                    ? [] : explode(',', (string) $row['validite']),
                'currency' => $currency,
                'base_unit' => (int) $row['unite_base'],
                'value' => (string) $row['valeur_texte'],
                'per_unit' => $this->scaledText(
                    $this->divideRounded(
                        (int) $row['valeur_echelle'],
                        (int) $row['unite_base']
                    )
                ),
                'source_url' => (string) $row['url_source'],
                'updated_at' => (string) $row['actualise_le'],
            ];
        }
        ksort($latest, SORT_STRING);
        return array_values($latest);
    }

    /** @return array<string,mixed> */
    private function refreshState(string $dataset, string $warning): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT statut, tente_le, reussie_le, erreur, url_source
             FROM actualisations_marche_publiques WHERE jeu_donnees = ?'
        );
        $stmt->execute([$dataset]);
        $row = $stmt->fetch();
        return [
            'status' => $row === false ? 'absent' : (string) $row['statut'],
            'attempted_at' => $row === false ? null : (string) $row['tente_le'],
            'succeeded_at' => $row === false || $row['reussie_le'] === null
                ? null : (string) $row['reussie_le'],
            'source_url' => $row === false ? '' : (string) $row['url_source'],
            'warning' => $warning,
        ];
    }

    private function recordRefresh(
        string $dataset,
        string $signature,
        string $url,
        string $status,
        string $error = '',
    ): void {
        $this->pdo->prepare(
            'INSERT INTO actualisations_marche_publiques
             (jeu_donnees, signature_besoin, url_source, statut,
              tente_le, reussie_le, erreur)
             VALUES (?, ?, ?, ?, datetime(\'now\'),
                     CASE WHEN ? = \'succes\' THEN datetime(\'now\') END, ?)
             ON CONFLICT(jeu_donnees) DO UPDATE SET
               signature_besoin = excluded.signature_besoin,
               url_source = excluded.url_source, statut = excluded.statut,
               tente_le = excluded.tente_le,
               reussie_le = CASE WHEN excluded.statut = \'succes\'
                    THEN excluded.reussie_le
                    ELSE actualisations_marche_publiques.reussie_le END,
               erreur = excluded.erreur'
        )->execute([$dataset, $signature, $url, $status, $status, $error]);
    }

    /** @return list<string> */
    private function periods(string $start, string $end): array
    {
        $cursor = new DateTimeImmutable($start . '-01');
        $last = new DateTimeImmutable($end . '-01');
        $periods = [];
        while ($cursor <= $last && count($periods) < 120) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }
        return $periods;
    }

    /** @return array{string,int} */
    private function decimal(string $value): array
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($value), $match) !== 1) {
            throw new TreasuryException('Valeur publique décimale invalide.');
        }
        $fraction = substr(str_pad((string) ($match[3] ?? ''), 8, '0'), 0, 8);
        $scaled = ((int) $match[2] * self::SCALE) + (int) $fraction;
        if ($match[1] === '-') {
            $scaled *= -1;
        }
        return [$this->scaledText($scaled), $scaled];
    }

    private function divideRounded(int $value, int $divisor): int
    {
        $negative = $value < 0;
        $absolute = abs($value);
        $result = intdiv($absolute + intdiv($divisor, 2), $divisor);
        return $negative ? -$result : $result;
    }

    private function scaledText(int $value): string
    {
        $negative = $value < 0;
        $absolute = abs($value);
        $whole = intdiv($absolute, self::SCALE);
        $fraction = rtrim(str_pad((string) ($absolute % self::SCALE), 8, '0', STR_PAD_LEFT), '0');
        $text = (string) $whole . ($fraction === '' ? '' : '.' . $fraction);
        return $negative ? '-' . $text : $text;
    }

    private function now(): DateTimeImmutable
    {
        return $this->today ?? new DateTimeImmutable('today');
    }
}
