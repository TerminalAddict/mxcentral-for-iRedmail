@extends('layouts.app')

@section('content')
@php
    $selectedMismatchSenders = array_flip($settings['allowed_login_mismatch_senders']);
    $selectedForgedSenders = array_flip($settings['allowed_forged_senders']);
    $hostedMailboxes = $settings['hosted_mailboxes'];
    $hostedDomains = $settings['hosted_domains'];
@endphp

<div class="page-titlebar">
    <h1>System Settings</h1>
</div>

<div class="panel">
    <h2>Sender Mismatch permission</h2>
    <form method="post" action="{{ route('system.settings.update') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <div class="span-4 settings-picker" data-settings-picker>
                <label>Allowed login mismatch senders
                    <input type="search" data-settings-picker-filter placeholder="Filter hosted mailboxes">
                    <span class="field-hint">Select hosted mailbox accounts that may send with a different From address. The saved iRedAPD setting is generated as a Python list.</span>
                </label>
                <div class="settings-picker__list" data-settings-picker-list>
                    @forelse($hostedMailboxes as $mailbox)
                        <label class="settings-picker__item" data-settings-picker-item data-search="{{ strtolower($mailbox->username.' '.$mailbox->domain.' '.($mailbox->name ?? '')) }}">
                            <input type="checkbox" name="allowed_login_mismatch_senders[]" value="{{ $mailbox->username }}" @checked(isset($selectedMismatchSenders[$mailbox->username]))>
                            <span class="settings-picker__body">
                                <span class="settings-picker__email">{{ $mailbox->username }}</span>
                                <span class="field-hint">
                                    {{ $mailbox->domain }}
                                    @if(!empty($mailbox->name)) - {{ $mailbox->name }} @endif
                                    - {{ ($mailbox->active ?? false) ? 'active' : 'disabled' }}
                                </span>
                            </span>
                        </label>
                    @empty
                        <span class="field-hint">No hosted mailboxes were found.</span>
                    @endforelse
                </div>
            </div>
            <div class="span-2">
                <strong>Managed file</strong>
                <span class="field-hint">{{ $settings['path'] }}</span>
                <span class="field-hint">The app enables the iRedAPD reject_sender_login_mismatch plugin, writes ALLOWED_LOGIN_MISMATCH_SENDERS, and keeps a .bak copy before replacing the file.</span>
            </div>
            <div>
                <strong>File access</strong>
                <span class="field-hint">
                    Read: {{ $settings['readable'] ? 'yes' : 'no' }}.
                    Write: {{ $settings['writable'] ? 'yes' : 'no' }}.
                </span>
            </div>
            <div>
                <strong>iRedAPD plugin</strong>
                <span class="field-hint">
                    {{ $settings['sender_mismatch_plugin_enabled'] ? 'Enabled in settings.py.' : 'Not detected. Saving will add reject_sender_login_mismatch to plugins.' }}
                </span>
            </div>
            <div>
                <strong>Postfix sender restriction</strong>
                <span class="field-hint">
                    {{ $settings['postfix_sender_login_mismatch_present'] ? 'Still present in main.cf. Saving will remove it.' : 'Removed or not detected in main.cf.' }}
                    Read: {{ $settings['postfix_main_cf_readable'] ? 'yes' : 'no' }}.
                    Write: {{ $settings['postfix_main_cf_writable'] ? 'yes' : 'no' }}.
                </span>
            </div>
            <div>
                <strong>Restart</strong>
                <span class="field-hint">
                    iRedAPD: {{ $settings['restart_command_configured'] ? 'configured' : 'not configured' }}.
                    Postfix reload: {{ $settings['postfix_reload_command_configured'] ? 'configured' : 'not configured' }}.
                </span>
            </div>
        </div>
        <div class="record-form__footer">
            <button>Save settings</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>Send Without SMTP Auth</h2>
    <form method="post" action="{{ route('system.settings.unauthenticated.update') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <div class="span-4 settings-picker" data-settings-picker>
                <label>Allowed hosted senders
                    <input type="search" data-settings-picker-filter placeholder="Filter hosted mailboxes">
                    <span class="field-hint">Selected hosted senders are written to iRedAPD ALLOWED_FORGED_SENDERS and Postfix sender_access.pcre.</span>
                </label>
                <div class="settings-picker__list" data-settings-picker-list>
                    @forelse($hostedMailboxes as $mailbox)
                        <label class="settings-picker__item" data-settings-picker-item data-search="{{ strtolower($mailbox->username.' '.$mailbox->domain.' '.($mailbox->name ?? '')) }}">
                            <input type="checkbox" name="allowed_forged_senders[]" value="{{ $mailbox->username }}" @checked(isset($selectedForgedSenders[$mailbox->username]))>
                            <span class="settings-picker__body">
                                <span class="settings-picker__email">{{ $mailbox->username }}</span>
                                <span class="field-hint">
                                    {{ $mailbox->domain }}
                                    @if(!empty($mailbox->name)) - {{ $mailbox->name }} @endif
                                    - {{ ($mailbox->active ?? false) ? 'active' : 'disabled' }}
                                </span>
                            </span>
                        </label>
                    @empty
                        <span class="field-hint">No hosted mailboxes were found.</span>
                    @endforelse
                </div>
            </div>
            <div class="span-2">
                <label>Allowed client IPs/networks
                    <textarea name="allowed_unauthenticated_networks" placeholder="192.168.0.1&#10;192.168.1.0/24">{{ implode("\n", $settings['allowed_unauthenticated_networks']) }}</textarea>
                    <span class="field-hint">Written to iRedAPD MYNETWORKS and Postfix sender_access.pcre. Use IP addresses or CIDR networks.</span>
                </label>
            </div>
            <div class="span-2">
                <strong>iRedAPD settings</strong>
                <span class="field-hint">{{ $settings['path'] }}</span>
                <span class="field-hint">
                    Read: {{ $settings['readable'] ? 'yes' : 'no' }}.
                    Write: {{ $settings['writable'] ? 'yes' : 'no' }}.
                </span>
            </div>
            <div class="span-2">
                <strong>Postfix sender access</strong>
                <span class="field-hint">{{ $settings['postfix_sender_access_path'] }}</span>
                <span class="field-hint">
                    Read: {{ $settings['postfix_sender_access_readable'] ? 'yes' : 'no' }}.
                    Write: {{ $settings['postfix_sender_access_writable'] ? 'yes' : 'no' }}.
                </span>
            </div>
            <div>
                <strong>Postfix hook</strong>
                <span class="field-hint">{{ $settings['postfix_sender_access_configured'] ? 'Detected in main.cf.' : 'Not detected in main.cf.' }}</span>
            </div>
            <div>
                <strong>Reload</strong>
                <span class="field-hint">
                    Postfix: {{ $settings['postfix_reload_command_configured'] ? 'configured' : 'not configured' }}.
                    iRedAPD: {{ $settings['restart_command_configured'] ? 'configured' : 'not configured' }}.
                </span>
            </div>
        </div>
        <div class="record-form__footer">
            <button>Save unauthenticated senders</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>Discard Messages Silently</h2>
    <form method="post" action="{{ route('system.settings.discard.update') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <div class="span-4">
                <label>Discard recipients
                    <textarea name="discard_recipients" placeholder="no-reply@example.com&#10;smtp@example.com">{{ implode("\n", $settings['discard_recipients']) }}</textarea>
                    <span class="field-hint">Enter recipient addresses that should accept messages and then silently discard them with Postfix DISCARD. The mailbox does not need to exist, but the domain must be hosted here.</span>
                </label>
            </div>
            <div class="span-2">
                <strong>Managed map</strong>
                <span class="field-hint">{{ $settings['discard_path'] }}</span>
                <span class="field-hint">The app writes one DISCARD row per address and keeps a .bak copy when replacing an existing map.</span>
            </div>
            <div class="span-2">
                <strong>Hosted domains</strong>
                <div class="settings-domain-list">
                    @forelse($hostedDomains as $domain)<span>{{ $domain->domain }}</span>@empty<span>None found</span>@endforelse
                </div>
                <span class="field-hint">Discard recipients must use one of these domains.</span>
            </div>
            <div>
                <strong>Map access</strong>
                <span class="field-hint">
                    Read: {{ $settings['discard_readable'] ? 'yes' : 'no' }}.
                    Write: {{ $settings['discard_writable'] ? 'yes' : 'no' }}.
                </span>
            </div>
            <div>
                <strong>Postfix hook</strong>
                <span class="field-hint">
                    {{ $settings['postfix_recipient_access_configured'] ? 'Detected in main.cf.' : 'Not detected in main.cf.' }}
                    Main config: {{ $settings['postfix_main_cf_path'] }}.
                </span>
            </div>
            <div>
                <strong>Postmap and reload</strong>
                <span class="field-hint">
                    postmap: {{ $settings['postmap_command_configured'] ? 'configured' : 'not configured' }}.
                    reload: {{ $settings['postfix_reload_command_configured'] ? 'configured' : 'not configured' }}.
                </span>
            </div>
        </div>
        <div class="record-form__footer">
            <button>Save discard recipients</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>SOGo Branding</h2>
    <form method="post" action="{{ route('system.settings.sogo.update') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <label class="span-3">Logo image URL
                <input name="sogo_logo_url" type="url" value="{{ $settings['sogo_logo_url'] ?? '' }}" placeholder="https://static.example.com/logo.svg">
                <span class="field-hint">Hosted image URL to use on the SOGo login/root page. SVG, PNG, JPG, or WebP URLs are acceptable if the browser can load them.</span>
            </label>
            <div class="settings-image-preview">
                <strong>Current image</strong>
                <div class="settings-image-preview__frame">
                    @if(!empty($settings['sogo_logo_url']))
                        <img src="{{ $settings['sogo_logo_url'] }}" alt="Configured SOGo logo">
                    @else
                        <span class="field-hint">No custom SOGo logo detected.</span>
                    @endif
                </div>
            </div>
            <div class="span-2">
                <strong>Source template</strong>
                <span class="field-hint">{{ $settings['sogo_template_source'] }}</span>
                <span class="field-hint">Readable: {{ $settings['sogo_template_source_readable'] ? 'yes' : 'no' }}.</span>
            </div>
            <div class="span-2">
                <strong>Override template</strong>
                <span class="field-hint">{{ $settings['sogo_template_target'] }}</span>
                <span class="field-hint">
                    Exists: {{ $settings['sogo_template_target_exists'] ? 'yes' : 'no' }}.
                    Read: {{ $settings['sogo_template_target_readable'] ? 'yes' : 'no' }}.
                    Write: {{ $settings['sogo_template_target_writable'] ? 'yes' : 'no' }}.
                </span>
            </div>
            <div>
                <strong>Reload</strong>
                <span class="field-hint">{{ $settings['sogo_reload_command_configured'] ? 'Configured. SOGo will be reloaded after saving.' : 'Not configured. Reload SOGo manually if needed.' }}</span>
            </div>
        </div>
        <div class="record-form__footer">
            <button>Save SOGo logo</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>iRedMail Upgrade Check</h2>
    <table class="summary-table">
        <thead><tr><th>Component</th><th>Installed</th><th>Latest</th><th>Status</th></tr></thead>
        <tbody>
            <tr>
                <td>iRedMail</td>
                <td>{{ $upgradeStatus['iredmail']['installed'] ?: 'Unknown' }}</td>
                <td>{{ $upgradeStatus['iredmail']['latest'] ?: 'Unknown' }}</td>
                <td class="{{ ($upgradeStatus['iredmail']['upgrade_available'] ?? false) ? 'bad' : 'ok' }}">
                    {{ ($upgradeStatus['iredmail']['upgrade_available'] ?? false) ? 'Upgrade available' : 'Current or unknown' }}
                </td>
            </tr>
            <tr>
                <td>iRedAPD</td>
                <td>{{ $upgradeStatus['iredapd']['installed'] ?: 'Unknown' }}</td>
                <td>{{ $upgradeStatus['iredapd']['latest'] ?: 'Unknown' }}</td>
                <td class="{{ ($upgradeStatus['iredapd']['upgrade_available'] ?? false) ? 'bad' : 'ok' }}">
                    {{ ($upgradeStatus['iredapd']['upgrade_available'] ?? false) ? 'Upgrade available' : 'Current or unknown' }}
                </td>
            </tr>
        </tbody>
    </table>
    <div class="record-form__grid">
        <div class="span-2">
            <strong>Last check</strong>
            <span class="field-hint">
                @if(($upgradeStatus['status'] ?? '') === 'never')
                    Not run yet.
                @else
                    {{ $upgradeStatus['checked_at'] ?: 'Unknown' }}.
                    {{ ($upgradeStatus['status'] ?? '') === 'failed' ? 'Failed' : 'OK' }}.
                @endif
            </span>
            @if(!empty($upgradeStatus['error']))
                <span class="field-hint">Error: {{ $upgradeStatus['error'] }}</span>
            @endif
        </div>
        <div class="span-2">
            <strong>Version files</strong>
            <span class="field-hint">iRedMail: {{ $upgradeStatus['iredmail']['installed_version_path'] ?? '/etc/iredmail-release' }}</span>
            <span class="field-hint">iRedAPD: {{ $upgradeStatus['iredapd']['installed_version_path'] ?? '/opt/iredapd/libs/__init__.py' }}</span>
        </div>
        <div class="span-2">
            <strong>Notification</strong>
            <span class="field-hint">{{ $upgradeStatus['notification']['reason'] ?? 'No notification state.' }}</span>
            @if(!empty($upgradeStatus['notification']['recipients']))
                <span class="field-hint">Recipients: {{ implode(', ', $upgradeStatus['notification']['recipients']) }}</span>
            @endif
        </div>
        <div class="span-2">
            <strong>Manual check</strong>
            <span class="field-hint">Run: php artisan iredmail:check-upgrades</span>
            <span class="field-hint">Cron task: iredmail-upgrade-check, every 24 hours.</span>
        </div>
    </div>
    @if(!empty($upgradeStatus['iredmail']['upgrade_path']))
        <h3>iRedMail upgrade path</h3>
        <table class="summary-table">
            <thead><tr><th>From</th><th>To</th><th>Tutorial</th></tr></thead>
            <tbody>
                @foreach($upgradeStatus['iredmail']['upgrade_path'] as $step)
                    <tr>
                        <td>{{ $step['from'] }}</td>
                        <td>{{ $step['to'] }}</td>
                        <td><a href="{{ $step['url'] }}" target="_blank" rel="noopener">Open tutorial</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif($upgradeStatus['iredmail']['upgrade_available'] ?? false)
        <p class="field-hint">No sequential iRedMail upgrade tutorial path was detected. Check release notes before upgrading.</p>
    @endif
    <div class="button-row">
        <a class="button secondary" href="{{ $upgradeStatus['iredmail']['release_notes_url'] ?? 'https://docs.iredmail.org/iredmail.releases.html' }}" target="_blank" rel="noopener">iRedMail release notes</a>
        <a class="button secondary" href="{{ $upgradeStatus['iredmail']['download_url'] ?? 'https://www.iredmail.org/download.html' }}" target="_blank" rel="noopener">iRedMail downloads</a>
        <a class="button secondary" href="{{ $upgradeStatus['iredapd']['tags_url'] ?? 'https://github.com/iredmail/iRedAPD/tags' }}" target="_blank" rel="noopener">iRedAPD tags</a>
    </div>
