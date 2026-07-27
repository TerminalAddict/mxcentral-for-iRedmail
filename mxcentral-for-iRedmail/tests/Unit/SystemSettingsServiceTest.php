<?php

namespace Tests\Unit;

use App\Services\IredMail\AuditLogger;
use App\Services\IredMail\SystemSettingsService;
use ReflectionClass;
use Tests\Fakes\FakePrivilegedHelper;
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

    public function test_privileged_operations_use_one_fixed_helper_command(): void
    {
        $this->assertSame(
            '/usr/bin/sudo /usr/local/sbin/mxcentral-privileged',
            config('iredmail.privileged_helper_command'),
        );
    }

    public function test_sogo_login_colors_are_inserted_after_first_script_and_before_main_content_comment(): void
    {
        $template = <<<'WOX'
<html>
  <script type="text/javascript">
    var language = 'en';
  </script>

  <!--
      MAIN CONTENT ROW
  -->
  <img class="md-margin" src="default.svg">
</html>
WOX;

        $updated = $this->invokePrivate('replaceSogoLoginColors', [$template, '#175f55', '#ffffff']);

        $this->assertLessThan(strpos($updated, '.md-default-theme.md-accent.md-bg'), strpos($updated, '</script>'));
        $this->assertLessThan(strpos($updated, 'MAIN CONTENT ROW'), strpos($updated, '#login *'));
        $this->assertStringContainsString('background-color: #175f55 !important;', $updated);
        $this->assertStringContainsString('color: #ffffff !important;', $updated);
    }

    public function test_sogo_login_color_block_is_updated_without_duplication(): void
    {
        $template = <<<'WOX'
<html>
  <script></script>
  <!-- MAIN CONTENT ROW -->
</html>
WOX;
        $first = $this->invokePrivate('replaceSogoLoginColors', [$template, '#175f55', '#ffffff']);
        $updated = $this->invokePrivate('replaceSogoLoginColors', [$first, '#123456', '#abcdef']);
        $colors = $this->invokePrivate('sogoLoginColorsFromContent', [$updated]);

        $this->assertSame(['background' => '#123456', 'foreground' => '#abcdef'], $colors);
        $this->assertSame(1, substr_count($updated, '.md-default-theme.md-accent.md-bg'));
        $this->assertSame(1, substr_count($updated, '#login *'));
    }

    public function test_sogo_login_colors_replace_an_existing_unmanaged_style_block(): void
    {
        $template = <<<'WOX'
<html>
  <script></script>
  <style type="text/css">
  .md-default-theme.md-accent.md-bg { background-color: #000000 !important; }
  #login * { color: #111111 !important; }
  </style>
  <!-- MAIN CONTENT ROW -->
</html>
WOX;

        $updated = $this->invokePrivate('replaceSogoLoginColors', [$template, '#175f55', '#ffffff']);

        $this->assertStringNotContainsString('#000000', $updated);
        $this->assertStringNotContainsString('#111111', $updated);
        $this->assertSame(1, substr_count($updated, '.md-default-theme.md-accent.md-bg'));
    }

    public function test_sogo_logo_replacement_treats_regex_backreferences_and_xml_characters_literally(): void
    {
        $template = '<root><img class="md-margin" src="old.svg"/></root>';
        $url = 'https://example.com/$0-$1-logo?x=one&ampersand=✓';

        $updated = $this->invokePrivate('replaceSogoLogoUrl', [$template, $url]);

        $this->assertStringContainsString(
            'src="https://example.com/$0-$1-logo?x=one&amp;ampersand=✓"',
            $updated,
        );
        $this->assertStringNotContainsString('src="https://example.com/src=', $updated);
        $this->assertNotFalse(simplexml_load_string($updated));
    }

    public function test_sogo_logo_replacement_handles_long_urls_without_substitution(): void
    {
        $template = "<root><img rsrc:src='old.svg'/></root>";
        $url = 'https://example.com/'.str_repeat('a', 4000).'$0';

        $updated = $this->invokePrivate('replaceSogoLogoUrl', [$template, $url]);

        $this->assertStringContainsString(htmlspecialchars($url, ENT_QUOTES | ENT_HTML5), $updated);
    }

    public function test_managed_sender_blocks_treat_dollar_sequences_as_literal_data(): void
    {
        $original = "# BEGIN iredadmin-php managed: login mismatch senders\nALLOWED_LOGIN_MISMATCH_SENDERS = ['old@example.com']\n# END iredadmin-php managed: login mismatch senders\n";

        $updated = $this->invokePrivate('replaceManagedBlock', [$original, ['sender$0@example.com', 'sender$1@example.com']]);

        $this->assertStringContainsString("'sender$0@example.com'", $updated);
        $this->assertStringContainsString("'sender$1@example.com'", $updated);
        $this->assertStringNotContainsString('old@example.com', $updated);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivate(string $method, array $arguments): mixed
    {
        $service = new SystemSettingsService(new AuditLogger, new FakePrivilegedHelper);
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
