@extends('layouts.app')

@section('content')
@php
    $sections = [
        'domains' => [
            'title' => 'Domains',
            'route' => 'domains',
            'key' => 'domain',
            'label' => 'Domain',
            'secondary' => 'description',
        ],
        'users' => [
            'title' => 'Users',
            'route' => 'users',
            'key' => 'username',
            'label' => 'Mailbox',
            'secondary' => 'name',
        ],
        'aliases' => [
            'title' => 'Aliases',
            'route' => 'aliases',
            'key' => 'address',
            'label' => 'Alias',
            'secondary' => 'name',
        ],
        'lists' => [
            'title' => 'Mailing Lists',
            'route' => 'lists',
            'key' => 'address',
            'label' => 'List',
            'secondary' => 'name',
        ],
    ];
@endphp

<div class="page-titlebar">
    <h1>Search</h1>
    <form class="search-compact" method="get" action="{{ route('search') }}">
        <input name="q" value="{{ $term }}" placeholder="Domain, email, or display name">
        <button class="secondary">Search</button>
    </form>
</div>

@foreach($sections as $type => $section)
    @php
        $rows = $results[$type] ?? collect();
    @endphp
    <div class="panel">
        <h2>{{ $section['title'] }}</h2>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>{{ $section['label'] }}</th>
                    <th>Details</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @if($rows->isEmpty())
                    <tr><td colspan="3" class="muted">No results</td></tr>
                @endif
                @foreach($rows as $row)
                    @php
                        $value = $row->{$section['key']} ?? '';
                        $domain = $row->domain ?? (str_contains($value, '@') ? substr(strrchr($value, '@') ?: '', 1) : null);
                        $params = ['edit' => $value];
                        if ($type !== 'domains' && $domain) {
                            $params['domain'] = $domain;
                        }
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route($section['route'], $params) }}">{{ $value }}</a>
                            @if($type !== 'domains' && $domain)<div class="muted">{{ $domain }}</div>@endif
                        </td>
                        <td>{{ $row->{$section['secondary']} ?? '' }}</td>
                        <td><span class="{{ ($row->active ?? 0) ? 'ok' : 'bad' }}">{{ ($row->active ?? 0) ? 'Active' : 'Disabled' }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach
@endsection
