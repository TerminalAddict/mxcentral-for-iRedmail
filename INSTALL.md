# INSTALL

These notes track the server-side setup required for `mxcentral-for-iRedmail` on an iRedMail host.

The example deployment target is:

```text
root@mail.example.com:/opt/www/mxcentral-for-iRedmail
```

Put real deployment values in the ignored top-level `Makefile.local`:

```make
# Short per-server profile name and SSH target.
HOSTNAME := mail
DEPLOY_HOST := $(HOSTNAME)
DEPLOY_PATH := /opt/www/mxcentral-for-iRedmail
APP_USER := www-data
APP_GROUP := www-data

# Per-server files kept outside the repository:
SERVER_ENV_FILE := $(HOME)/.config/mxcentral/$(HOSTNAME).env

# Applied manually; the deploy target does not execute SQL:
DATABASE_GRANTS_FILE := $(HOME)/.config/mxcentral/$(HOSTNAME).sql

# Optional, only when this host needs non-default privileged paths:
# PRIVILEGED_CONFIG_FILE := $(HOME)/.config/mxcentral/$(HOSTNAME)-helper.json
```

There are deliberately no deployable default host or path values. Use a
different `Makefile.local` or explicit environment values for each mail server.
In a Makefile, use `$(HOME)` and `$(HOSTNAME)` as shown; `~` is not guaranteed
to expand after the value is passed through the quoted deploy command.
If `SERVER_ENV_FILE` is omitted, deployment preserves that server's existing
remote `.env`. If `PRIVILEGED_CONFIG_FILE` is omitted, it preserves the
root-owned `/etc/mxcentral/privileged-helper.json`.

Create the local profile directory once:

```sh
install -d -m 0700 "$HOME/.config/mxcentral"
```

Use one matching `.env` and `.sql` filename per `HOSTNAME`. For example:

```text
~/.config/mxcentral/mail.env
~/.config/mxcentral/mail.sql
~/.config/mxcentral/ccl-mailbox.env
~/.config/mxcentral/ccl-mailbox.sql
```

The `~` notation above is descriptive. Use `$HOME` in shell commands and
`$(HOME)` in `Makefile.local`. Keep these files outside the repository and set
both environment and SQL profiles to mode `0600` because they contain live
credentials.

## Get the Code

Clone the repository onto your build/workstation with one of these options:

```sh
git clone git@github.com:TerminalAddict/mxcentral-for-iRedmail.git
```

```sh
git clone https://github.com/TerminalAddict/mxcentral-for-iRedmail.git
```

```sh
gh repo clone TerminalAddict/mxcentral-for-iRedmail
```

## Deploy App

From the repo root on the build/workstation:

```sh
make deploy
```

The deploy target:

- creates required app/cache/storage directories
- rsyncs the app to `/opt/www/mxcentral-for-iRedmail`
- preserves the remote `.env`, privileged-helper configuration, and `storage/`
  unless an explicit per-server profile is supplied
- does not deploy local `database/*.sqlite*` files
- makes application source, dependencies, configuration, and `.env` root-owned
- sets `.env` to `root:<app-group>` mode `0640`
- gives the PHP user write access only to `storage/` and `bootstrap/cache/`
- installs the root-owned privileged helper and its single-command sudo policy
- runs Laravel cache clearing and fail-closed application, database-schema, and
  privileged-helper health checks as the configured app user

For updating multiple existing installs, use the generic rsync helper:

```sh
scripts/deploy-rsync.sh paul@mail.example.com /opt/www/mxcentral-for-iRedmail
```

The helper refuses to deploy unless the remote path already looks like an
MXCentral deployment and the remote account has root or passwordless sudo.
It will not fall back to web-worker ownership because writable application code
would turn a PHP compromise into persistent code execution.

If an older checkout fails during deploy with `Database file at path
.../database/database.sqlite does not exist`, create or fix the server `.env`
so it uses non-database Laravel runtime stores:

```dotenv
SESSION_DRIVER=file
CACHE_STORE=array
QUEUE_CONNECTION=sync
MXCENTRAL_PRIVILEGED_HELPER_COMMAND="/usr/bin/sudo /usr/local/sbin/mxcentral-privileged"
MXCENTRAL_LOGIN_RATE_CACHE_STORE=file
MXCENTRAL_LOGIN_ACCOUNT_LOCK_THRESHOLD=5
MXCENTRAL_LOGIN_IP_LOCK_THRESHOLD=10
MXCENTRAL_LOGIN_LOCK_SECONDS=60
MXCENTRAL_LOGIN_MAX_LOCK_SECONDS=900
```

