<?php

namespace Tests\Unit;

use App\Services\IredMail\ProductionSafetyCheck;
use Tests\TestCase;

final class ProductionSafetyCheckTest extends TestCase
{
    public function test_explicit_safe_server_profile_passes(): void
    {
        $this->app->instance('env', 'production');
        config([
            'app.debug' => false,
            'app.url' => 'https://mail.example.com/mxcentral',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'session.secure' => true,
            'session.http_only' => true,
            'session.encrypt' => true,
            'session.same_site' => 'lax',
            'iredmail.storage_base_directory' => '/var/vmail/vmail1',
            'iredmail.storage_node' => 'vmail1',
            'iredmail.mailbox_directory_template' => '{domain}/{local}/',
            'iredmail.mailbox_language' => 'en_US',
            'iredmail.backup_mx_port' => 25,
            'iredmail.mailing_list_transport_template' => 'mlmmj:{address}',
            'iredmail.page_size' => 50,
            'iredmail.privileged_helper_command' => '/usr/bin/sudo /usr/local/sbin/mxcentral-privileged',
        ]);
        foreach (['vmail', 'iredadmin', 'amavisd', 'iredapd', 'fail2ban'] as $connection) {
            config([
                "database.connections.{$connection}.username" => "mxcentral_{$connection}",
                "database.connections.{$connection}.password" => "secret-{$connection}",
            ]);
        }

        $this->assertSame([], (new ProductionSafetyCheck)->errors());
    }

    public function test_unsafe_debug_http_and_shared_database_profile_fails_closed(): void
    {
        $this->app->instance('env', 'production');
        config([
            'app.debug' => true,
            'app.url' => 'http://mail.example.com',
            'app.key' => '',
            'session.secure' => false,
        ]);
        foreach (['vmail', 'iredadmin', 'amavisd', 'iredapd', 'fail2ban'] as $connection) {
            config([
                "database.connections.{$connection}.username" => 'shared',
                "database.connections.{$connection}.password" => 'secret',
            ]);
        }

        $errors = (new ProductionSafetyCheck)->errors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('APP_DEBUG', implode(' ', $errors));
        $this->assertStringContainsString('separate database username', implode(' ', $errors));
    }
}
