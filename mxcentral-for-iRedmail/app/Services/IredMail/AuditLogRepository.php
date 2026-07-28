<?php

namespace App\Services\IredMail;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class AuditLogRepository
{
    public const EVENTS = ['check', 'create', 'delete', 'update', 'view'];

    public function entries(CurrentActor $actor, ?string $search = null, ?string $event = null): LengthAwarePaginator
    {
        abort_unless($actor->globalAdmin, 403);

        $search = mb_substr(trim((string) $search), 0, 200);
        $event = strtolower(trim((string) $event));
        $query = DB::connection('iredadmin')->table('log')
            ->select('id', 'timestamp', 'admin', 'ip', 'domain', 'username', 'event', 'loglevel', 'msg');

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($query) use ($like): void {
                $query->where('admin', 'like', $like)
                    ->orWhere('ip', 'like', $like)
                    ->orWhere('domain', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('event', 'like', $like)
                    ->orWhere('msg', 'like', $like);
            });
        }

        if (in_array($event, self::EVENTS, true)) {
            $query->where('event', $event);
        }

        return $query
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->paginate((int) config('iredmail.page_size', 50))
            ->withQueryString();
    }
}
