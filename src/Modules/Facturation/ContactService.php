<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class ContactService
{
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param list<string> $roles
     * @param array{ligne1:string,ligne2?:string,code_postal:string,localite:string,pays?:string,type?:string}|null $address
     */
    public function create(
        int $organisationId,
        int $dossierId,
        array $data,
        array $roles,
        ?array $address = null,
        ?int $actorId = null,
        string $idempotencyKey = '',
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $data,
            $roles,
            $address,
            $actorId,
            $idempotencyKey
        ): int {
            $type = (string) ($data['type_personne'] ?? 'entreprise');
            $company = trim((string) ($data['raison_sociale'] ?? ''));
            $firstName = trim((string) ($data['prenom'] ?? ''));
            $lastName = trim((string) ($data['nom'] ?? ''));
            if (
                !in_array($type, ['entreprise', 'personne'], true)
                || ($type === 'entreprise' && $company === '')
                || ($type === 'personne' && $firstName === '' && $lastName === '')
            ) {
                throw new BillingException('Identité du contact invalide.');
            }
            $this->assertRoles($roles);
            $idempotencyKey = trim($idempotencyKey);
            if ($idempotencyKey !== '') {
                $existing = $this->pdo->prepare(
                    'SELECT id FROM contacts
                     WHERE organisation_id = ? AND dossier_id = ?
                       AND cle_idempotence = ?'
                );
                $existing->execute([
                    $organisationId,
                    $dossierId,
                    $idempotencyKey,
                ]);
                $existingId = $existing->fetchColumn();
                if ($existingId !== false) {
                    return (int) $existingId;
                }
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO contacts
                 (organisation_id, dossier_id, type_personne, raison_sociale,
                  prenom, nom, email, telephone, iban_paiement, bic_paiement,
                  langue, cree_par, cle_idempotence)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $type, $company, $firstName, $lastName,
                trim((string) ($data['email'] ?? '')),
                trim((string) ($data['telephone'] ?? '')),
                strtoupper(str_replace(' ', '', trim((string) ($data['iban_paiement'] ?? '')))),
                strtoupper(str_replace(' ', '', trim((string) ($data['bic_paiement'] ?? '')))),
                (string) ($data['langue'] ?? 'fr'),
                $actorId,
                $idempotencyKey,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $insertRole = $this->pdo->prepare(
                'INSERT INTO contact_roles (contact_id, role) VALUES (?, ?)'
            );
            foreach (array_values(array_unique($roles)) as $role) {
                $insertRole->execute([$id, $role]);
            }
            if ($address !== null) {
                $this->addAddress($organisationId, $dossierId, $id, $address);
            }
            $this->audit->log(
                'facturation.contact_cree',
                $actorId,
                $organisationId,
                $dossierId,
                'contact',
                (string) $id,
                ['roles' => array_values(array_unique($roles))]
            );
            return $id;
        }, true);
    }

    /**
     * @param list<string> $roles
     * @param array{ligne1:string,ligne2?:string,code_postal:string,localite:string,pays?:string} $address
     */
    public function update(
        int $organisationId,
        int $dossierId,
        int $contactId,
        int $expectedVersion,
        array $data,
        array $roles,
        array $address,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $contactId,
            $expectedVersion,
            $data,
            $roles,
            $address,
            $actorId
        ): void {
            $type = (string) ($data['type_personne'] ?? 'entreprise');
            $company = trim((string) ($data['raison_sociale'] ?? ''));
            $firstName = trim((string) ($data['prenom'] ?? ''));
            $lastName = trim((string) ($data['nom'] ?? ''));
            if (
                !in_array($type, ['entreprise', 'personne'], true)
                || ($type === 'entreprise' && $company === '')
                || ($type === 'personne' && $firstName === '' && $lastName === '')
            ) {
                throw new BillingException('Identité du contact invalide.');
            }
            $this->assertRoles($roles);
            foreach (['ligne1', 'code_postal', 'localite'] as $field) {
                if (trim((string) ($address[$field] ?? '')) === '') {
                    throw new BillingException('Adresse incomplète.');
                }
            }
            $update = $this->pdo->prepare(
                'UPDATE contacts
                 SET type_personne = ?, raison_sociale = ?, prenom = ?, nom = ?,
                     email = ?, telephone = ?, iban_paiement = ?, bic_paiement = ?,
                     langue = ?,
                     modifie_le = datetime(\'now\'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ? AND actif = 1'
            );
            $update->execute([
                $type,
                $company,
                $firstName,
                $lastName,
                trim((string) ($data['email'] ?? '')),
                trim((string) ($data['telephone'] ?? '')),
                strtoupper(str_replace(' ', '', trim((string) ($data['iban_paiement'] ?? '')))),
                strtoupper(str_replace(' ', '', trim((string) ($data['bic_paiement'] ?? '')))),
                (string) ($data['langue'] ?? 'fr'),
                $contactId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new BillingException(
                    'Contact absent ou modifié par un autre utilisateur.'
                );
            }
            $this->pdo->prepare(
                'DELETE FROM contact_roles WHERE contact_id = ?'
            )->execute([$contactId]);
            $insertRole = $this->pdo->prepare(
                'INSERT INTO contact_roles (contact_id, role) VALUES (?, ?)'
            );
            foreach (array_values(array_unique($roles)) as $role) {
                $insertRole->execute([$contactId, $role]);
            }
            $addressId = $this->pdo->prepare(
                "SELECT id FROM adresses_contacts
                 WHERE contact_id = ? AND type = 'facturation' AND actif = 1
                 ORDER BY id LIMIT 1"
            );
            $addressId->execute([$contactId]);
            $currentAddressId = $addressId->fetchColumn();
            if ($currentAddressId === false) {
                $this->addAddress(
                    $organisationId,
                    $dossierId,
                    $contactId,
                    $address + ['type' => 'facturation']
                );
            } else {
                $this->pdo->prepare(
                    'UPDATE adresses_contacts
                     SET ligne1 = ?, ligne2 = ?, code_postal = ?, localite = ?,
                         pays = ?, modifie_le = datetime(\'now\'),
                         version = version + 1
                     WHERE id = ?'
                )->execute([
                    trim((string) $address['ligne1']),
                    trim((string) ($address['ligne2'] ?? '')),
                    trim((string) $address['code_postal']),
                    trim((string) $address['localite']),
                    strtoupper(trim((string) ($address['pays'] ?? 'CH'))),
                    (int) $currentAddressId,
                ]);
            }
            $this->audit->log(
                'facturation.contact_modifie',
                $actorId,
                $organisationId,
                $dossierId,
                'contact',
                (string) $contactId,
                ['roles' => array_values(array_unique($roles))]
            );
        }, true);
    }

    /** @param array{ligne1:string,ligne2?:string,code_postal:string,localite:string,pays?:string,type?:string} $data */
    public function addAddress(
        int $organisationId,
        int $dossierId,
        int $contactId,
        array $data,
    ): int {
        $contact = $this->pdo->prepare(
            'SELECT 1 FROM contacts
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $contact->execute([$contactId, $organisationId, $dossierId]);
        if ($contact->fetchColumn() === false) {
            throw new BillingException('Contact absent du dossier.');
        }
        foreach (['ligne1', 'code_postal', 'localite'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw new BillingException('Adresse incomplète.');
            }
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO adresses_contacts
             (contact_id, type, ligne1, ligne2, code_postal, localite, pays)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $contactId,
            (string) ($data['type'] ?? 'facturation'),
            trim((string) $data['ligne1']),
            trim((string) ($data['ligne2'] ?? '')),
            trim((string) $data['code_postal']),
            trim((string) $data['localite']),
            strtoupper(trim((string) ($data['pays'] ?? 'CH'))),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function all(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, group_concat(cr.role, ',') AS roles,
                    a.ligne1, a.ligne2, a.code_postal, a.localite, a.pays
             FROM contacts c
             LEFT JOIN contact_roles cr ON cr.contact_id = c.id
             LEFT JOIN adresses_contacts a ON a.id = (
                 SELECT a2.id FROM adresses_contacts a2
                 WHERE a2.contact_id = c.id AND a2.actif = 1
                 ORDER BY CASE a2.type WHEN 'facturation' THEN 0 ELSE 1 END, a2.id
                 LIMIT 1
             )
             WHERE c.organisation_id = ? AND c.dossier_id = ? AND c.actif = 1
             GROUP BY c.id
             ORDER BY COALESCE(NULLIF(c.raison_sociale, ''), c.nom), c.prenom"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    public function snapshot(int $organisationId, int $dossierId, int $contactId): array
    {
        foreach ($this->all($organisationId, $dossierId) as $contact) {
            if ((int) $contact['id'] === $contactId) {
                return [
                    'id' => $contactId,
                    'type_personne' => $contact['type_personne'],
                    'raison_sociale' => $contact['raison_sociale'],
                    'prenom' => $contact['prenom'],
                    'nom' => $contact['nom'],
                    'email' => $contact['email'],
                    'telephone' => $contact['telephone'],
                    'iban_paiement' => $contact['iban_paiement'],
                    'bic_paiement' => $contact['bic_paiement'],
                    'langue' => $contact['langue'],
                    'adresse' => [
                        'ligne1' => $contact['ligne1'] ?? '',
                        'ligne2' => $contact['ligne2'] ?? '',
                        'code_postal' => $contact['code_postal'] ?? '',
                        'localite' => $contact['localite'] ?? '',
                        'pays' => $contact['pays'] ?? 'CH',
                    ],
                ];
            }
        }
        throw new BillingException('Contact absent du dossier.');
    }

    /** @param list<string> $roles */
    private function assertRoles(array $roles): void
    {
        if ($roles === []) {
            throw new BillingException('Le contact doit posséder au moins un rôle.');
        }
        foreach ($roles as $role) {
            if (!in_array($role, ['client', 'fournisseur', 'employe', 'autre'], true)) {
                throw new BillingException('Rôle de contact invalide.');
            }
        }
    }

    private function transaction(callable $callback, bool $immediate = false): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            return $callback();
        }
        if ($immediate) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
        $this->transactionActive = true;
        try {
            $result = $callback();
            $immediate ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            $this->transactionActive = false;
            return $result;
        } catch (Throwable $e) {
            if ($this->transactionActive) {
                $immediate ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
                $this->transactionActive = false;
            }
            throw $e;
        }
    }
}
