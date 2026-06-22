@extends('layouts.app')

@section('content')
<h1>Whitelisting / Blacklisting</h1>
<div class="panel">
    <h2>Add Entry</h2>
    <form method="post" action="{{ route('wblist.add') }}" class="form-grid">@csrf
        <label>Recipient scope<input name="recipient" value="@." required></label>
        <label>Sender / domain / IP<input name="sender" required></label>
        <label>Type<select name="wb"><option value="W">Whitelist</option><option value="B">Blacklist</option></select></label>
        <button>Save</button>
    </form>
</div>
<table>
    <thead><tr><th>Recipient ID</th><th>Sender</th><th>Type</th><th>Priority</th></tr></thead>
    <tbody>@foreach($rows as $row)<tr><td>{{ $row->rid }}</td><td>{{ $row->sender }}</td><td>{{ $row->wb === 'B' ? 'Blacklist' : 'Whitelist' }}</td><td>{{ $row->priority ?? '' }}</td></tr>@endforeach</tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
