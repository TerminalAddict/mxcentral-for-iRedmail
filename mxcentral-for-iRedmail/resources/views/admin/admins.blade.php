@extends('layouts.app')

@section('content')
<div class="page-titlebar">
    <h1>Domain Admins</h1>
    <a class="button secondary" href="{{ route('export.admins') }}">Export statistics</a>
</div>

<div class="panel">
    <h2>Assign Admin</h2>
    <form method="post" action="{{ route('admins.assign') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <label class="span-2">Admin mailbox
                <input name="username" type="email" required>
                <span class="field-hint">Use an existing mailbox to promote it, or enter a new separate admin login.</span>
            </label>
            <label>Name
                <input name="name">
                <span class="field-hint">Used only when creating a separate admin login.</span>
            </label>
            <label>Domain or ALL
                <input name="domain" required>
                <span class="field-hint">Enter a hosted domain for domain-level access, or ALL for global admin access.</span>
            </label>
            <label class="span-2">Password for new separate admin
                <input name="password" type="password" placeholder="Only needed if mailbox/admin does not exist">
                <span class="field-hint">Leave blank when assigning an existing mailbox admin. Required for a new separate admin record.</span>
            </label>
        </div>
        <div class="record-form__footer">
            <button>Assign admin</button>
        </div>
    </form>
</div>

<table class="summary-table admin-summary-table">
    <thead><tr><th>Admin</th><th>Managed domains</th><th>Remove assignment</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td>{{ $row->username }}</td>
            <td>{{ $row->domains }}</td>
            <td>
                @foreach(array_filter(array_map('trim', explode(',', $row->domains ?? ''))) as $domain)
                    <form method="post" action="{{ route('admins.delete', [$row->username, $domain]) }}" style="display:inline">@csrf @method('delete')
                        <button class="danger">Remove {{ $domain }}</button>
                    </form>
                @endforeach
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
