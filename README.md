# mxcentral-for-iRedmail

Laravel-based MXCentral admin panel for iRedMail SQL installations using MySQL or MariaDB.

This app manages the existing iRedMail databases directly:

- `vmail`: domains, users, aliases, mailing lists, admin ownership.
- `iredadmin`: sessions, settings, audit logs, deleted mailbox path logs.
- `amavisd`: sent/received mail metadata, quarantine, white/blacklists.
- `iredapd`: throttling.
- `fail2ban`: optional banned IP view/unban integration.

For server deployment and required iRedMail/Postfix/SOGo permissions, start with [INSTALL.md](INSTALL.md).

## Local Development

```bash
cd mxcentral-for-iRedmail
composer install
cp .env.example .env
php artisan key:generate
php artisan serve
```

Open `http://127.0.0.1:8000`.

## Configuration

Set a separate least-privilege identity for each iRedMail schema in `.env`:

```dotenv
DB_CONNECTION=sqlite
SESSION_DRIVER=file
CACHE_STORE=array
QUEUE_CONNECTION=sync

IREDMAIL_DB_HOST=127.0.0.1
IREDMAIL_DB_PORT=3306

VMAIL_DB_DATABASE=vmail
VMAIL_DB_USERNAME=mxcentral_vmail
VMAIL_DB_PASSWORD=
IREDADMIN_DB_DATABASE=iredadmin
IREDADMIN_DB_USERNAME=mxcentral_iredadmin
IREDADMIN_DB_PASSWORD=
AMAVISD_DB_DATABASE=amavisd
AMAVISD_DB_USERNAME=mxcentral_amavisd
AMAVISD_DB_PASSWORD=
IREDAPD_DB_DATABASE=iredapd
IREDAPD_DB_USERNAME=mxcentral_iredapd
IREDAPD_DB_PASSWORD=
FAIL2BAN_DB_DATABASE=fail2ban
FAIL2BAN_DB_USERNAME=mxcentral_fail2ban
FAIL2BAN_DB_PASSWORD=

IREDMAIL_DECRYPTABLE_PASSWORD_COLUMN=decrypt-pass
MXCENTRAL_PRIVILEGED_HELPER_COMMAND="/usr/bin/sudo /usr/local/sbin/mxcentral-privileged"
```

Use [the table-level grant template](mxcentral-for-iRedmail/docs/database-grants.sql)
to provision these accounts. Do not grant global privileges.

Do not commit `.env`. It contains live database credentials and server-specific
application settings. Keep privileged command paths in the root-owned
`/etc/mxcentral/privileged-helper.json`.

MxCentral does not require a local Laravel application database for normal
operation. Keep `SESSION_DRIVER=file`, `CACHE_STORE=array`, and
`QUEUE_CONNECTION=sync` unless you intentionally create and migrate a separate
Laravel database.

## Subdirectory Deployment

The app can be mounted below any URL prefix, for example `https://domain.example/mxcentral`.

Set these values in `.env`:

```dotenv
APP_URL=https://domain.example/mxcentral
SESSION_PATH=/mxcentral
```

`APP_URL` controls generated URLs from CLI/background contexts. `SESSION_PATH` keeps the browser session cookie scoped to the mounted app path.

If assets are generated without the prefix on your web server, also set:

```dotenv
ASSET_URL=https://domain.example/mxcentral
```

The SOGo/webmail button is deliberately configurable because SOGo is often mounted outside this app:

```dotenv
IREDMAIL_WEBMAIL_URL=/SOGo/
```

Point the web server prefix to the Laravel `public` directory, not the project root.

Apache example:

```apache
Alias /mxcentral /opt/www/mxcentral-for-iRedmail/public

<Directory /opt/www/mxcentral-for-iRedmail/public>
    AllowOverride All
    Require all granted
</Directory>
```

If Apache strips the prefix from generated routes or redirects, uncomment `RewriteBase` in `public/.htaccess` and set it to your chosen prefix:

