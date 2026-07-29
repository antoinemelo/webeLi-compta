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
            $companyContactId = $type === 'personne'
                ? (int) ($data['entreprise_id'] ?? 0)
                : 0;
            if (
                !in_array($type, ['entreprise', 'personne'], true)
                || ($type === 'entreprise' && $company === '')
                || ($type === 'personne' && $firstName === '' && $lastName === '')
            ) {
                throw new BillingException('Identité du contact invalide.');
            }
            $this->assertCompanyContact(
                $organisationId,
                $dossierId,
                $companyContactId
            );
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
                 (organisation_id, dossier_id, type_personne, entreprise_id, raison_sociale,
                  prenom, nom, email, telephone, iban_paiement, bic_paiement,
                  langue, cree_par, cle_idempotence)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $type,
                $companyContactId > 0 ? $companyContactId : null,
                $company, $firstName, $lastName,
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
            $companyContactId = $type === 'personne'
                ? (int) ($data['entreprise_id'] ?? 0)
                : 0;
            if (
                !in_array($type, ['entreprise', 'personne'], true)
                || ($type === 'entreprise' && $company === '')
                || ($type === 'personne' && $firstName === '' && $lastName === '')
            ) {
                throw new BillingException('Identité du contact invalide.');
            }
            $this->assertCompanyContact(
                $organisationId,
                $dossierId,
                $companyContactId,
                $contactId
            );
            $this->assertRoles($roles);
            foreach (['ligne1', 'code_postal', 'localite'] as $field) {
                if (trim((string) ($address[$field] ?? '')) === '') {
                    throw new BillingException('Adresse incomplète.');
                }
            }
            $update = $this->pdo->prepare(
                'UPDATE contacts
                 SET type_personne = ?, entreprise_id = ?, raison_sociale = ?, prenom = ?, nom = ?,
                     email = ?, telephone = ?, iban_paiement = ?, bic_paiement = ?,
                     langue = ?,
                     modifie_le = datetime(\'now\'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ? AND actif = 1'
            );
            $update->execute([
                $type,
                $companyContactId > 0 ? $companyContactId : null,
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
    public function all(
        int $organisationId,
        int $dossierId,
        bool $includeArchived = false,
    ): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, group_concat(cr.role, ',') AS roles,
                    a.ligne1, a.ligne2, a.code_postal, a.localite, a.pays,
                    e.raison_sociale AS entreprise_nom,
                    (
                        SELECT COUNT(*) FROM documents_commerciaux dc
                        WHERE dc.contact_id = c.id
                          AND dc.type IN (
                              'offre_client',
                              'demande_offre_fournisseur',
                              'reponse_offre_fournisseur'
                          )
                          AND dc.statut NOT IN ('refuse', 'annule', 'archive')
                    ) AS offres_actives,
                    (
                        SELECT COUNT(*) FROM documents_commerciaux dc
                        WHERE dc.contact_id = c.id
                          AND dc.type IN ('commande_client', 'commande_fournisseur')
                          AND dc.statut NOT IN ('refuse', 'annule', 'archive')
                    ) AS commandes_actives
             FROM contacts c
             LEFT JOIN contacts e ON e.id = c.entreprise_id
             LEFT JOIN contact_roles cr ON cr.contact_id = c.id
             LEFT JOIN adresses_contacts a ON a.id = (
                 SELECT a2.id FROM adresses_contacts a2
                 WHERE a2.contact_id = c.id AND a2.actif = 1
                 ORDER BY CASE a2.type WHEN 'facturation' THEN 0 ELSE 1 END, a2.id
                 LIMIT 1
             )
             WHERE c.organisation_id = ? AND c.dossier_id = ?
               AND (? = 1 OR c.actif = 1)
             GROUP BY c.id
             ORDER BY c.actif DESC,
                      COALESCE(NULLIF(c.raison_sociale, ''), c.nom), c.prenom"
        );
        $stmt->execute([$organisationId, $dossierId, $includeArchived ? 1 : 0]);
        return $stmt->fetchAll();
    }

    public function restore(
        int $organisationId,
        int $dossierId,
        int $contactId,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $restore = $this->pdo->prepare(
            "UPDATE contacts
             SET actif = 1, archive_le = NULL, archive_par = NULL,
                 modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND version = ? AND actif = 0"
        );
        $restore->execute([
            $contactId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($restore->rowCount() !== 1) {
            throw new BillingException(
                'Contact absent, déjà actif ou modifié par une autre session.'
            );
        }
        $this->audit->log(
            'facturation.contact_reactive',
            $actorId,
            $organisationId,
            $dossierId,
            'contact',
            (string) $contactId
        );
    }

    /** @return array<string,mixed> */
    public function snapshot(int $organisationId, int $dossierId, int $contactId): array
    {
        foreach ($this->all($organisationId, $dossierId) as $contact) {
            if ((int) $contact['id'] === $contactId) {
                return [
                    'id' => $contactId,
                    'type_personne' => $contact['type_personne'],
                    'entreprise_id' => $contact['entreprise_id'],
                    'entreprise_nom' => $contact['entreprise_nom'],
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

    /**
     * Supprime physiquement un contact sans historique, sinon l’archive.
     *
     * @return array{action:'deleted'|'archived',dependencies:list<string>}
     */
    public function remove(
        int $organisationId,
        int $dossierId,
        int $contactId,
        int $expectedVersion,
        ?int $actorId = null,
    ): array {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $contactId,
            $expectedVersion,
            $actorId
        ): array {
            $contact = $this->pdo->prepare(
                'SELECT raison_sociale, prenom, nom, version, actif
                 FROM contacts
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $contact->execute([$contactId, $organisationId, $dossierId]);
            $row = $contact->fetch();
            if ($row === false) {
                throw new BillingException('Contact absent du dossier.');
            }
            if ((int) $row['version'] !== $expectedVersion) {
                throw new BillingException(
                    'Le contact a été modifié par une autre session. Rechargez la page.'
                );
            }
            if ((int) $row['actif'] !== 1) {
                throw new BillingException('Ce contact est déjà archivé.');
            }

            $dependencies = $this->dependencies(
                $organisationId,
                $dossierId,
                $contactId
            );
            $name = trim(
                (string) $row['raison_sociale'] . ' '
                . (string) $row['prenom'] . ' '
                . (string) $row['nom']
            );
            if ($dependencies !== []) {
                $archive = $this->pdo->prepare(
                    "UPDATE contacts
                     SET actif = 0, archive_le = datetime('now'), archive_par = ?,
                         modifie_le = datetime('now'), version = version + 1
                     WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                       AND version = ? AND actif = 1"
                );
                $archive->execute([
                    $actorId,
                    $contactId,
                    $organisationId,
                    $dossierId,
                    $expectedVersion,
                ]);
                if ($archive->rowCount() !== 1) {
                    throw new BillingException('Conflit pendant l’archivage du contact.');
                }
                $this->audit->log(
                    'facturation.contact_archive',
                    $actorId,
                    $organisationId,
                    $dossierId,
                    'contact',
                    (string) $contactId,
                    ['nom' => $name, 'dependances' => $dependencies]
                );
                return ['action' => 'archived', 'dependencies' => $dependencies];
            }

            $employee = $this->pdo->prepare(
                'SELECT id FROM employes
                 WHERE organisation_id = ? AND dossier_id = ? AND contact_id = ?'
            );
            $employee->execute([$organisationId, $dossierId, $contactId]);
            $employeeId = $employee->fetchColumn();
            if ($employeeId !== false) {
                $this->pdo->prepare(
                    'DELETE FROM contrats_salariaux
                     WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?'
                )->execute([$organisationId, $dossierId, (int) $employeeId]);
                $this->pdo->prepare(
                    'DELETE FROM employes
                     WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
                )->execute([(int) $employeeId, $organisationId, $dossierId]);
            }
            $delete = $this->pdo->prepare(
                'DELETE FROM contacts
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ?'
            );
            $delete->execute([
                $contactId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($delete->rowCount() !== 1) {
                throw new BillingException('Conflit pendant la suppression du contact.');
            }
            $this->audit->log(
                'facturation.contact_supprime',
                $actorId,
                $organisationId,
                $dossierId,
                'contact',
                (string) $contactId,
                ['nom' => $name]
            );
            return ['action' => 'deleted', 'dependencies' => []];
        }, true);
    }

    /** @return list<string> */
    private function dependencies(
        int $organisationId,
        int $dossierId,
        int $contactId,
    ): array {
        $dependencies = [];
        foreach ([
            'documents_financiers' => 'document financier',
            'documents_commerciaux' => 'document commercial',
            'paiements' => 'paiement',
            'modeles_factures_recurrentes' => 'facture récurrente',
            'modeles_depenses_recurrentes' => 'dépense récurrente',
            'ordres_paiement_sortants' => 'ordre de paiement',
            'contacts' => 'personne rattachée',
        ] as $table => $label) {
            $column = $table === 'contacts' ? 'entreprise_id' : 'contact_id';
            $scope = $table === 'contacts'
                ? 'dossier_id = ?'
                : 'organisation_id = ? AND dossier_id = ?';
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE {$scope} AND {$column} = ?"
            );
            $params = $table === 'contacts'
                ? [$dossierId, $contactId]
                : [$organisationId, $dossierId, $contactId];
            $stmt->execute($params);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $dependencies[] = "{$count} {$label}" . ($count > 1 ? 's' : '');
            }
        }
        $employee = $this->pdo->prepare(
            'SELECT id FROM employes
             WHERE organisation_id = ? AND dossier_id = ? AND contact_id = ?'
        );
        $employee->execute([$organisationId, $dossierId, $contactId]);
        $employeeId = $employee->fetchColumn();
        if ($employeeId !== false) {
            foreach ([
                'fiches_salaires' => 'fiche de salaire',
                'certificats_salaires' => 'certificat de salaire',
                'paiements_salaires' => 'paiement salarial',
            ] as $table => $label) {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?"
                );
                $stmt->execute([$organisationId, $dossierId, (int) $employeeId]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $dependencies[] = "{$count} {$label}" . ($count > 1 ? 's' : '');
                }
            }
        }
        return $dependencies;
    }

    private function assertCompanyContact(
        int $organisationId,
        int $dossierId,
        int $companyContactId,
        int $contactId = 0,
    ): void {
        if ($companyContactId === 0) {
            return;
        }
        if ($companyContactId === $contactId) {
            throw new BillingException('Un contact ne peut pas être sa propre entreprise.');
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM contacts
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND type_personne = 'entreprise' AND actif = 1"
        );
        $stmt->execute([$companyContactId, $organisationId, $dossierId]);
        if ($stmt->fetchColumn() === false) {
            throw new BillingException(
                'L’entreprise associée est absente, archivée ou hors du dossier.'
            );
        }
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
