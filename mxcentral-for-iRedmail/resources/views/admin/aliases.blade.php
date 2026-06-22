@extends('layouts.app')

@section('content')
@php($policies = ['public', 'domain', 'subdomain', 'membersonly', 'moderatorsonly', 'membersandmoderatorsonly'])

<div class="page-titlebar">
    <h1>Aliases</h1>
    <form class="search-compact" method="get" action="{{ route('aliases') }}">
        <input name="q" value="{{ request('q') }}" placeholder="Search aliases">
        <input name="domain" value="{{ request('domain') }}" placeholder="Domain">
        <button>Search</button>
    </form>
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
    <form method="get" action="{{ route('aliases') }}" class="record-selector-row">
        @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
        @if(request('domain'))<input type="hidden" name="domain" value="{{ request('domain') }}">@endif
        <label>Alias
            <select name="edit">
                @foreach($aliasOptions as $option)
                    <option value="{{ $option->address }}" @selected(($selectedAlias->address ?? '') === $option->address)>{{ $option->address }}</option>
                @endforeach
            </select>
            <span class="field-hint">Pick an alias to load its editable settings.</span>
        </label>
        <button class="secondary">Load</button>
    </form>

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
        <p class="muted">Select an alias above to edit it.</p>
    @endif
</div>

<table class="summary-table">
    <thead><tr><th>Address</th><th>Domain</th><th>Policy</th><th>Members</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td><a href="{{ route('aliases', array_filter(['edit' => $row->address, 'q' => request('q'), 'domain' => request('domain')])) }}">{{ $row->address }}</a></td>
            <td>{{ $row->domain }}</td>
            <td>{{ $row->accesspolicy ?? 'public' }}</td>
            <td>{{ $row->members ?? '' }}</td>
            <td class="{{ ($row->active ?? false) ? 'ok' : 'bad' }}">{{ ($row->active ?? false) ? 'Active' : 'Disabled' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
