<?php
declare(strict_types=1);

namespace Compta\Core\Audit;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, scalar|null> $summary */
    public function log(
        string $action,
        ?int $userId = null,
        ?int $organisationId = null,
        ?int $dossierId = null,
        string $targetType = '',
        string $targetId = '',
        array $summary = [],
        string $ip = '',
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_events
             (utilisateur_id, organisation_id, dossier_id, action, cible_type, cible_id, resume_json, ip)
             VALUES (:user, :organisation, :dossier, :action, :type, :target, :summary, :ip)'
        );
        $stmt->execute([
            'user' => $userId,
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'action' => $action,
            'type' => $targetType,
            'target' => $targetId,
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => $this->anonymizeIp($ip),
        ]);
    }

    private function anonymizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
            }
        }
        return '';
    }
}
