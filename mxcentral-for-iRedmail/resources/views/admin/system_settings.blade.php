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
    <a class="button secondary" href="{{ route('system.audit-log') }}">View Audit Log</a>
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
                <span class="field-hint">The root helper enables the iRedAPD reject_sender_login_mismatch plugin and writes ALLOWED_LOGIN_MISMATCH_SENDERS in one validated, rollback-capable transaction.</span>
            </div>
            <div>
                <strong>File access</strong>
                <span class="field-hint">
                    Read: {{ $settings['readable'] ? 'yes' : 'no' }}.
                    Privileged helper: {{ $settings['writable'] ? 'available' : 'unavailable' }}.
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
                    Privileged helper: {{ $settings['postfix_main_cf_writable'] ? 'available' : 'unavailable' }}.
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
                    Privileged helper: {{ $settings['writable'] ? 'available' : 'unavailable' }}.
                </span>
            </div>
            <div class="span-2">
                <strong>Postfix sender access</strong>
                <span class="field-hint">{{ $settings['postfix_sender_access_path'] }}</span>
                <span class="field-hint">
                    Read: {{ $settings['postfix_sender_access_readable'] ? 'yes' : 'no' }}.
                    Privileged helper: {{ $settings['postfix_sender_access_writable'] ? 'available' : 'unavailable' }}.
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
                    Privileged helper: {{ $settings['discard_writable'] ? 'available' : 'unavailable' }}.
                </span>
            </div>
            <div>
                <strong>Postfix hook</strong>
                <span class="field-hint">
                    {{ $settings['postfix_recipient_access_configured'] ? 'Detected in main.cf.' : 'Not detected yet; MXCentral will install it when this form is saved.' }}
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
            <span class="field-hint">Saving manages the map, main.cf hook, postmap rebuild, and Postfix reload as one application operation.</span>
            <button>Save discard recipients</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>SOGo Branding</h2>
    <p>Choose the original or a custom value independently for the logo and login colours.</p>
    <form method="post" action="{{ route('system.settings.sogo.update') }}" class="record-form">@csrf
        <div class="record-form__grid">
            <label class="span-3">Logo image URL
                <input name="sogo_logo_url" type="url" value="{{ old('sogo_logo_url', $settings['sogo_logo_url'] ?? '') }}" placeholder="https://static.example.com/logo.svg">
                <span class="field-hint">Used when custom logo is selected. SVG, PNG, JPG, or WebP URLs are acceptable if the browser can load them.</span>
            </label>
            <div class="settings-image-preview">
                <strong>Current image</strong>
                <div class="settings-image-preview__frame">
                    @if($settings['sogo_logo_custom'] && !empty($settings['sogo_logo_url']))
                        <img src="{{ $settings['sogo_logo_url'] }}" alt="Configured SOGo logo">
                    @else
                        <span class="field-hint">Original SOGo logo.</span>
                    @endif
                </div>
            </div>
            <label class="checkbox-field span-2">
                <input type="hidden" name="sogo_use_original_logo" value="0">
                <input name="sogo_use_original_logo" type="checkbox" value="1" @checked((bool) old('sogo_use_original_logo', ! $settings['sogo_logo_custom']))>
                <span class="checkbox-field__body">
                    <span class="checkbox-field__label">Use original SOGo logo</span>
                    <span class="field-hint">Restores SOGo’s packaged logo. The custom URL is ignored while selected.</span>
                </span>
            </label>
            <label>Login background colour
                <input name="sogo_login_background_color" type="color" value="{{ old('sogo_login_background_color', $settings['sogo_login_background_color']) }}">
                <span class="field-hint">Used when custom login colours are selected.</span>
            </label>
            <label>Login foreground colour
                <input name="sogo_login_foreground_color" type="color" value="{{ old('sogo_login_foreground_color', $settings['sogo_login_foreground_color']) }}">
                <span class="field-hint">Used when custom login colours are selected.</span>
            </label>
            <label class="checkbox-field span-2">
                <input type="hidden" name="sogo_use_original_colors" value="0">
                <input name="sogo_use_original_colors" type="checkbox" value="1" @checked((bool) old('sogo_use_original_colors', ! $settings['sogo_login_colors_custom']))>
                <span class="checkbox-field__body">
                    <span class="checkbox-field__label">Use original SOGo login colours</span>
                    <span class="field-hint">Removes MXCentral’s colour override while leaving the logo choice unchanged.</span>
                </span>
            </label>
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
                    Privileged helper: {{ $settings['sogo_template_target_writable'] ? 'available' : 'unavailable' }}.
                </span>
            </div>
            <div>
                <strong>Reload</strong>
                <span class="field-hint">{{ $settings['sogo_reload_command_configured'] ? 'Configured. SOGo will be reloaded after saving.' : 'Not configured. Reload SOGo manually if needed.' }}</span>
            </div>
        </div>
        <div class="record-form__footer">
            <button>Save SOGo branding</button>
        </div>
    </form>
