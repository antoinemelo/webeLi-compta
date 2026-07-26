<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use PDO;

final class ReportingService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{
     *   exercice_id?:int,journal_id?:int,statut?:string,compte_id?:int,
     *   date_debut?:string,date_fin?:string,texte?:string,
     *   montant_min_centimes?:int,montant_max_centimes?:int,page?:int,par_page?:int
     * } $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,par_page:int,pages:int}
     */
    public function journal(int $organisationId, int $dossierId, array $filters = []): array
    {
        [$where, $params] = $this->entryFilters($organisationId, $dossierId, $filters);
        if ((int) ($filters['compte_id'] ?? 0) > 0) {
            $where[] = 'EXISTS (
                SELECT 1 FROM lignes_ecriture lf
                WHERE lf.ecriture_id = e.id AND lf.compte_id = :account
            )';
            $params['account'] = (int) $filters['compte_id'];
        }
        $having = [];
        if (isset($filters['montant_min_centimes'])) {
            $having[] = 'SUM(l.debit_centimes) >= :minimum';
            $params['minimum'] = max(0, (int) $filters['montant_min_centimes']);
        }
        if (isset($filters['montant_max_centimes'])) {
            $having[] = 'SUM(l.debit_centimes) <= :maximum';
            $params['maximum'] = max(0, (int) $filters['montant_max_centimes']);
        }
        $whereSql = implode(' AND ', $where);
        $havingSql = $having === [] ? '' : ' HAVING ' . implode(' AND ', $having);
        $base = " FROM ecritures e
                  JOIN journaux j ON j.id = e.journal_id
                  JOIN lignes_ecriture l ON l.ecriture_id = e.id
                  JOIN comptes c ON c.id = l.compte_id
                  WHERE {$whereSql}
                  GROUP BY e.id {$havingSql}";
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM (SELECT e.id {$base}) q");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        [$page, $perPage, $offset] = $this->pagination($filters, $total);
        $order = ($filters['ordre'] ?? '') === 'desc'
            ? 'e.date_comptable DESC, e.id DESC'
            : 'e.date_comptable, e.id';
        $query = $this->pdo->prepare(
            "SELECT e.id, e.numero, e.date_comptable, e.libelle, e.reference,
                    e.statut, j.code AS journal,
                    GROUP_CONCAT(DISTINCT CASE
                      WHEN l.debit_centimes > 0 THEN c.numero END) AS comptes_debit,
                    GROUP_CONCAT(DISTINCT CASE
                      WHEN l.credit_centimes > 0 THEN c.numero END) AS comptes_credit,
                    SUM(l.debit_centimes) AS debit_centimes,
                    SUM(l.credit_centimes) AS credit_centimes
             {$base}
             ORDER BY {$order}
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $name => $value) {
            $query->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        return [
            'items' => $query->fetchAll(),
            'total' => $total,
            'page' => $page,
            'par_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Grand livre synthétique fidèle au document historique : solde initial,
     * mouvements de la période et solde final pour chaque compte utilisé.
     *
     * @return array{items:list<array<string,mixed>>}
     */
    public function generalLedger(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        ?string $dateStart = null,
        ?string $dateEnd = null,
    ): array {
        $params = [
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'exercise' => $exerciseId,
            'date_start' => $dateStart !== null && $dateStart !== ''
                ? $dateStart
                : '0000-01-01',
        ];
        $dateSql = '';
        if ($dateEnd !== null && $dateEnd !== '') {
            $dateSql = ' AND e.date_comptable <= :date_end';
            $params['date_end'] = $dateEnd;
        }
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.numero, c.libelle, c.sens_normal,
                    CASE c.sens_normal
                      WHEN 'debit' THEN COALESCE(SUM(CASE
                        WHEN e.source_type = 'ouverture'
                          OR e.date_comptable < :date_start
                        THEN l.debit_centimes - l.credit_centimes ELSE 0 END), 0)
                      ELSE COALESCE(SUM(CASE
                        WHEN e.source_type = 'ouverture'
                          OR e.date_comptable < :date_start
                        THEN l.credit_centimes - l.debit_centimes ELSE 0 END), 0)
                    END AS initial_centimes,
                    COALESCE(SUM(CASE
                      WHEN e.id IS NOT NULL AND e.source_type <> 'ouverture'
                        AND e.date_comptable >= :date_start
                      THEN l.debit_centimes ELSE 0 END), 0) AS debit_centimes,
                    COALESCE(SUM(CASE
                      WHEN e.id IS NOT NULL AND e.source_type <> 'ouverture'
                        AND e.date_comptable >= :date_start
                      THEN l.credit_centimes ELSE 0 END), 0) AS credit_centimes,
                    CASE c.sens_normal
                      WHEN 'debit' THEN COALESCE(SUM(CASE WHEN e.id IS NOT NULL
                        THEN l.debit_centimes - l.credit_centimes ELSE 0 END), 0)
                      ELSE COALESCE(SUM(CASE WHEN e.id IS NOT NULL
                        THEN l.credit_centimes - l.debit_centimes ELSE 0 END), 0)
                    END AS solde_centimes
             FROM comptes c
             LEFT JOIN lignes_ecriture l ON l.compte_id = c.id
             LEFT JOIN ecritures e ON e.id = l.ecriture_id
                AND e.exercice_id = :exercise
                AND e.statut IN ('validee', 'contre_passee')
                {$dateSql}
             WHERE c.organisation_id = :organisation
               AND c.dossier_id = :dossier
               AND c.actif = 1 AND c.imputable = 1
             GROUP BY c.id
             HAVING initial_centimes <> 0 OR debit_centimes <> 0
                OR credit_centimes <> 0 OR solde_centimes <> 0
             ORDER BY length(c.numero), c.numero COLLATE NOCASE"
        );
        $stmt->execute($params);
        return ['items' => $stmt->fetchAll()];
    }

    /**
     * @param array{
     *   exercice_id?:int,date_debut?:string,date_fin?:string,texte?:string,
     *   page?:int,par_page?:int
     * } $filters
     * @return array{
     *   items:list<array<string,mixed>>,total:int,page:int,par_page:int,pages:int,
     *   account:array<string,mixed>,total_debit_centimes:int,
     *   total_credit_centimes:int,solde_centimes:int
     * }
     */
    public function ledger(
        int $organisationId,
        int $dossierId,
        int $accountId,
        array $filters = [],
    ): array {
        $account = $this->account($organisationId, $dossierId, $accountId);
        [$where, $params] = $this->entryFilters(
            $organisationId,
            $dossierId,
            $filters + ['statut' => 'comptabilisee']
        );
        $where[] = 'l.compte_id = :account';
        $params['account'] = $accountId;
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM lignes_ecriture l JOIN ecritures e ON e.id = l.ecriture_id
             WHERE {$whereSql}"
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $totals = $this->pdo->prepare(
            "SELECT COALESCE(SUM(l.debit_centimes), 0) AS debit,
                    COALESCE(SUM(l.credit_centimes), 0) AS credit
             FROM lignes_ecriture l JOIN ecritures e ON e.id = l.ecriture_id
             WHERE {$whereSql}"
        );
        $totals->execute($params);
        $sum = $totals->fetch() ?: ['debit' => 0, 'credit' => 0];
        [$page, $perPage, $offset] = $this->pagination($filters, $total);
        $normalMovement = $account['sens_normal'] === 'debit'
            ? '(l.debit_centimes - l.credit_centimes)'
            : '(l.credit_centimes - l.debit_centimes)';
        $query = $this->pdo->prepare(
            "SELECT e.id AS ecriture_id, e.numero, e.date_comptable, j.code AS journal,
                    e.reference, COALESCE(NULLIF(l.libelle, ''), e.libelle) AS libelle,
                    l.debit_centimes, l.credit_centimes,
                    l.devise_origine, l.montant_origine_centimes, l.devise_base,
                    l.taux_change_numerateur, l.taux_change_denominateur,
                    l.taux_change_date, l.taux_change_source,
                    l.montant_base_centimes, l.ecart_arrondi_centimes,
                    SUM({$normalMovement}) OVER (
                        ORDER BY e.date_comptable, e.id, l.ordre
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ) AS solde_centimes
             FROM lignes_ecriture l
             JOIN ecritures e ON e.id = l.ecriture_id
             JOIN journaux j ON j.id = e.journal_id
             WHERE {$whereSql}
             ORDER BY e.date_comptable, e.id, l.ordre
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $name => $value) {
            $query->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $query->bindValue(':offset', $offset, PDO::PARAM_INT);
        $query->execute();
        $debit = (int) $sum['debit'];
        $credit = (int) $sum['credit'];
        return [
            'items' => $query->fetchAll(),
            'total' => $total,
            'page' => $page,
            'par_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'account' => $account,
            'total_debit_centimes' => $debit,
            'total_credit_centimes' => $credit,
            'solde_centimes' => $account['sens_normal'] === 'debit'
                ? $debit - $credit
                : $credit - $debit,
        ];
    }

    /**
     * @return array{
     *   items:list<array<string,mixed>>,total_debit_centimes:int,
     *   total_credit_centimes:int,equilibree:bool
     * }
     */
    public function trialBalance(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        ?string $dateEnd = null,
    ): array {
        $params = [
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'exercise' => $exerciseId,
        ];
        $dateSql = '';
        if ($dateEnd !== null && $dateEnd !== '') {
            $dateSql = ' AND e.date_comptable <= :date_end';
            $params['date_end'] = $dateEnd;
        }
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.numero, c.libelle, c.type,
                    COALESCE(t.libelle, c.type) AS type_libelle, c.sens_normal,
                    c.rubrique_id,
                    COALESCE(r.code, '') AS rubrique_code,
                    COALESCE(r.code, '') AS rubrique_prefixe,
                    COALESCE(r.libelle, '') AS rubrique_libelle,
                    COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit_centimes ELSE 0 END), 0)
                        AS debit_centimes,
                    COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit_centimes ELSE 0 END), 0)
                        AS credit_centimes,
                    CASE c.sens_normal
                      WHEN 'debit' THEN COALESCE(SUM(CASE WHEN e.id IS NOT NULL
                        THEN l.debit_centimes - l.credit_centimes ELSE 0 END), 0)
                      ELSE COALESCE(SUM(CASE WHEN e.id IS NOT NULL
                        THEN l.credit_centimes - l.debit_centimes ELSE 0 END), 0)
                    END AS solde_centimes
             FROM comptes c
             LEFT JOIN types_comptes t ON t.organisation_id = c.organisation_id
                AND t.dossier_id = c.dossier_id
                AND t.code = c.type AND t.actif = 1
             LEFT JOIN rubriques_comptables r ON r.id = c.rubrique_id
                AND r.organisation_id = c.organisation_id
                AND r.dossier_id = c.dossier_id
             LEFT JOIN lignes_ecriture l ON l.compte_id = c.id
             LEFT JOIN ecritures e ON e.id = l.ecriture_id
                AND e.exercice_id = :exercise
                AND e.statut IN ('validee', 'contre_passee')
                {$dateSql}
             WHERE c.organisation_id = :organisation
               AND c.dossier_id = :dossier
               AND c.imputable = 1
             GROUP BY c.id
             HAVING debit_centimes <> 0 OR credit_centimes <> 0
             ORDER BY c.numero COLLATE NOCASE"
        );
        $stmt->execute($params);
        $items = $this->decorateRubricPaths(
            $organisationId,
            $dossierId,
            $stmt->fetchAll()
        );
        $debit = array_sum(array_map(
            static fn (array $row): int => (int) $row['debit_centimes'],
            $items
        ));
        $credit = array_sum(array_map(
            static fn (array $row): int => (int) $row['credit_centimes'],
            $items
        ));
        return [
            'items' => $items,
            'total_debit_centimes' => $debit,
            'total_credit_centimes' => $credit,
            'equilibree' => $debit === $credit,
        ];
    }

    /** @return array{items:list<array<string,mixed>>,total_actif_centimes:int,total_passif_centimes:int,equilibre:bool} */
    public function balanceSheet(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        ?string $dateEnd = null,
    ): array {
        $balance = $this->trialBalance(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateEnd
        );
        $items = array_values(array_filter(
            $balance['items'],
            static fn (array $row): bool => in_array(
                $row['type'],
                ['actif', 'passif', 'fonds_propres'],
                true
            )
        ));
        $assets = 0;
        $liabilities = 0;
        foreach ($items as $row) {
            if ($row['type'] === 'actif') {
                $assets += (int) $row['solde_centimes'];
            } else {
                $liabilities += (int) $row['solde_centimes'];
            }
        }
        $income = $this->incomeStatement(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateEnd
        );
        $currentResult = $income['resultat_centimes'];
        if ($currentResult !== 0) {
            $items[] = [
                'id' => 0,
                'numero' => 'RÉSULTAT',
                'libelle' => 'Résultat courant avant clôture',
                'type' => 'passif',
                'type_libelle' => 'Passif',
                'sens_normal' => 'credit',
                'rubrique_id' => null,
                'rubrique_code' => '',
                'rubrique_prefixe' => '',
                'rubrique_libelle' => 'Résultat de l’exercice',
                'rubrique_chemin' => 'Résultat de l’exercice',
                'debit_centimes' => $currentResult < 0 ? -$currentResult : 0,
                'credit_centimes' => $currentResult > 0 ? $currentResult : 0,
                'solde_centimes' => $currentResult,
            ];
            $liabilities += $currentResult;
        }
        return [
            'items' => $items,
            'total_actif_centimes' => $assets,
            'total_passif_centimes' => $liabilities,
            'equilibre' => $assets === $liabilities,
        ];
    }

    /** @return array{items:list<array<string,mixed>>,produits_centimes:int,charges_centimes:int,resultat_centimes:int} */
    public function incomeStatement(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        ?string $dateEnd = null,
    ): array {
        $balance = $this->trialBalance(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateEnd
        );
        $items = array_values(array_filter(
            $balance['items'],
            static fn (array $row): bool => in_array(
                $row['type'],
                ['produit', 'charge'],
                true
            )
        ));
        $products = 0;
        $charges = 0;
        foreach ($items as $row) {
            if ($row['type'] === 'produit') {
                $products += (int) $row['solde_centimes'];
            } else {
                $charges += (int) $row['solde_centimes'];
            }
        }
        return [
            'items' => $items,
            'produits_centimes' => $products,
            'charges_centimes' => $charges,
            'resultat_centimes' => $products - $charges,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,string> $columns key => libellé
     */
    public function csv(array $rows, array $columns): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new AccountingException('Impossible de préparer l’export CSV.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_values($columns), ';', '"', '\\');
        foreach ($rows as $row) {
            $values = [];
            foreach (array_keys($columns) as $key) {
                $value = $row[$key] ?? '';
                $values[] = str_ends_with($key, '_centimes')
                    ? number_format((int) $value / 100, 2, '.', '')
                    : $value;
            }
            fputcsv($stream, $values, ';', '"', '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv === false ? '' : $csv;
    }

    /** @return array{id:int,libelle:string,date_debut:string,date_fin:string} */
    public function exercise(int $organisationId, int $dossierId, ?int $exerciseId = null): array
    {
        $sql = 'SELECT x.id, x.libelle, x.date_debut, x.date_fin
                FROM exercices x JOIN dossiers d ON d.id = x.dossier_id
                WHERE d.organisation_id = :organisation AND x.dossier_id = :dossier';
        $params = ['organisation' => $organisationId, 'dossier' => $dossierId];
        if ($exerciseId !== null && $exerciseId > 0) {
            $sql .= ' AND x.id = :exercise';
            $params['exercise'] = $exerciseId;
        }
        $sql .= " ORDER BY CASE x.statut WHEN 'ouvert' THEN 0 ELSE 1 END, x.date_debut DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AccountingException('Aucun exercice disponible dans ce dossier.');
        }
        return [
            'id' => (int) $row['id'],
            'libelle' => (string) $row['libelle'],
            'date_debut' => (string) $row['date_debut'],
            'date_fin' => (string) $row['date_fin'],
        ];
    }

    /** @return array<string,mixed> */
    private function account(int $organisationId, int $dossierId, int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$accountId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AccountingException('Compte absent du dossier.');
        }
        return $row;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function decorateRubricPaths(
        int $organisationId,
        int $dossierId,
        array $items,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, libelle, parent_id
             FROM rubriques_comptables
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $rubrics = [];
        foreach ($stmt->fetchAll() as $rubric) {
            $rubrics[(int) $rubric['id']] = $rubric;
        }
        foreach ($items as &$item) {
            $parts = [];
            $rubricId = (int) ($item['rubrique_id'] ?? 0);
            $visited = [];
            while ($rubricId > 0 && isset($rubrics[$rubricId]) && !isset($visited[$rubricId])) {
                $visited[$rubricId] = true;
                $rubric = $rubrics[$rubricId];
                $code = trim((string) $rubric['code']);
                $parts[] = trim($code . ' ' . (string) $rubric['libelle']);
                $rubricId = (int) ($rubric['parent_id'] ?? 0);
            }
            $item['rubrique_chemin'] = implode(' ‹ ', $parts);
        }
        unset($item);
        return $items;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:list<string>,1:array<string,int|string>}
     */
    private function entryFilters(
        int $organisationId,
        int $dossierId,
        array $filters,
    ): array {
        $where = [
            'e.organisation_id = :organisation',
            'e.dossier_id = :dossier',
        ];
        $params = ['organisation' => $organisationId, 'dossier' => $dossierId];
        if ((int) ($filters['exercice_id'] ?? 0) > 0) {
            $where[] = 'e.exercice_id = :exercise';
            $params['exercise'] = (int) $filters['exercice_id'];
        }
        if ((int) ($filters['journal_id'] ?? 0) > 0) {
            $where[] = 'e.journal_id = :journal';
            $params['journal'] = (int) $filters['journal_id'];
        }
        $status = (string) ($filters['statut'] ?? '');
        if ($status === 'comptabilisee') {
            $where[] = "e.statut IN ('validee', 'contre_passee')";
        } elseif (in_array($status, ['brouillon', 'validee', 'contre_passee'], true)) {
            $where[] = 'e.statut = :status';
            $params['status'] = $status;
        }
        if ((string) ($filters['date_debut'] ?? '') !== '') {
            $where[] = 'e.date_comptable >= :date_start';
            $params['date_start'] = (string) $filters['date_debut'];
        }
        if ((string) ($filters['date_fin'] ?? '') !== '') {
            $where[] = 'e.date_comptable <= :date_end';
            $params['date_end'] = (string) $filters['date_fin'];
        }
        $text = trim((string) ($filters['texte'] ?? ''));
        if ($text !== '') {
            $where[] = "(e.libelle LIKE :text ESCAPE '\\'
                        OR e.reference LIKE :text ESCAPE '\\'
                        OR e.numero LIKE :text ESCAPE '\\')";
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
            $params['text'] = '%' . $escaped . '%';
        }
        return [$where, $params];
    }

    /** @param array<string,mixed> $filters @return array{int,int,int} */
    private function pagination(array $filters, int $total): array
    {
        $perPage = min(200, max(1, (int) ($filters['par_page'] ?? 50)));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($pages, max(1, (int) ($filters['page'] ?? 1)));
        return [$page, $perPage, ($page - 1) * $perPage];
    }
}