```apache
RewriteBase /mxcentral
```

nginx iRedMail template example, for example `/etc/nginx/templates/mxcentral.tmpl`:

```nginx
# Settings for mxcentral-for-iRedmail.

# Redirect /mxcentral to /mxcentral/.
location = /mxcentral {
    rewrite ^ /mxcentral/;
}

# Laravel public files under /mxcentral.
location /mxcentral/ {
    alias /opt/www/mxcentral-for-iRedmail/public/;
    index index.php;
    try_files $uri $uri/ @mxcentral_front_controller;
}

# Laravel front controller.
location @mxcentral_front_controller {
    rewrite ^ /mxcentral/index.php last;
}

# Do not expose any other PHP files directly.
location ~ ^/mxcentral/(?!index\.php$).+\.php$ {
    return 404;
}

location = /mxcentral/index.php {
    include /etc/nginx/templates/hsts.tmpl;
    include /etc/nginx/templates/fastcgi_php.tmpl;

    fastcgi_param SCRIPT_FILENAME @@MXCENTRAL_PUBLIC_PATH@@/index.php;
    fastcgi_param SCRIPT_NAME /mxcentral/index.php;
    fastcgi_param DOCUMENT_ROOT @@MXCENTRAL_PUBLIC_PATH@@;
    fastcgi_param REQUEST_URI $request_uri;
}

```

Render `docs/nginx/mxcentral.tmpl` with
`scripts/render-nginx-template.sh <absolute-public-path> <absolute-output-path>`
before including it. Put this include before any broad PHP catchall include if
the server block has one. The iRedMail `fastcgi_php.tmpl` normally supplies the
PHP-FPM socket; if your server does not, add the correct `fastcgi_pass` line
inside the `location = /mxcentral/index.php` block.

### DKIM Management

The domain edit screen can generate DKIM keys for hosted domains with selector `mxcentral`, producing DNS records at `mxcentral._domainkey.<domain>`.
The DNS records panel also checks MX, SPF, and DMARC. Results are saved in the local Laravel file cache and page loads only display that saved snapshot; **Check DNS** performs a fresh lookup and replaces it. Set `IREDMAIL_DNS_CACHE_STORE` if a different persistent Laravel cache store is required. MX is checked against the configured mail server hostname and public IPs. For SPF expansion, set `IREDMAIL_SPF_SERVER_HOSTNAME` and `IREDMAIL_SPF_SERVER_IPS` to the outbound mail server hostname and public sending IPs. DMARC checks include external report authorization records, for example `example.com._report._dmarc.reports.example.net TXT "v=DMARC1"`.

On Debian/Ubuntu iRedMail installs, amavisd custom settings are normally in `/etc/amavis/conf.d/50-user` and DKIM private keys are normally kept in `/var/lib/dkim`. Configure these values in `.env`:

```dotenv
AMAVISD_CONFIG_PATH=/etc/amavis/conf.d/50-user
AMAVISD_DKIM_DIRECTORY=/var/lib/dkim
AMAVISD_DKIM_SELECTOR=mxcentral
AMAVISD_DKIM_BITS=1024
```

The app only writes a marked MXCentral block in the amavisd config and appends
DKIM sender mappings so existing iRedMail mappings remain intact. Key generation,
ownership/mode enforcement, configuration replacement, and service reloads go
through the root-owned MXCentral helper. The PHP worker must not have write
access to the amavisd configuration or DKIM directory.

## System Settings

Global admins can use `/system/settings` for host-level mail settings. Most
settings manage service configuration files; optional decryptable password
storage is represented by a column in `vmail.mailbox`.

The only sudo grant is the fixed, no-argument
`/usr/local/sbin/mxcentral-privileged` broker. It validates JSON operations and
uses root-owned paths and command arrays from
`/etc/mxcentral/privileged-helper.json`. See [INSTALL.md](INSTALL.md).

### Decryptable Mailbox Passwords

