@extends('layouts.app')

@section('content')
<div class="page-titlebar">
    <h1>Throttling</h1>
    <form class="search-compact" method="get" action="{{ route('throttle') }}">
        <input name="account" value="{{ $currentAccount ?? request('account') }}" placeholder="@., @domain, or user@domain">
        <button>Filter</button>
    </form>
</div>

<div class="panel">
    <h2>Save Throttle</h2>
    <form method="post" action="{{ route('throttle.save') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <label class="span-2">Account
                <input name="account" value="{{ $currentAccount ?: '@.' }}" required>
                <span class="field-hint">@. applies globally. @example.com applies to one domain. user@example.com applies to one mailbox.</span>
            </label>
            <label>Direction
                <select name="kind">
                    <option value="outbound">Outbound</option>
                    <option value="inbound">Inbound</option>
                </select>
                <span class="field-hint">Outbound limits sent mail; inbound limits received mail.</span>
            </label>
            <label>Period seconds
                <input name="period" type="number" min="0" value="3600">
                <span class="field-hint">Time window used when counting messages, quota, and recipients.</span>
            </label>
            <label>Max messages
                <input name="max_msgs" type="number" value="-1">
                <span class="field-hint">Maximum message count in the period. Use -1 for no explicit account-specific limit.</span>
            </label>
            <label>Max single size
                <input name="msg_size" type="number" value="-1">
                <span class="field-hint">Maximum size for one message. Use -1 when this row should not set a size cap.</span>
            </label>
            <label>Max cumulative size
                <input name="max_quota" type="number" value="-1">
                <span class="field-hint">Maximum total message size across the period.</span>
            </label>
            <label>Max recipients
                <input name="max_rcpts" type="number" value="-1">
                <span class="field-hint">Maximum recipient count across the period.</span>
            </label>
        </div>
        <div class="record-form__footer">
            <button>Save throttle</button>
        </div>
    </form>
</div>

<table class="summary-table">
    <thead><tr><th>Account</th><th>Direction</th><th>Period</th><th>Messages</th><th>Message size</th><th>Cumulative size</th><th>Recipients</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td>{{ $row->account }}</td>
            <td>{{ ucfirst($row->kind) }}</td>
            <td>{{ $row->period }}</td>
            <td>{{ $row->max_msgs }}</td>
            <td>{{ $row->msg_size }}</td>
            <td>{{ $row->max_quota }}</td>
            <td>{{ $row->max_rcpts ?? '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
