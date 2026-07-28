@extends('layouts.app')

@section('content')
@php
    $policies = ['public', 'domain', 'subdomain', 'membersonly', 'moderatorsonly', 'membersandmoderatorsonly'];
    $sortableColumns = [
        'address' => 'Address',
        'domain' => 'Domain',
        'policy' => 'Policy',
        'members' => 'Members',
        'status' => 'Status',
    ];
    $currentSort = array_key_exists((string) request('sort'), $sortableColumns) ? request('sort') : 'address';
    $currentDirection = request('direction', 'asc') === 'desc' ? 'desc' : 'asc';
    $sortUrl = function (string $column) use ($currentSort, $currentDirection): string {
        $direction = $currentSort === $column && $currentDirection === 'asc' ? 'desc' : 'asc';

        return route('aliases', array_merge(
            request()->except(['page', 'sort', 'direction']),
            ['sort' => $column, 'direction' => $direction],
        ));
    };
    $sortIndicator = fn (string $column): string => $currentSort === $column
        ? ($currentDirection === 'asc' ? '▲' : '▼')
        : '';
@endphp

<div class="page-titlebar">
    <h1>Aliases</h1>
    <form class="search-compact alias-filter" method="get" action="{{ route('aliases') }}">
        <input type="hidden" name="sort" value="{{ $currentSort }}">
        <input type="hidden" name="direction" value="{{ $currentDirection }}">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Address, name, policy, or member">
        <select name="domain" aria-label="Filter by domain">
            <option value="">All domains</option>
            @foreach($domainOptions as $domain)
                <option value="{{ $domain->domain }}" @selected(request('domain') === $domain->domain)>{{ $domain->domain }}</option>
            @endforeach
        </select>
        <button>Filter</button>
        @if(request('q') || request('domain'))
            <a class="button secondary" href="{{ route('aliases', ['sort' => $currentSort, 'direction' => $currentDirection]) }}">Clear</a>
        @endif
    </form>
</div>

<div class="panel" id="catch-all">
    <h2>Catch-all Forwarding</h2>
    <p>
        Deliver mail sent to unknown or misspelled addresses at a hosted domain to one destination address.
        Existing mailboxes, aliases, and mailing lists continue to receive their own mail normally.
    </p>
    <p class="muted">The destination may be hosted on this server or at an external mail provider. Catch-all forwarding can attract additional spam.</p>

    @if($domainOptions->isNotEmpty())
        <form method="post" action="{{ route('aliases.catch-all.create') }}" class="record-form">@csrf
            <div class="record-form__grid">
                <label class="span-2">Catch all unknown email for domain
                    <select name="catch_all_domain" required>
                        @foreach($domainOptions as $domain)
                            <option value="{{ $domain->domain }}" @selected(old('catch_all_domain', request('domain')) === $domain->domain)>{{ $domain->domain }}</option>
                        @endforeach
                    </select>
                    <span class="field-hint">Only domains you are allowed to manage are shown.</span>
                </label>
                <label class="span-2">Forward unmatched mail to
                    <input name="forwarding" type="email" value="{{ old('forwarding') }}" required placeholder="paul@example.net">
                    <span class="field-hint">Enter a complete local or external email address.</span>
                </label>
            </div>
            <div class="record-form__footer">
                <button>Add catch-all forwarding</button>
            </div>
        </form>
    @else
        <p class="muted">You do not manage any hosted domains, so no catch-all forwarding can be added.</p>
    @endif

    <table class="summary-table">
        <thead><tr><th>Hosted domain</th><th>Unknown recipients forward to</th><th>Actions</th></tr></thead>
        <tbody>
            @forelse($catchAlls as $catchAll)
                <tr>
                    <td><span class="mono">{{ $catchAll->domain }}</span></td>
                    <td>{{ $catchAll->forwarding }}</td>
                    <td>
                        <form method="post" action="{{ route('aliases.catch-all.delete', $catchAll->domain) }}" onsubmit="return confirm('Remove this catch-all destination?')">@csrf @method('delete')
                            <input type="hidden" name="forwarding" value="{{ $catchAll->forwarding }}">
                            <button class="danger">Remove</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">No catch-all forwarding is configured for your domains.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="pagination">
        @if($catchAlls->total() > 0)
            <p class="muted">Showing {{ $catchAlls->firstItem() }}–{{ $catchAlls->lastItem() }} of {{ $catchAlls->total() }} catch-all destinations.</p>
        @endif
        {{ $catchAlls->withQueryString()->links() }}
    </div>
</div>