Then rerun `make deploy`.

## Create Server `.env` and App Key

For a new installation, create the server-local `.env` file on the mail server.
Deployment deliberately does not overwrite it unless `SERVER_ENV_FILE` names
an explicit local profile. Generate the key before making the file read-only to
PHP:

```sh
cd /opt/www/mxcentral-for-iRedmail
install -o root -g www-data -m 0640 .env.production.example .env
php artisan key:generate
chown root:www-data .env
chmod 0640 .env
```

Keep the generated `APP_KEY` stable. Optional decryptable mailbox password
storage encrypts values with this key; if the key is changed, previously stored
decryptable passwords cannot be recovered.

For an existing installation, initialize the workstation profile from the
server's current `.env` so its `APP_KEY` and server-specific values are
preserved. This example uses the `mail` SSH/profile name:

```sh
ssh mail 'sudo -n cat /opt/www/mxcentral-for-iRedmail/.env' \
  > "$HOME/.config/mxcentral/mail.env"
chmod 0600 "$HOME/.config/mxcentral/mail.env"
```

Repeat with the matching hostname/profile name for every server. Never copy one
server's `APP_KEY` or database passwords into another server's profile.

## Install the Privileged Helper

The deployment script installs these automatically. For a manual installation:

```sh
install -o root -g root -m 0755 /opt/www/mxcentral-for-iRedmail/scripts/mxcentral-privileged /usr/local/sbin/mxcentral-privileged
install -d -o root -g root -m 0755 /etc/mxcentral
install -o root -g root -m 0640 /opt/www/mxcentral-for-iRedmail/docs/privileged-helper.json /etc/mxcentral/privileged-helper.json
visudo -cf /opt/www/mxcentral-for-iRedmail/docs/sudoers.conf
install -o root -g root -m 0440 /opt/www/mxcentral-for-iRedmail/docs/sudoers.conf /etc/sudoers.d/mxcentral-for-iRedmail
```

The sudo policy grants only `/usr/local/sbin/mxcentral-privileged` with no
arguments. Requests arrive as JSON on stdin. The helper validates named
operations, rejects symlinks and hard links, uses fixed root-configured paths,
writes with `O_NOFOLLOW`, fsyncs, and atomically renames files. It also owns DKIM
key creation, mode/ownership changes, service reloads, postmap, and fail2ban
unban. Do not grant the PHP user write ACLs on `/var/lib/dkim`, Postfix, Amavis,
iRedAPD, or SOGo files.

The per-server helper JSON must list the deployed app user in `web_users` and
must set `file_sources.sogo_template` to the root-owned package template on that
server. Generic file writes are not exposed: the helper compares every proposed
file against the live/root source and refuses changes outside MXCentral's
specific managed blocks and access hooks. Related writes, validation, postmap,
and reloads run under one root lock. Their root-owned state records in
`/var/lib/mxcentral/operations` are explicitly `pending`, `applied`, or `failed`;
a failed validation or reload restores the previous files and service state.

iRedMail commonly provides versioned packages through root-owned aliases such
as `/opt/iredapd -> /opt/iRedAPD-6.1`. The helper accepts an alias only when the
alias is root-owned, its containing path is not writable by the PHP worker, and
every component of the resolved target is independently secure. The resolved
directory is still opened with `O_NOFOLLOW`. Web-worker-owned aliases and
aliases in writable directories remain rejected.

For a dedicated MXCentral PHP-FPM pool, set `APP_USER` and `APP_GROUP` during
deployment and configure nginx/FPM to use that account. The deploy script
generates the matching narrow sudoers entry.

## Required `.env` Settings

Keep the remote `.env` on the server. `make deploy` does not overwrite it.

Create separate MySQL/MariaDB identities for each schema, each with a different
strong password. Never grant MXCentral privileges on `*.*`, `FILE`,
`GRANT OPTION`, or unrelated schemas.

Review the table-level grant template, replace all placeholder passwords, then
save a separate protected copy for each server:

```sh
cp mxcentral-for-iRedmail/docs/database-grants.sql \
  "$HOME/.config/mxcentral/mail.sql"
chmod 0600 "$HOME/.config/mxcentral/mail.sql"
editor "$HOME/.config/mxcentral/mail.sql"
```

The passwords in `mail.sql` must match the five schema-specific passwords in
`mail.env`. Apply the SQL explicitly to the matching host; deployment never
executes SQL automatically:

```sh
ssh mail 'sudo -n mysql' < "$HOME/.config/mxcentral/mail.sql"
```

