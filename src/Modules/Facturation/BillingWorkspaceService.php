<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use DateTimeImmutable;
use PDO;

final class BillingWorkspaceService
{
    private const BUCKETS = [
        'not_due',
        'days_0_30',
        'days_31_60',
        'days_61_90',
        'days_91_plus',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly BillingService $billing,
        private readonly PaymentService $payments,
        private readonly ContactService $contactService,
    ) {
    }

    /**
     * @param array{direction:string,status:string,search:string,contact_id:?int} $filters
     * @return array<string,mixed>
     */
    public function read(
        int $organisationId,
        int $dossierId,
        string $asOfDate,
        array $filters,
    ): array {
        $this->assertDate($asOfDate);
        $documents = $this->documents(
            $organisationId,
            $dossierId,
            $asOfDate,
            $filters
        );
        $aging = $this->aging($documents);
        $paymentRows = $this->paymentRows(
            $organisationId,
            $dossierId,
            $asOfDate
        );
        $allDocuments = $filters === [
            'direction' => 'all',
            'status' => 'all',
            'search' => '',
            'contact_id' => null,
        ] ? $documents : $this->documents(
            $organisationId,
            $dossierId,
            $asOfDate,
            [
                'direction' => 'all',
                'status' => 'all',
                'search' => '',
                'contact_id' => null,
            ]
        );
        foreach ($paymentRows as $payment) {
            if (
                $filters['contact_id'] !== null
                && (int) $payment['contact_id'] !== $filters['contact_id']
            ) {
                continue;
            }
            if ((int) $payment['unallocated_cents'] === 0) {
                continue;
            }
            $side = $payment['direction'] === 'encaissement'
                ? 'receivables'
                : 'payables';
            $aging[$side]['unallocated_payments_cents'] +=
                (int) $payment['unallocated_cents'];
            $aging[$side]['net_open_cents'] -=
                (int) $payment['unallocated_cents'];
        }
        $contacts = $this->contacts(
            $organisationId,
            $dossierId,
            $allDocuments,
            $paymentRows
        );
        $selectedContactId = $filters['contact_id'];
        $contact360 = $selectedContactId === null
            ? null
            : $this->contact360(
                $organisationId,
                $dossierId,
                $selectedContactId,
                $asOfDate
            );
        return [
            'reference_date' => $asOfDate,
            'filters' => $filters,
            'documents' => $documents,
            'aging' => $aging,
            'contacts' => $contacts,
            'contact_360' => $contact360,
            'payments' => $paymentRows,
            'allocations' => $this->payments->allocations(
                $organisationId,
                $dossierId
            ),
            'recurrences' => $this->recurrences(
                $organisationId,
                $dossierId
            ),
            'reminders' => $this->reminders(
                $organisationId,
                $dossierId
            ),
            'catalog' => $this->catalog($organisationId, $dossierId),
            'definitions' => [
                'aging' =>
                    'Les factures et avoirs ouverts sont ventilés selon leur échéance à la date de référence.',
                'prepayments' =>
                    'Les paiements non alloués réduisent le solde net, mais restent séparés des tranches d’âge.',
                'immutability' =>
                    'Un document émis ou comptabilisé est immuable et se corrige par avoir.',
            ],
        ];
    }

