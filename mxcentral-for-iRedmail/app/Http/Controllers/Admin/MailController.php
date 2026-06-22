<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IredMail\CurrentActor;
use App\Services\IredMail\MailActivityRepository;
use Illuminate\Http\Request;

final class MailController extends Controller
{
    public function logs(Request $request, MailActivityRepository $mail, CurrentActor $actor, string $direction)
    {
        abort_unless(in_array($direction, ['sent', 'received'], true), 404);

        return view('admin.mail_logs', [
            'rows' => $mail->logs($actor, $direction, $request->query('account')),
            'direction' => $direction,
        ]);
    }

    public function quarantine(Request $request, MailActivityRepository $mail, CurrentActor $actor, ?string $type = null)
    {
        return view('admin.quarantine', [
            'rows' => $mail->quarantined($actor, $type, $request->query('account')),
            'type' => $type,
        ]);
    }

    public function raw(MailActivityRepository $mail, CurrentActor $actor, string $mailId)
    {
        return response($mail->rawMessage($actor, $mailId), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function deleteQuarantine(Request $request, MailActivityRepository $mail, CurrentActor $actor)
    {
        $deleted = $mail->deleteQuarantine($actor, $request->input('mail_ids', []));
        return back()->with('status', "Deleted {$deleted} quarantined message(s).");
    }

    public function release(Request $request, MailActivityRepository $mail, CurrentActor $actor, string $mailId)
    {
        $released = $mail->release($actor, $mailId, (string) $request->input('secret_id'));
        return back()->with('status', $released ? 'Release request accepted.' : 'Release request failed.');
    }
}
