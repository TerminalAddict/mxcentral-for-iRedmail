#!/usr/bin/env bash
set -euo pipefail

APP_DIR="mxcentral-for-iRedmail"
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"

usage() {
    cat <<'USAGE'
Usage:
  scripts/deploy-rsync.sh <ssh-target> <remote-path>

Example:
  scripts/deploy-rsync.sh paul@mail.example.com /opt/www/mxcentral-for-iRedmail

Environment:
  APP_USER   Remote web-server user. Default: www-data
  APP_GROUP  Remote web-server group. Default: www-data

This updates an existing mxcentral-for-iRedmail deployment. It refuses to rsync
unless the remote path already contains the expected Laravel app files.
USAGE
}

quote_remote() {
    local value=${1//\'/\'\\\'\'}
    printf "'%s'" "$value"
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

if [[ $# -ne 2 ]]; then
    usage >&2
    exit 64
fi

SSH_TARGET="$1"
REMOTE_PATH="${2%/}"
SOURCE_DIR="${APP_DIR}/"

if [[ ! -d "$APP_DIR" || ! -f "$APP_DIR/artisan" || ! -f "$APP_DIR/composer.json" ]]; then
    echo "ERROR: run this script from the repository root." >&2
    exit 1
fi

REMOTE_Q="$(quote_remote "$REMOTE_PATH")"
APP_USER_Q="$(quote_remote "$APP_USER")"
APP_GROUP_Q="$(quote_remote "$APP_GROUP")"

echo "Checking remote deployment: ${SSH_TARGET}:${REMOTE_PATH}"
ssh "$SSH_TARGET" "set -eu
    test -d $REMOTE_Q
    test -f $REMOTE_Q/artisan
    test -f $REMOTE_Q/composer.json
    test -f $REMOTE_Q/.env
    grep -q 'mxcentral/mxcentral-for-iredmail' $REMOTE_Q/composer.json
"

REMOTE_ACCESS="$(
    ssh "$SSH_TARGET" "set -eu
        if [ \"\$(id -u)\" -eq 0 ]; then
            echo root
        elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
            echo sudo
        else
            echo user
        fi
    "
)"

RSYNC_ARGS=(
    -az
    --delete
    --exclude=.env
    --exclude=.phpunit.result.cache
    --exclude='/database/*.sqlite*'
    --exclude='/node_modules/'
    --exclude='/public/hot'
    --exclude='/storage/'
)

if [[ ! -e "${APP_DIR}/vendor/autoload.php" ]]; then
    RSYNC_ARGS+=(--exclude='/vendor/')
    echo "Local vendor/ is missing; preserving remote vendor/. Run composer install on the remote host if composer.lock changed."
fi

if [[ "$REMOTE_ACCESS" == "sudo" ]]; then
    RSYNC_ARGS+=(--rsync-path='sudo -n rsync')
fi

echo "Ensuring remote runtime directories exist."
ssh "$SSH_TARGET" "set -eu
    if [ '$REMOTE_ACCESS' = 'sudo' ]; then
        sudo -n mkdir -p \
            $REMOTE_Q/bootstrap/cache \
            $REMOTE_Q/database \
            $REMOTE_Q/storage/app \
            $REMOTE_Q/storage/framework/cache/data \
            $REMOTE_Q/storage/framework/sessions \
            $REMOTE_Q/storage/framework/views \
            $REMOTE_Q/storage/logs
    else
        mkdir -p \
            $REMOTE_Q/bootstrap/cache \
            $REMOTE_Q/database \
            $REMOTE_Q/storage/app \
            $REMOTE_Q/storage/framework/cache/data \
            $REMOTE_Q/storage/framework/sessions \
            $REMOTE_Q/storage/framework/views \
            $REMOTE_Q/storage/logs
    fi
"

echo "Rsyncing application files."
rsync "${RSYNC_ARGS[@]}" "$SOURCE_DIR" "${SSH_TARGET}:${REMOTE_PATH}/"

echo "Applying ownership where permitted."
ssh "$SSH_TARGET" "set -eu
    if [ \"\$(id -u)\" -eq 0 ]; then
        chown -R $APP_USER_Q:$APP_GROUP_Q $REMOTE_Q
    elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
        sudo -n chown -R $APP_USER_Q:$APP_GROUP_Q $REMOTE_Q
    else
        echo 'WARNING: cannot chown remotely without root or passwordless sudo.' >&2
        echo 'Run this on the server:' >&2
        echo '  sudo chown -R $APP_USER:$APP_GROUP $REMOTE_PATH' >&2
    fi
"

echo "Checking ownership."
ssh "$SSH_TARGET" "set -eu
    if [ '$REMOTE_ACCESS' = 'sudo' ]; then
        mismatch_count=\"\$(sudo -n find $REMOTE_Q \\( ! -user $APP_USER_Q -o ! -group $APP_GROUP_Q \\) -print | wc -l | tr -d ' ')\"
    else
        mismatch_count=\"\$(find $REMOTE_Q \\( ! -user $APP_USER_Q -o ! -group $APP_GROUP_Q \\) -print | wc -l | tr -d ' ')\"
    fi
    if [ \"\$mismatch_count\" != '0' ]; then
        echo \"ERROR: \$mismatch_count deployed file(s) are not owned by $APP_USER:$APP_GROUP.\" >&2
        if [ '$REMOTE_ACCESS' = 'sudo' ]; then
            sudo -n find $REMOTE_Q \\( ! -user $APP_USER_Q -o ! -group $APP_GROUP_Q \\) -print | sed -n '1,20p' >&2
        else
            find $REMOTE_Q \\( ! -user $APP_USER_Q -o ! -group $APP_GROUP_Q \\) -print | sed -n '1,20p' >&2
        fi
        echo 'Run this on the server:' >&2
        echo '  sudo chown -R $APP_USER:$APP_GROUP $REMOTE_PATH' >&2
        exit 1
    fi
"

echo "Clearing Laravel caches."
ssh "$SSH_TARGET" "set -eu
    cd $REMOTE_Q
    if [ \"\$(id -u)\" -eq 0 ]; then
        sudo -u $APP_USER_Q php artisan optimize:clear
    elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
        sudo -n -u $APP_USER_Q php artisan optimize:clear
    else
        php artisan optimize:clear
    fi
"

echo "Deploy complete: ${SSH_TARGET}:${REMOTE_PATH}"
