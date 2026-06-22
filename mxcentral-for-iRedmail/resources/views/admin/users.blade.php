@extends('layouts.app')

@section('content')
@php
    $serviceLabels = [
        'smtp' => 'SMTP',
        'pop3' => 'POP3',
        'imap' => 'IMAP',
        'managesieve' => 'ManageSieve',
        'sieve' => 'Sieve',
        'deliver' => 'Deliver',
    ];
@endphp

<div class="page-titlebar">
    <h1>{{ session('actor.self_service') ? 'Preferences' : 'Users' }}</h1>
    @unless(session('actor.self_service'))
        <form class="search-compact">
            <input name="q" value="{{ request('q') }}" placeholder="Search users">
            <input name="domain" value="{{ request('domain') }}" placeholder="Domain">
            <button class="secondary">Search</button>
        </form>
    @endunless
</div>

@unless(session('actor.self_service'))
<div class="panel">
    <h2>Create User</h2>
    <form method="post" action="{{ route('users.create') }}" class="user-form">@csrf
        <div class="user-form__grid">
            <label>Username<input name="local_part" required placeholder="firstname.lastname"><span class="field-hint">Mailbox name before the @ sign.</span></label>
            <label>Domain
                <select name="domain" required>
                    @foreach($domainOptions as $domain)
                        <option value="{{ $domain->domain }}" @selected(request('domain') === $domain->domain)>{{ $domain->domain }}</option>
                    @endforeach
                </select>
                <span class="field-hint">Choose one of the hosted domains.</span>
            </label>
            <label>Name<input name="name"><span class="field-hint">Display name shown in admin views.</span></label>
            <label>Password<input name="password" type="password" required><span class="field-hint">Minimum 8 characters.</span></label>
            <label>Quota MB<input name="quota" type="number" min="0" value="0"><span class="field-hint">Mailbox storage quota. 0 = unlimited.</span></label>
        </div>
        <div class="domain-form__footer">
            <span class="field-hint">The user is created active with default mail services enabled.</span>
            <button>Create user</button>
        </div>
    </form>
</div>
@endunless

