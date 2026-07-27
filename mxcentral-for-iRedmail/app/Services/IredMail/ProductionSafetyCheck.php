<?php

namespace App\Services\IredMail;

final class ProductionSafetyCheck
{
    public function errors(): array
    {
        $errors = [];
        if (app()->environment() !== 'production') {
            $errors[] = 'APP_ENV must be production.';
        }
        if ((bool) config('app.debug')) {
            $errors[] = 'APP_DEBUG must be false.';
        }
        if (parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            $errors[] = 'APP_URL must use https.';
        }
        $key = (string) config('app.key');
        if ($key === '' || $key === 'base64:'.base64_encode(str_repeat("\0", 32))) {
            $errors[] = 'APP_KEY must be generated and non-empty.';
        }
        if (! (bool) config('session.secure')) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true.';
        }
        if (! (bool) config('session.http_only')) {
            $errors[] = 'SESSION_HTTP_ONLY must be true.';
        }
        if (! (bool) config('session.encrypt')) {
            $errors[] = 'SESSION_ENCRYPT must be true.';
        }
        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $errors[] = 'SESSION_SAME_SITE must be lax or strict.';
        }
        $storageBase = (string) config('iredmail.storage_base_directory');
        if (! str_starts_with($storageBase, '/') || str_contains($storageBase, '..')) {
            $errors[] = 'IREDMAIL_STORAGE_BASE_DIRECTORY must be an absolute path without traversal.';
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', (string) config('iredmail.storage_node')) !== 1) {
            $errors[] = 'IREDMAIL_STORAGE_NODE contains invalid characters.';
        }
        $maildir = (string) config('iredmail.mailbox_directory_template');
        if (! str_contains($maildir, '{domain}') || ! str_contains($maildir, '{local}') || str_contains($maildir, '..') || str_starts_with($maildir, '/')) {
            $errors[] = 'IREDMAIL_MAILBOX_DIRECTORY_TEMPLATE must be relative and contain {domain} and {local}.';
        }
        if (preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z]{2})?(?:\\.UTF-8)?$/', (string) config('iredmail.mailbox_language')) !== 1) {
            $errors[] = 'IREDMAIL_MAILBOX_LANGUAGE is invalid.';
        }
        $backupPort = (int) config('iredmail.backup_mx_port');
        if ($backupPort < 1 || $backupPort > 65535) {
            $errors[] = 'IREDMAIL_BACKUP_MX_PORT must be between 1 and 65535.';
        }
        $listTransport = (string) config('iredmail.mailing_list_transport_template');
        if (! str_contains($listTransport, '{address}') || preg_match('/[\r\n\0]/', $listTransport)) {
            $errors[] = 'IREDMAIL_MAILING_LIST_TRANSPORT_TEMPLATE must contain {address} and no control characters.';
        }
        if ((int) config('iredmail.page_size') < 1 || (int) config('iredmail.page_size') > 500) {
            $errors[] = 'IREDMAIL_PAGE_SIZE must be between 1 and 500.';
        }
        $databaseUsers = [];
        foreach (['vmail', 'iredadmin', 'amavisd', 'iredapd', 'fail2ban'] as $connection) {
            $username = trim((string) config("database.connections.{$connection}.username"));
            $password = (string) config("database.connections.{$connection}.password");
            if ($username === '' || $password === '') {
                $errors[] = strtoupper($connection).' database credentials must be explicitly configured.';
            }
            if ($username !== '') {
                $databaseUsers[] = $username;
            }
        }
        if (count($databaseUsers) !== count(array_unique($databaseUsers))) {
            $errors[] = 'Each iRedMail schema must use a separate database username.';
        }
        $helperArguments = array_values(array_filter(
            str_getcsv((string) config('iredmail.privileged_helper_command'), ' ', '"', '\\'),
            fn (string $argument): bool => $argument !== '',
        ));
        if (count($helperArguments) !== 2
            || ! str_ends_with($helperArguments[0] ?? '', '/sudo')
            || ($helperArguments[1] ?? '') !== '/usr/local/sbin/mxcentral-privileged') {
            $errors[] = 'MXCENTRAL_PRIVILEGED_HELPER_COMMAND must invoke only sudo and the fixed root helper.';
        }

        return $errors;
    }
}
