# INSTALL

These notes track the server-side setup required for `mxcentral-for-iRedmail` on an iRedMail host.

The example deployment target is:

```text
root@mail.example.com:/opt/www/mxcentral-for-iRedmail
```

Put real deployment values in the ignored top-level `Makefile.local`:

```make
DEPLOY_HOST := root@mail.example.com
DEPLOY_PATH := /opt/www/mxcentral-for-iRedmail
```

## Deploy App

From the repo root on the build/workstation:

```sh
make deploy
```

The deploy target:

- creates required app/cache/storage directories
- rsyncs the app to `/opt/www/mxcentral-for-iRedmail`
- does not overwrite remote `.env` or remote `storage/`
- does not deploy local `database/*.sqlite*` files
- runs `chown -R www-data:www-data /opt/www/mxcentral-for-iRedmail`
- runs `sudo -u www-data php artisan optimize:clear`

If an older checkout fails during deploy with `Database file at path
.../database/database.sqlite does not exist`, create or fix the server `.env`
so it uses non-database Laravel runtime stores:

```dotenv
SESSION_DRIVER=file
CACHE_STORE=array
QUEUE_CONNECTION=sync
```

Then rerun `make deploy`.

## Create Server `.env` and App Key

On the mail server, create the server-local `.env` file. Deploy deliberately
does not overwrite this file because it contains host-specific secrets.

Run the ownership fix before generating the Laravel app key so `www-data` can
write the key into `.env`:

```sh
cd /opt/www/mxcentral-for-iRedmail
cp .env.example .env
chown -R www-data:www-data /opt/www/mxcentral-for-iRedmail
sudo -u www-data php artisan key:generate
```

If you edit `.env` later as `root`, rerun:

```sh
chown www-data:www-data /opt/www/mxcentral-for-iRedmail/.env
```

## Install sudoers Include

On the mail server:

```sh
visudo -cf /opt/www/mxcentral-for-iRedmail/docs/sudoers.conf
install -o root -g root -m 0440 /opt/www/mxcentral-for-iRedmail/docs/sudoers.conf /etc/sudoers.d/mxcentral-for-iRedmail
```

Do not install the sudoers include with a dot in the target filename, for example
`/etc/sudoers.d/mxcentral.conf`. Common sudo builds ignore dotted filenames in
`@includedir`.

## One-Time ACL Setup

These ACLs are required because the app edits fixed iRedMail/Postfix/SOGo files directly as `www-data` and creates `.bak` files before replacing them.

Run on the mail server as `root`:

```sh
setfacl -m u:www-data:rwx /etc/amavis/conf.d
setfacl -m u:www-data:rw /etc/amavis/conf.d/50-user
setfacl -m u:www-data:rwx /var/lib/dkim

setfacl -m u:www-data:rwx /opt/iredapd
setfacl -m u:www-data:rw /opt/iredapd/settings.py

setfacl -m u:www-data:rw /etc/postfix/main.cf
setfacl -m u:www-data:rwx /etc/postfix
touch /etc/postfix/sender_access.pcre
setfacl -m u:www-data:rw /etc/postfix/sender_access.pcre
touch /etc/postfix/discard_recipients
setfacl -m u:www-data:rw /etc/postfix/discard_recipients

install -d -m 0755 /var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI
if [ -f /usr/lib/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox ]; then
    install -m 0644 /usr/lib/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox /var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI/SOGoRootPage.wox
elif [ -f /usr/lib64/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox ]; then
    install -m 0644 /usr/lib64/GNUstep/SOGo/Templates/MainUI/SOGoRootPage.wox /var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI/SOGoRootPage.wox
fi
setfacl -m u:www-data:x /var/lib/sogo
setfacl -m u:www-data:x /var/lib/sogo/GNUstep
setfacl -m u:www-data:x /var/lib/sogo/GNUstep/Library
setfacl -m u:www-data:x /var/lib/sogo/GNUstep/Library/SOGo
setfacl -m u:www-data:x /var/lib/sogo/GNUstep/Library/SOGo/Templates
setfacl -m u:www-data:rwx /var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI
setfacl -m u:www-data:rw /var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI/SOGoRootPage.wox
```

Verify key permissions:

```sh
sudo -u www-data test -r /opt/iredapd/settings.py && echo iredapd-read-ok
sudo -u www-data test -w /opt/iredapd/settings.py && echo iredapd-write-ok
sudo -u www-data sh -c 'touch /opt/iredapd/.mxcentral-acl-test && rm /opt/iredapd/.mxcentral-acl-test' && echo iredapd-backup-ok

sudo -u www-data test -w /etc/amavis/conf.d/50-user && echo amavis-write-ok
sudo -u www-data sh -c 'touch /etc/amavis/conf.d/.mxcentral-acl-test && rm /etc/amavis/conf.d/.mxcentral-acl-test' && echo amavis-backup-ok

sudo -u www-data test -w /etc/postfix/sender_access.pcre && echo sender-access-write-ok
sudo -u www-data sh -c 'touch /etc/postfix/.mxcentral-acl-test && rm /etc/postfix/.mxcentral-acl-test' && echo postfix-backup-ok
```

