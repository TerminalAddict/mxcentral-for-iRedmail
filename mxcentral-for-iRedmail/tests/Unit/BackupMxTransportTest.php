<?php

namespace Tests\Unit;

use App\Services\IredMail\AccountRepository;
use App\Services\IredMail\AuditLogger;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Tests\TestCase;

final class BackupMxTransportTest extends TestCase
{
    public function test_backup_mx_transport_uses_primary_ip(): void
    {
        $this->assertSame(
            'relay:[45.56.127.226]:25',
            $this->domainTransport(['backupmx_primary_ip' => '45.56.127.226'], true)
        );
    }

    public function test_backup_mx_requires_valid_ip(): void
    {
        $this->expectException(ValidationException::class);

        $this->domainTransport(['backupmx_primary_ip' => 'mx01.example.com'], true);
    }

    public function test_backup_mx_primary_ip_is_parsed_from_transport(): void
    {
        $repository = new AccountRepository(new AuditLogger());

        $this->assertSame('45.56.127.226', $repository->backupMxPrimaryIp((object) [
            'transport' => 'relay:[45.56.127.226]:25',
        ]));
    }

    private function domainTransport(array $data, bool $backupMx): string
    {
        $repository = new AccountRepository(new AuditLogger());
        $method = (new ReflectionClass($repository))->getMethod('domainTransport');

        return $method->invoke($repository, $data, $backupMx);
    }
}
