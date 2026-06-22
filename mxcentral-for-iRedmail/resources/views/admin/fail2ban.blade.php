@extends('layouts.app')

@section('content')
<h1>Fail2ban</h1>
<table>
    <thead><tr><th>IP</th><th>Jail</th><th>Country</th><th>Log lines</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td>{{ $row->ip ?? $row->ipaddress ?? '' }}</td>
            <td>{{ $row->jail ?? '' }}</td>
            <td>{{ $row->country ?? '' }} {{ $row->city ?? '' }}</td>
            <td><pre style="white-space:pre-wrap">{{ $row->loglines ?? $row->matches ?? '' }}</pre></td>
            <td>{{ ($row->remove ?? 0) ? 'Pending removal' : 'Banned' }}</td>
            <td>@if(!empty($row->ip))<form method="post" action="{{ route('fail2ban.unban', $row->ip) }}">@csrf<button class="danger">Unban</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
