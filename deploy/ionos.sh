#!/usr/bin/env bash
# Deployment and server tasks for IONOS web hosting, run by GitHub Actions.
# Adapted from NIMBY's deploy/ionos.sh (same server, same deploy key).
#
#   deploy/ionos.sh check    report what the server has (PHP, rsync, cron, config, database)
#   deploy/ionos.sh deploy   copy the code to the server, then update database tables
#   deploy/ionos.sh setup    first-time setup: tables, first sync, cron jobs
#   deploy/ionos.sh sync     read the council calendars now
#   deploy/ionos.sh cron     (re)install the cron jobs: sync twice a day, reminders every 15 minutes
#   deploy/ionos.sh test-reminder   send a sample reminder to the site's own mailbox only
#   deploy/ionos.sh signup-open | signup-close | signup-status
#
# Needs environment variables SSH_HOST, SSH_USER and SSH_KEY (from repository
# secrets). DEPLOY_PATH defaults to "bins", relative to the hosting home folder.
#
# The repository is public, so its Actions logs are public too. Everything this
# script prints passes through redact(), and it never echoes host names, user
# names or server paths.
set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:-bins}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PINS="$HERE/ionos-host-keys.txt"
SSH_DIR="${RUNNER_TEMP:-/tmp}/bins-ssh"
KEY="$SSH_DIR/id_deploy"
KNOWN="$SSH_DIR/known_hosts"
SYNC_TAG='# bins-sync'
REMIND_TAG='# bins-reminders'

note() { echo "::notice title=BINS server::$*"; }
fail() { echo "::error title=BINS server::$*"; exit 1; }

redact() {
  sed -E \
    -e "s/'[^']*'@'[^']*'/'***'@'***'/g" \
    -e 's#/(kunden|homepages)/[^ "]*#[server path]#g' \
    -e 's/db[0-9]+\.hosting-data\.io/[db host]/g' \
    -e 's/[a-z0-9.-]+\.1and1-data\.host/[ssh host]/g' \
    -e 's/\b(dbu|dbs|acc|u)[0-9]{6,}\b/***/g' \
    -e 's/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.)+[A-Za-z]{2,}/[email]/g'
}

ssh_opts=(-i "$KEY" -o "UserKnownHostsFile=$KNOWN" -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=20 -o LogLevel=ERROR)
remote() { ssh "${ssh_opts[@]}" "$SSH_USER@$SSH_HOST" "$@"; }

# Run a remote command and show its whole output as ONE multi-line notice
# (GitHub keeps only 10 notices per step, so one per line would be cut off).
report() {
  local status=0
  remote "$1" > "$SSH_DIR/out" 2>&1 || status=$?
  local body
  body="$(redact < "$SSH_DIR/out" | grep -v '^[[:space:]]*$' | sed -e 's/%/%25/g' | awk 'BEGIN{ORS="%0A"} {print}' | sed -e 's/%0A$//')"
  [ -n "$body" ] && note "$body"
  echo "$body" | sed 's/%0A/\n/g; s/%25/%/g'   # also readable in the step log
  return "$status"
}

connect() {
  if [ -z "${SSH_HOST:-}" ] || [ -z "${SSH_USER:-}" ] || [ -z "${SSH_KEY:-}" ]; then
    fail "The IONOS_SSH_HOST, IONOS_SSH_USER and IONOS_SSH_KEY repository secrets are not all set"
  fi
  mkdir -p "$SSH_DIR" && chmod 700 "$SSH_DIR"
  printf '%s\n' "$SSH_KEY" | tr -d '\r' > "$KEY" && chmod 600 "$KEY"

  # Only trust host keys whose fingerprints are pinned in deploy/ionos-host-keys.txt.
  ssh-keyscan -T 20 "$SSH_HOST" 2>/dev/null | grep -v '^#' > "$SSH_DIR/scanned" || true
  [ -s "$SSH_DIR/scanned" ] || fail "Could not reach the server's SSH port"
  : > "$KNOWN"
  local pinned; pinned="$(grep -v '^#' "$PINS" 2>/dev/null | awk 'NF {print $1}' || true)"
  while IFS= read -r line; do
    printf '%s\n' "$line" > "$SSH_DIR/one"
    local fp; fp="$(ssh-keygen -lf "$SSH_DIR/one" 2>/dev/null | awk '{print $2}')"
    if [ -n "$fp" ] && grep -qxF "$fp" <<< "$pinned"; then
      printf '%s\n' "$line" >> "$KNOWN"
    fi
  done < "$SSH_DIR/scanned"
  if [ ! -s "$KNOWN" ]; then
    ssh-keygen -lf "$SSH_DIR/scanned" | awk '{gsub(/[()]/, "", $NF); print $NF, $2}' | while read -r type fp; do
      note "Server offered host key $type $fp (not yet pinned)"
    done
    fail "The server's host key is not in deploy/ionos-host-keys.txt, so it was not trusted"
  fi

  remote true 2>&1 | redact || fail "SSH login with the deploy key was refused"
}

