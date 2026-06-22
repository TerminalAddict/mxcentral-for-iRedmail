@extends('layouts.app')

@section('content')
@php
    $mailIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5v-9Zm2.2.1v.3L12 12.1l5.8-4.2v-.3H6.2Zm11.6 1.8-5.3 3.8a.9.9 0 0 1-1 0L6.2 9.4v7.1c0 .2.1.4.3.4h11c.2 0 .3-.2.3-.4V9.4Z"></path></svg>';
@endphp
<div class="login-page">
    <div class="login-shell">
        <section class="login-panel">
            <a class="brand" href="{{ route('login') }}">
                <span class="brand__mark">{!! $mailIcon !!}</span>
                <span class="brand__text">
                    <span class="brand__title">MXCentral</span>
                    <span class="brand__subtitle">Local mail admin access</span>
                </span>
            </a>
            <h1>Sign in with a local MXCentral account.</h1>
            <p>
                Use a global-admin or domain-admin account for the control plane. Mailbox users can use
                self-service mode for mailbox-focused account actions.
            </p>
            <div class="login-highlights">
                <div class="login-highlight">
                    <strong>Admin shell</strong>
                    Global admins and domain admins can manage domains, accounts, quarantine, throttling, and logs from one portal.
                </div>
                <div class="login-highlight">
                    <strong>Self-service</strong>
                    Mailbox users can manage their own preferences without accessing global server controls.
                </div>
            </div>
        </section>

        <section class="login-card">
            <h2>MXCentral portal</h2>
            <p>Authentication is local-only and uses your existing iRedMail account database.</p>
            <form method="post" action="{{ route('login.store') }}" style="display:grid;gap:16px;margin-top:20px;">
                @csrf
                <label>Email<input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
                <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
                <label>Mode
                    <select name="mode">
                        <option value="admin" @selected(old('mode', 'admin') === 'admin')>Admin panel</option>
                        <option value="user" @selected(old('mode') === 'user')>Self-service</option>
                    </select>
                </label>
                <div class="button-row">
                    <button>Sign in</button>
                    <a class="button secondary" href="{{ config('iredmail.webmail_url') }}">Open webmail</a>
                </div>
            </form>
            <p class="login-note">Admin accounts are read from the local iRedMail SQL backend.</p>
        </section>
    </div>
</div>
@endsection