Decryptable password storage is disabled by default and can only be enabled or
disabled by a global admin. Its state is determined directly from the configured
column in `vmail.mailbox`, so no separate application setting or migration is
required.

When enabled:

- MXCentral adds the nullable `decrypt-pass` text column to `vmail.mailbox`.
- New mailbox passwords and subsequent password changes are encrypted with
  Laravel's application encryption key before being stored.
- Only an explicitly allowlisted global admin can request a reveal from the
  user edit screen. Each reveal requires current-password re-authentication, a
  configured TOTP code, and a recorded access purpose.
- Existing password hashes are not converted. A password becomes available only
  after it is created or changed while the feature is enabled.

When disabled, MXCentral drops the column and permanently removes every stored
decryptable password. Re-enabling creates an empty column; it does not restore
previous values or passwords changed while storage was disabled.

Keep `APP_KEY` stable and secret. Changing it makes existing encrypted values
unreadable. The vmail database account needs `ALTER` privilege on
`vmail.mailbox` so the global-admin toggle can add and drop the column.
Reveals use short-lived single-use links, never embed plaintext in the user
listing, and are disabled unless `MXCENTRAL_PASSWORD_REVEAL_ADMINS` and
`MXCENTRAL_PASSWORD_REVEAL_TOTP_SECRETS` explicitly authorize the administrator.

### Deployment-specific mailbox settings

Mailbox layout and transports are validated settings rather than code
assumptions. Configure `IREDMAIL_STORAGE_BASE_DIRECTORY`,
`IREDMAIL_STORAGE_NODE`, `IREDMAIL_MAILBOX_DIRECTORY_TEMPLATE` (containing
`{domain}` and `{local}`), `IREDMAIL_MAILBOX_LANGUAGE`,
`IREDMAIL_BACKUP_MX_PORT`, and
`IREDMAIL_MAILING_LIST_TRANSPORT_TEMPLATE` (containing `{address}`) in each
server's protected `.env`. Deployment runs `mxcentral:check-production`, and
production HTTP requests fail closed if these or the application/session
security settings are unsafe, required iRedMail tables are inaccessible, or the
root helper reports insecure paths, commands, ownership, or modes.

### Sender Mismatch Permission

This implements the iRedMail sender mismatch setup documented for iRedAPD:

- UI input: checkbox list of hosted mailboxes from `vmail.mailbox`.
- Validation: selected addresses must be existing hosted mailboxes.
- iRedAPD: enables the `reject_sender_login_mismatch` plugin in `settings.py`.
- Postfix: removes `reject_sender_login_mismatch` from `smtpd_sender_restrictions` in `main.cf` because iRedAPD handles the flexible check.
- Output: a managed iRedAPD block containing a Python list, for example:

```python
ALLOWED_LOGIN_MISMATCH_SENDERS = ['smtp@example.com', 'support@example.com']
```

Environment:

```dotenv
IREDAPD_SETTINGS_PATH=/opt/iredapd/settings.py
POSTFIX_MAIN_CF_PATH=/etc/postfix/main.cf
```

### Send Without SMTP Auth

Global admins can use `/system/settings` to allow selected hosted mailbox senders, client IPs, or CIDR networks to submit mail without SMTP AUTH.

MXCentral writes the iRedMail settings documented for iRedAPD:

```python
ALLOWED_FORGED_SENDERS = ['user@example.com']
MYNETWORKS = ['192.168.0.1', '192.168.1.0/24']
```

It also manages a marked block in `/etc/postfix/sender_access.pcre` and ensures `smtpd_sender_restrictions` contains:

```text
check_sender_access pcre:/etc/postfix/sender_access.pcre
```

Environment:

```dotenv
POSTFIX_SENDER_ACCESS_PATH=/etc/postfix/sender_access.pcre
```

### Backup MX Domains

For MySQL-backed iRedMail installs, Backup MX domains are stored in `vmail.domain`. When Backup MX is enabled, MXCentral requires the primary MX server IP address and saves:

```sql
backupmx = 1
transport = 'relay:[PRIMARY_MX_IP]:25'
```

