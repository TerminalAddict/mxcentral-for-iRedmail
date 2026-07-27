#!/usr/bin/env bash
set -euo pipefail

APP_DIR="mxcentral-for-iRedmail"
APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"
SERVER_ENV_FILE="${SERVER_ENV_FILE:-}"
PRIVILEGED_CONFIG_FILE="${PRIVILEGED_CONFIG_FILE:-}"

usage() {
    cat <<'USAGE'
Usage:
  scripts/deploy-rsync.sh <ssh-target> <remote-path>

Example:
  scripts/deploy-rsync.sh paul@mail.example.com /opt/www/mxcentral-for-iRedmail

Environment:
  APP_USER   Remote web-server user. Default: www-data
  APP_GROUP  Remote web-server group. Default: www-data
  SERVER_ENV_FILE
             Optional local, server-specific .env to install as root:<APP_GROUP>
             mode 0640. If omitted, the remote .env is preserved.
  PRIVILEGED_CONFIG_FILE
             Optional local, server-specific privileged-helper JSON config.
             If omitted, the remote config is preserved (or initialized once).

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

if [[ -n "$SERVER_ENV_FILE" && ! -f "$SERVER_ENV_FILE" ]]; then
    echo "ERROR: SERVER_ENV_FILE does not exist: $SERVER_ENV_FILE" >&2
    exit 66
fi
if [[ -n "$PRIVILEGED_CONFIG_FILE" && ! -f "$PRIVILEGED_CONFIG_FILE" ]]; then
    echo "ERROR: PRIVILEGED_CONFIG_FILE does not exist: $PRIVILEGED_CONFIG_FILE" >&2
    exit 66
fi
if [[ ! "$APP_USER" =~ ^[a-z_][a-z0-9_-]*$ || ! "$APP_GROUP" =~ ^[a-z_][a-z0-9_-]*$ ]]; then
    echo "ERROR: APP_USER and APP_GROUP must be simple Unix account names." >&2
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

if [[ "$REMOTE_ACCESS" == "user" ]]; then
    echo "ERROR: secure deployment requires root or passwordless sudo on ${SSH_TARGET}." >&2
    exit 77
fi

echo "Ensuring remote runtime directories exist."
ssh "$SSH_TARGET" "set -eu
    if [ '$REMOTE_ACCESS' = 'sudo' ]; then
        sudo -n mkdir -p \
            $REMOTE_Q/bootstrap/cache \
            $REMOTE_Q/storage/app \
            $REMOTE_Q/storage/framework/cache/data \
            $REMOTE_Q/storage/framework/sessions \
            $REMOTE_Q/storage/framework/views \
            $REMOTE_Q/storage/logs
    else
        mkdir -p \
            $REMOTE_Q/bootstrap/cache \
            $REMOTE_Q/storage/app \
            $REMOTE_Q/storage/framework/cache/data \
            $REMOTE_Q/storage/framework/sessions \
            $REMOTE_Q/storage/framework/views \
            $REMOTE_Q/storage/logs
    fi
"

echo "Rsyncing application files."
rsync "${RSYNC_ARGS[@]}" "$SOURCE_DIR" "${SSH_TARGET}:${REMOTE_PATH}/"

UPLOAD_ARGS=(-az --chmod=F600)
if [[ "$REMOTE_ACCESS" == "sudo" ]]; then
    UPLOAD_ARGS+=(--rsync-path='sudo -n rsync')
fi

if [[ -n "$SERVER_ENV_FILE" ]]; then
    echo "Uploading server-specific environment."
    rsync "${UPLOAD_ARGS[@]}" "$SERVER_ENV_FILE" "${SSH_TARGET}:${REMOTE_PATH}/.env.mxcentral-upload"
fi
if [[ -n "$PRIVILEGED_CONFIG_FILE" ]]; then
    echo "Uploading server-specific privileged-helper configuration."
    rsync "${UPLOAD_ARGS[@]}" "$PRIVILEGED_CONFIG_FILE" "${SSH_TARGET}:${REMOTE_PATH}/privileged-helper.mxcentral-upload"
fi

echo "Installing the root helper, sudoers policy, and secure ownership."
ssh "$SSH_TARGET" "set -eu
    if [ \"\$(id -u)\" -eq 0 ]; then ROOT_RUN=''; else ROOT_RUN='sudo -n'; fi
    \$ROOT_RUN test \"\$(stat -c %U $REMOTE_Q/scripts/mxcentral-privileged)\" != '$APP_USER'
    \$ROOT_RUN install -o root -g root -m 0755 $REMOTE_Q/scripts/mxcentral-privileged /usr/local/sbin/mxcentral-privileged
    \$ROOT_RUN install -d -o root -g root -m 0755 /etc/mxcentral
    \$ROOT_RUN install -d -o root -g root -m 0750 /var/lib/mxcentral /var/lib/mxcentral/operations
    if [ -f $REMOTE_Q/privileged-helper.mxcentral-upload ]; then
        \$ROOT_RUN install -o root -g root -m 0640 $REMOTE_Q/privileged-helper.mxcentral-upload /etc/mxcentral/privileged-helper.json
        \$ROOT_RUN rm $REMOTE_Q/privileged-helper.mxcentral-upload
    elif [ ! -f /etc/mxcentral/privileged-helper.json ]; then
        \$ROOT_RUN install -o root -g root -m 0640 $REMOTE_Q/docs/privileged-helper.json /etc/mxcentral/privileged-helper.json
    fi
    \$ROOT_RUN python3 -c \"import json,sys; data=json.load(open(sys.argv[1], encoding='utf-8')); users=data.get('web_users', []); raise SystemExit(0 if sys.argv[2] in users else 'privileged-helper.json web_users must include APP_USER')\" /etc/mxcentral/privileged-helper.json $APP_USER_Q
    if [ '$APP_USER' = www-data ]; then
        \$ROOT_RUN visudo -cf $REMOTE_Q/docs/sudoers.conf >/dev/null
        \$ROOT_RUN install -o root -g root -m 0440 $REMOTE_Q/docs/sudoers.conf /etc/sudoers.d/mxcentral-for-iRedmail
    else
        \$ROOT_RUN sed -e 's/^Defaults:www-data /Defaults:$APP_USER /' -e 's/^www-data /$APP_USER /' $REMOTE_Q/docs/sudoers.conf \
            | \$ROOT_RUN tee /etc/sudoers.d/mxcentral-for-iRedmail.new >/dev/null
        \$ROOT_RUN chown root:root /etc/sudoers.d/mxcentral-for-iRedmail.new
        \$ROOT_RUN chmod 0440 /etc/sudoers.d/mxcentral-for-iRedmail.new
        \$ROOT_RUN visudo -cf /etc/sudoers.d/mxcentral-for-iRedmail.new >/dev/null
        \$ROOT_RUN mv /etc/sudoers.d/mxcentral-for-iRedmail.new /etc/sudoers.d/mxcentral-for-iRedmail
    fi

    if [ -f $REMOTE_Q/.env.mxcentral-upload ]; then
        \$ROOT_RUN install -o root -g $APP_GROUP_Q -m 0640 $REMOTE_Q/.env.mxcentral-upload $REMOTE_Q/.env
        \$ROOT_RUN rm $REMOTE_Q/.env.mxcentral-upload
    else
        \$ROOT_RUN chown root:$APP_GROUP_Q $REMOTE_Q/.env
        \$ROOT_RUN chmod 0640 $REMOTE_Q/.env
    fi

    \$ROOT_RUN chown -R root:root $REMOTE_Q
    \$ROOT_RUN chown root:$APP_GROUP_Q $REMOTE_Q/.env
    \$ROOT_RUN chmod 0640 $REMOTE_Q/.env
    \$ROOT_RUN find $REMOTE_Q -xdev -type d -exec chmod go-w {} +
    \$ROOT_RUN find $REMOTE_Q -xdev -type f -exec chmod go-w {} +
    \$ROOT_RUN chown -R $APP_USER_Q:$APP_GROUP_Q $REMOTE_Q/storage $REMOTE_Q/bootstrap/cache
    \$ROOT_RUN find $REMOTE_Q/storage $REMOTE_Q/bootstrap/cache -type d -exec chmod 0750 {} +
    \$ROOT_RUN find $REMOTE_Q/storage $REMOTE_Q/bootstrap/cache -type f -exec chmod 0640 {} +

    test \"\$(stat -c %U $REMOTE_Q)\" = root
    test \"\$(stat -c %U $REMOTE_Q/app)\" = root
    test \"\$(stat -c %U $REMOTE_Q/.env)\" = root
    test \"\$(stat -c %G $REMOTE_Q/.env)\" = '$APP_GROUP'
    test \"\$(stat -c %a $REMOTE_Q/.env)\" = 640
    test \"\$(stat -c %U $REMOTE_Q/storage)\" = '$APP_USER'
"

echo "Clearing Laravel caches and enforcing production safety."
ssh "$SSH_TARGET" "set -eu
    cd $REMOTE_Q
    if [ \"\$(id -u)\" -eq 0 ]; then
        sudo -u $APP_USER_Q php artisan optimize:clear
        sudo -u $APP_USER_Q php artisan mxcentral:check-production
    elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
        sudo -n -u $APP_USER_Q php artisan optimize:clear
        sudo -n -u $APP_USER_Q php artisan mxcentral:check-production
    else
        php artisan optimize:clear
        php artisan mxcentral:check-production
    fi
"

echo "Deploy complete: ${SSH_TARGET}:${REMOTE_PATH}"