</div>

<div class="panel">
    <h2>Server Setup</h2>
    <table class="summary-table">
        <thead><tr><th>Check</th><th>Status</th><th>Message</th></tr></thead>
        <tbody>
            @foreach($setupChecks as $check)
                <tr>
                    <td>{{ $check['name'] }}</td>
                    <td class="{{ $check['ok'] ? 'ok' : 'bad' }}">{{ $check['ok'] ? 'OK' : 'Problem' }}</td>
                    <td>{{ $check['message'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="record-form__grid">
        <div class="span-2">
            <strong>Recommended restart command</strong>
            <span class="field-hint">Set IREDAPD_RESTART_COMMAND to a narrow sudo command, for example: /usr/bin/sudo /usr/bin/systemctl restart iredapd.service</span>
        </div>
        <div class="span-2">
            <strong>sudoers rule</strong>
            <span class="field-hint">Allow only the web server user to run only that service restart without a password. Do not give the app broad sudo access.</span>
        </div>
        <div class="span-2">
            <strong>Postfix discard setup</strong>
            <span class="field-hint">Add check_recipient_access hash:/etc/postfix/discard_recipients to smtpd_recipient_restrictions before using the discard list.</span>
        </div>
        <div class="span-2">
            <strong>Sender mismatch setup</strong>
            <span class="field-hint">The app removes reject_sender_login_mismatch from smtpd_sender_restrictions and enables the iRedAPD plugin of the same name when sender mismatch settings are saved.</span>
        </div>
        <div class="span-2">
            <strong>Unauthenticated sender setup</strong>
            <span class="field-hint">Add check_sender_access pcre:/etc/postfix/sender_access.pcre to smtpd_sender_restrictions if it is not already present.</span>
        </div>
        <div class="span-2">
            <strong>Postfix commands</strong>
            <span class="field-hint">Set POSTFIX_POSTMAP_COMMAND to a narrow postmap command and POSTFIX_RELOAD_COMMAND to a narrow Postfix reload command.</span>
        </div>
        <div class="span-2">
            <strong>SOGo template override</strong>
            <span class="field-hint">The app copies SOGoRootPage.wox to the SOGo user's template override path, then updates the logo img src.</span>
        </div>
        <div class="span-2">
            <strong>SOGo reload</strong>
            <span class="field-hint">Set SOGO_RELOAD_COMMAND to a narrow sudo command such as /usr/bin/sudo /usr/bin/systemctl reload sogo.service.</span>
        </div>
    </div>
</div>
<script>
    (() => {
        document.querySelectorAll('[data-settings-picker]').forEach((picker) => {
            const filter = picker.querySelector('[data-settings-picker-filter]');
            const items = Array.from(picker.querySelectorAll('[data-settings-picker-item]'));
            if (!filter || items.length === 0) return;

            filter.addEventListener('input', () => {
                const term = filter.value.trim().toLowerCase();
                items.forEach((item) => {
                    item.hidden = term !== '' && !item.dataset.search.includes(term);
                });
            });
        });
    })();
</script>
@endsection