Using the primary MX IP address follows the iRedMail recommendation and avoids mail loops caused by DNS MX lookups routing back to the backup MX.

### Alias Domains

The Domains screen supports iRedMail SQL alias domains. Adding `domain.ltd` as an alias of `example.com` inserts:

```sql
INSERT INTO alias_domain (alias_domain, target_domain) VALUES ('domain.ltd', 'example.com');
```

Mail for `user@domain.ltd` is delivered to `user@example.com`.

### Catch-All

The Domains screen supports per-domain catch-all destinations. Adding `dest@example.com` as catch-all for `domain.com` inserts:

```sql
INSERT INTO forwardings (address, forwarding, domain, dest_domain)
VALUES ('domain.com', 'dest@example.com', 'domain.com', 'example.com');
```

The destination must be an existing mailbox.

### Quarantine Notifications

iRedMail quarantine does not notify users by itself. iRedAdmin-Pro ships a periodic script for this; MXCentral provides an equivalent Artisan command:

```sh
php artisan quarantine:notify-recipients
```

The command groups current quarantined messages by recipient, sends each user a digest with a self-service quarantine link, and records notified message IDs in `storage/app/quarantine-notifications.json` so routine runs only notify new quarantined mail. Use `--dry-run` to count pending notifications without sending, or `--force-all` to resend notifications for all currently quarantined mail.

Use one cron entry for all MXCentral scheduled tasks:

```cron
* * * * * MXCENTRAL_CRON_USER=www-data MXCENTRAL_SUDO_PATH=/usr/bin/sudo /usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php >/dev/null 2>&1
```

Cron documentation is in `docs/cron/quarantine-notifications.md`.

### Discard Messages Silently

This manages a Postfix recipient access map for addresses that should accept mail and silently discard it.

- UI input: newline/comma/space-separated recipient addresses.
- Validation: the mailbox does not need to exist, but the domain must exist in `vmail.domain`.
- Output: `/etc/postfix/discard_recipients` entries:

```text
no-reply@example.com DISCARD
smtp@example.com DISCARD
```

When the form is saved, MXCentral automatically installs this restriction in
`smtpd_recipient_restrictions`, rebuilds the hash map, and reloads Postfix:

```text
check_recipient_access hash:/etc/postfix/discard_recipients
```

Environment:

```dotenv
POSTFIX_MAIN_CF_PATH=/etc/postfix/main.cf
POSTFIX_DISCARD_RECIPIENTS_PATH=/etc/postfix/discard_recipients
POSTFIX_STAGING_DOMAINS_PATH=/etc/postfix/mxcentral_staging_domains.pcre
```

Binary paths and service names are root-controlled in
`/etc/mxcentral/privileged-helper.json`; they cannot be changed by PHP.

### Staging Domains During Migration

Global admins can create a domain in staging mode or switch an existing domain
between **Staging** and **Accepting mail** from `/domains`.

Staging is independent from iRedMail's active flag:

- `vmail.domain.active` and mailbox accounts remain active so users can
  authenticate through IMAP, webmail, and migration tools.
- MXCentral writes `/etc/postfix/mxcentral_staging_domains.pcre`.
- Postfix temporarily rejects staged-domain recipients with
  `450 4.2.0 Domain migration in progress; please try again later`.
- Message content is not accepted, queued, or discarded on the new server.
- Alias domains targeting a staged primary domain are included automatically.
- MX records are never changed by MXCentral.

MXCentral installs the staging recipient restriction before the silent-discard
restriction, atomically updates `main.cf`, and reloads Postfix through the
privileged helper. The PCRE map does not require postmap.

The intended migration sequence is: create the staged domain and users, copy
mail, test logins, select **Start accepting inbound mail**, change MX records,
then perform final incremental synchronization while old DNS answers expire.

### SOGo Branding

This manages the per-user SOGo root page template override, the logo image URL, and the login page colours.