For another `HOSTNAME`, use its matching files and SSH target, for example
`ccl-mailbox.env`, `ccl-mailbox.sql`, and `ssh ccl-mailbox`. Do not reuse SQL
passwords between servers.

The global-admin Audit Log page reads `iredadmin.log`. Existing deployments
that previously granted insert-only access must apply this one-time upgrade
before deploying the Audit Log feature:

```sql
GRANT SELECT, INSERT ON iredadmin.log TO 'mxcentral_iredadmin'@'localhost';
FLUSH PRIVILEGES;
```

The optional commented `ALTER ON vmail.mailbox` grant is needed only if global
admins will toggle decryptable-password storage from the application.

Core paths and commands:

```dotenv
APP_NAME="mxcentral-for-iRedmail"
APP_URL=https://your-mail-host.example/mxcentral
ASSET_URL=
SESSION_DRIVER=file
CACHE_STORE=array
QUEUE_CONNECTION=sync

IREDMAIL_DB_HOST=127.0.0.1
IREDMAIL_DB_PORT=3306
VMAIL_DB_USERNAME=mxcentral_vmail
VMAIL_DB_PASSWORD=unique-vmail-secret
IREDADMIN_DB_USERNAME=mxcentral_iredadmin
IREDADMIN_DB_PASSWORD=unique-iredadmin-secret
AMAVISD_DB_USERNAME=mxcentral_amavisd
AMAVISD_DB_PASSWORD=unique-amavisd-secret
IREDAPD_DB_USERNAME=mxcentral_iredapd
IREDAPD_DB_PASSWORD=unique-iredapd-secret
FAIL2BAN_DB_USERNAME=mxcentral_fail2ban
FAIL2BAN_DB_PASSWORD=unique-fail2ban-secret

IREDAPD_SETTINGS_PATH=/opt/iredapd/settings.py

POSTFIX_MAIN_CF_PATH=/etc/postfix/main.cf
POSTFIX_SENDER_ACCESS_PATH=/etc/postfix/sender_access.pcre
POSTFIX_DISCARD_RECIPIENTS_PATH=/etc/postfix/discard_recipients
POSTFIX_STAGING_DOMAINS_PATH=/etc/postfix/mxcentral_staging_domains.pcre

AMAVISD_CONFIG_PATH=/etc/amavis/conf.d/50-user
AMAVISD_DKIM_DIRECTORY=/var/lib/dkim
AMAVISD_DKIM_SELECTOR=mxcentral
AMAVISD_DKIM_BITS=1024

IREDMAIL_SPF_SERVER_HOSTNAME=mail.example.com
IREDMAIL_SPF_SERVER_IPS=203.0.113.10
IREDMAIL_DECRYPTABLE_PASSWORD_COLUMN=decrypt-pass

SOGO_ROOT_TEMPLATE_SOURCE=
SOGO_ROOT_TEMPLATE_TARGET=/var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI/SOGoRootPage.wox
```

Executable paths, service names, file ownership/modes, and privileged target
paths are configured in the root-owned
`/etc/mxcentral/privileged-helper.json`, not in the PHP-readable `.env`.

Login rate-limit state must use a cache that persists between HTTP requests;
the local `file` store is the default. If an administrator is mistakenly
locked out, verify the request separately and run:

```sh
cd /opt/www/mxcentral-for-iRedmail
sudo -u www-data php artisan mxcentral:unlock-admin admin@example.com
# Include the source address when an IP lock also needs clearing:
sudo -u www-data php artisan mxcentral:unlock-admin admin@example.com --ip=192.0.2.10
```

Optional decryptable password storage is controlled from `system/settings`.
When enabled, the app alters `vmail.mailbox` and adds `decrypt-pass` as a
nullable encrypted text column. Only passwords created or changed after the
feature is enabled can be stored. Turning the feature off drops the column and
removes stored decryptable passwords.

Password viewing is separately disabled by default. To authorize a global
administrator, add the normalized address to
`MXCENTRAL_PASSWORD_REVEAL_ADMINS` and provide that administrator's Base32 TOTP
secret in `MXCENTRAL_PASSWORD_REVEAL_TOTP_SECRETS`, for example:

```dotenv
MXCENTRAL_PASSWORD_REVEAL_ADMINS=postmaster@example.com
MXCENTRAL_PASSWORD_REVEAL_REQUIRE_TOTP=true
MXCENTRAL_PASSWORD_REVEAL_TOTP_SECRETS='{"postmaster@example.com":"BASE32SECRET"}'
```

