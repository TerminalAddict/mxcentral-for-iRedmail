@extends('layouts.app')

@section('content')
<div class="page-titlebar">
    <h1>Domains</h1>
    <form class="search-compact">
        <input name="q" value="{{ request('q') }}" placeholder="Search domains">
        <button class="secondary">Search</button>
    </form>
</div>

@if(session('actor.global_admin'))
<div class="panel">
    <h2>Create Domain</h2>
    <form method="post" action="{{ route('domains.create') }}" class="domain-form">@csrf
        <div class="domain-form__grid">
            <label>Domain<input name="domain" required><span class="field-hint">Example: example.com</span></label>
            <label>Description<input name="description"><span class="field-hint">Internal admin note.</span></label>
            <label class="span-2">Transport<input name="transport" value="{{ config('iredmail.default_mta_transport') }}"><span class="field-hint">Use dovecot unless LMTP is configured.</span></label>
            <label>Mailbox limit<input name="mailboxes" type="number" min="0" value="0"><span class="field-hint">0 = unlimited users.</span></label>
            <label>Alias limit<input name="aliases" type="number" min="0" value="0"><span class="field-hint">0 = unlimited aliases.</span></label>
            <label>List limit<input name="maillists" type="number" min="0" value="0"><span class="field-hint">0 = unlimited lists.</span></label>
            <label>Max user quota MB<input name="maxquota" type="number" min="0" value="0"><span class="field-hint">0 = no per-user cap.</span></label>
            <label>Domain quota MB<input name="quota" type="number" min="0" value="0"><span class="field-hint">0 = unlimited domain storage.</span></label>
            <label class="span-2">Primary MX IP<input name="backupmx_primary_ip" placeholder="45.56.127.226"><span class="field-hint">Required for Backup MX. The app saves transport as relay:[IP]:25.</span></label>
        </div>
        <div class="domain-form__footer">
            <label class="checkbox-field">
                <input name="backupmx" type="checkbox" value="1">
                <span class="checkbox-field__body"><span class="checkbox-field__label">Backup MX</span><span class="field-hint">Accept and relay mail onward for this domain.</span></span>
            </label>
            <button>Create domain</button>
        </div>
    </form>
</div>
@endif

