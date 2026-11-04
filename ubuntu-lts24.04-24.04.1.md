# Ubuntu LTS upgrade: mail-server recovery and second-server checklist

Repair date: 30 September 2026 (Pacific/Auckland).

> This filename was requested as `ubuntu-lts24.04-24.04.1.md`. However, the
> server reported **Ubuntu 26.04.1 LTS** after the upgrade. This records the
> actual Dovecot 2.3-to-2.4 repair, not a verified Ubuntu 24.04-to-24.04.1
> upgrade procedure. The pre-upgrade Ubuntu release was not established.

This document combines the repair histories on `mail` and `ccl-mailbox` with
a checklist for another server. Earlier sections describe intermediate failures; they are
not the final status. At completion, Dovecot was running, both web login
pages loaded, and MXCentral's production health check passed. Authenticated
user testing remains outstanding. The later DKIM review found missing DNS
records, documented below; these were not changed.

For another server, start with [the second-server checklist](#second-server-checklist),
then use the detailed repair sections as references. This is a runbook, not
an automated migration or an instruction to duplicate `mail`'s configuration.
The subsequent [ccl-mailbox repair record](#repair-record-ccl-mailbox) documents
its additional settings and independently verified results.

## Scope and cause

The first repair was performed on `ssh root@mail` (`mail.netent.co.nz`). No
changes were made to `ccl-mailbox` during that first repair. Its separately
requested repair is recorded at the end of this document.

The upgrade replaced Dovecot 2.3.21 with Dovecot 2.4.2
(`1:2.4.2+dfsg1-3ubuntu2.1`). The existing iRedMail configuration used the
incompatible 2.3 syntax, preventing Dovecot from starting:

```text
The first setting must be dovecot_config_version
```

Adding that setting alone would not have fixed the remaining syntax changes
and removed plugins. Postfix and MariaDB were running; Dovecot needed a
configuration migration.

## Backup taken before changes

A root-only backup directory was created on `mail`:

```text
/root/dovecot-pre24-UNgjrpzm/
  dovecot/             Original /etc/dovecot, including SQL configuration
  sieve/               Original global Sieve source and compiled binary
  migrate.py           One-off conversion script
  verify.py            Checks which do not deliver test mail
  check-delivery.py    Local test-message delivery check
  dovecot-24.svbin      Recompiled global Sieve binary
  REPAIR-NOTES.txt      Server-side repair notes
```

The directory is owned by root with mode `0700`. It contains database
credentials in the original configuration: do not publish it or add it to Git.
The user's separate whole-system backup was not modified.

## Changes applied

A separate `/etc/dovecot/dovecot-24-candidate.conf` was generated and checked
with the installed Dovecot parser before replacing the live configuration.
The main configuration was installed via a temporary file and rename.

### Configuration and authentication

- Added `dovecot_config_version = 2.4.2` as the first setting.
- Set `dovecot_storage_version = 2.3.21` to retain that storage compatibility
  setting; this is not a guarantee that a complete system downgrade is safe.
- Converted SQL authentication to named `passdb` and `userdb` blocks and
  native MySQL settings. Existing credentials and account eligibility checks
  were retained.
- Converted single-letter variable expansions to the 2.4 syntax, including
  authentication queries, shared mailbox paths and POP3 UIDL formatting.
- Replaced `mail_location` with `mail_driver`, `mail_path` and
  `mail_index_path`; updated the corresponding SQL user lookup fields.
- Converted TLS certificate/key/DH settings, cipher preference,
  cleartext-authentication settings, listener settings and service restart
  limits. TLS remained required with a minimum of TLS 1.2.
- Converted protocol plugin lists and the master-user passdb configuration.
- Converted the delivery log format to retain the previous diagnostic fields.

### Quotas, sharing and activity tracking

- Replaced the removed dictionary quota driver with the `count` driver.
- Enabled `quota_clone` to continue reporting usage to `vmail.used_quota`.
- Converted SQL dictionaries for quotas, shared-folder ACLs and last-login
  tracking to the 2.4 format.
- Converted the 85%, 90%, 95% and 100% quota warnings and retained the quota
  policy service.
- Preserved the previous 10% quota grace by returning its byte value from
  the SQL user lookup; the new grace setting does not accept the old
  percentage syntax.
- Converted the shared namespace and ACL dictionary configuration.

No database schema changes, password resets, mailbox deletions or mailbox
relocations were performed. Normal delivery and quota checks updated their
existing database records.

### Sieve and special folders

- Converted personal, global and before-delivery Sieve settings to named
  `sieve_script` blocks.
- Compiled the global `/var/vmail/sieve/dovecot.sieve` with the installed 2.4
  `sievec`, then installed the resulting `dovecot.svbin` as `vmail:vmail`,
  mode `0400`.
- Removed the unavailable `mailbox_alias` plugin. Existing `Sent`,
  `Sent Messages` and `Sent Items` special-use definitions were retained.

**Behaviour change:** alternate Sent-folder names are no longer forced to
refer to the same underlying folder. Existing mail was not moved. Clients
should be checked for their configured Sent folder.

### File permissions and Postfix delivery

| Live file | Ownership | Mode |
| --- | --- | --- |
| `/etc/dovecot/dovecot.conf` | `root:root` | `0644` |
| `/etc/dovecot/mxcentral-24-auth.conf` | `root:root` | `0600` |
| `/etc/dovecot/mxcentral-24-dicts.conf` | `root:root` | `0600` |
| `/var/vmail/sieve/dovecot.svbin` | `vmail:vmail` | `0400` |

The main file contains no embedded database passwords. The restricted SQL
files use `!include_try`: Dovecot's root process reads them, while the
unprivileged delivery executable can skip them and parse the main file.

This matters because this server's Postfix `virtual_transport` is `dovecot`,
using `/usr/lib/dovecot/deliver` as `vmail:vmail`. Testing LMTP alone would
not verify that actual delivery path. The main file was checked as `vmail`
as well as root, and a real delivery through that executable was tested.
Postfix's transport configuration was not changed.

Dovecot was restarted after the initial conversion and reloaded after final
configuration adjustments.

## Verification results

The following checks passed at completion:

- Configuration parsing as root and as `vmail`.
- Dovecot running and enabled at boot; Postfix and MariaDB also active.
- SQL user and password-record lookups for all 10 active mailbox accounts.
- IMAPS on port 993 and POP3S on port 995, using TLS 1.3 with certificate
  verification for `mail.netent.co.nz`.
- LMTP greeting and local-recipient acceptance on port 24. That check used
  `RSET` without sending a message.
- Quota policy response on port 12340, mailbox listing and quota reporting.
- A real message delivered through the `vmail`-owned Dovecot LDA and Sieve
  into `paul@golive.co.nz`'s INBOX.
- SQL quota and last-delivery tracking updated after that delivery.
- Empty Postfix queue and no Dovecot error-priority journal entries during
  the final check.

The diagnostic message was left in the mailbox with subject:

```text
Dovecot upgrade repair: local delivery verification
```

It may be deleted by the mailbox owner.

**Verification limits:** no actual user-password login or end-to-end
external mail delivery was performed. Database password-record lookup is
not the same as authenticating with a user's password. Shared-folder access
and every user's personal Sieve rules were not individually exercised.
A mail-client login and a normal send/receive test remain recommended.

## Follow-up checks

Run on `mail` as root:

```sh
doveconf -n >/dev/null
runuser -u vmail -- doveconf -n >/dev/null
systemctl is-active dovecot postfix mariadb
systemctl is-enabled dovecot
postqueue -p
journalctl -u dovecot --since '10 minutes ago' --no-pager
python3 /root/dovecot-pre24-UNgjrpzm/verify.py
```

Do not publish an unreviewed configuration dump; it may contain sensitive
settings. The `verify.py` checks use this server's hostname and a known local
recipient and are not a generic checker for other servers.

Running `check-delivery.py` again sends another diagnostic email. Do not
rerun `migrate.py` casually: it regenerates the candidate and SQL includes
from the **original backup**, not from any later configuration edits.

## Rollback and future upgrades

Restoring the old configuration alone will not make Dovecot 2.4 start.
A full rollback needs the matching pre-upgrade packages or system backup
and configuration. Restoring old mail data can lose messages received since
that backup; preserve newer mail and database changes when planning recovery.

For other servers, first identify the actual Ubuntu and Dovecot versions,
back up configuration and mail data, and review the server-specific SQL,
plugins, delivery transport and paths. Do not copy this server's credentials
or run its one-off converter unchanged elsewhere.

This repair changed the live server configuration; it did not add a generic
MXCentral deployment migration. Review future iRedMail/MXCentral upgrades
before allowing them to overwrite the migrated Dovecot files.

Reference: [Dovecot's official 2.3-to-2.4 upgrade guide](https://doc.dovecot.org/2.4.2/installation/upgrade/2.3-to-2.4.html).

## Follow-up: MXCentral and SOGo web outage

On the same day, both web applications were checked after the user reported
that they would not load. Nginx was active and its configuration passed
`nginx -t`; HTTP 502 responses came from unavailable application backends.

Findings:

- Nginx's PHP upstream still used `127.0.0.1:9999`. The upgrade removed
  PHP 8.3 FPM and installed PHP 8.5 FPM with its default Unix socket instead
  of the existing iRedMail pool configuration.
- `/etc/nginx/templates/mxcentral.tmpl` contained unresolved
  `@@MXCENTRAL_PUBLIC_PATH@@` placeholders. This produced `Primary script
  unknown` once PHP connectivity was restored. The inspection did not
  establish when that template was installed.
- SOGo was removed during the upgrade. Its configuration and state directory
  remained, but there was no SOGo service or listener on port 20000.
- With PHP and the Nginx path repaired, MXCentral returned HTTP 503 because
  its production health check correctly rejected the missing SOGo template
  `/usr/lib/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox`.

Before changes, `/etc/nginx`, `/etc/php`, `/etc/sogo`, APT source definitions
and `/var/lib/sogo` were copied into the root-only directory:

```text
/root/web-post-upgrade-LAyD5ICj/
```

Repairs completed:

1. Installed the retained `/etc/php/8.3/fpm/pool.d/www.conf` as the PHP 8.5
   pool configuration, restoring its loopback-only port 9999 listener and
   previous pool settings. `php-fpm8.5 -t` passed, then PHP FPM was restarted.
2. Replaced the active Nginx template's path placeholders with the verified
   `/opt/www/mxcentral-for-iRedmail/public` path. `nginx -t` passed, then
   Nginx was reloaded. This was a server-side configuration repair, not a
   change to the repository's reusable deployment template.

Status at this stage: PHP and Nginx are running, but **the web applications
are not yet restored**. MXCentral returns 503 due to its missing-SOGo health
check; SOGo returns 502 because the service is absent. The health check was
not disabled or bypassed.

SOGo package selection requires confirmation before continuing. The
previously installed package was `5.12.10.20260831-1` from the upstream
nightly repository for Ubuntu Noble. That repository was disabled by the
release upgrade, and its published distribution list did not include
Resolute. The configured Ubuntu 26.04 repositories offered
`5.12.4-1.2ubuntu0.1~esm1` as the candidate. An installation simulation
proposed 11 new packages and no removals; no SOGo packages were installed.
Using the distribution package would be a lower upstream version, so data
compatibility and a fresh SOGo database backup must be considered before
installation. Packages for another Ubuntu release were not enabled.

## SOGo restoration completed with Ubuntu packages

The user subsequently approved replacing the removed upstream nightly with
Ubuntu's supported package. The following work was completed on `mail` on
30 September 2026. `ccl-mailbox` was not changed.

### Package choice and compatibility

Installed `sogo` and `sogo-common` version
`5.12.4-1.2ubuntu0.1~esm1` from the configured Ubuntu Pro ESM Apps repository
for Resolute. This is a lower upstream version than the former
`5.12.10.20260831-1`, although it contains Ubuntu-backported security fixes.
Its packaged changelog lists specific fixes; this must not be interpreted as
a guarantee that every fix in newer upstream releases has been backported.
Continued ESM updates depend on this server's Ubuntu Pro configuration.

The old upstream nightly source remains disabled. It targets Noble, and its
then-current `5.12.11.20260929-1` required `libgnustep-base1.29`, which was
not available from the configured Resolute repositories. Resolute supplies
`libgnustep-base1.31`. No Noble system libraries or cross-release package
sources were added.

APT's installation simulation proposed 11 new packages and no removals.
The installation completed with that package set. No `apt autoremove` was
performed and no database migration or schema-conversion script was run.

Downgrade compatibility was not assumed from the version number. Recovery
copies were taken first, and startup, page rendering and an application-level
export of existing user data were checked afterward. These checks do not
constitute a guarantee of every authenticated feature's compatibility.

### Fresh backups

Before installation, a new root-only directory was created:

```text
/root/sogo-ubuntu-restore-ZIwnsdAn/
  sogo/                         Retained /etc/sogo configuration
    GNUstep/                    Retained /var/lib/sogo/GNUstep state
  sogo.sql                      Full pre-install SOGo database dump
  privileged-helper.json        Helper configuration before path changes
  mxcentral.env                 Application environment before path changes
  sogo.tmpl                     Nginx template before path changes
  sogo-subdomain.tmpl            Alternate Nginx template before path changes
  ubuntu-sogo-paths.py           One-off path adjustment script
  application-export/           Post-install SOGo application backup
  sogo-tool-backup-check.log     Application backup check output
```

The database dump was generated with:

```sh
mariadb-dump --single-transaction --routines --events --triggers --hex-blob \
  --databases sogo > /root/sogo-ubuntu-restore-ZIwnsdAn/sogo.sql
```

It completed successfully and was nonempty (40,586 bytes, mode `0600`,
owned by root). The database contained 11 tables before installation.
Configuration and database backups contain secrets and personal data and
must remain on the server, outside Git.

### Installation and existing configuration

The package was installed noninteractively while retaining the existing
modified configuration:

```sh
DEBIAN_FRONTEND=noninteractive apt-get -y \
  -o Dpkg::Options::=--force-confold install sogo
```

The package installation started and enabled `sogo.service`. A byte-for-byte
comparison confirmed `/etc/sogo/sogo.conf` was unchanged from the backup.
SOGo listens on `127.0.0.1:20000`, behind the existing Nginx proxy.
Memcached remained active. Existing IMAP, SMTP and Sieve connection settings
were retained.

### Ubuntu package paths

The Ubuntu package layout differs from the upstream nightly layout. The
following explicit paths were applied rather than creating compatibility
symlinks or relaxing the privileged helper's security checks:

| Purpose | Ubuntu package path |
| --- | --- |
| Original login template | `/usr/share/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox` |
| Static web resources | `/usr/share/GNUstep/SOGo/WebServerResources/` |
| Architecture-specific bundles | `/usr/lib/x86_64-linux-gnu/GNUstep/SOGo/` |

Changes made:

- Set `file_sources.sogo_template` in
  `/etc/mxcentral/privileged-helper.json` to the original login template above.
- Set `SOGO_ROOT_TEMPLATE_SOURCE` in
  `/opt/www/mxcentral-for-iRedmail/.env` to the same path.
- Applied the same environment setting to the deployment machine's
  `~/.config/mxcentral/mail.env` so subsequent deployments retain it.
- Left the custom template target at
  `/var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI/SOGoRootPage.wox`;
  retained existing custom files rather than replacing them with defaults.
- Updated resource aliases in `/etc/nginx/templates/sogo.tmpl` and
  `/etc/nginx/templates/sogo-subdomain.tmpl` to the package paths above.
- Validated Nginx, reloaded it, and rebuilt Laravel's configuration cache
  as `www-data`.

The path-editing script saved originals before replacement and retained their
ownership and modes. It is a one-off repair script, not a reusable deployment
migration; do not rerun it casually and overwrite the original backups.

### Final checks and outcome

These checks passed after installation and path corrections:

- `https://mail.netent.co.nz/mxcentral/` redirects to `/mxcentral/login`,
  which returns HTTP 200.
- `https://mail.netent.co.nz/SOGo/` returns HTTP 200, including the full login
  page for a browser user agent.
- All 15 same-host static assets referenced by that SOGo login page returned
  HTTP 200 with the expected CSS, JavaScript or icon content types.
- `php artisan mxcentral:check-production`, executed as `www-data` from the
  application directory, reported that production safety checks passed.
  The health check was not bypassed.
- SOGo, Nginx, PHP 8.5 FPM and Dovecot were active; SOGo was enabled at boot.
- `systemctl --failed` showed no failed units; `dpkg --audit` reported no
  incomplete package installations.
- `sogo-tool backup <private-temporary-directory> ALL`, run as `sogo`, exited
  successfully and produced seven files from the existing application data.
  That export was moved into the root-only backup directory and made
  root-owned. This exercised the installed SOGo application's database
  access without changing passwords or requiring a user login.

SOGo logged a template-builder warning while successfully rendering the login
page, and its backup tool emitted a GNUstep regular-expression implementation
warning. Neither prevented the above checks. Nginx also retains nonfatal
warnings about the deprecated `listen ... http2` syntax.

**Still requires user testing:** sign in to MXCentral and SOGo, open a mailbox,
send and receive a message, and check calendars, contacts, personal Sieve
rules and any ActiveSync clients in use. No user-password login was performed
by the repair process. Existing custom branding was preserved but its edit
and reset workflows were not exercised.

### Recovery and future deployment notes

The original configuration and pre-install SQL dump remain available if
authenticated testing reveals a downgrade incompatibility. Stop SOGo and
preserve any newer data before planning a restore. Do not import the old
dump into a live database casually: it can overwrite changes made since the
backup. Returning to the former nightly also requires a compatible operating
system/runtime, not merely reinstating the Noble repository on Resolute.

Keep the explicit source-template setting in `~/.config/mxcentral/mail.env`.
Nginx's package-specific resource paths were repaired on the server, not in
the repository's reusable templates. A later deployment or iRedMail upgrade
that overwrites those files must preserve/render the correct paths and be
followed by the HTTP, asset and production-health checks above. Bundle paths
are architecture-specific; do not copy this server's `x86_64-linux-gnu` path
unconditionally to other servers.

## Follow-up: SOGo branding verification

A subsequent read-only check confirmed that the live SOGo login page still
renders the existing Net Enterprises logo from the custom template. Both
the application and privileged helper reference the Ubuntu package's original
template for restoring defaults. MXCentral's production health check passed
again. No branding save or reset was performed during that check.

The expected workflow is unchanged: a global administrator uses System /
Settings / SOGo Branding to select a custom or original logo and custom or
original colours independently. The application writes the custom template
through its privileged helper and restarts SOGo when content changes.
After migration, explicitly test save and reset with the owner's permission;
reading the settings page and seeing the existing logo do not prove those
write operations work end to end.

## Follow-up: DKIM review

DKIM is handled by Amavis and Postfix, not by SOGo's branding templates.
A read-only review on `mail` found:

| Check | Result on `mail` |
| --- | --- |
| Amavis and Postfix services | Active |
| Authenticated submission on ports 587 and 465 | Routed to Amavis port 10026 |
| Local Postfix pickup | Routed to Amavis port 10026 |
| Amavis originating policy | DKIM signing enabled |
| Signing after the OS upgrade | Logs contain `dkim_new=dkim:relay.netent.co.nz` |
| `mxcentral._domainkey.golive.co.nz` | `amavisd testkeys` passed |
| `dkim._domainkey.relay.netent.co.nz` | Public key unavailable; DNS returned NXDOMAIN |
| `dkim._domainkey.smtp.centralcomms.nz` | Public key unavailable; DNS returned NXDOMAIN |

Key tests were run as root and as `amavis`. The private keys were present
and accessible to their service account. No keys, signing rules or DNS
records were changed. The review did not establish when the DNS records
became absent, so this is not attributed to the Ubuntu upgrade.

The configured catch-all signing rule uses `relay.netent.co.nz`. Adding a
signature is not the same as recipients successfully verifying it: the
corresponding selector's public key must be published in DNS. Also, a
passing `testkeys` result does not prove that a particular sender selects
that key. Check an actual outbound message's `DKIM-Signature` domain and
selector, receiving-side verification, and alignment with its From domain.

On another server, use its own domains and selectors:

```sh
systemctl is-active amavis postfix
postconf content_filter smtpd_milters non_smtpd_milters
postconf -M
runuser -u amavis -- amavisd testkeys
```

Use `dig TXT SELECTOR._domainkey.DOMAIN` for each configured signing key.
If a record is missing, export only the **public** DNS record (for example
with `amavisd showkeys`) for review and publication by the DNS administrator.
Never publish private `.pem` files. Do not regenerate a working private key
merely because its public DNS record is absent.

## Second-server checklist

### 1. Identify the server and capture its current state

Confirm the intended SSH target explicitly. Do not assume the other server
has the same OS, package versions, paths, PHP pool user or mail transport.
Record the following before making changes:

```sh
hostname -f
cat /etc/os-release
dovecot --version
php -v
systemctl --failed --no-pager
systemctl list-unit-files --no-pager
dpkg-query -W 'dovecot*' 'php*-fpm' 'sogo*' nginx
apt-cache policy sogo sogo-common
nginx -t
postconf virtual_transport mailbox_transport content_filter
postconf -M
postqueue -p
df -h
```

Inspect the active Nginx includes and upstreams, service logs, and APT source
`Suites`/`Enabled` fields. Keep configuration dumps private: SQL connection
strings, proxy credentials and environment files can contain secrets.
Use the actually installed Dovecot version to decide whether 2.4 migration
is needed; do not apply it to a still-working 2.3 installation.

### 2. Establish recoverable backups

Take a whole-system backup or snapshot and confirm the recovery procedure.
In addition, create a fresh root-only repair directory on that server and
save separately named copies of:

- `/etc/dovecot`, `/etc/nginx`, `/etc/php`, `/etc/sogo` and `/var/lib/sogo`.
- `/etc/postfix`, `/etc/amavis`, `/var/lib/dkim` and APT source definitions.
- `/etc/mxcentral/privileged-helper.json` and the deployed application's `.env`.
- Relevant global Sieve sources and binaries, service overrides, and package
  version information.
- Consistent database dumps, including SOGo before changing its version.

Do not merge identically named source directories unintentionally; use names
such as `etc-sogo` and `var-lib-sogo`. Verify dump completion and nonempty
output. These extra configuration/database copies do not replace a mail-data
backup. Use the site's backup procedure for nontransactional tables and any
concurrent database writes. Do not stop or drain mail services without a
maintenance/recovery plan for incoming messages.

### 3. Restore Dovecot if its major version changed

Use the Dovecot migration sections above and the official guide for the
installed version. Build a candidate from **that server's** original config.
Preserve its credentials, mailbox paths, UID/GID, master users, TLS files,
enabled protocols, account restrictions and delivery integration.

Important conversion details from this repair:

| Old setting/feature | Conversion used on `mail` |
| --- | --- |
| Missing version declarations | Explicit config and storage compatibility versions |
| `plugin { ... }` | Settings moved out of the obsolete block |
| `ssl_cert`, `ssl_key`, `ssl_dh` with leading `<` | `ssl_server_*_file` paths without `<` |
| `ssl_prefer_server_ciphers = yes` | `ssl_server_prefer_ciphers = server` |
| `disable_plaintext_auth = yes` | `auth_allow_cleartext = no` |
| `mail_location` and SQL `mail` field | Separate driver, path and index settings |
| Old SQL include formats | Named passdb/userdb and native MySQL settings |
| `%u`, `%s`, `%Lc` | `%{user}`, `%{protocol}`, `%{secured}` as appropriate |
| Dictionary quota | Count quota plus SQL `quota_clone` reporting |
| SQL `quota_rule` | Byte-valued `quota_storage_size` |
| Percentage quota grace | SQL-calculated byte-valued `quota_storage_grace` |
| `$mail_plugins` inheritance | Explicit full required plugin list per protocol |
| Anonymous listeners and `address` | Named listeners with `listen` |
| `service_count` | `service_restart_request_count` |
| Old stats FIFO | Removed; retained supported stats listeners |
| Old Sieve path settings | Named personal, before and global script blocks |
| `mailbox_alias` | Removed; preserve special-use folders and review client behaviour |

Do not blindly replace variables inside unrelated content or assume SQL
column names match. Preserve the original POP3 UIDL representation to avoid
clients downloading old messages again. Check effective per-protocol plugin
lists, not just whether the parser accepts the file.

Validate the candidate before activation, then check the configuration as
the delivery user if Postfix uses LDA. Compile global Sieve using the new
version before installing its binary. Keep SQL includes restricted. Confirm
whether `!include_try` is necessary for an unprivileged LDA, as on `mail`;
do not make database credentials world-readable to fix a permission error.

Restart/reload only after validation. Test user lookup, TLS, quota reporting,
Sieve and **the configured delivery transport**, not merely any available
listener. Use an agreed local mailbox for a labelled test message and check
its rules first to avoid unintentionally forwarding diagnostic mail elsewhere.

The conversion and verification scripts listed in the first backup section
remain on `mail`; they are not checked into this repository. They contain
hardcoded backup paths, mailbox assumptions and test recipients. Review and
adapt them to a second server rather than executing them unchanged or copying
the generated configuration, which contains `mail`'s credentials.

### 4. Restore PHP and Nginx integration

Compare the old and new PHP FPM pool configurations. Preserve the intended
web user, loopback/socket listener, permissions, pool sizing and logging;
also review PHP extensions and relevant `php.ini` differences. Validate
with the installed version's `php-fpmX.Y -t` before restarting it.

Inspect the active MXCentral Nginx template for unresolved
`@@MXCENTRAL_PUBLIC_PATH@@` tokens. Use the verified public directory on that
server. The repository's `scripts/render-nginx-template.sh` takes an absolute
public directory and an absolute output filename; run it from the source
checkout into a review file, then install the reviewed output. Do not install
the unrendered template or assume the flattened deployed directory has the
same layout as the source checkout.

Run `nginx -t` before reloading. Follow redirects when testing the URL:
HTTP 301 from `/mxcentral` does not establish that `/mxcentral/login` works.
Distinguish a missing backend (502), invalid script path (404) and an
application health-check failure (503).

### 5. Restore SOGo using compatible packages

Check whether SOGo is installed, whether its service exists, and whether the
expected local port is listening. If the upgrade disabled a third-party
source, inspect its target Ubuntu suite and dependencies before re-enabling
it. Do not substitute an old suite's libraries to force installation.

Recheck candidate versions and security coverage at the time of the repair;
the versions in this document are historical observations, not pins for
future deployments. Simulate installation before proceeding. If choosing
an older upstream version, obtain the owner's approval and back up the
database before installing it. Preserve the existing SOGo configuration.

Discover the package's template, resource and bundle paths with
`dpkg -L sogo sogo-common`. Apply the actual paths to:

1. Nginx's active SOGo resource aliases and any corresponding alternate templates.
2. `file_sources.sogo_template` in the root-owned privileged-helper JSON.
3. `SOGO_ROOT_TEMPLATE_SOURCE` in the deployed application environment.
4. The second server's local `~/.config/mxcentral/<HOSTNAME>.env` profile.

Keep the custom branding target separate from the package's original
template. Rebuild Laravel's config cache using that deployment's application
user, validate/reload Nginx and run `php artisan mxcentral:check-production`.
Keep security checks enabled; fix the reported path/permission mismatch
instead of bypassing it.

If deployment uses `PRIVILEGED_CONFIG_FILE`, update that server-specific
local JSON too, otherwise a later deployment can overwrite the corrected
server copy. Without that option, the current deployment script preserves
an existing remote helper configuration. The `.env` setting alone does not
rewrite the helper JSON. Do not copy `mail.env`, its SQL grants or credentials
over the second server's profile. No new SQL grants were required for the
repair on `mail`.

### 6. Verify functionality and record the handoff

- [ ] Dovecot, Postfix, Amavis, PHP, Nginx, SOGo and required supporting services
  are active; intended services are enabled at boot.
- [ ] Package configuration is complete and no relevant services have failed.
- [ ] Real mailbox lookups, TLS connections, quota and actual local delivery work.
- [ ] Both web login pages load, and their CSS, JavaScript and images load too.
- [ ] MXCentral's production health check passes as the real application user.
- [ ] Existing branding renders; approved logo/colour save and reset tests pass.
- [ ] SOGo can read existing application data; authenticated mail, calendars,
  contacts, Sieve and mobile-client workflows are tested by the owner.
- [ ] DKIM key/DNS checks pass for the required domains; a receiving mailbox
  verifies a real outbound signature and expected sender alignment.
- [ ] The mail queue is checked for unexplained deferred messages.
- [ ] Backups, changed files, package versions, commands, warnings and any
  untested workflows are recorded separately for this server.
- [ ] Per-host deployment settings match the repaired server, with no secrets
  committed to Git.

Do not mark unchecked authenticated or DNS tests as passed simply because
a service is active. Recovery must preserve any mail and application data
created after the backup. This checklist does not itself perform any changes;
each repair must be performed against the target server's own state.

## Repair record: ccl-mailbox

On 30 September 2026, the user separately requested repair of
`ssh root@ccl-mailbox` (`mailbox.centralcomms.nz`). The server was inspected
independently before changes. Nothing was deployed or copied from `mail`'s
generated configuration or credentials.

### Findings specific to this server

- Ubuntu reported 26.04.1; Dovecot was 2.4.2 and failed on the old
  configuration's missing initial version declaration.
- Dovecot listened on IPv4 **and IPv6**, unlike the inspected `mail` setup.
- IMAPSieve spam/ham reporting was configured, with `imap_sieve`,
  `sieve_imapsieve`, `sieve_extprograms`, and an `imapsieve_copy` pipe script.
- `/etc/dovecot/iredmail/99-mail-attributes.conf` contained the old
  `mail_attribute_dict = file:%Lh/dovecot-attributes` setting.
- There were six active mailbox accounts. Postfix used the Dovecot LDA for
  virtual delivery, not LMTP.
- PHP 8.3 FPM had been removed; PHP 8.5 FPM used its default socket instead
  of the existing port-9999 pool configuration.
- Nginx was running and the MXCentral public path was **already correct**;
  no placeholder repair was needed on this server.
- SOGo had been removed, and its Noble nightly repository was disabled.
  The Ubuntu candidate was again `5.12.4-1.2ubuntu0.1~esm1`.
- Six local cron messages were deferred with earlier Amavis connection
  refusals. At inspection Amavis was already running and listening on the
  expected ports; it did not need a configuration change.
- The two configured DKIM keys passed their DNS checks, unlike the missing
  records found on `mail`.

### Independent backup

Before configuration changes, a root-only backup was created at:

```text
/root/lts-recovery-CaSRrFzd/
```

It contains separately named copies of `etc-dovecot`, `etc-nginx`, `etc-php`,
`etc-sogo`, `etc-postfix`, `etc-amavis`, `etc-mxcentral`, `var-lib-sogo`,
`global-sieve`, `dkim`, `apt-sources`, and `mxcentral.env`. A successful,
nonempty `sogo.sql` dump includes routines, events and triggers using the
same single-transaction dump options described above.

The diagnostic delivery recipient's existing personal Sieve directory was
also saved as `paul-personal-sieve` before the delivery check. This protected
the old compiled binary before Dovecot could recompile it. No mailbox was
deleted or moved. These are configuration/database backups, not a fresh
whole-system snapshot or copy of all mailbox data.

### Dovecot changes

An adapted, server-specific `migrate.py` was saved in this backup directory.
It reads `ccl-mailbox`'s backed-up configuration and credentials, preserving
its IPv6 listener, service settings, mailbox paths and account restrictions.
The core 2.4 conversions follow the first-server repair, with these additions:

1. Converted the legacy `imapsieve_mailbox1_*` settings to a `mailbox Junk`
   filter containing a `report-spam` Sieve script with `type = before`,
   `cause = copy append`, and path `/var/vmail/sieve/report_spam.sieve`.
2. Converted `imapsieve_mailbox2_*` to an `imapsieve_from Junk` filter with
   a `report-ham` script, `type = before`, `cause = copy`, and path
   `/var/vmail/sieve/report_ham.sieve`. The script's existing Trash exception
   was retained unchanged.
3. Converted Sieve plugin and global-extension settings to boolean-list
   blocks. The existing pipe directory and `imapsieve_copy` script were retained.
4. Created `/etc/dovecot/mxcentral-24-attributes.conf` with the 2.4
   `mail_attribute { dict file { path = %{home | lower}/dovecot-attributes } }`
   configuration. The active main config includes this file instead of the
   old wildcard include, whose sole discovered file was the obsolete
   `99-mail-attributes.conf`. The old file remains on disk but is inactive.
   Any future additional `iredmail/*.conf` files must be reviewed and included
   explicitly as appropriate; do not assume that old wildcard is still active.
5. Successfully recompiled and installed all three global Sieve binaries:
   `dovecot.svbin`, `report_spam.svbin` and `report_ham.svbin`.

The candidate parsed successfully, and the active main configuration also
parsed as `vmail`. Main and metadata configs are `root:root`, mode `0644`;
SQL authentication and dictionary includes are `root:root`, mode `0600`.
The compiled global Sieve binaries are `vmail:vmail`, mode `0400`.
Dovecot was restarted successfully. The same removed-Sent-alias caveat
described for `mail` applies here.

Compilation and effective plugin settings were verified, but no synthetic
message was moved into/out of Junk solely to train the spam system. The
owner should verify the spam/ham workflow with suitable real messages.

### PHP, SOGo and Nginx changes

- Restored this server's own retained PHP 8.3 pool settings into the PHP 8.5
  pool configuration. `php-fpm8.5 -t` passed before restart.
- Installed Ubuntu's SOGo and dependencies with `--force-confold`. The
  simulation and installation involved **10** new packages and no removals,
  not the first server's 11-package set. No autoremove was performed.
- Confirmed `/etc/sogo/sogo.conf` still matched its pre-install backup.
- Updated helper `file_sources.sogo_template`, deployed
  `SOGO_ROOT_TEMPLATE_SOURCE`, and local
  `~/.config/mxcentral/ccl-mailbox.env` to the verified original template at
  `/usr/share/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox`.
- Updated the two SOGo Nginx resource templates to `/usr/share/GNUstep/SOGo`
  resources and `/usr/lib/x86_64-linux-gnu/GNUstep/SOGo` bundles.
- Preserved the existing custom branding target. The live page still renders
  the configured `#27448f` background colour.
- Found a retained `custom-login.css` under the old package's resource path.
  Backed it up and copied it to the new package resource directory without
  overwriting an existing file. The active page already includes its managed
  inline colours; no reference to this legacy CSS file was found in the
  inspected Nginx or custom-template directories.
- Validated/reloaded Nginx, rebuilt Laravel's config cache as `www-data`,
  and ran the production health check successfully.

The source nightly repository remains disabled. No SOGo database migration
scripts, password resets, DKIM changes or Amavis configuration changes were
needed. Server-side `ubuntu-sogo-paths.py` and before-change copies of edited
path configuration are saved in the backup directory.

### Tests completed

| Test | Result |
| --- | --- |
| SQL user/password-record lookups | All six active accounts passed |
| IMAPS/POP3S | TLS 1.3, certificate verified for `mailbox.centralcomms.nz` |
| SMTP submission on 587 | STARTTLS certificate verified; PLAIN/LOGIN advertised |
| LMTP recipient and quota policy | Local recipient accepted; quota response `DUNNO` |
| Mailbox quota access | Existing mailbox usage read successfully |
| Real delivery as `vmail` through LDA/Sieve | Labelled test message found in `paul@centralcomms.nz` INBOX |
| Existing client authentication | Post-repair logs show successful TLS logins for support and Paul |
| Deferred messages | `postqueue -f` retried the six messages; delivery logs confirm arrival in the local catch-all mailbox; queue empty |
| MXCentral | `/mxcentral/` redirects to login, HTTP 200; production health check passes |
| SOGo | Login page and all 15 referenced same-host assets return HTTP 200 |
| Existing SOGo data | `sogo-tool backup ... ALL` as `sogo` completed, producing six export files |
| DKIM | `mxcentral` selectors for `centralcomms.nz` and `centralcommunications.co.nz` both passed `amavisd testkeys` as `amavis` |
| Services | Dovecot, Postfix, Amavis, Nginx, PHP FPM, SOGo, MariaDB, memcached, iRedAPD and Fail2ban active |
| Boot/package checks | Dovecot and SOGo enabled; no failed units; `dpkg --audit` clean |

The post-install SOGo export and its log are retained under
`application-export/` and `sogo-export-check.log` in the root-only backup.
SOGo's GNUstep regular-expression warning was present but did not prevent
the export. Nginx still reports the nonfatal deprecated HTTP/2-listener syntax.
The final service-journal error check since activation showed no entries.

The diagnostic email's subject is `Dovecot upgrade repair: local delivery
verification`; it may be deleted by the mailbox owner. No user password was
requested or used by the repair process; successful client logins were
observed in normal service logs. Owner testing is still needed for SOGo's
authenticated calendar/contact workflows, branding save/reset, spam/ham
training, shared folders and an externally verified outbound DKIM signature.

The scripts in this backup are specific to `ccl-mailbox`. `verify.py` runs
checks without delivering mail; `check-delivery.py` sends another diagnostic
message. `migrate.py` regenerates from the original backup and must not be
rerun over later changes without review. Rollback has the same constraints
as the first server: preserve new data and use matching packages/configuration,
not just the obsolete Dovecot 2.3 files with a 2.4 binary.

## Follow-up: sudo-rs compatibility on both servers

After subsequent reboots, both servers reported:

```text
/etc/sudoers.d/mxcentral-for-iRedmail:12:20: unknown setting: 'requiretty'
```

Both were running `sudo-rs 0.2.13-0ubuntu1.2`. Unlike traditional sudo,
[sudo-rs does not implement the legacy requiretty setting](https://github.com/trifectatechfoundation/sudo-rs/blob/main/FAQ.md).
The MXCentral policy contained `Defaults:www-data !requiretty`, which was
unnecessary on these installations and rejected by that parser.

The fix removes that Defaults line without changing the command alias,
run-as user, or passwordless permission for the fixed privileged helper.
It does not grant additional commands or disable sudo security checks.

The installed policy and deployed `docs/sudoers.conf` were backed up in:

- `mail`: `/root/mxcentral-sudoers-fix-izRGmP5t/`
- `ccl-mailbox`: `/root/mxcentral-sudoers-fix-EHDk4414/`

Each directory contains `original`, `deployed-template`, and the corrected
`candidate`. Candidates were validated with the server's `visudo -cf` before
installation. The policy retains `root:root` ownership and mode `0440`.
The deployed template was updated as well to avoid reinstating the old line
from that local copy. The repository's template was fixed, with a regression
assertion against reintroducing `requiretty` in an active Defaults line.

After installation, full `visudo -c` validation and MXCentral's production
health check as `www-data` passed on both servers. These commands were run
over SSH without allocating a terminal, exercising the helper access used
by the application. No service restart is required for a sudoers change.

For future servers, check the actual sudo implementation, preserve any
host-specific policy, validate before installing, and verify noninteractive
helper access. Traditional-sudo installations with a deliberately enabled
global `requiretty` policy require separate review; do not blindly remove
that host's unrelated security settings. The historical vendored iRedMail
HTML documentation is not the MXCentral policy installed by deployment.

## Follow-up: domain-specific DKIM precedence (4 November 2026)

On `mail.netent.co.nz`, messages from `nx-camera-info.com` were signed as
`relay.netent.co.nz` even though the new domain's private key and public DNS
record passed `amavisd testkeys`. This was a configuration-generation bug,
not a missing key or an established consequence of the Ubuntu upgrade.

The original catch-all map explicitly set `d => 'relay.netent.co.nz'`.
MXCentral appended its domain-specific map using `push`, giving that earlier
catch-all priority. The fix changes generation to `unshift`, so managed
domain rules precede the existing fallback without modifying the fallback.

Changes made:

- Updated `DomainDkimService.php` to generate `unshift`.
- Updated the privileged helper's narrowly allowed map statements to accept
  `unshift`. Legacy `push` remains accepted for rolling-upgrade compatibility;
  new configuration generation always prepends. Other validation restrictions
  remain in place.
- Added generator regression coverage that evaluates generated Perl and
  confirms the managed map is first and the fallback is retained. Existing
  legacy-block cleanup also verifies migration to `unshift`.
- Added helper tests for the transition, legacy compatibility, rejected
  arbitrary statements, duplicate maps and changes outside the managed block.
- Applied the two runtime code files to `mail` after confirming their previous
  hashes matched the repository baseline. Updated the deployed helper source
  copy too, and reloaded PHP FPM. No broad application deployment was performed.
- Backed up the original runtime files and Amavis configuration to
  `/root/dkim-precedence-fix-aGTEBaU3/` on `mail`.
- Changed only `push` to `unshift` in the existing managed Amavis block.
  Validated the candidate with Amavis's complete ordered configuration set
  and `testkeys`, then used the privileged helper's validated, locked
  configuration transaction to atomically apply it and restart Amavis.
  The operation ID was `5ed68b8d6869facc0f835956b06a9954`.

A standalone `perl -c` cannot validate this Amavis fragment because it needs
Amavis's declared globals. The successful preflight used each configuration
file returned by `run-parts --list` for `/usr/share/amavis/conf.d` and
`/etc/amavis/conf.d`, replacing only `50-user` with a temporary candidate.
That candidate was root-owned, readable by Amavis, excluded from normal
`run-parts` discovery by its dotted filename, and removed after validation.

All existing private-key hashes were unchanged. MXCentral's production health
check passed. A single labelled diagnostic message from
`accounts@nx-camera-info.com` was submitted through the local originating
Amavis listener and delivered to `paul@golive.co.nz`. Both Amavis's log and
the delivered `DKIM-Signature` header confirmed:

```text
d=nx-camera-info.com; s=mxcentral
```

The subject is `MXCentral DKIM signing verification`; the recipient may delete
it. The verification script handles folded DKIM headers and can recheck an
existing Message-ID without sending another message. This verifies the actual
signing choice and local delivery, not a third-party recipient's full DMARC
evaluation. Four older, unrelated deferred messages remained in the queue
with destination-connectivity errors; they were not deleted or force-retried.

The targeted PHP suite passed (3 tests, 18 assertions), all Python helper
tests passed (17 tests), and PHP formatting and whitespace checks passed.
The earlier unrelated sudoers changes in the worktree were preserved.
No DNS records or private keys were changed. The previously missing fallback
DNS records were not repaired by this change.

Only `mail` was updated live in this task; `ccl-mailbox` was not changed.
For other deployments, install the compatible helper before the generator.
Existing managed blocks need a reviewed `push`-to-`unshift` conversion and
Amavis restart to take immediate effect; deploying PHP alone does not rewrite
them. Do not rotate a key merely to fix map ordering.

### Subsequent deployment to ccl-mailbox

Later on 4 November 2026, the user requested the same fix on
`mailbox.centralcomms.nz`. Read-only inspection first established that its
catch-all was commented out: recent messages already used the correct
`centralcomms.nz` signing domain, and both configured keys passed DNS checks.
The old generator still used `push`, so the fix was deployed preventively.

Original runtime files and Amavis configuration were backed up under
`/root/dkim-precedence-fix-bkVJYw7D/`. Their runtime code hashes matched the
same repository baseline before replacement. The updated helper was installed
before the generator, its deployed source copy was updated, and PHP FPM was
reloaded. The candidate was validated with the complete Amavis configuration
set, then applied through the helper transaction
`15fb1b7872f5c71ea1b0e299efa844c3`, including an Amavis restart.

The sole live Amavis configuration difference is `push` becoming `unshift`
in the managed block. The catch-all remains commented out and all private
key hashes are unchanged. Both `centralcomms.nz` and
`centralcommunications.co.nz` passed `amavisd testkeys` before and after the
change. The production health check passed, and Amavis, Postfix and PHP FPM
were active. No new diagnostic email was sent during this deployment.
