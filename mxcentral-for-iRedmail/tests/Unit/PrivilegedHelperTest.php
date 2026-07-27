<?php

namespace Tests\Unit;

use App\Services\IredMail\PrivilegedHelper;
use Tests\TestCase;

final class PrivilegedHelperTest extends TestCase
{
    public function test_empty_parameters_are_serialized_as_a_json_object(): void
    {
        config()->set(
            'iredmail.privileged_helper_command',
            PHP_BINARY.' '.base_path('tests/Fixtures/privileged-helper-request.php'),
        );

        $result = (new PrivilegedHelper)->run('health_check');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('stdClass', $result['data']['parameters_type']);
    }
}