php_bin() {
  # IONOS ships CGI builds as /usr/bin/php8.x and command-line builds as /usr/bin/php8.x-cli.
  # Take the newest PHP 8 binary that really is the command-line build and is a
  # full release (IONOS also offers pre-releases, e.g. 8.6.0RC4 in November 2026).
  remote 'for p in $(ls -1 /usr/bin/php8.* 2>/dev/null | grep -E "/php8\.[0-9]+(-cli)?$" | sort -V -r); do
            if [ "$("$p" -r "echo PHP_SAPI, preg_match(\"/^[0-9.]+$/\", PHP_VERSION) ? \" ok\" : \" pre\";" 2>/dev/null)" = "cli ok" ]; then echo "$p"; break; fi
          done'
}

need_php() {
  PHP="$(php_bin)"
  [ -n "$PHP" ] || fail "No command-line PHP 8 found in /usr/bin on the server"
}

task_check() {
  need_php
  note "PHP $(remote "$PHP -r 'echo PHP_VERSION;'") at $PHP"
  note "rsync: $(remote 'command -v rsync >/dev/null && echo available || echo missing')"
  note "crontab: $(remote 'command -v crontab >/dev/null && echo available || echo missing')"
  note "Server time: $(remote 'date "+%H:%M %Z"')"
  note "BINS folder: $(remote "[ -d '$DEPLOY_PATH' ] && echo present || echo missing")"
  note "Code deployed: $(remote "[ -f '$DEPLOY_PATH/bootstrap.php' ] && echo yes || echo no")"
  note "config.php present: $(remote "[ -f '$DEPLOY_PATH/config.php' ] && echo yes || echo no")"
  note "Cron jobs: sync $(remote "crontab -l 2>/dev/null | grep -q '$SYNC_TAG' && echo installed || echo 'not installed'"), reminders $(remote "crontab -l 2>/dev/null | grep -q '$REMIND_TAG' && echo installed || echo 'not installed'")"
  if remote "[ -f '$DEPLOY_PATH/bin/healthcheck.php' ] && [ -f '$DEPLOY_PATH/config.php' ]"; then
    report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php" || true
  fi
}

need_config() {
  remote "[ -f '$DEPLOY_PATH/config.php' ]" || fail "config.php is not on the server yet (expected in the $DEPLOY_PATH folder)"
}

task_deploy() {
  local rev; rev="$(git rev-parse --short HEAD)"
  if remote 'command -v rsync >/dev/null'; then
    # --delete keeps the server in step with the repository; the P (protect) rules
    # stop it removing the server's own config.php, config.mail.php and data/.
    rsync -az --delete \
      --filter='P /config.php' \
      --filter='P /config.mail.php' \
      --filter='P /data/' \
      --filter='P /public/data/*.json' \
      --exclude='/.git/' --exclude='/.github/' --exclude='/tests/' --exclude='/deploy/' \
      -e "ssh ${ssh_opts[*]}" \
      ./ "$SSH_USER@$SSH_HOST:$DEPLOY_PATH/" 2>&1 | redact
    note "Deployed $rev with rsync"
  else
    tar czf - --exclude=./.git --exclude=./.github --exclude=./tests --exclude=./deploy . \
      | remote "mkdir -p '$DEPLOY_PATH' && tar xzf - -C '$DEPLOY_PATH'" 2>&1 | redact
    note "Deployed $rev with tar (rsync is missing, so files deleted from the repository stay on the server)"
  fi

  if remote "[ -f '$DEPLOY_PATH/config.php' ]"; then
    need_php
    report "cd '$DEPLOY_PATH' && $PHP bin/migrate.php"
  else
    note "Database update skipped: config.php is not on the server yet"
  fi
}

task_setup() {
  need_php
  need_config
  remote "chmod 600 '$DEPLOY_PATH/config.php' && mkdir -p '$DEPLOY_PATH/data/cache' '$DEPLOY_PATH/public/data'"
  report "cd '$DEPLOY_PATH' && $PHP bin/migrate.php"
  report "cd '$DEPLOY_PATH' && $PHP bin/sync.php" || true
  install_cron
  report "cd '$DEPLOY_PATH' && $PHP bin/healthcheck.php" || true
}

# The sync reads the council calendars at 05:23 and 16:23 server time (CET, an hour
# ahead of the UK). Reminders run every 15 minutes; the script itself only acts
# from 18:00 to 22:00 and 06:30 to 08:00 UK time. Output goes to data/cache/*.log.
install_cron() {
  remote "dir=\$(cd ~/'$DEPLOY_PATH' && pwd) \
    && sync=\"23 5,16 * * * $PHP \$dir/bin/sync.php >> \$dir/data/cache/sync.log 2>&1 $SYNC_TAG\" \
    && remind=\"1,16,31,46 * * * * $PHP \$dir/bin/send-reminders.php >> \$dir/data/cache/reminders.log 2>&1 $REMIND_TAG\" \
    && (crontab -l 2>/dev/null | grep -vF '$SYNC_TAG' | grep -vF '$REMIND_TAG'; echo \"\$sync\"; echo \"\$remind\") | crontab -"
  note "Cron jobs: $(remote "crontab -l 2>/dev/null | grep -cF -e '$SYNC_TAG' -e '$REMIND_TAG'") installed (sync 05:23 and 16:23 server time; reminders every 15 minutes)"
}

task_simple() {
  need_php
  need_config
  report "cd '$DEPLOY_PATH' && $PHP $1"
}

case "${1:-}" in
  check)  connect; task_check ;;
  deploy) connect; task_deploy ;;
  setup)  connect; task_setup ;;
  sync)   connect; task_simple "bin/sync.php"; task_simple "bin/healthcheck.php" || true ;;
  cron)   connect; need_php; install_cron ;;
  test-reminder) connect; task_simple "bin/send-reminders.php --test" ;;
  signup-open)   connect; task_simple "bin/signup.php open" ;;
  signup-close)  connect; task_simple "bin/signup.php close" ;;
  signup-status) connect; task_simple "bin/signup.php status" ;;
  *) echo "Usage: $0 check|deploy|setup|sync|cron|test-reminder|signup-open|signup-close|signup-status" >&2; exit 2 ;;
esac
