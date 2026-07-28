<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IredMail\AuditLogRepository;
use App\Services\IredMail\CurrentActor;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AuditLogController extends Controller
{
    public function index(Request $request, AuditLogRepository $audit, CurrentActor $actor): View
    {
        return view('admin.audit_log', [
            'rows' => $audit->entries(
                $actor,
                (string) $request->query('q', ''),
                (string) $request->query('event', ''),
            ),
            'events' => AuditLogRepository::EVENTS,
        ]);
    }
}