<div class="panel">
    <h2>{{ session('actor.self_service') ? 'Edit Preferences' : 'Edit User' }}</h2>
    @unless(session('actor.self_service'))
        <form method="get" action="{{ route('users') }}" class="user-selector-row">
            <label>Select mailbox
                <select name="edit" onchange="this.form.submit()">
                    @foreach($userOptions as $option)
                        <option value="{{ $option->username }}" @selected(($selectedUser->username ?? '') === $option->username)>{{ $option->username }}</option>
                    @endforeach
                </select>
                <span class="field-hint">Choose one mailbox to edit. The table below is only a summary.</span>
            </label>
            <button class="secondary">Load</button>
        </form>
    @endunless

    @if($selectedUser)
        <form method="post" action="{{ route('users.update', $selectedUser->username) }}" class="user-form">@csrf @method('patch')
            <div class="user-form__grid">
                <label class="span-2">Mailbox<input value="{{ $selectedUser->username }}" disabled><span class="field-hint">Mailbox address cannot be renamed from this screen.</span></label>
                <label>Name<input name="name" value="{{ $selectedUser->name ?? '' }}"><span class="field-hint">Display name.</span></label>
                <label>Quota MB<input name="quota" type="number" min="0" value="{{ $selectedUser->quota ?? 0 }}"><span class="field-hint">0 = unlimited mailbox quota.</span></label>
                <label class="span-2">New password<input name="password" type="password" placeholder="Leave blank"><span class="field-hint">Leave blank to keep the current password.</span></label>
                @unless(session('actor.self_service'))
                    <label class="checkbox-field">
                        <input type="hidden" name="active" value="0"><input name="active" type="checkbox" value="1" @checked($selectedUser->active ?? false)>
                        <span class="checkbox-field__body"><span class="checkbox-field__label">Active</span><span class="field-hint">Disable to stop this mailbox being treated as live.</span></span>
                    </label>
                @else
                    <input type="hidden" name="active" value="{{ $selectedUser->active ?? 1 }}">
                @endunless
            </div>
            <div class="user-actions-row">
                <span class="field-hint">Save profile changes before changing service controls.</span>
                <button class="secondary">Save profile</button>
            </div>
        </form>

        <form method="post" action="{{ route('users.forwarding', $selectedUser->username) }}" class="user-form user-forwarding-form">@csrf
            <h2>Mail Forwarding</h2>
            <div class="user-form__grid">
                <label class="span-4">Forward to
                    <textarea name="forwarding_destinations" rows="4" placeholder="forward@example.com&#10;other@example.net">{{ str_replace(', ', "\n", (string) ($selectedUser->forwarding_destinations ?? '')) }}</textarea>
                    <span class="field-hint">One destination per line, comma, space, or semicolon. These are written to <span class="mono">vmail.forwardings</span> with <span class="mono">is_forwarding=1</span>.</span>
                </label>
            </div>
            <div class="user-actions-row">
                <label class="checkbox-field">
                    <input name="keep_local_copy" type="checkbox" value="1" @checked((int) ($selectedUser->keep_local_copy ?? 0) > 0)>
                    <span class="checkbox-field__body"><span class="checkbox-field__label">Keep local mailbox copy</span><span class="field-hint">Keeps the default forwarding row from {{ $selectedUser->username }} to itself so mail still lands in this mailbox.</span></span>
                </label>
                <button class="secondary">Save forwarding</button>
            </div>
        </form>

        @unless(session('actor.self_service'))
            <form method="post" action="{{ route('users.services', $selectedUser->username) }}" class="user-form" style="margin-top:18px">@csrf
                <h2>Service Control</h2>
                <div class="user-service-grid">
                    @foreach($serviceLabels as $svc => $label)
                        <label class="checkbox-field">
                            <input type="checkbox" name="services[]" value="{{ $svc }}" @checked(($selectedUser->{'enable'.$svc} ?? 0))>
                            <span class="checkbox-field__body"><span class="checkbox-field__label">{{ $label }}</span><span class="field-hint">
                                @switch($svc)
                                    @case('smtp') Send mail @break
                                    @case('pop3') POP mailbox access @break
                                    @case('imap') IMAP mailbox access @break
                                    @case('managesieve') Manage filters @break
                                    @case('sieve') Server-side filters @break
                                    @case('deliver') Local delivery @break
                                @endswitch
                            </span></span>
                        </label>
                    @endforeach
                </div>
                <div class="user-actions-row">
                    <span class="field-hint">Service toggles affect which mail protocols/features this user can use.</span>
                    <button class="secondary">Save services</button>
                </div>
            </form>

            <form method="post" action="{{ route('users.delete', $selectedUser->username) }}" onsubmit="return confirm('Delete this user and log mailbox path?')" class="user-danger-row">@csrf @method('delete')
                <label>Keep days<input name="keep_days" type="number" min="0" value="0"><span class="field-hint">Sets the logged scheduled mailbox deletion date. 0 = no scheduled date.</span></label>
                <button class="danger">Delete user</button>
            </form>
        @endunless
    @else
        <p class="muted" style="margin-top:14px">No mailbox is available to edit.</p>
    @endif
</div>

<table class="user-summary-table">
    <thead><tr><th>Email</th><th>Name</th><th>Quota</th><th>Services</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        @php($enabled = collect(array_keys($serviceLabels))->filter(fn($s) => ($row->{'enable'.$s} ?? 0))->map(fn($s) => $serviceLabels[$s]))
        <tr>
            <td><a href="{{ route('users', ['edit' => $row->username]) }}">{{ $row->username }}</a><div class="muted">{{ $row->domain }}</div></td>
            <td>{{ $row->name ?? '' }}</td>
            <td>{{ $row->quota ?? 0 }} MB</td>
            <td>{{ $enabled->join(', ') }}</td>
            <td><span class="{{ ($row->active ?? 0) ? 'ok' : 'bad' }}">{{ ($row->active ?? 0) ? 'Active' : 'Disabled' }}</span></td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
