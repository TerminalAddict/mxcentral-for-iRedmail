@extends('layouts.app')

@section('content')
<div class="page-titlebar">
    <h1>{{ ucfirst($direction) }} Mail</h1>
    <form class="search-compact" method="get" action="{{ route('mail.logs', $direction) }}">
        <a class="button secondary" href="{{ route('mail.logs', 'received') }}">Received</a>
        <a class="button secondary" href="{{ route('mail.logs', 'sent') }}">Sent</a>
        <input name="account" value="{{ request('account') }}" placeholder="Domain or email">
        <button class="secondary">Filter</button>
    </form>
</div>
<table>
    <thead><tr><th>Date</th><th>Sender</th><th>Recipient</th><th>Subject</th><th>Score</th><th>Size</th></tr></thead>
    <tbody>@foreach($rows as $row)<tr><td>{{ $row->time_num ? date('Y-m-d H:i:s', $row->time_num) : '' }}</td><td>{{ $row->sender_email }}</td><td>{{ $row->recipient }}</td><td>{{ $row->subject }}</td><td>{{ $row->spam_level }}</td><td>{{ $row->size }}</td></tr>@endforeach</tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
