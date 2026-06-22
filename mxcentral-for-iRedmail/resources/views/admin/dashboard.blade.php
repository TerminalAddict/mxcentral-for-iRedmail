@extends('layouts.app')

@section('content')
<h1>Dashboard</h1>
<div class="grid">
    @foreach($stats as $label => $value)
        @if($value !== null)
            <div class="stat"><span class="muted">{{ ucfirst(str_replace('_', ' ', $label)) }}</span><strong>{{ $value }}</strong></div>
        @endif
    @endforeach
</div>
<div class="panel">
    <h2>Exports</h2>
    <div class="toolbar">
        <a class="button" href="{{ route('export.accounts') }}">Export managed accounts</a>
        @if(session('actor.global_admin'))<a class="button secondary" href="{{ route('export.admins') }}">Export admin statistics</a>@endif
    </div>
</div>
@endsection
