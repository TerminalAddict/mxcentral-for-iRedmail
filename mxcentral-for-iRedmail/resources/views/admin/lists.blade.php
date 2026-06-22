@extends('layouts.app')

@section('content')
@php($policies = ['public', 'domain', 'subdomain', 'membersonly', 'moderatorsonly', 'membersandmoderatorsonly'])

<div class="page-titlebar">
    <h1>Mailing Lists</h1>
    <form class="search-compact" method="get" action="{{ route('lists') }}">
        <input name="q" value="{{ request('q') }}" placeholder="Search lists">
        <input name="domain" value="{{ request('domain') }}" placeholder="Domain">
        <button>Search</button>
    </form>
</div>

<div class="panel">
    <h2>Create Mailing List</h2>
    <form method="post" action="{{ route('lists.create') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <label>List name
                <input name="local_part" required placeholder="announce">
                <span class="field-hint">Posting address before the @ sign.</span>
            </label>
            <label>Domain
                <select name="domain" required>
                    @foreach($domainOptions as $domain)
                        <option value="{{ $domain->domain }}" @selected(request('domain') === $domain->domain)>{{ $domain->domain }}</option>
                    @endforeach
                </select>
                <span class="field-hint">Choose one of the hosted domains. The full list address must not already exist as a mailbox, alias, or list.</span>
            </label>
            <label>Name
                <input name="name">
                <span class="field-hint">Optional display name for this list.</span>
            </label>
            <label>Access policy
                <select name="accesspolicy">
                    @foreach($policies as $policy)<option>{{ $policy }}</option>@endforeach
                </select>
                <span class="field-hint">Controls who can post to the list. Member-only policies are useful for private lists.</span>
            </label>
            <label>Max message size
                <input name="maxmsgsize" type="number" min="0" value="0">
                <span class="field-hint">Maximum accepted message size for list mail. Use 0 for no list-specific limit.</span>
            </label>
            <label class="span-3">Owners
                <textarea name="owners" required></textarea>
                <span class="field-hint">Owners can manage the list. At least one owner is required.</span>
            </label>
            <label class="span-4">Members
                <textarea name="members"></textarea>
                <span class="field-hint">Recipients subscribed to the list. Separate addresses with commas, spaces, or new lines.</span>
            </label>
            <label class="span-4">Description
                <textarea name="description"></textarea>
                <span class="field-hint">Internal notes or purpose for the list.</span>
            </label>
        </div>
        <div class="record-form__footer">
            <button>Create list</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>Edit Mailing List</h2>
    <form method="get" action="{{ route('lists') }}" class="record-selector-row">
        @if(request('q'))<input type="hidden" name="q" value="{{ request('q') }}">@endif
        @if(request('domain'))<input type="hidden" name="domain" value="{{ request('domain') }}">@endif
        <label>Mailing list
            <select name="edit">
                @foreach($listOptions as $option)
                    <option value="{{ $option->address }}" @selected(($selectedList->address ?? '') === $option->address)>{{ $option->address }}</option>
                @endforeach
            </select>
            <span class="field-hint">Pick a list to load its settings and membership.</span>
        </label>
        <button class="secondary">Load</button>
    </form>

    @if($selectedList)
        <form method="post" action="{{ route('lists.update', $selectedList->address) }}" class="record-form">@csrf @method('patch')
            <div class="record-form__grid">
                <label class="span-2">Name
                    <input name="name" value="{{ $selectedList->name ?? '' }}">
                    <span class="field-hint">Optional label shown in admin views.</span>
                </label>
                <label>Access policy
                    <select name="accesspolicy">
                        @foreach($policies as $policy)<option @selected(($selectedList->accesspolicy ?? '') === $policy)>{{ $policy }}</option>@endforeach
                    </select>
                    <span class="field-hint">Restricts who may send to {{ $selectedList->address }}.</span>
                </label>
                <label>Max message size
                    <input name="maxmsgsize" type="number" min="0" value="{{ $selectedList->maxmsgsize ?? 0 }}">
                    <span class="field-hint">Use 0 for no list-specific size limit.</span>
                </label>
                <label class="checkbox-field">
                    <input type="hidden" name="active" value="0">
                    <input name="active" type="checkbox" value="1" @checked($selectedList->active ?? false)>
                    <span class="checkbox-field__body">
                        <span class="checkbox-field__label">Active</span>
                        <span class="field-hint">Inactive lists stay configured but stop receiving mail.</span>
                    </span>
                </label>
                <label class="span-3">Owners
                    <textarea name="owners" required>{{ $selectedList->owners ?? '' }}</textarea>
                    <span class="field-hint">At least one owner is required for list management.</span>
                </label>
                <label class="span-4">Members
                    <textarea name="members">{{ $selectedList->members ?? '' }}</textarea>
                    <span class="field-hint">These addresses receive list messages.</span>
                </label>
                <label class="span-4">Description
                    <textarea name="description">{{ $selectedList->description ?? '' }}</textarea>
                    <span class="field-hint">Internal notes for administrators.</span>
                </label>
            </div>
            <div class="record-form__footer">
                <button class="secondary">Save list</button>
            </div>
        </form>
        <form method="post" action="{{ route('lists.delete', $selectedList->address) }}" class="record-danger-row record-danger-row--compact" onsubmit="return confirm('Delete this mailing list?')">@csrf @method('delete')
            <div>
                <strong>Delete {{ $selectedList->address }}</strong>
                <span class="field-hint">Deletes the list, owners, moderators, and forwarding records. It does not delete member mailboxes.</span>
            </div>
            <button class="danger">Delete list</button>
        </form>
    @else
        <p class="muted">Select a mailing list above to edit it.</p>
    @endif
</div>

<table class="summary-table">
    <thead><tr><th>Address</th><th>Domain</th><th>Policy</th><th>Owners</th><th>Members</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td><a href="{{ route('lists', array_filter(['edit' => $row->address, 'q' => request('q'), 'domain' => request('domain')])) }}">{{ $row->address }}</a></td>
            <td>{{ $row->domain }}</td>
            <td>{{ $row->accesspolicy ?? 'public' }}</td>
            <td>{{ $row->owners ?? '' }}</td>
            <td>{{ $row->members ?? '' }}</td>
            <td class="{{ ($row->active ?? false) ? 'ok' : 'bad' }}">{{ ($row->active ?? false) ? 'Active' : 'Disabled' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