<div class="panel">
    <h2>Create Alias</h2>
    <form method="post" action="{{ route('aliases.create') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <label>Alias name
                <input name="local_part" required placeholder="sales">
                <span class="field-hint">Address before the @ sign.</span>
            </label>
            <label>Domain
                <select name="domain" required>
                    @foreach($domainOptions as $domain)
                        <option value="{{ $domain->domain }}" @selected(request('domain') === $domain->domain)>{{ $domain->domain }}</option>
                    @endforeach
                </select>
                <span class="field-hint">Choose one of the hosted domains.</span>
            </label>
            <label>Name
                <input name="name">
                <span class="field-hint">Optional display label for your own reference. The full alias must be unique across mailboxes, aliases, and mailing lists.</span>
            </label>
            <label>Access policy
                <select name="accesspolicy">
                    @foreach($policies as $policy)<option>{{ $policy }}</option>@endforeach
                </select>
                <span class="field-hint">Controls who can send to this alias. public accepts mail from anyone; member policies restrict senders.</span>
            </label>
            <label class="span-4">Members
                <textarea name="members" placeholder="one@example.com, two@example.net" required></textarea>
                <span class="field-hint">One or more delivery recipients. Separate addresses with commas, spaces, or new lines.</span>
            </label>
        </div>
        <div class="record-form__footer">
            <button>Create alias</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>Edit Alias</h2>
    @if($selectedAlias)
        <form method="post" action="{{ route('aliases.update', $selectedAlias->address) }}" class="record-form">@csrf @method('patch')
            <div class="record-form__grid">
                <label class="span-2">Name
                    <input name="name" value="{{ $selectedAlias->name ?? '' }}">
                    <span class="field-hint">Optional label shown in admin views.</span>
                </label>
                <label>Access policy
                    <select name="accesspolicy">
                        @foreach($policies as $policy)<option @selected(($selectedAlias->accesspolicy ?? '') === $policy)>{{ $policy }}</option>@endforeach
                    </select>
                    <span class="field-hint">Use member-only policies when only listed members or moderators should post.</span>
                </label>
                <label class="checkbox-field">
                    <input type="hidden" name="active" value="0">
                    <input name="active" type="checkbox" value="1" @checked($selectedAlias->active ?? false)>
                    <span class="checkbox-field__body">
                        <span class="checkbox-field__label">Active</span>
                        <span class="field-hint">Inactive aliases remain in the database but do not receive mail.</span>
                    </span>
                </label>
                <label class="span-4">Members
                    <textarea name="members" required>{{ $selectedAlias->members ?? '' }}</textarea>
                    <span class="field-hint">These recipients get copies of mail sent to {{ $selectedAlias->address }}.</span>
                </label>
            </div>
            <div class="record-form__footer">
                <button class="secondary">Save alias</button>
            </div>
        </form>
        <form method="post" action="{{ route('aliases.delete', $selectedAlias->address) }}" class="record-danger-row record-danger-row--compact" onsubmit="return confirm('Delete this alias?')">@csrf @method('delete')
            <div>
                <strong>Delete {{ $selectedAlias->address }}</strong>
                <span class="field-hint">Deletes the alias and its forwarding/member records. It does not delete recipient mailboxes.</span>
            </div>
            <button class="danger">Delete alias</button>
        </form>
    @else
        <p class="muted">Choose an alias from the paginated table below to edit it.</p>
    @endif
</div>

<table class="summary-table">
    <thead>
        <tr>
            @foreach($sortableColumns as $column => $label)
                <th @if($currentSort === $column) aria-sort="{{ $currentDirection === 'asc' ? 'ascending' : 'descending' }}" @endif>
                    <a class="sortable-heading" href="{{ $sortUrl($column) }}">
                        {{ $label }}
                        <span class="sortable-heading__indicator" aria-hidden="true">{{ $sortIndicator($column) }}</span>
                    </a>
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
    @forelse($rows as $row)
        <tr>
            <td><a href="{{ route('aliases', array_merge(request()->query(), ['edit' => $row->address])) }}">{{ $row->address }}</a></td>
            <td>{{ $row->domain }}</td>
            <td>{{ $row->accesspolicy ?? 'public' }}</td>
            <td>{{ $row->members ?? '' }}</td>
            <td class="{{ ($row->active ?? false) ? 'ok' : 'bad' }}">{{ ($row->active ?? false) ? 'Active' : 'Disabled' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No aliases match the current filter.</td></tr>
    @endforelse
    </tbody>
</table>
<div class="pagination">
    @if($rows->total() > 0)
        <p class="muted">Showing {{ $rows->firstItem() }}–{{ $rows->lastItem() }} of {{ $rows->total() }} aliases.</p>
    @endif
    {{ $rows->withQueryString()->links() }}
</div>
@endsection