    /**
     * @param array{direction:string,status:string,search:string,contact_id:?int} $filters
     * @return list<array<string,mixed>>
     */
    public function documents(
        int $organisationId,
        int $dossierId,
        string $asOfDate,
        array $filters,
    ): array {
        $this->assertDate($asOfDate);
        $stmt = $this->pdo->prepare(
            "SELECT d.*, COALESCE(NULLIF(c.raison_sociale, ''),
                        trim(c.prenom || ' ' || c.nom)) AS contact,
                    COALESCE((
                      SELECT SUM(a.montant_centimes)
                      FROM allocations a
                      LEFT JOIN paiements p ON p.id = a.paiement_id
                      LEFT JOIN documents_financiers av ON av.id = a.avoir_id
                      WHERE a.document_id = d.id AND a.statut = 'valide'
                        AND (
                          (p.id IS NOT NULL AND p.statut = 'valide'
                            AND p.date_paiement <= :as_of)
                          OR
                          (av.id IS NOT NULL
                            AND av.statut IN ('emis', 'comptabilise')
                            AND av.date_document <= :as_of)
                        )
                    ), 0) AS target_allocated_cents,
                    COALESCE((
                      SELECT SUM(a.montant_centimes)
                      FROM allocations a
                      WHERE a.avoir_id = d.id AND a.statut = 'valide'
                    ), 0) AS credit_allocated_cents,
                    (SELECT COUNT(*) FROM rappels_factures r
                     WHERE r.document_id = d.id) AS reminder_count
             FROM documents_financiers d
             JOIN contacts c ON c.id = d.contact_id
             WHERE d.organisation_id = :organisation
               AND d.dossier_id = :dossier
               AND d.workflow = 'facturation'
               AND d.date_document <= :as_of
             ORDER BY d.date_document DESC, d.id DESC"
        );
        $stmt->execute([
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'as_of' => $asOfDate,
        ]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $direction = str_contains((string) $row['type'], 'client')
                ? 'sales'
                : 'purchases';
            $isCredit = str_starts_with((string) $row['type'], 'avoir_');
            $open = 0;
            if (in_array($row['statut'], ['emis', 'comptabilise'], true)) {
                $allocated = $isCredit
                    ? (int) $row['credit_allocated_cents']
                    : (int) $row['target_allocated_cents'];
                $open = max(0, abs((int) $row['total_brut_centimes']) - $allocated);
                if ($isCredit) {
                    $open *= -1;
                }
            }
            $paymentState = $this->paymentState($row, $open, $asOfDate);
            if ($filters['direction'] !== 'all' && $filters['direction'] !== $direction) {
                continue;
            }
            if ($filters['status'] !== 'all' && $filters['status'] !== $paymentState) {
                continue;
            }
            if (
                $filters['contact_id'] !== null
                && (int) $row['contact_id'] !== $filters['contact_id']
            ) {
                continue;
            }
            $haystack = mb_strtolower(
                (string) $row['numero'] . ' '
                . (string) $row['numero_externe'] . ' '
                . (string) $row['contact']
            );
            if (
                $filters['search'] !== ''
                && !str_contains($haystack, mb_strtolower($filters['search']))
            ) {
                continue;
            }
            $items[] = [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'external_number' => (string) $row['numero_externe'],
                'type' => (string) $row['type'],
                'direction' => $direction,
                'status' => (string) $row['statut'],
                'payment_state' => $paymentState,
                'version' => (int) $row['version'],
                'contact_id' => (int) $row['contact_id'],
                'contact' => (string) $row['contact'],
                'document_date' => (string) $row['date_document'],
                'due_date' => (string) $row['date_echeance'],
                'currency' => (string) $row['monnaie'],
                'net_cents' => (int) $row['total_net_centimes'],
                'vat_cents' => (int) $row['total_tva_centimes'],
                'gross_cents' => (int) $row['total_brut_centimes'],
                'allocated_cents' => $isCredit
                    ? (int) $row['credit_allocated_cents']
                    : (int) $row['target_allocated_cents'],
                'open_cents' => $open,
                'reminder_count' => (int) $row['reminder_count'],
                'entry_id' => $row['ecriture_id'] === null
                    ? null : (int) $row['ecriture_id'],
                'origin_document_id' => $row['document_origine_id'] === null
                    ? null : (int) $row['document_origine_id'],
                'scor_reference' => (string) $row['reference_scor'],
                'has_archived_pdf' => (string) $row['pdf_empreinte_sha256'] !== '',
                'lines' => [],
            ];
        }
        return $items;
    }

    /**
     * @param list<array<string,mixed>> $documents
     * @return array<string,array<string,mixed>>
     */
    private function aging(array $documents): array
    {
        $blank = static fn (): array => [
            'buckets' => array_fill_keys(self::BUCKETS, 0),
            'open_documents_cents' => 0,
            'unallocated_payments_cents' => 0,
            'net_open_cents' => 0,
            'item_count' => 0,
        ];
        $result = ['receivables' => $blank(), 'payables' => $blank()];
        foreach ($documents as $document) {
            $open = (int) $document['open_cents'];
            if (
                $open === 0
                || !in_array($document['status'], ['emis', 'comptabilise'], true)
            ) {
                continue;
            }
            $side = $document['direction'] === 'sales'
                ? 'receivables'
                : 'payables';
            $bucket = (string) $document['payment_state'] === 'non_echu'
                ? 'not_due'
                : $this->bucketFromState((string) $document['payment_state']);
            $result[$side]['buckets'][$bucket] += $open;
            $result[$side]['open_documents_cents'] += $open;
            $result[$side]['net_open_cents'] += $open;
            $result[$side]['item_count']++;
        }
        return $result;
    }

    private function paymentState(array $row, int $open, string $asOfDate): string
    {
        if ($row['statut'] === 'brouillon') {
            return 'brouillon';
        }
        if ($row['statut'] === 'annule') {
            return 'annule';
        }
        if ($open === 0) {
            return 'solde';
        }
        $days = (int) (new DateTimeImmutable((string) $row['date_echeance']))
            ->diff(new DateTimeImmutable($asOfDate))
            ->format('%r%a');
        if ($days < 0) {
            return 'non_echu';
        }
        if ($days <= 30) {
            return 'retard_0_30';
        }
        if ($days <= 60) {
            return 'retard_31_60';
        }
        if ($days <= 90) {
            return 'retard_61_90';
        }
        return 'retard_91_plus';
    }

    private function bucketFromState(string $state): string
    {
        return match ($state) {
            'retard_0_30' => 'days_0_30',
            'retard_31_60' => 'days_31_60',
            'retard_61_90' => 'days_61_90',
            'retard_91_plus' => 'days_91_plus',
            default => 'not_due',
        };
    }

    /** @return list<array<string,mixed>> */
    private function paymentRows(
        int $organisationId,
        int $dossierId,
        string $asOfDate,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.contact_id, p.sens AS direction,
                    p.date_paiement AS payment_date,
                    p.montant_centimes AS amount_cents, p.monnaie AS currency,
                    p.reference, p.statut AS status,
                    COALESCE(NULLIF(c.raison_sociale, ''),
                             trim(c.prenom || ' ' || c.nom)) AS contact,
                    COALESCE((
                      SELECT SUM(a.montant_centimes) FROM allocations a
                      WHERE a.paiement_id = p.id AND a.statut = 'valide'
                    ), 0) AS allocated_cents
             FROM paiements p
             JOIN contacts c ON c.id = p.contact_id
             WHERE p.organisation_id = ? AND p.dossier_id = ?
               AND p.date_paiement <= ?
             ORDER BY p.date_paiement DESC, p.id DESC"
        );
        $stmt->execute([$organisationId, $dossierId, $asOfDate]);
        return array_map(static function (array $row): array {
            $amount = (int) $row['amount_cents'];
            $allocated = (int) $row['allocated_cents'];
            return [
                'id' => (int) $row['id'],
                'contact_id' => (int) $row['contact_id'],
                'contact' => (string) $row['contact'],
                'direction' => (string) $row['direction'],
                'payment_date' => (string) $row['payment_date'],
                'amount_cents' => $amount,
                'allocated_cents' => $allocated,
                'unallocated_cents' => $row['status'] === 'valide'
                    ? max(0, $amount - $allocated) : 0,
                'currency' => (string) $row['currency'],
                'reference' => (string) $row['reference'],
                'status' => (string) $row['status'],
            ];
        }, $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function contacts(
        int $organisationId,
        int $dossierId,
        array $documents,
        array $payments,
    ): array {
        $contacts = $this->contactService->all($organisationId, $dossierId);
        $result = [];
        foreach ($contacts as $contact) {
            $summary = $this->balanceFromRows(
                (int) $contact['id'],
                $documents,
                $payments
            );
            $result[] = [
                'id' => (int) $contact['id'],
                'type' => (string) $contact['type_personne'],
                'company' => (string) $contact['raison_sociale'],
                'first_name' => (string) $contact['prenom'],
                'last_name' => (string) $contact['nom'],
                'label' => trim(
                    (string) $contact['raison_sociale'] . ' '
                    . (string) $contact['prenom'] . ' '
                    . (string) $contact['nom']
                ),
                'email' => (string) $contact['email'],
                'phone' => (string) $contact['telephone'],
                'iban' => (string) $contact['iban_paiement'],
                'bic' => (string) $contact['bic_paiement'],
                'language' => (string) $contact['langue'],
                'roles' => array_values(array_filter(
                    explode(',', (string) $contact['roles'])
                )),
                'version' => (int) $contact['version'],
                'address' => [
                    'line1' => (string) ($contact['ligne1'] ?? ''),
                    'line2' => (string) ($contact['ligne2'] ?? ''),
                    'postal_code' => (string) ($contact['code_postal'] ?? ''),
                    'city' => (string) ($contact['localite'] ?? ''),
                    'country' => (string) ($contact['pays'] ?? 'CH'),
                ],
                'balance' => $summary,
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function contact360(
        int $organisationId,
        int $dossierId,
        int $contactId,
        string $asOfDate,
    ): array {
        $exists = $this->pdo->prepare(
            'SELECT 1 FROM contacts
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $exists->execute([$contactId, $organisationId, $dossierId]);
        if ($exists->fetchColumn() === false) {
            throw new BillingException('Contact absent du dossier.');
        }
        $filters = [
            'direction' => 'all',
            'status' => 'all',
            'search' => '',
            'contact_id' => $contactId,
        ];
        $documents = $this->documents(
            $organisationId,
            $dossierId,
            $asOfDate,
            $filters
        );
        $payments = array_values(array_filter(
            $this->paymentRows($organisationId, $dossierId, $asOfDate),
            static fn (array $payment): bool =>
                (int) $payment['contact_id'] === $contactId
        ));
        $aging = $this->aging($documents);
        foreach ($payments as $payment) {
            $side = $payment['direction'] === 'encaissement'
                ? 'receivables'
                : 'payables';
            $aging[$side]['unallocated_payments_cents'] +=
                (int) $payment['unallocated_cents'];
            $aging[$side]['net_open_cents'] -=
                (int) $payment['unallocated_cents'];
        }
        return [
            'contact_id' => $contactId,
            'reference_date' => $asOfDate,
            'documents' => $documents,
            'payments' => $payments,
            'aging' => $aging,
            'balance' => $this->contactBalance(
                $organisationId,
                $dossierId,
                $contactId,
                $asOfDate
            ),
        ];
    }

    /** @return array<string,int> */
    private function contactBalance(
        int $organisationId,
        int $dossierId,
        int $contactId,
        string $asOfDate,
    ): array {
        $filters = [
            'direction' => 'all',
            'status' => 'all',
            'search' => '',
            'contact_id' => $contactId,
        ];
        $documents = $this->documents(
            $organisationId,
            $dossierId,
            $asOfDate,
            $filters
        );
        return $this->balanceFromRows(
            $contactId,
            $documents,
            $this->paymentRows($organisationId, $dossierId, $asOfDate)
        );
    }

    /**
     * @param list<array<string,mixed>> $documents
     * @param list<array<string,mixed>> $payments
     * @return array<string,int>
     */
    private function balanceFromRows(
        int $contactId,
        array $documents,
        array $payments,
    ): array {
        $receivable = 0;
        $payable = 0;
        foreach ($documents as $document) {
            if ((int) $document['contact_id'] !== $contactId) {
                continue;
            }
            if ($document['direction'] === 'sales') {
                $receivable += (int) $document['open_cents'];
            } else {
                $payable += (int) $document['open_cents'];
            }
        }
        foreach ($payments as $payment) {
            if ((int) $payment['contact_id'] !== $contactId) {
                continue;
            }
            if ($payment['direction'] === 'encaissement') {
                $receivable -= (int) $payment['unallocated_cents'];
            } else {
                $payable -= (int) $payment['unallocated_cents'];
            }
        }
        return [
            'receivable_cents' => $receivable,
            'payable_cents' => $payable,
            'net_cents' => $receivable - $payable,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function recurrences(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*, COALESCE(NULLIF(c.raison_sociale, ''),
                        trim(c.prenom || ' ' || c.nom)) AS contact,
                    (SELECT COUNT(*) FROM generations_factures_recurrentes g
                     WHERE g.modele_id = r.id) AS generation_count
             FROM modeles_factures_recurrentes r
             JOIN contacts c ON c.id = r.contact_id
             WHERE r.organisation_id = ? AND r.dossier_id = ?
             ORDER BY r.prochaine_echeance, r.id"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'label' => (string) $row['libelle'],
            'contact_id' => (int) $row['contact_id'],
            'contact' => (string) $row['contact'],
            'frequency' => (string) $row['periodicite'],
            'interval' => (int) $row['intervalle'],
            'next_date' => (string) $row['prochaine_echeance'],
            'end_date' => $row['date_fin'] === null
                ? null : (string) $row['date_fin'],
            'due_days' => (int) $row['jours_echeance'],
            'status' => (string) $row['statut'],
            'generation_count' => (int) $row['generation_count'],
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function reminders(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.document_id, r.niveau AS level, r.canal AS channel,
                    r.note, r.rappele_le AS reminded_at, d.numero AS document_number
             FROM rappels_factures r
             JOIN documents_financiers d ON d.id = r.document_id
             WHERE r.organisation_id = ? AND r.dossier_id = ?
             ORDER BY r.rappele_le DESC, r.id DESC LIMIT 200'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function catalog(int $organisationId, int $dossierId): array
    {
        $catalog = $this->billing->catalog($organisationId, $dossierId);
        return [
            'accounts' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'label' => (string) $row['libelle'],
            ], $catalog['accounts']),
            'vat_codes' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'label' => (string) $row['libelle'],
                'nature' => (string) $row['nature'],
                'valid_from' => (string) $row['date_debut'],
                'valid_until' => $row['date_fin'] === null
                    ? null : (string) $row['date_fin'],
            ], $catalog['vat_codes']),
            'exercises' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['libelle'],
            ], $catalog['exercises']),
            'journals' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'label' => (string) $row['libelle'],
            ], $catalog['journals']),
        ];
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new BillingException('Date de référence invalide.');
        }
    }
}
