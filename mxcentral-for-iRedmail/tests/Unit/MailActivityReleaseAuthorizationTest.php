<?php

namespace Tests\Unit;

use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\MailActivityRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class MailActivityReleaseAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.amavisd' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('amavisd');
        $schema = DB::connection('amavisd')->getSchemaBuilder();
        $schema->create('msgs', function ($table): void {
            $table->string('mail_id')->primary();
            $table->string('secret_id');
            $table->integer('sid');
            $table->string('quar_type');
        });
        $schema->create('msgrcpt', function ($table): void {
            $table->string('mail_id');
            $table->integer('rid');
        });
        $schema->create('maddr', function ($table): void {
            $table->integer('id')->primary();
            $table->string('email');
            $table->string('domain');
        });
        DB::connection('amavisd')->table('maddr')->insert([
            ['id' => 1, 'email' => 'sender@outside.test', 'domain' => 'test.outside'],
            ['id' => 2, 'email' => 'user@example.com', 'domain' => 'com.example'],
        ]);
        for ($index = 1; $index <= 30; $index++) {
            DB::connection('amavisd')->table('msgs')->insert([
                'mail_id' => 'message-'.$index,
                'secret_id' => 'secret-'.$index,
                'sid' => 1,
                'quar_type' => 'Q',
            ]);
            DB::connection('amavisd')->table('msgrcpt')->insert(['mail_id' => 'message-'.$index, 'rid' => 2]);
        }
    }

    public function test_release_directly_authorizes_a_message_beyond_the_first_page_and_checks_secret(): void
    {
        config(['iredmail.page_size' => 10, 'iredmail.amavisd_quarantine_host' => '127.0.0.1', 'iredmail.amavisd_quarantine_port' => 1]);
        $actor = new CurrentActor('user@example.com', 'user', false, false, true, ['example.com']);
        $repository = new MailActivityRepository;

        $this->assertFalse($repository->release($actor, 'message-30', 'secret-30'));

        try {
            $repository->release($actor, 'message-30', 'wrong-secret');
            $this->fail('A quarantine release accepted the wrong secret.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