<div class="panel">
    <h2>Edit Domain</h2>
    <form method="get" action="{{ route('domains') }}" class="domain-selector-row">
        <label>Select hosted domain
            <select name="edit" onchange="this.form.submit()">
                @foreach($domainOptions as $option)
                    <option value="{{ $option->domain }}" @selected(($selectedDomain->domain ?? '') === $option->domain)>{{ $option->domain }}</option>
                @endforeach
            </select>
            <span class="field-hint">Choose one domain to edit. The table below is only a summary.</span>
        </label>
        <button class="secondary">Load</button>
    </form>

    @if($selectedDomain)
        <form method="post" action="{{ route('domains.update', $selectedDomain->domain) }}" class="domain-form">@csrf @method('patch')
            <div class="domain-form__grid">
                <label>Description<input name="description" value="{{ $selectedDomain->description ?? '' }}"><span class="field-hint">Internal admin note.</span></label>
                <label class="span-2">Transport<input name="transport" value="{{ $selectedDomain->transport ?? '' }}"><span class="field-hint">dovecot = normal delivery. LMTP: lmtp:unix:private/dovecot-lmtp or lmtp:inet:127.0.0.1:24.</span></label>
                <label>Mailboxes<input name="mailboxes" type="number" min="0" value="{{ $selectedDomain->mailboxes ?? 0 }}"><span class="field-hint">0 = unlimited users.</span></label>
                <label>Aliases<input name="aliases" type="number" min="0" value="{{ $selectedDomain->aliases ?? 0 }}"><span class="field-hint">0 = unlimited aliases.</span></label>
                <label>Lists<input name="maillists" type="number" min="0" value="{{ $selectedDomain->maillists ?? 0 }}"><span class="field-hint">0 = unlimited lists.</span></label>
                <label>Max user quota MB<input name="maxquota" type="number" min="0" value="{{ $selectedDomain->maxquota ?? 0 }}"><span class="field-hint">Largest per-user mailbox quota.</span></label>
                <label>Domain quota MB<input name="quota" type="number" min="0" value="{{ $selectedDomain->quota ?? 0 }}"><span class="field-hint">Total domain mailbox storage.</span></label>
                <label class="span-2">Primary MX IP<input name="backupmx_primary_ip" value="{{ $backupMxPrimaryIp }}" placeholder="45.56.127.226"><span class="field-hint">Required when Backup MX is enabled. Saves transport as relay:[IP]:25.</span></label>
            </div>
            <div class="domain-edit-actions">
                <label class="checkbox-field">
                    <input type="hidden" name="active" value="0"><input name="active" type="checkbox" value="1" @checked($selectedDomain->active ?? false)>
                    <span class="checkbox-field__body"><span class="checkbox-field__label">Active</span><span class="field-hint">Disable to stop this domain being treated as live.</span></span>
                </label>
                <label class="checkbox-field">
                    <input name="backupmx" type="checkbox" value="1" @checked($selectedDomain->backupmx ?? false)>
                    <span class="checkbox-field__body"><span class="checkbox-field__label">Backup MX</span><span class="field-hint">Accept mail here and relay it to the primary MX IP.</span></span>
                </label>
                <button class="secondary">Save changes</button>
            </div>
        </form>

        <div class="domain-dkim">
            <div class="domain-dkim__header">
                <div>
                    <h3>Alias domains</h3>
                    <p>Mail sent to the same local-part at an alias domain is delivered to this primary domain.</p>
                </div>
            </div>

            <form method="post" action="{{ route('domains.alias-domains.create', $selectedDomain->domain) }}" class="record-form">@csrf
                <div class="record-form__grid">
                    <label class="span-3">Alias domain
                        <input name="alias_domain" placeholder="domain.ltd">
                        <span class="field-hint">Example: user@domain.ltd delivers to user@{{ $selectedDomain->domain }}.</span>
                    </label>
                </div>
                <div class="record-form__footer">
                    <button>Add alias domain</button>
                </div>
            </form>

            <table class="summary-table">
                <thead><tr><th>Alias domain</th><th>Target domain</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse($aliasDomains as $aliasDomain)
                        <tr>
                            <td>{{ $aliasDomain->alias_domain }}</td>
                            <td>{{ $aliasDomain->target_domain }}</td>
                            <td>
                                <form method="post" action="{{ route('domains.alias-domains.delete', $aliasDomain->alias_domain) }}" onsubmit="return confirm('Remove this alias domain?')">@csrf @method('delete')
                                    <button class="danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No alias domains configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="domain-dkim">
            <div class="domain-dkim__header">
                <div>
                    <h3>Catch-all</h3>
                    <p>Mail sent to non-existing addresses at this domain is delivered to the configured destination mailbox.</p>
                </div>
            </div>

            <form method="post" action="{{ route('domains.catch-all.create', $selectedDomain->domain) }}" class="record-form">@csrf
                <div class="record-form__grid">
                    <label class="span-3">Destination mailbox
                        <input name="forwarding" type="email" placeholder="dest@example.com">
                        <span class="field-hint">Destination must be an existing mailbox. This creates address={{ $selectedDomain->domain }} in vmail.forwardings.</span>
                    </label>
                </div>
                <div class="record-form__footer">
                    <button>Add catch-all</button>
                </div>
            </form>

            <table class="summary-table">
                <thead><tr><th>Domain address</th><th>Destination</th><th>Actions</th></tr></thead>
                <tbody>
                    @forelse($catchAllDestinations as $catchAll)
                        <tr>
                            <td>{{ $catchAll->address }}</td>
                            <td>{{ $catchAll->forwarding }}</td>
                            <td>
                                <form method="post" action="{{ route('domains.catch-all.delete', [$selectedDomain->domain, $catchAll->forwarding]) }}" onsubmit="return confirm('Remove this catch-all destination?')">@csrf @method('delete')
                                    <button class="danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No catch-all destination configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($dkimStatus)
            <div class="domain-dkim">
                <div class="domain-dkim__header">
                    <div>
                        <h3>DKIM signing</h3>
                        <p>Outgoing mail for this domain uses selector <span class="mono">{{ $dkimStatus['selector'] }}</span>.</p>
                    </div>
                    <div class="domain-dkim__status">
                        <span class="{{ $dkimStatus['key_exists'] ? 'ok' : 'bad' }}">{{ $dkimStatus['key_exists'] ? 'Key exists' : 'No key' }}</span>
                        <span class="{{ $dkimStatus['configured'] ? 'ok' : 'bad' }}">{{ $dkimStatus['configured'] ? 'Amavisd configured' : 'Amavisd not configured' }}</span>
                    </div>
                </div>

                <dl class="domain-dkim__meta">
                    <div><dt>DNS name</dt><dd class="mono">{{ $dkimStatus['dns_name'] }}</dd></div>
                    <div><dt>Key file</dt><dd class="mono">{{ $dkimStatus['key_path'] }}</dd></div>
                    <div><dt>Amavisd config</dt><dd class="mono">{{ $dkimStatus['config_path'] }}</dd></div>
                </dl>

                @if($dkimStatus['expected_txt'])
                    <label>DNS TXT value
                        <textarea class="mono" readonly rows="7">{{ $dkimStatus['expected_txt'] }}</textarea>
                        <span class="field-hint">Publish this at {{ $dkimStatus['dns_name'] }}. Long TXT values may need to be split into quoted chunks by your DNS provider.</span>
                    </label>
                @else
                    <p class="muted">Generate a DKIM key before publishing DNS.</p>
                @endif

                <div class="domain-dkim__actions">
                    @if(session('actor.global_admin'))
                        <form method="post" action="{{ route('domains.dkim.generate', $selectedDomain->domain) }}">@csrf
                            <label>Key size
                                <select name="bits">
                                    <option value="1024" selected>1024</option>
                                    <option value="2048">2048</option>
                                </select>
                            </label>
                            <button>Generate/rotate DKIM</button>
                        </form>
                    @endif
                </div>

                @unless(session('actor.global_admin'))
                    <p class="field-hint">Only global admins can generate keys or update amavisd configuration.</p>
                @endunless
            </div>
        @endif

        @if($dnsStatus)
            <div class="domain-dkim">
                <div class="domain-dkim__header">
                    <div>
                        <h3>DNS records</h3>
                        <p>Check the published authentication records receivers use for this domain.</p>
                    </div>
                    <form method="post" action="{{ route('domains.dns.check', $selectedDomain->domain) }}">@csrf
                        <button class="secondary">Check DNS</button>
                    </form>
                </div>

                <table class="summary-table domain-dns-table">
                    <thead><tr><th>Record</th><th>Name</th><th>Status</th><th>Published TXT</th></tr></thead>
                    <tbody>
                        @foreach(['dkim' => 'DKIM', 'mx' => 'MX', 'spf' => 'SPF', 'dmarc' => 'DMARC'] as $key => $label)
                            @php($record = $dnsStatus[$key])
                            <tr>
                                <td>{{ $label }}</td>
                                <td class="mono">{{ $record['name'] }}</td>
                                <td class="{{ $record['ok'] ? 'ok' : 'bad' }}">{{ $record['label'] }}</td>
                                <td>
                                    @if($key === 'mx')
                                        @forelse($record['records'] as $mx)
                                            <pre>{{ $mx['priority'] }} {{ $mx['target'] }}</pre>
                                        @empty
                                            <span class="muted">No MX record found.</span>
                                        @endforelse
                                    @else
                                        @forelse($record['records'] as $txt)
                                            <pre>{{ $txt }}</pre>
                                        @empty
                                            <span class="muted">No matching TXT record found.</span>
                                        @endforelse
                                    @endif
                                    @if($key === 'mx')
                                        <div class="field-hint">
                                            Checking {{ $record['targets']['hostname'] ?: 'configured server' }}
                                            @if($record['targets']['ips'])
                                                ({{ implode(', ', $record['targets']['ips']) }})
                                            @endif
                                            @if($record['details'])
                                                - {{ $record['details'] }}
                                            @endif
                                        </div>
                                    @endif
                                    @if($key === 'spf')
                                        <div class="field-hint">
                                            Checking {{ $record['targets']['hostname'] ?: 'configured server' }}
                                            @if($record['targets']['ips'])
                                                ({{ implode(', ', $record['targets']['ips']) }})
                                            @endif
                                            @if($record['details'])
                                                - {{ $record['details'] }}
                                            @endif
                                        </div>
                                    @endif
                                    @if($key === 'dmarc')
                                        <div class="field-hint">{{ $record['details'] }}</div>
                                        @foreach($record['external_reports'] as $report)
                                            <div class="field-hint">
                                                {{ strtoupper($report['tag']) }} external reporting to {{ $report['domain'] }}:
                                                <span class="{{ $report['ok'] ? 'ok' : 'bad' }}">{{ $report['label'] }}</span>
                                                <span class="mono">{{ $report['name'] }}</span>
                                            </div>
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if(session('actor.global_admin'))
            <form method="post" action="{{ route('domains.delete', $selectedDomain->domain) }}" onsubmit="return confirm('Delete this domain and all related accounts?')" class="domain-danger-row">@csrf @method('delete')
                <label>Keep days<input name="keep_days" type="number" min="0" value="0"><span class="field-hint">Sets the logged scheduled mailbox deletion date. 0 = no scheduled date.</span></label>
                <button class="danger">Delete selected domain</button>
            </form>
        @endif
    @else
        <p class="muted" style="margin-top:14px">No hosted domains are available to edit.</p>
    @endif
</div>

<table class="domain-summary-table">
    <thead><tr><th>Domain</th><th>Transport</th><th>Limits</th><th>Quota</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr>
            <td><a href="{{ route('domains', ['edit' => $row->domain]) }}">{{ $row->domain }}</a><div class="muted">{{ $row->description ?? '' }}</div></td>
            <td class="mono">{{ $row->transport ?? '' }}</td>
            <td>Users {{ $row->mailboxes ?? 0 }} · Aliases {{ $row->aliases ?? 0 }} · Lists {{ $row->maillists ?? 0 }}</td>
            <td>User max {{ $row->maxquota ?? 0 }} MB · Domain {{ $row->quota ?? 0 }} MB</td>
            <td>
                <span class="{{ ($row->active ?? 0) ? 'ok' : 'bad' }}">{{ ($row->active ?? 0) ? 'Active' : 'Disabled' }}</span>
                @if($row->backupmx ?? false)<div class="muted">Backup MX</div>@endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="pagination">{{ $rows->links() }}</div>
@endsection
