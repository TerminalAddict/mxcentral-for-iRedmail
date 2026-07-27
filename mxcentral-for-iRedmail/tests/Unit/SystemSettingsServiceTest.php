<?php

namespace Tests\Unit;

use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\SystemSettingsService;
use ReflectionClass;
use Tests\TestCase;

final class SystemSettingsServiceTest extends TestCase
{
    public function test_it_accepts_non_octet_boundary_ipv4_cidr_networks(): void
    {
        $this->assertSame(
            ['103.123.164.0/22'],
            $this->invokePrivate('normalizeNetworks', ['103.123.164.0/22'])
        );
    }

    public function test_sender_access_pattern_matches_non_octet_boundary_ipv4_cidr(): void
    {
        $block = $this->invokePrivate('senderAccessBlock', [[], ['103.123.164.0/22']]);

        $this->assertMatchesRegularExpression('/103/', $block);
        $this->assertTrue($this->senderAccessBlockMatches($block, '103.123.164.0'));
        $this->assertTrue($this->senderAccessBlockMatches($block, '103.123.165.42'));
        $this->assertTrue($this->senderAccessBlockMatches($block, '103.123.167.255'));
        $this->assertFalse($this->senderAccessBlockMatches($block, '103.123.163.255'));
        $this->assertFalse($this->senderAccessBlockMatches($block, '103.123.168.0'));
    }

    public function test_discard_recipient_hook_preserves_existing_restrictions_and_is_idempotent(): void
    {
        config(['iredmail.postfix_discard_recipients_path' => '/etc/postfix/discard_recipients']);
        $original = "smtpd_recipient_restrictions = permit_mynetworks,\n    permit_sasl_authenticated,\n    reject_unauth_destination\n";

        $updated = $this->invokePrivate('addPostfixRecipientAccessHook', [$original]);

        $this->assertStringContainsString('check_recipient_access hash:/etc/postfix/discard_recipients', $updated);
        $this->assertStringContainsString('permit_mynetworks', $updated);
        $this->assertStringContainsString('permit_sasl_authenticated', $updated);
        $this->assertStringContainsString('reject_unauth_destination', $updated);
        $this->assertSame($updated, $this->invokePrivate('addPostfixRecipientAccessHook', [$updated]));
    }

    public function test_commented_discard_hook_does_not_prevent_active_hook_installation(): void
    {
        config(['iredmail.postfix_discard_recipients_path' => '/etc/postfix/discard_recipients']);
        $original = "# check_recipient_access hash:/etc/postfix/discard_recipients\nsmtpd_recipient_restrictions = reject_unauth_destination\n";

        $updated = $this->invokePrivate('addPostfixRecipientAccessHook', [$original]);

        $this->assertSame(2, substr_count($updated, 'check_recipient_access hash:/etc/postfix/discard_recipients'));
        $this->assertStringContainsString(
            'smtpd_recipient_restrictions = check_recipient_access hash:/etc/postfix/discard_recipients, reject_unauth_destination',
            $updated,
        );
    }

    public function test_discard_hook_is_kept_after_the_staging_hook(): void
    {
        config([
            'iredmail.postfix_discard_recipients_path' => '/etc/postfix/discard_recipients',
            'iredmail.postfix_staging_domains_path' => '/etc/postfix/mxcentral_staging_domains.pcre',
        ]);
        $original = 'smtpd_recipient_restrictions = check_recipient_access hash:/etc/postfix/discard_recipients, check_recipient_access pcre:/etc/postfix/mxcentral_staging_domains.pcre, reject_unauth_destination'."\n";

        $updated = $this->invokePrivate('addPostfixRecipientAccessHook', [$original]);

        $this->assertLessThan(
            strpos($updated, 'check_recipient_access hash:/etc/postfix/discard_recipients'),
            strpos($updated, 'check_recipient_access pcre:/etc/postfix/mxcentral_staging_domains.pcre'),
        );
    }

    public function test_postfix_commands_use_narrow_sudo_defaults_when_environment_values_are_blank(): void
    {
        config([
            'iredmail.postfix_postmap_command' => '',
            'iredmail.postfix_reload_command' => '',
        ]);

        $this->assertSame('/usr/bin/sudo /usr/sbin/postmap', $this->invokePrivate('postmapCommand', []));
        $this->assertSame('/usr/bin/sudo /usr/bin/systemctl reload postfix.service', $this->invokePrivate('postfixReloadCommand', []));
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivate(string $method, array $arguments): mixed
    {
        $service = new SystemSettingsService(new AuditLogger());
        $reflectionMethod = (new ReflectionClass($service))->getMethod($method);

        return $reflectionMethod->invokeArgs($service, $arguments);
    }

    private function senderAccessBlockMatches(string $block, string $ip): bool
    {
        foreach (explode("\n", $block) as $line) {
            if (! str_ends_with($line, ' OK')) {
                continue;
            }

            $pattern = substr($line, 0, -3);
            if (@preg_match($pattern, $ip) === 1) {
                return true;
            }
        }

        return false;
    }
}
