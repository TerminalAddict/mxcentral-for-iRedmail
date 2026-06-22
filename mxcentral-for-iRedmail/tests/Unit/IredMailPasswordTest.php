<?php

namespace Tests\Unit;

use App\Support\IredMailPassword;
use PHPUnit\Framework\TestCase;

final class IredMailPasswordTest extends TestCase
{
    public function test_plain_hashes_verify(): void
    {
        $this->assertTrue(IredMailPassword::verify('secret', '{PLAIN}secret'));
        $this->assertFalse(IredMailPassword::verify('wrong', '{PLAIN}secret'));
    }

    public function test_ssha_hashes_verify(): void
    {
        $hash = IredMailPassword::hash('correct horse battery staple');

        $this->assertStringStartsWith('{SSHA}', $hash);
        $this->assertTrue(IredMailPassword::verify('correct horse battery staple', $hash));
        $this->assertFalse(IredMailPassword::verify('incorrect', $hash));
    }

    public function test_sha_hashes_verify(): void
    {
        $hash = '{SHA}'.base64_encode(sha1('secret', true));

        $this->assertTrue(IredMailPassword::verify('secret', $hash));
        $this->assertFalse(IredMailPassword::verify('wrong', $hash));
    }

    public function test_ssha512_hashes_verify(): void
    {
        $salt = 'salty';
        $hash = '{SSHA512}'.base64_encode(hash('sha512', 'secret'.$salt, true).$salt);

        $this->assertTrue(IredMailPassword::verify('secret', $hash));
        $this->assertFalse(IredMailPassword::verify('wrong', $hash));
    }
}
