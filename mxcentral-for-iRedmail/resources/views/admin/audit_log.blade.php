@extends('layouts.app')

@section('content')
<div class="page-titlebar">
    <div>
        <h1>Audit Log</h1>
        <span class="field-hint">Global-administrator activity, including the recorded purpose for one-time password reveals.</span>
    </div>
    <form class="search-compact" method="get" action="{{ route('system.audit-log') }}">
        <input name="q" type="search" value="{{ request('q') }}" maxlength="200" placeholder="Admin, target, IP, or message">
        <select name="event">
            <option value="">All events</option>
            @foreach($events as $event)
                <option value="{{ $event }}" @selected(request('event') === $event)>{{ ucfirst($event) }}</option>
            @endforeach
        </select>
        <button class="secondary">Filter</button>
        @if(request()->filled('q') || request()->filled('event'))
            <a class="button secondary" href="{{ route('system.audit-log') }}">Clear</a>
        @endif
    </form>
</div>

<div class="panel">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Administrator</th>
                <th>IP</th>
                <th>Event</th>
                <th>Target</th>
                <th>Message</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->timestamp }}</td>
                    <td>{{ $row->admin }}</td>
                    <td>{{ $row->ip }}</td>
                    <td>{{ $row->event }}</td>
                    <td>
                        @if($row->username)
                            {{ $row->username }}
                        @elseif($row->domain)
                            {{ $row->domain }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $row->msg }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No audit entries matched this filter.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="pagination">{{ $rows->links() }}</div>
@endsection
