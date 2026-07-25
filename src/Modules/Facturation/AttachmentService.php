<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Compta\Core\Audit\AuditLogger;
use PDO;

final class AttachmentService
{
    private const MAX_BYTES = 10_000_000;
    private const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    public function store(
        int $organisationId,
        int $dossierId,
        string $filename,
        string $contents,
        ?int $actorId = null,
    ): int {
        $size = strlen($contents);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (
            $size === 0
            || $size > self::MAX_BYTES
            || !is_string($mime)
            || !in_array($mime, self::ALLOWED_MIME, true)
        ) {
            throw new BillingException('Justificatif vide, trop volumineux ou non autorisé.');
        }
        $safeName = trim(basename(str_replace('\\', '/', $filename)));
        if ($safeName === '') {
            throw new BillingException('Nom de justificatif invalide.');
        }
        $hash = hash('sha256', $contents);
        $find = $this->pdo->prepare(
            'SELECT id FROM pieces_jointes
             WHERE dossier_id = ? AND empreinte_sha256 = ?'
        );
        $find->execute([$dossierId, $hash]);
        $existing = $find->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO pieces_jointes
             (organisation_id, dossier_id, nom_fichier, type_mime,
              taille_octets, empreinte_sha256, contenu, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $organisationId, PDO::PARAM_INT);
        $stmt->bindValue(2, $dossierId, PDO::PARAM_INT);
        $stmt->bindValue(3, $safeName);
        $stmt->bindValue(4, $mime);
        $stmt->bindValue(5, $size, PDO::PARAM_INT);
        $stmt->bindValue(6, $hash);
        $stmt->bindValue(7, $contents, PDO::PARAM_LOB);
        $stmt->bindValue(8, $actorId, $actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'facturation.justificatif_archive',
            $actorId,
            $organisationId,
            $dossierId,
            'piece_jointe',
            (string) $id,
            ['nom' => $safeName, 'empreinte_sha256' => $hash]
        );
        return $id;
    }
}
