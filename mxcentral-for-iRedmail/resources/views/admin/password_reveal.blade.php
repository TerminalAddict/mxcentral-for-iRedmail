@extends('layouts.app')

@section('content')
<div class="page-titlebar">
    <h1>One-time Password Reveal</h1>
    <a class="button secondary" href="{{ route('users', ['edit' => $reveal['email']]) }}">Return to user</a>
</div>

<div class="panel">
    <p class="alert bad">This value is shown once. It will not be available if this page is refreshed.</p>
    <label>Mailbox
        <input value="{{ $reveal['email'] }}" readonly>
    </label>
    <label>Password
        <input value="{{ $reveal['password'] }}" readonly autocomplete="off">
    </label>
    <p class="field-hint">Recorded purpose: {{ $reveal['purpose'] }}</p>
</div>
@endsection