Every reveal requires the administrator's current password, a current TOTP
code, and a recorded purpose. It uses a short-lived, single-use token and all
authenticated responses are marked private and non-cacheable. Keep the TOTP
secret and `APP_KEY` root-readable only through the protected `.env`.

TOTP remains required by default. A deployment can explicitly rely on the
global-admin allowlist and current-password reauthentication instead:

```dotenv
MXCENTRAL_PASSWORD_REVEAL_ADMINS=postmaster@example.com
MXCENTRAL_PASSWORD_REVEAL_REQUIRE_TOTP=false
```

With this override, `MXCENTRAL_PASSWORD_REVEAL_TOTP_SECRETS` is not required.
The administrator must still be a global admin named in the allowlist, enter
their current password for every reveal, record an access purpose, and use the
short-lived single-use result. Disabling TOTP reduces protection if an
allowlisted administrator's password is compromised, so set the override only
as a deliberate per-server decision.

The configured vmail database account must therefore have `ALTER` privilege on
`vmail.mailbox` in addition to its normal mailbox read/write privileges.

Set `IREDMAIL_SPF_SERVER_HOSTNAME` and `IREDMAIL_SPF_SERVER_IPS` to the real
outbound mail hostname and public sending IPs. The DNS checker uses these
values when expanding SPF `include:`, `ip4:`, `a`, and `mx` mechanisms.

For example:

```dotenv
IREDMAIL_SPF_SERVER_HOSTNAME=mail.example.com
IREDMAIL_SPF_SERVER_IPS=203.0.113.10
```

If a hosted domain sends DMARC aggregate or forensic reports to another
domain, publish the external report authorization TXT record at:

```text
<hosted-domain>._report._dmarc.<report-destination-domain> TXT "v=DMARC1"
```

Mail delivery for app notifications, using the local mail server with SMTP AUTH:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=127.0.0.1
MAIL_PORT=587
MAIL_USERNAME=no-reply@example.com
MAIL_PASSWORD=change-me
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

If using local unauthenticated SMTP on port 25, first allow the sender and/or `127.0.0.1` in **System Settings -> Send Without SMTP Auth**, then use:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=25
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

After editing `.env`:

```sh
cd /opt/www/mxcentral-for-iRedmail
sudo -u www-data php artisan optimize:clear
```

## Nginx Subdirectory

For a `/mxcentral` subdirectory deployment, configure nginx to route `/mxcentral` to:

```text
/opt/www/mxcentral-for-iRedmail/public
```

PHP scripts must be executed via PHP-FPM, and Laravel must receive requests through `public/index.php`.

The app `.env` must use:

```dotenv
APP_URL=https://your-mail-host.example/mxcentral
```

Render the nginx template with this server's explicit deployment path, then
install the rendered file:

```sh
/opt/www/mxcentral-for-iRedmail/scripts/render-nginx-template.sh \
  /opt/www/mxcentral-for-iRedmail/public \
  /etc/nginx/templates/mxcentral.tmpl
```

Then include it from the active iRedMail nginx server block, before any broad
PHP catchall include:

```sh
editor /etc/nginx/sites-enabled/00-default-ssl.conf
```

The server block should look similar to this:

```nginx
server {
    listen 443 ssl http2;
    #listen [::]:443 ssl http2;
    server_name _;

    root /var/www/html;
    index index.php index.html;

    include /etc/nginx/templates/misc.tmpl;
    include /etc/nginx/templates/ssl.tmpl;
    include /etc/nginx/templates/iredadmin.tmpl;
    include /etc/nginx/templates/roundcube.tmpl;
    include /etc/nginx/templates/sogo.tmpl;
    include /etc/nginx/templates/netdata.tmpl;
    include /etc/nginx/templates/mxcentral.tmpl;
    include /etc/nginx/templates/php-catchall.tmpl;
    include /etc/nginx/templates/stub_status.tmpl;
}
```

The provided file contains `location` blocks, so do not install it directly as
`/etc/nginx/sites-available/mxcentral.conf` unless you wrap it inside a valid
nginx `server { ... }` block.

Test and reload nginx:

```sh
nginx -t
systemctl reload nginx
```

## Cron

Install one cron entry on the mail server:

```cron
* * * * * MXCENTRAL_CRON_USER=www-data MXCENTRAL_SUDO_PATH=/usr/bin/sudo /usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php >/dev/null 2>&1
```

Set the user and executable paths explicitly for each server. If invoked as
root, the runner refuses to launch Artisan until `MXCENTRAL_CRON_USER` names an
existing non-root application user.

