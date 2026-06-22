@extends('layouts.app')

@section('content')
@php
    $types = [
        null => 'All',
        'spam' => 'Spam',
        'virus' => 'Virus',
        'banned' => 'Banned',
        'badheader' => 'Bad Header',
        'badmime' => 'Bad MIME',
    ];
    $typeDescriptions = [
        null => session('actor.self_service') ? 'All quarantined messages currently visible to your mailbox.' : 'All quarantined messages currently visible to your admin account.',
        'spam' => 'Messages held because SpamAssassin or Amavisd classified them as spam.',
        'virus' => 'Messages held because virus scanning detected malicious content.',
        'banned' => 'Messages held because attachment or content policy blocked them.',
        'badheader' => 'Messages held because malformed or suspicious headers were detected.',
        'badmime' => 'Messages held because the MIME structure or attachment encoding failed checks.',
    ];
@endphp

<div class="page-titlebar">
    <h1>Quarantine</h1>
    <form class="search-compact" method="get" action="{{ route('quarantine', array_filter(['type' => $type])) }}">
        <input name="account" value="{{ request('account') }}" placeholder="Domain or email">
        <button>Filter</button>
    </form>
</div>

<div class="filter-tabs">
    @foreach($types as $key => $label)
        <a class="button secondary {{ $type === $key ? 'is-active' : '' }}" href="{{ route('quarantine', array_filter(['type' => $key, 'account' => request('account')])) }}">{{ $label }}</a>
    @endforeach
</div>

<div class="panel">
    <h2>{{ $types[$type] ?? 'All' }} Messages</h2>
    <div class="record-form__grid">
        <div class="span-2">
            <strong>Current filter</strong>
            <span class="field-hint">{{ $typeDescriptions[$type] ?? $typeDescriptions[null] }}</span>
        </div>
        <div>
            <strong>Account filter</strong>
            <span class="field-hint">{{ session('actor.self_service') ? 'Self-service quarantine is limited to your own mailbox.' : 'Enter a hosted domain to see mail for that domain, or a full address to match sender or recipient.' }}</span>
        </div>
        <div>
            <strong>Release and delete</strong>
            <span class="field-hint">Release asks Amavisd to deliver the message. Delete removes selected quarantine records.</span>
        </div>
    </div>
</div>

<form id="quarantine-delete" method="post" action="{{ route('quarantine.delete') }}">@csrf @method('delete')</form>

<table class="summary-table quarantine-summary-table">
    <thead>
        <tr>
            <th></th>
            <th>Date</th>
            <th>Sender</th>
            <th>Recipient</th>
            <th>Subject</th>
            <th>Type</th>
            <th>Score</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td class="select-cell"><input form="quarantine-delete" type="checkbox" name="mail_ids[]" value="{{ $row->mail_id }}"></td>
            <td>{{ $row->time_num ? date('Y-m-d H:i:s', $row->time_num) : '' }}</td>
            <td>{{ $row->sender_email }}</td>
            <td>{{ $row->recipient }}</td>
            <td>{{ $row->subject }}</td>
            <td>{{ $row->content }}</td>
            <td>{{ $row->spam_level }}</td>
            <td>
                <div class="table-actions">
                    <a class="button secondary" href="{{ route('quarantine.raw', $row->mail_id) }}">Raw</a>
                    <form method="post" action="{{ route('quarantine.release', $row->mail_id) }}">@csrf
                        <input type="hidden" name="secret_id" value="{{ $row->secret_id }}">
                        <button class="secondary">Release</button>
                    </form>
                </div>
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="bulk-action-bar">
    <span class="field-hint">Select one or more rows, then delete them from quarantine.</span>
    <button form="quarantine-delete" class="danger" onclick="return confirm('Delete selected quarantined messages?')">Delete selected</button>
</div>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