</div>

<div class="panel">
    <h2>Decryptable Password Storage</h2>
    <form method="post" action="{{ route('system.settings.decryptable-passwords.update') }}" class="record-form"
        @if($settings['decryptable_passwords_enabled']) onsubmit="return this.querySelector('input[type=checkbox][name=enabled]').checked || confirm('Disable decryptable password storage and permanently remove all stored decryptable passwords?')" @endif>
        @csrf
        <div class="record-form__grid">
            <label class="checkbox-field span-2">
                <input type="hidden" name="enabled" value="0">
                <input name="enabled" type="checkbox" value="1" @checked($settings['decryptable_passwords_enabled'])>
                <span class="checkbox-field__body">
                    <span class="checkbox-field__label">Store decryptable mailbox passwords</span>
                    <span class="field-hint">Adds {{ $settings['decryptable_password_column'] }} to vmail.mailbox and encrypts new or changed passwords with this app's APP_KEY.</span>
                </span>
            </label>
            <div class="span-2">
                <strong>Current state</strong>
                <span class="field-hint">{{ $settings['decryptable_passwords_enabled'] ? ($settings['password_reveal_requires_totp'] ? 'Enabled. Explicitly authorized global admins can request a one-time, password- and MFA-authenticated reveal from /users.' : 'Enabled. Explicitly authorized global admins can request a one-time, password-reauthenticated reveal from /users; TOTP is disabled by deployment configuration.') : 'Disabled. The decryptable password column is not present, and no password can be revealed.' }}</span>
            </div>
            <div class="span-2">
                <strong>Important limit</strong>
                <span class="field-hint">Existing hashed passwords cannot be decrypted retrospectively. Only passwords entered while this is enabled can be stored for later display.</span>
            </div>
            <div class="span-2">
                <strong>Disabling</strong>
                <span class="field-hint">Turning this off drops the column from vmail.mailbox, removes stored decryptable passwords, and disables one-time reveals.</span>
            </div>
        </div>
        <div class="record-form__footer">
            <button>{{ $settings['decryptable_passwords_enabled'] ? 'Save password storage setting' : 'Enable password storage' }}</button>
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
            <strong>Privileged helper</strong>
            <span class="field-hint">Command paths and service names are root-owned settings in /etc/mxcentral/privileged-helper.json. PHP cannot supply or override them.</span>
        </div>
        <div class="span-2">
            <strong>sudoers rule</strong>
            <span class="field-hint">The only permitted root command is /usr/local/sbin/mxcentral-privileged with no command-line arguments.</span>
        </div>
        <div class="span-2">
            <strong>Postfix discard setup</strong>
            <span class="field-hint">MXCentral installs and maintains check_recipient_access hash:/etc/postfix/discard_recipients in smtpd_recipient_restrictions when the discard list is saved.</span>
        </div>
        <div class="span-2">
            <strong>Sender mismatch setup</strong>
            <span class="field-hint">The app removes reject_sender_login_mismatch from smtpd_sender_restrictions and enables the iRedAPD plugin of the same name when sender mismatch settings are saved.</span>
        </div>
        <div class="span-2">
            <strong>Unauthenticated sender setup</strong>
            <span class="field-hint">MXCentral installs and maintains check_sender_access pcre:/etc/postfix/sender_access.pcre when unauthenticated sender settings are saved.</span>
        </div>
        <div class="span-2">
            <strong>Postfix transaction</strong>
            <span class="field-hint">The helper atomically applies managed files, runs postmap and postfix check, reloads Postfix, and restores the previous state on failure.</span>
        </div>
        <div class="span-2">
            <strong>SOGo template override</strong>
            <span class="field-hint">The app copies SOGoRootPage.wox to the SOGo user's template override path, then updates the logo and managed login colours.</span>
        </div>
        <div class="span-2">
            <strong>SOGo reload</strong>
            <span class="field-hint">The root helper validates WOX/XML, limits changes to the logo and managed colour block, then reloads the configured SOGo service.</span>
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