List scheduled tasks:

```sh
/usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php --list
```

Force quarantine notification run:

```sh
/usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php --task=quarantine-notifications --force
```

Test quarantine notifications without sending:

```sh
cd /opt/www/mxcentral-for-iRedmail
sudo -u www-data php artisan quarantine:notify-recipients --dry-run
```

## Feature-Specific Server Changes

### DKIM

The app generates DKIM keys under `/var/lib/dkim`, writes amavisd config at `/etc/amavis/conf.d/50-user`, and restarts amavisd.
Generating or rotating a DKIM key always restarts amavisd because amavis may
keep the previous private key in memory. Deleting a domain removes the
mxcentral-managed DKIM config entry, deletes that domain's DKIM key files, and
restarts amavisd before the domain record is removed.

If generated key ownership or mode must be fixed, the app runs:

```sh
sudo chown amavis:amavis /var/lib/dkim/example.com.pem
sudo chmod 0400 /var/lib/dkim/example.com.pem
```

### Sender Mismatch

The app edits `/opt/iredapd/settings.py`, enables iRedAPD plugin `reject_sender_login_mismatch`, writes `ALLOWED_LOGIN_MISMATCH_SENDERS`, removes Postfix `reject_sender_login_mismatch` from `smtpd_sender_restrictions`, then restarts iRedAPD and reloads Postfix.

### Send Without SMTP Auth

The app edits `/opt/iredapd/settings.py`, writes `ALLOWED_FORGED_SENDERS` and `MYNETWORKS`, manages `/etc/postfix/sender_access.pcre`, ensures `main.cf` has:

```text
check_sender_access pcre:/etc/postfix/sender_access.pcre
```

Then it restarts iRedAPD and reloads Postfix.

The UI accepts exact IPs and IPv4 CIDRs. Examples:

```text
192.168.1.1     -> /^192\.168\.1\.1$/ OK
192.168.2.0/24  -> /^192\.168\.2\./ OK
172.16.0.0/16   -> /^172\.16\./ OK
103.123.164.0/22 -> generated PCRE matching 103.123.164.0 through 103.123.167.255
```

### Discard Recipients

When the discard form is saved, the app writes
`/etc/postfix/discard_recipients`, ensures `main.cf` contains:

```text
check_recipient_access hash:/etc/postfix/discard_recipients
```

It then runs postmap and reloads Postfix through the privileged helper. No
separate manual `main.cf`, postmap, or reload step is required after the helper
has been installed.

### Staging Domains

Domain staging is controlled from `/domains`. MXCentral creates and maintains
`/etc/postfix/mxcentral_staging_domains.pcre`, installs its recipient restriction
before the discard map, and reloads Postfix through the privileged helper.

The map returns a temporary SMTP 450 response for staged primary and alias
domains. The corresponding `vmail.domain` and `vmail.mailbox` records remain
active so administrators can create accounts, authenticate, and migrate mail.
Switching to **Accepting mail** removes the domain patterns and reloads Postfix;
it does not change public DNS.

The PHP worker has no write access to `/etc/postfix`. The staging PCRE map does
not use postmap; all writes and reloads are brokered by the helper.

### Backup MX

For MySQL-backed iRedMail domains, enabling Backup MX sets:

```sql
backupmx = 1
transport = 'relay:[PRIMARY_MX_IP]:25'
```

### Alias Domains

Alias domains are rows in `vmail.alias_domain`:

```sql
INSERT INTO alias_domain (alias_domain, target_domain) VALUES ('domain.ltd', 'example.com');
```

### Catch-All

Catch-all destinations are rows in `vmail.forwardings` where `address` is the domain name:

```sql
INSERT INTO forwardings (address, forwarding, domain, dest_domain)
VALUES ('domain.com', 'dest@example.com', 'domain.com', 'example.com');
```

The destination must be an existing mailbox.

## Post-Install Checks

```sh
cd /opt/www/mxcentral-for-iRedmail
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan mxcentral:check-production
sudo -u www-data php artisan route:list >/dev/null
sudo -u www-data php artisan quarantine:notify-recipients --dry-run
```

Confirm the PHP worker cannot write application code, `.env`, or service
configuration; privileged changes must go through the root helper:

```sh
sudo -u www-data test ! -w app
sudo -u www-data test ! -w .env
sudo -u www-data test ! -w /opt/iredapd/settings.py
sudo -u www-data test ! -w /etc/amavis/conf.d/50-user
sudo -u www-data test ! -w /etc/postfix/main.cf
```
