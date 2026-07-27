<?php

namespace Tests\Unit;

use App\Services\IredMail\AccountRepository;
use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\PasswordRevealAccess;
use App\Services\IredMail\PasswordRevealService;
use App\Services\IredMail\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\FakeAuthService;
use Tests\TestCase;

final class DecryptablePasswordStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'database.connections.vmail' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'database.connections.iredadmin' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'iredmail.decryptable_password_column' => 'decrypt-pass',
            'iredmail.storage_base_directory' => '/var/vmail/vmail1',
            'iredmail.default_mta_transport' => 'dovecot',
        ]);

        DB::purge('vmail');
        DB::purge('iredadmin');

        DB::connection('vmail')->getSchemaBuilder()->create('domain', function ($table): void {
            $table->string('domain')->primary();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('mailbox', function ($table): void {
            $table->string('username')->primary();
            $table->string('password')->nullable();
            $table->string('name')->nullable();
            $table->string('language')->nullable();
            $table->string('domain')->nullable();
            $table->string('maildir')->nullable();
            $table->integer('quota')->default(0);
            $table->string('storagebasedirectory')->nullable();
            $table->string('storagenode')->nullable();
            $table->string('transport')->nullable();
            $table->dateTime('created')->nullable();
            $table->dateTime('modified')->nullable();
            $table->dateTime('passwordlastchange')->nullable();
            $table->integer('active')->default(1);
        });
        DB::connection('vmail')->getSchemaBuilder()->create('forwardings', function ($table): void {
            $table->string('address')->nullable();
            $table->string('forwarding')->nullable();
            $table->string('domain')->nullable();
            $table->string('dest_domain')->nullable();
            $table->integer('is_forwarding')->default(0);
            $table->integer('active')->default(1);
        });
        DB::connection('vmail')->getSchemaBuilder()->create('alias', function ($table): void {
            $table->string('address')->primary();
        });
        DB::connection('vmail')->getSchemaBuilder()->create('maillists', function ($table): void {
            $table->string('address')->primary();
        });
        DB::connection('iredadmin')->getSchemaBuilder()->create('log', function ($table): void {
            $table->string('admin')->nullable();
            $table->string('ip')->nullable();
            $table->string('domain')->nullable();
            $table->string('username')->nullable();
            $table->string('event')->nullable();
            $table->string('loglevel')->nullable();
            $table->text('msg')->nullable();
        });

        DB::connection('vmail')->table('domain')->insert(['domain' => 'example.com']);
    }

    protected function tearDown(): void
    {
        DB::purge('vmail');
        DB::purge('iredadmin');

        parent::tearDown();
    }

    public function test_setting_adds_and_drops_decryptable_password_column(): void
    {
        $settings = new SystemSettingsService(new AuditLogger);

        $this->assertFalse(Schema::connection('vmail')->hasColumn('mailbox', 'decrypt-pass'));

        $result = $settings->saveDecryptablePasswords($this->actor(), true);
        $this->assertTrue($result['changed']);
        $this->assertTrue(Schema::connection('vmail')->hasColumn('mailbox', 'decrypt-pass'));

        $result = $settings->saveDecryptablePasswords($this->actor(), true);
        $this->assertFalse($result['changed']);

        $result = $settings->saveDecryptablePasswords($this->actor(), false);
        $this->assertTrue($result['changed']);
        $this->assertFalse(Schema::connection('vmail')->hasColumn('mailbox', 'decrypt-pass'));

        $result = $settings->saveDecryptablePasswords($this->actor(), false);
        $this->assertFalse($result['changed']);
    }

    public function test_create_and_update_user_store_encrypted_decryptable_password_when_enabled(): void
    {
        (new SystemSettingsService(new AuditLogger))->saveDecryptablePasswords($this->actor(), true);

        $this->repository()->createUser($this->actor(), [
            'local_part' => 'user',
            'domain' => 'example.com',
            'name' => 'Example User',
            'password' => 'first-password',
        ]);

        $stored = DB::connection('vmail')->table('mailbox')->where('username', 'user@example.com')->value('decrypt-pass');
        $this->assertIsString($stored);
        $this->assertNotSame('first-password', $stored);
        $this->assertSame('first-password', Crypt::decryptString($stored));

        $this->repository()->updateUser($this->actor(), 'user@example.com', [
            'name' => 'Example User',
            'quota' => 0,
            'active' => 1,
            'password' => 'second-password',
        ]);

        $updated = DB::connection('vmail')->table('mailbox')->where('username', 'user@example.com')->value('decrypt-pass');
        $this->assertSame('second-password', Crypt::decryptString($updated));

        $selected = $this->repository()->user($this->actor(), 'user@example.com');
        $this->assertTrue($selected->has_decryptable_password);
        $this->assertFalse(property_exists($selected, 'decryptable_password'));
        $this->assertFalse(property_exists($selected, 'decrypt-pass'));
    }

    public function test_disabling_removes_values_and_disabled_password_changes_are_not_recoverable(): void
    {
        $settings = new SystemSettingsService(new AuditLogger);
        $settings->saveDecryptablePasswords($this->actor(), true);

        $this->repository()->createUser($this->actor(), [
            'local_part' => 'user',
            'domain' => 'example.com',
            'name' => 'Example User',
            'password' => 'stored-password',
        ]);
        $this->assertNotNull(DB::connection('vmail')->table('mailbox')->value('decrypt-pass'));

        $settings->saveDecryptablePasswords($this->actor(), false);
        $this->assertFalse(Schema::connection('vmail')->hasColumn('mailbox', 'decrypt-pass'));

        $this->repository()->updateUser($this->actor(), 'user@example.com', [
            'name' => 'Example User',
            'quota' => 0,
            'active' => 1,
            'password' => 'disabled-change',
        ]);

        $settings->saveDecryptablePasswords($this->actor(), true);
        $this->assertNull(DB::connection('vmail')->table('mailbox')->value('decrypt-pass'));
        $selected = $this->repository()->user($this->actor(), 'user@example.com');
        $this->assertFalse($selected->has_decryptable_password);
        $this->assertFalse(property_exists($selected, 'decryptable_password'));
    }

    public function test_user_without_stored_decryptable_password_gets_null_display_value(): void
    {
        (new SystemSettingsService(new AuditLogger))->saveDecryptablePasswords($this->actor(), true);

        DB::connection('vmail')->table('mailbox')->insert([
            'username' => 'old-user@example.com',
            'password' => 'hashed-only',
            'domain' => 'example.com',
            'active' => 1,
        ]);

        $selected = $this->repository()->user($this->actor(), 'old-user@example.com');

        $this->assertFalse($selected->has_decryptable_password);
        $this->assertFalse(property_exists($selected, 'decryptable_password'));
    }

    public function test_stored_ciphertext_is_not_exposed_in_lists_or_self_service_results(): void
    {
        (new SystemSettingsService(new AuditLogger))->saveDecryptablePasswords($this->actor(), true);

        $this->repository()->createUser($this->actor(), [
            'local_part' => 'user',
            'domain' => 'example.com',
            'name' => 'Example User',
            'password' => 'secret-password',
        ]);

        $listed = $this->repository()->users($this->actor())->items()[0];
        $selfService = $this->repository()->user($this->selfServiceActor(), 'user@example.com');

        $this->assertFalse(property_exists($listed, 'decrypt-pass'));
        $this->assertFalse(property_exists($selfService, 'decrypt-pass'));
        $this->assertFalse(property_exists($selfService, 'decryptable_password'));
    }

    public function test_only_global_admin_can_change_decryptable_password_setting(): void
    {
        try {
            (new SystemSettingsService(new AuditLogger))->saveDecryptablePasswords($this->selfServiceActor(), true);
            $this->fail('A non-global administrator changed the decryptable password setting.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(Schema::connection('vmail')->hasColumn('mailbox', 'decrypt-pass'));
    }

    public function test_password_reveal_requires_reauthentication_mfa_and_is_one_time(): void
    {
        $actor = $this->actor();
        $secret = 'JBSWY3DPEHPK3PXP';
        config([
            'iredmail.password_reveal_admins' => $actor->email,
            'iredmail.password_reveal_totp_secrets' => json_encode([$actor->email => $secret]),
            'iredmail.password_reveal_cache_store' => 'file',
            'iredmail.password_reveal_token_seconds' => 60,
        ]);
        CarbonImmutable::setTestNow('2026-07-28 12:00:00 UTC');
        app()->instance(CurrentActor::class, $actor);

        (new SystemSettingsService(new AuditLogger))->saveDecryptablePasswords($actor, true);
        $repository = $this->repository();
        $repository->createUser($actor, [
            'local_part' => 'user',
            'domain' => 'example.com',
            'name' => 'Example User',
            'password' => 'stored-password',
        ]);
        $service = new PasswordRevealService(
            new PasswordRevealAccess,
            new FakeAuthService($actor),
            $repository,
            new AuditLogger,
        );

        try {
            $service->request(
                $actor,
                'admin-record',
                'user@example.com',
                'wrong-password',
                $this->totp($secret),
                'Customer-authorized migration support.',
            );
            $this->fail('Password reveal accepted an incorrect administrator password.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_password', $exception->errors());
        }

        $token = $service->request(
            $actor,
            'admin-record',
            'user@example.com',
            'current-password',
            $this->totp($secret),
            'Customer-authorized migration support.',
        );
        $revealed = $service->consume($actor, $token);
        $this->assertSame('stored-password', $revealed['password']);
        $this->assertSame('user@example.com', $revealed['email']);

        $this->expectException(HttpException::class);
        $service->consume($actor, $token);
    }

    public function test_cross_type_address_conflict_is_rejected_inside_serialized_creation(): void
    {
        $this->repository()->createUser($this->actor(), [
            'local_part' => 'shared',
            'domain' => 'example.com',
            'name' => 'Mailbox',
            'password' => 'first-password',
        ]);

        try {
            $this->repository()->createAlias($this->actor(), [
                'address' => 'shared@example.com',
                'members' => 'destination@example.com',
            ]);
            $this->fail('An alias was created over an existing mailbox address.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('address', $exception->errors());
        }

        $this->assertSame(1, DB::connection('vmail')->table('mailbox')->where('username', 'shared@example.com')->count());
        $this->assertSame(0, DB::connection('vmail')->table('alias')->where('address', 'shared@example.com')->count());
    }

    private function repository(): AccountRepository
    {
        return new AccountRepository(new AuditLogger);
    }

    private function actor(): CurrentActor
    {
        return new CurrentActor(
            email: 'postmaster@example.com',
            type: 'admin',
            globalAdmin: true,
            domainAdmin: false,
            selfService: false,
            domains: [],
        );
    }

    private function selfServiceActor(): CurrentActor
    {
        return new CurrentActor(
            email: 'user@example.com',
            type: 'user',
            globalAdmin: false,
            domainAdmin: false,
            selfService: true,
            domains: ['example.com'],
        );
    }

    private function totp(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $counter = intdiv(now()->timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $number = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % 1_000_000;

        return str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