## Required `.env` Settings

Keep the remote `.env` on the server. `make deploy` does not overwrite it.

Create a MySQL/MariaDB user for the app and use a strong unique password.
This broad grant matches the current app behavior across the iRedMail databases:

```sql
GRANT ALL PRIVILEGES ON *.* TO 'mxcentral'@'localhost' IDENTIFIED BY 'mxcentral-pass';
FLUSH PRIVILEGES;
```

Or run it directly from bash with a database admin account:

```sh
mysql \
  -u db_admin_user \
  -p \
  -e "
    GRANT ALL PRIVILEGES
      ON *.*
      TO 'mxcentral'@'localhost'
      IDENTIFIED BY 'mxcentral-pass';
    FLUSH PRIVILEGES;
  "
```

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
IREDMAIL_DB_USERNAME=mxcentral
IREDMAIL_DB_PASSWORD=mxcentral-pass

IREDAPD_SETTINGS_PATH=/opt/iredapd/settings.py
IREDAPD_RESTART_COMMAND="/usr/bin/sudo /usr/bin/systemctl restart iredapd.service"

FAIL2BAN_UNBAN_COMMAND="/usr/bin/sudo /usr/bin/fail2ban-client unban"

POSTFIX_MAIN_CF_PATH=/etc/postfix/main.cf
POSTFIX_SENDER_ACCESS_PATH=/etc/postfix/sender_access.pcre
POSTFIX_DISCARD_RECIPIENTS_PATH=/etc/postfix/discard_recipients
POSTFIX_POSTMAP_COMMAND="/usr/bin/sudo /usr/sbin/postmap"
POSTFIX_RELOAD_COMMAND="/usr/bin/sudo /usr/bin/systemctl reload postfix.service"

AMAVISD_CONFIG_PATH=/etc/amavis/conf.d/50-user
AMAVISD_DKIM_DIRECTORY=/var/lib/dkim
AMAVISD_DKIM_SELECTOR=mxcentral
AMAVISD_DKIM_BITS=1024
AMAVISD_GENRSA_COMMAND="/usr/bin/sudo /usr/sbin/amavisd genrsa"
AMAVISD_SHOWKEYS_COMMAND="/usr/bin/sudo /usr/sbin/amavisd showkeys"
AMAVISD_TESTKEYS_COMMAND="/usr/bin/sudo /usr/sbin/amavisd testkeys"
AMAVISD_RESTART_COMMAND="/usr/bin/sudo /usr/bin/systemctl restart amavis.service"
AMAVISD_DKIM_KEY_OWNER=amavis
AMAVISD_DKIM_KEY_GROUP=amavis
AMAVISD_DKIM_CHOWN_COMMAND="/usr/bin/sudo /usr/bin/chown"
AMAVISD_DKIM_CHMOD_COMMAND="/usr/bin/sudo /usr/bin/chmod"

IREDMAIL_SPF_SERVER_HOSTNAME=mail.example.com
IREDMAIL_SPF_SERVER_IPS=203.0.113.10

SOGO_ROOT_TEMPLATE_SOURCE=
SOGO_ROOT_TEMPLATE_TARGET=/var/lib/sogo/GNUstep/Library/SOGo/Templates/MainUI/SOGoRootPage.wox
SOGO_RELOAD_COMMAND="/usr/bin/sudo /usr/bin/systemctl restart sogo.service"
```

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

Install the provided nginx location template on the mail server:

```sh
ln -sfn /opt/www/mxcentral-for-iRedmail/docs/nginx/mxcentral.tmpl /etc/nginx/templates/mxcentral.tmpl
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
* * * * * /usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php >/dev/null 2>&1
```

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

The app writes `/etc/postfix/discard_recipients`, runs postmap, and reloads Postfix. `main.cf` must include:

```text
check_recipient_access hash:/etc/postfix/discard_recipients
```

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
sudo -u www-data php artisan route:list >/dev/null
sudo -u www-data php artisan quarantine:notify-recipients --dry-run
```

Check app-managed file access:

```sh
sudo -u www-data test -w /opt/iredapd/settings.py && echo ok
sudo -u www-data test -w /etc/amavis/conf.d/50-user && echo ok
sudo -u www-data test -w /etc/postfix/main.cf && echo ok
sudo -u www-data test -w /etc/postfix/sender_access.pcre && echo ok
sudo -u www-data test -w /etc/postfix/discard_recipients && echo ok
```
