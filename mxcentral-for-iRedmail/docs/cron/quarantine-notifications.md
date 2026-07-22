# Quarantine Notifications Cron

MXCentral uses one cron runner for scheduled jobs. Install one cron entry:

```cron
* * * * * /usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php >/dev/null 2>&1
```

The runner keeps per-task state in `storage/app/cron-state.json`, so it can be called every minute while each task controls its own interval. Quarantine notifications run every 6 hours. iRedMail upgrade checks run every 24 hours.

List configured tasks and due times:

```sh
/usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php --list
```

Force one task to run now:

```sh
/usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php --task=quarantine-notifications --force
```

Run the upgrade check immediately:

```sh
/usr/bin/php /opt/www/mxcentral-for-iRedmail/bin/cron.php --task=iredmail-upgrade-check --force
```

Test quarantine notifications directly without sending mail or updating notification state:

```sh
cd /opt/www/mxcentral-for-iRedmail
sudo -u www-data php artisan quarantine:notify-recipients --dry-run
```

To resend a digest for every currently quarantined message, including messages already notified before:

```sh
cd /opt/www/mxcentral-for-iRedmail
sudo -u www-data php artisan quarantine:notify-recipients --force-all
```

Configure Laravel mail delivery in `.env`. For a local iRedMail submission service, typical values are:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=587
MAIL_USERNAME=no-reply@example.com
MAIL_PASSWORD=change-me
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

The command tracks notified quarantined message IDs in:

```dotenv
QUARANTINE_NOTIFICATION_STATE_PATH=/opt/www/mxcentral-for-iRedmail/storage/app/quarantine-notifications.json
```

Leave `QUARANTINE_NOTIFICATION_STATE_PATH` empty to use the default `storage/app/quarantine-notifications.json`.