- UI input: an `http` or `https` image URL.
- The app copies `SOGoRootPage.wox` to the SOGo override template path when needed.
- The app updates the `src` attribute of the SOGo logo image and shows the currently configured image.
- The login background and foreground colour pickers maintain a style block immediately after the template's first `</script>` tag and before its `MAIN CONTENT ROW` comment.
- The foreground colour applies to text and form controls inside `#login`.

Environment:

```dotenv
SOGO_ROOT_TEMPLATE_SOURCE=
SOGO_ROOT_TEMPLATE_TARGET=/var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI/SOGoRootPage.wox
```

If `SOGO_ROOT_TEMPLATE_SOURCE` is empty, the app searches `/usr/lib*/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox`. Set it explicitly if your package installs the template elsewhere.

The privileged helper creates or replaces the override and reloads SOGo. PHP
does not need write access to the SOGo template tree.

## Security Notes

- All normal writes use Laravel CSRF protection and route middleware.
- Login attempts are limited independently by normalized account and source IP
  using a persistent local cache. Delays escalate into temporary lockouts. After
  verifying a request out of band, an operator can run
  `php artisan mxcentral:unlock-admin admin@example.com --ip=192.0.2.10`.
- Global-only system settings are protected with `iredmail.auth:global`.
- Decryptable mailbox passwords are encrypted at rest with `APP_KEY`; their
  encrypted database values are excluded from list, search, API, and
  self-service results.
- Shell execution is avoided for configured system commands; commands are run as argv arrays through Symfony Process.
- System-file writes create `.bak` backups and use per-target lock files under `storage/framework/locks`.
- Quarantine release protocol values are validated to reject control characters before sending to Amavisd.
- Raw quarantined mail is returned as `text/plain`.

## Setup Checks

After signing in, visit `/setup`. It checks PHP extensions, database connectivity, and expected tables.

If the `iredadmin` database has not been created yet, import the reference schema from:

```bash
../iRedAdmin-Pro-SQL/SQL/iredadmin.mysql
```

## Implemented Areas

- Admin and self-service login against existing iRedMail password hashes.
- Global admin, domain admin, and self-service scoping.
- Domain, user, alias, mailing list, and admin listings.
- Domain, user, and alias creation.
- Per-user service toggles.
- Staged domain migrations with active user logins and temporary inbound SMTP rejection.
- Optional global-admin-controlled decryptable mailbox password storage.
- User deletion with deleted mailbox path logging.
- Mail sent/received metadata views.
- Quarantine list, raw message view, delete, and Amavisd release request.
- Throttling settings.
- Amavisd white/blacklist entries.
- Fail2ban banned IP list and optional unban command.
- Global-only system settings for iRedAPD sender mismatch, Postfix silent discard recipients, and SOGo logo branding.
- Search, account export, admin statistics export.
- JSON endpoints under `/api/*` for the main read surfaces.

## FAQ

### Which database user should this app use?

Use separate table-scoped MariaDB/MySQL identities for `vmail`, `iredadmin`,
`amavisd`, `iredapd`, and `fail2ban`. The database host and port may remain
shared. If decryptable password storage will be toggled from System Settings,
only the vmail identity also needs `ALTER` on `vmail.mailbox`.

### Why do System Settings show readable or writable as "no"?

Those settings touch files owned by services such as iRedAPD, Postfix, and
SOGo. PHP receives no direct write permission; the root-owned helper performs
validated atomic updates and service reloads.

### Do discard recipients need to be real mailboxes?

No. The local part can be arbitrary, such as `no-reply@hosted-domain.example`. The domain must be hosted in `vmail.domain`.

### Do sender mismatch accounts need to be real mailboxes?

Yes. Sender mismatch permission is intentionally limited to existing hosted mailbox accounts.

### Why did a setting save but the service did not reload?

Check that the helper is installed, its root-owned JSON configuration matches
this host, and sudoers permits the PHP pool user to execute only the fixed
helper path. The UI reports helper status after each save.

## Tests

```bash
php artisan test
```
