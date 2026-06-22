<?php

namespace App\Services\IredMail;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class QuarantineNotificationService
{
    public function notify(bool $forceAll = false, bool $dryRun = false): array
    {
        $state = $this->loadState();
        $alreadyNotified = array_fill_keys($state['mail_ids'] ?? [], true);
        $allMessages = $this->quarantinedMessages();
        $messages = $allMessages;

        if (! $forceAll) {
            $messages = $messages->reject(fn (object $message) => isset($alreadyNotified[$message->mail_id]));
        }

        $groups = $messages
            ->filter(fn (object $message) => filter_var($message->recipient, FILTER_VALIDATE_EMAIL))
            ->groupBy(fn (object $message) => strtolower((string) $message->recipient));

        $sent = 0;
        $failed = [];
        $notifiedIds = [];

        foreach ($groups as $recipient => $recipientMessages) {
            try {
                if (! $dryRun) {
                    Mail::html($this->htmlBody($recipient, $recipientMessages), function ($mail) use ($recipient, $recipientMessages): void {
                        $mail->to($recipient)
                            ->subject($this->subject($recipientMessages->count()));
                    });
                }

                $sent++;
                array_push($notifiedIds, ...$recipientMessages->pluck('mail_id')->all());
            } catch (\Throwable $exception) {
                report($exception);
                $failed[$recipient] = $exception->getMessage();
            }
        }

        if (! $dryRun && $notifiedIds !== []) {
            $currentIds = array_fill_keys($allMessages->pluck('mail_id')->all(), true);
            $state['mail_ids'] = array_values(array_filter(
                array_unique(array_merge($state['mail_ids'] ?? [], $notifiedIds)),
                fn (string $mailId) => isset($currentIds[$mailId])
            ));
            $state['last_run_at'] = now()->toIso8601String();
            $this->saveState($state);
        }

        return [
            'recipients' => $groups->count(),
            'sent' => $sent,
            'messages' => $messages->count(),
            'failed' => $failed,
            'dry_run' => $dryRun,
            'force_all' => $forceAll,
        ];
    }

    private function quarantinedMessages(): Collection
    {
        return DB::connection('amavisd')->table('msgs')
            ->leftJoin('msgrcpt', 'msgs.mail_id', '=', 'msgrcpt.mail_id')
            ->leftJoin('maddr as sender', 'msgs.sid', '=', 'sender.id')
            ->leftJoin('maddr as recip', 'msgrcpt.rid', '=', 'recip.id')
            ->select(
                'msgs.mail_id',
                'msgs.subject',
                'msgs.content',
                'msgs.spam_level',
                'msgs.size',
                'msgs.time_num',
                'sender.email as sender_email',
                'recip.email as recipient',
            )
            ->where('msgs.quar_type', 'Q')
            ->orderByDesc('msgs.time_num')
            ->get();
    }

    private function htmlBody(string $recipient, Collection $messages): string
    {
        $selfServiceUrl = route('quarantine', absolute: true);
        $maxRows = max(1, (int) config('iredmail.quarantine_notification_max_rows', 50));
        $shown = $messages->take($maxRows);
        $hidden = max(0, $messages->count() - $shown->count());

        $rows = $shown->map(function (object $message): string {
            return '<tr>'
                .'<td style="padding:6px;border-bottom:1px solid #ddd;">'.$this->e($this->arrivedAt($message)).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #ddd;">'.$this->e((string) ($message->sender_email ?? 'unknown')).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #ddd;">'.$this->e((string) ($message->subject ?? '(no subject)')).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #ddd;">'.$this->e($this->contentLabel((string) $message->content)).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #ddd;text-align:right;">'.$this->e((string) ($message->spam_level ?? '')).'</td>'
                .'</tr>';
        })->implode('');

        $more = $hidden > 0 ? '<p>There are '.$hidden.' additional quarantined message(s) not shown in this email.</p>' : '';

        return '<!doctype html><html><body>'
            .'<p>Hello '.$this->e($recipient).',</p>'
            .'<p>You have '.$messages->count().' quarantined email(s) that were not delivered to your mailbox.</p>'
            .'<p><a href="'.$this->e($selfServiceUrl).'">Open MXCentral quarantine</a> to read the raw message, release safe mail to your mailbox, or delete unwanted mail.</p>'
            .'<table style="border-collapse:collapse;width:100%;max-width:900px;">'
            .'<thead><tr>'
            .'<th align="left" style="padding:6px;border-bottom:2px solid #ccc;">Arrived</th>'
            .'<th align="left" style="padding:6px;border-bottom:2px solid #ccc;">Sender</th>'
            .'<th align="left" style="padding:6px;border-bottom:2px solid #ccc;">Subject</th>'
            .'<th align="left" style="padding:6px;border-bottom:2px solid #ccc;">Type</th>'
            .'<th align="right" style="padding:6px;border-bottom:2px solid #ccc;">Score</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .$more
            .'<p>This is an automated quarantine notification from '.e((string) config('app.name')).'.</p>'
            .'</body></html>';
    }

    private function subject(int $total): string
    {
        $template = (string) config('iredmail.quarantine_notification_subject');

        return str_replace('%(total)d', (string) $total, $template);
    }

    private function arrivedAt(object $message): string
    {
        $timestamp = (int) ($message->time_num ?? 0);

        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    private function contentLabel(string $content): string
    {
        return match ($content) {
            'S', 's', 'Y' => 'Spam',
            'V' => 'Virus',
            'B' => 'Banned',
            'H' => 'Bad header',
            'M' => 'Bad MIME',
            default => $content,
        };
    }

    private function loadState(): array
    {
        $path = $this->statePath();
        if (! is_readable($path)) {
            return ['mail_ids' => []];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded + ['mail_ids' => []] : ['mail_ids' => []];
    }

    private function saveState(array $state): void
    {
        $path = $this->statePath();
        $directory = dirname($path);
        if (! is_dir($directory) && @mkdir($directory, 0755, true) === false) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }

        $state['mail_ids'] = array_values(array_filter(
            $state['mail_ids'] ?? [],
            fn ($mailId) => is_string($mailId) && $mailId !== ''
        ));

        if (@file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write {$path}.");
        }
    }

    private function statePath(): string
    {
        $configured = trim((string) config('iredmail.quarantine_notification_state_path'));

        return $configured !== '' ? $configured : storage_path('app/quarantine-notifications.json');
    }

    private function e(string $value): string
    {
        return e(Str::limit($value, 300, '...'));
    }
}
