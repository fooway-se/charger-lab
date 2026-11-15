<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/** Stores certificates managed by OCPP, separately from the host TLS trust store. */
final class OcppCertificateRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function install(int $chargerId, string $certificateType, string $certificatePem, string $fingerprint): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT OR IGNORE INTO ocpp_certificate (
                charger_id, certificate_type, certificate_pem, fingerprint_sha256, updated_at
            ) VALUES (:charger_id, :certificate_type, :certificate_pem, :fingerprint, :updated_at)
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'certificate_type' => $certificateType,
            'certificate_pem' => $certificatePem,
            'fingerprint' => $fingerprint,
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function all(int $chargerId, ?array $certificateTypes = null): array
    {
        if ($certificateTypes === []) {
            return [];
        }

        if ($certificateTypes === null) {
            $statement = $this->database->prepare(
                'SELECT id, certificate_type, certificate_pem, fingerprint_sha256 FROM ocpp_certificate WHERE charger_id = :charger_id ORDER BY id',
            );
            $statement->execute(['charger_id' => $chargerId]);

            return $statement->fetchAll();
        }

        $placeholders = [];
        $parameters = ['charger_id' => $chargerId];
        foreach (array_values($certificateTypes) as $index => $certificateType) {
            $name = 'certificate_type_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $certificateType;
        }

        $statement = $this->database->prepare(
            'SELECT id, certificate_type, certificate_pem, fingerprint_sha256 FROM ocpp_certificate '
                . 'WHERE charger_id = :charger_id AND certificate_type IN (' . implode(', ', $placeholders) . ') ORDER BY id',
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function manufacturerRootBundle(int $chargerId): string
    {
        return $this->rootBundle($chargerId, 'ManufacturerRootCertificate');
    }

    public function csmsRootBundle(int $chargerId): string
    {
        return $this->rootBundle($chargerId, 'CSMSRootCertificate');
    }

    private function rootBundle(int $chargerId, string $certificateType): string
    {
        $roots = $this->all($chargerId, [$certificateType]);

        return implode("\n", array_map(
            static fn (array $certificate): string => trim((string) $certificate['certificate_pem']),
            $roots,
        ));
    }

    public function delete(int $certificateId, int $chargerId): bool
    {
        $statement = $this->database->prepare(
            'DELETE FROM ocpp_certificate WHERE id = :id AND charger_id = :charger_id',
        );
        $statement->execute(['id' => $certificateId, 'charger_id' => $chargerId]);

        return $statement->rowCount() === 1;
    }
}
