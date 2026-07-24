<?php

namespace Tests\Unit;

use App\Services\IredMail\AccountRepository;
use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\SystemSettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        $this->assertSame('second-password', $selected->decryptable_password);
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
        $this->assertNull($this->repository()->user($this->actor(), 'user@example.com')->decryptable_password);
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

        $this->assertNull($selected->decryptable_password);
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
}
