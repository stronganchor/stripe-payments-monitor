#!/usr/bin/env bash
# Reviewed, explicit-target upgrade helper. Run as root on cPanel:
#   WP_PHP=/opt/cpanel/ea-php82/root/usr/bin/php bash deploy-cpanel.sh DOCROOT OWNER COMMIT
# Uses a dedicated deployment flock, monitor DB locks, and an owned maintenance
# marker. The target's legacy cron runner has no canonical flock to share.
# Caller performs independent HTTP QA. No cron changes, payment APIs, or option-value output.
set -Eeuo pipefail
umask 077

die() { printf 'Deployment stopped: %s\n' "$1" >&2; exit 1; }
[[ $# == 3 ]] || die 'Expected DOCROOT OWNER COMMIT.'
[[ $(id -u) == 0 ]] || die 'Run through the approved root/cPanel access path.'
DOCROOT=${1%/}
OWNER=$2
COMMIT=$3
[[ "$OWNER" =~ ^[a-z][a-z0-9_]{0,31}$ ]] || die 'Invalid owner.'
[[ "$COMMIT" =~ ^[a-f0-9]{40}$ ]] || die 'COMMIT must be the full lowercase 40-character commit hash.'
[[ "$DOCROOT" == /* && "$DOCROOT" != *$'\n'* ]] || die 'Use an absolute, explicit docroot.'
WP_PHP=${WP_PHP:-/opt/cpanel/ea-php82/root/usr/bin/php}
WP_CLI=${WP_CLI:-/usr/local/bin/wp}
for command in realpath stat flock runuser curl tar python3 sha256sum find sort xargs cmp install timeout; do
    command -v "$command" >/dev/null || die "Required executable unavailable: $command"
done
[[ -x "$WP_PHP" && -f "$WP_CLI" ]] || die 'Set WP_PHP and WP_CLI to the approved PHP and WP-CLI paths.'
ACCOUNT=$(getent passwd "$OWNER") || die 'Owner account does not exist.'
IFS=: read -r _ _ OWNER_UID OWNER_GID _ ACCOUNT_HOME _ <<< "$ACCOUNT"
[[ "$ACCOUNT_HOME" == "/home/$OWNER" ]] || die 'Owner home differs from /home/OWNER.'
[[ $(realpath -e -- "$ACCOUNT_HOME") == "$ACCOUNT_HOME" ]] || die 'Account home must be canonical.'
[[ $(realpath -e -- "$DOCROOT") == "$DOCROOT" && "$DOCROOT" == "$ACCOUNT_HOME/"* ]] || die 'Resolved docroot must equal the named path inside the owner home.'
[[ -f "$DOCROOT/wp-load.php" && -d "$DOCROOT/wp-admin" ]] || die 'The named docroot is not a WordPress installation.'
[[ $(stat -c '%u' -- "$DOCROOT") == "$OWNER_UID" ]] || die 'Docroot ownership does not match OWNER.'
PLUGIN="$DOCROOT/wp-content/plugins/stripe-payments-monitor"
[[ -d "$PLUGIN" && ! -L "$PLUGIN" && $(realpath -e -- "$PLUGIN") == "$PLUGIN" ]] || die 'Expected existing plugin directory is missing or not canonical.'
[[ -f "$PLUGIN/stripe-payments-monitor.php" ]] || die 'Expected plugin entry point is missing.'
[[ ! -e "$DOCROOT/.maintenance" && ! -L "$DOCROOT/.maintenance" ]] || die 'A maintenance marker already exists.'
[[ -z $(find "$PLUGIN" -type l -print -quit) ]] || die 'Existing plugin contains symlinks; review before deployment.'

BACKUP_ROOT="$ACCOUNT_HOME/.codex-backups"
[[ ! -L "$BACKUP_ROOT" ]] || die 'Backup parent must not be a symlink.'
install -d -m 0700 -o "$OWNER_UID" -g "$OWNER_GID" -- "$BACKUP_ROOT"
[[ $(realpath -e -- "$BACKUP_ROOT") == "$BACKUP_ROOT" ]] || die 'Backup parent is not canonical.'
[[ ! -L "$BACKUP_ROOT/.spm-deploy.lock" ]] || die 'Deployment lock must not be a symlink.'
exec 9>"$BACKUP_ROOT/.spm-deploy.lock"
flock -n 9 || die 'Another Stripe Payments Monitor deployment is running.'
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$BACKUP_ROOT/spm-$STAMP-$$"
[[ ! -e "$BACKUP" ]] || die 'Backup directory already exists.'
install -d -m 0700 -o "$OWNER_UID" -g "$OWNER_GID" -- "$BACKUP"
[[ $(stat -c '%d' -- "$BACKUP") == $(stat -c '%d' -- "$PLUGIN") ]] || die 'Backup/staging and plugin must share one filesystem for rename rollback.'
LOG="$BACKUP/deploy.log"
touch "$LOG"
chmod 0600 "$LOG"
GUARD_PID=''
MARKER_HASH=''
COMPLETED=0

wp_safe() { runuser -u "$OWNER" -- env HTTPS=on SERVER_PORT=443 SPM_BACKUP_DIR="$BACKUP" "$WP_PHP" "$WP_CLI" --path="$DOCROOT" --skip-plugins --skip-themes "$@"; }
wp_runtime() { runuser -u "$OWNER" -- env HTTPS=on SERVER_PORT=443 SPM_BACKUP_DIR="$BACKUP" "$WP_PHP" "$WP_CLI" --path="$DOCROOT" --skip-themes "$@"; }
manifest() { (cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum --); }
remove_owned_marker() {
    [[ -n "$MARKER_HASH" && -f "$DOCROOT/.maintenance" && ! -L "$DOCROOT/.maintenance" ]] || return 1
    [[ $(sha256sum -- "$DOCROOT/.maintenance" | cut -d ' ' -f 1) == "$MARKER_HASH" ]] || return 1
    unlink -- "$DOCROOT/.maintenance"
}
release_guard() {
    if [[ -n "$GUARD_PID" ]]; then
        touch "$BACKUP/guard-stop"
        wait "$GUARD_PID" 2>/dev/null || true
        GUARD_PID=''
    fi
}
finish() {
    local status=$?
    trap - EXIT INT TERM
    set +e
    if [[ $COMPLETED != 1 && -d "$BACKUP/previous-tree" ]]; then
        printf 'Runtime/deployment validation failed; restoring the saved plugin and scoped options.\n' >&2
        local rollback_ok=1
        if [[ -e "$PLUGIN" ]]; then
            [[ -d "$PLUGIN" && ! -L "$PLUGIN" && ! -e "$BACKUP/failed-tree" ]] && mv -- "$PLUGIN" "$BACKUP/failed-tree" || rollback_ok=0
        fi
        if [[ $rollback_ok == 1 ]]; then
            mv -- "$BACKUP/previous-tree" "$PLUGIN" || rollback_ok=0
            wp_safe eval-file "$BACKUP/restore-options.php" >>"$LOG" 2>&1 || rollback_ok=0
            manifest "$PLUGIN" >"$BACKUP/restored-tree.sha256" 2>>"$LOG" || rollback_ok=0
            cmp -s "$BACKUP/pre-tree.sha256" "$BACKUP/restored-tree.sha256" || rollback_ok=0
            wp_safe eval-file "$BACKUP/export-options.php" >"$BACKUP/options-restored.json" 2>>"$LOG" || rollback_ok=0
            cmp -s "$BACKUP/options-before.json" "$BACKUP/options-restored.json" || rollback_ok=0
            wp_runtime eval-file "$BACKUP/runtime-before.php" >"$BACKUP/runtime-restored.json" 2>>"$LOG" || rollback_ok=0
            cmp -s "$BACKUP/runtime-before.json" "$BACKUP/runtime-restored.json" || rollback_ok=0
        fi
        if [[ $rollback_ok == 1 ]]; then
            remove_owned_marker || rollback_ok=0
        fi
        if [[ $rollback_ok == 1 ]]; then
            printf 'Rollback verified. Private evidence: %s\n' "$BACKUP" >&2
        else
            printf 'ROLLBACK NEEDS ATTENTION. Owned maintenance marker retained if present. Private evidence: %s\n' "$BACKUP" >&2
        fi
    elif [[ $COMPLETED != 1 && -n "$MARKER_HASH" ]]; then
        remove_owned_marker || printf 'Maintenance marker could not be verified for removal; inspect %s\n' "$BACKUP" >&2
    fi
    release_guard
    [[ $COMPLETED == 1 ]] || printf 'See private deployment log: %s\n' "$LOG" >&2
    [[ $COMPLETED == 1 ]] || status=1
    exit "$status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Public immutable source only. Credentials are neither accepted nor sent to codeload.
curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' --connect-timeout 20 --max-time 120 \
    "https://codeload.github.com/stronganchor/stripe-payments-monitor/tar.gz/$COMMIT" -o "$BACKUP/package.tar.gz" >>"$LOG" 2>&1
python3 -I - "$BACKUP/package.tar.gz" "$COMMIT" >>"$LOG" 2>&1 <<'PY'
import pathlib, sys, tarfile
archive, commit = sys.argv[1:]
prefix = 'stripe-payments-monitor-' + commit
with tarfile.open(archive, 'r:gz') as handle:
    members = handle.getmembers()
    if not members or len(members) > 25000 or sum(m.size for m in members) > 250 * 1024 * 1024:
        raise SystemExit('Archive is empty or exceeds the reviewed package limit.')
    seen = set()
    for member in members:
        path = pathlib.PurePosixPath(member.name)
        if (path.is_absolute() or not path.parts or path.parts[0] != prefix or '..' in path.parts
                or not (member.isfile() or member.isdir()) or '\\' in member.name
                or any(ord(c) < 32 or ord(c) == 127 for c in member.name) or member.name in seen):
            raise SystemExit('Archive contains an unsafe, duplicate, or unexpected member.')
        seen.add(member.name)
PY
STAGE="$BACKUP/staged-plugin"
install -d -m 0700 -o "$OWNER_UID" -g "$OWNER_GID" -- "$STAGE"
chown "$OWNER_UID:$OWNER_GID" "$BACKUP/package.tar.gz"
runuser -u "$OWNER" -- tar -xzf "$BACKUP/package.tar.gz" -C "$STAGE" --strip-components=1 --no-same-owner --no-same-permissions >>"$LOG" 2>&1
[[ -f "$STAGE/stripe-payments-monitor.php" && -f "$STAGE/vendor/autoload.php" && -f "$STAGE/includes/monitor.php" ]] || die 'Package is missing runtime files.'
find "$STAGE" -type d -exec chmod 0755 {} +
find "$STAGE" -type f -exec chmod 0644 {} +
"$WP_PHP" -l "$STAGE/stripe-payments-monitor.php" >>"$LOG" 2>&1
while IFS= read -r -d '' file; do "$WP_PHP" -l "$file" >>"$LOG" 2>&1; done < <(find "$STAGE/includes" -type f -name '*.php' -print0)
manifest "$STAGE" >"$BACKUP/package-tree.sha256"
printf '%s\n' "$COMMIT" >"$BACKUP/source-commit.txt"

cat >"$BACKUP/preflight.php" <<'PHP'
<?php
if (is_multisite()) { WP_CLI::error('This helper requires an explicitly reviewed single-site install.'); }
foreach (['core_updater.lock', 'auto_updater.lock'] as $name) {
    if (get_option($name, false) || get_site_option($name, false)) { WP_CLI::error('A WordPress updater lock exists.'); }
}
$lease = get_option('anchor_temp_admin_automation_lease', []);
if (is_array($lease) && (int) ($lease['expires_at'] ?? 0) > time()) { WP_CLI::error('An Anchor automation lease is active.'); }
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_plugin_active('stripe-payments-monitor/stripe-payments-monitor.php')) { WP_CLI::error('The expected plugin is not active.'); }
WP_CLI::success('Explicit target, plugin activation, and coordination checks passed.');
PHP
cat >"$BACKUP/guard.php" <<'PHP'
<?php
global $wpdb;
$held = [];
$directory = getenv('SPM_BACKUP_DIR');
try {
    foreach (['state', 'refresh_stripe', 'refresh_moonclerk'] as $scope) {
        $name = 'spm_' . md5($wpdb->prefix . DB_NAME . $scope);
        if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name))) { throw new RuntimeException('An active monitor operation holds a lock.'); }
        $held[] = $name;
    }
    if (false === file_put_contents($directory . '/guard-ready', 'ready')) { throw new RuntimeException('Cannot confirm deployment guard.'); }
    $deadline = time() + 240;
    while (!file_exists($directory . '/guard-stop') && time() < $deadline) { usleep(200000); }
    if (!file_exists($directory . '/guard-stop')) { throw new RuntimeException('Deployment guard expired.'); }
} finally {
    foreach (array_reverse($held) as $name) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
}
PHP
cat >"$BACKUP/export-options.php" <<'PHP'
<?php
global $wpdb;
$prefixes = ['spm_', 'stripe_pm_', '_transient_spm_', '_transient_timeout_spm_'];
$clauses = [];
foreach ($prefixes as $prefix) { $clauses[] = $wpdb->prepare('option_name LIKE %s', $wpdb->esc_like($prefix) . '%'); }
$rows = $wpdb->get_results('SELECT option_name, option_value, autoload FROM ' . $wpdb->options . ' WHERE ' . implode(' OR ', $clauses) . ' ORDER BY option_name', ARRAY_A);
if ($wpdb->last_error) { WP_CLI::error('Cannot export scoped options.'); }
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
PHP
cat >"$BACKUP/restore-options.php" <<'PHP'
<?php
global $wpdb;
$rows = json_decode(file_get_contents(getenv('SPM_BACKUP_DIR') . '/options-before.json'), true, 512, JSON_THROW_ON_ERROR);
$allowed = static function($name) { return is_string($name) && preg_match('/^(?:spm_|stripe_pm_|_transient_spm_|_transient_timeout_spm_)/', $name); };
if (!is_array($rows)) { WP_CLI::error('Invalid scoped rollback data.'); }
$saved = [];
foreach ($rows as $row) {
    if (!is_array($row) || !$allowed($row['option_name'] ?? null) || !isset($row['option_value'], $row['autoload'])) { WP_CLI::error('Unexpected rollback option.'); }
    $saved[$row['option_name']] = $row;
}
$wpdb->query('START TRANSACTION');
try {
    $names = $wpdb->get_col('SELECT option_name FROM ' . $wpdb->options);
    if ($wpdb->last_error) { throw new RuntimeException('Cannot read rollback option names.'); }
    foreach ($names as $name) {
        if ($allowed($name) && !isset($saved[$name]) && false === $wpdb->delete($wpdb->options, ['option_name' => $name])) { throw new RuntimeException('Cannot remove deployment-created option.'); }
        if ($allowed($name)) { wp_cache_delete($name, 'options'); }
    }
    foreach ($saved as $name => $row) {
        $exists = $wpdb->get_var($wpdb->prepare('SELECT option_id FROM ' . $wpdb->options . ' WHERE option_name = %s', $name));
        $ok = $exists ? $wpdb->update($wpdb->options, ['option_value' => $row['option_value'], 'autoload' => $row['autoload']], ['option_name' => $name]) : $wpdb->insert($wpdb->options, $row);
        if (false === $ok) { throw new RuntimeException('Cannot restore scoped option.'); }
        wp_cache_delete($name, 'options');
    }
    $wpdb->query('COMMIT');
    wp_cache_delete('alloptions', 'options'); wp_cache_delete('notoptions', 'options');
} catch (Throwable $error) {
    $wpdb->query('ROLLBACK'); WP_CLI::error('Scoped options restoration failed; inspect the private backup.');
}
PHP
cat >"$BACKUP/runtime-before.php" <<'PHP'
<?php
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = get_plugin_data(WP_PLUGIN_DIR . '/stripe-payments-monitor/stripe-payments-monitor.php', false, false);
if (!is_plugin_active('stripe-payments-monitor/stripe-payments-monitor.php')) { WP_CLI::error('Monitor must remain active.'); }
echo json_encode(['home' => get_option('home'), 'version' => $plugin['Version'], 'active' => true], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
PHP
cat >"$BACKUP/runtime-after.php" <<'PHP'
<?php
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$before = json_decode(file_get_contents(getenv('SPM_BACKUP_DIR') . '/runtime-before.json'), true, 512, JSON_THROW_ON_ERROR);
$plugin = get_plugin_data(WP_PLUGIN_DIR . '/stripe-payments-monitor/stripe-payments-monitor.php', false, false);
if (get_option('home') !== $before['home'] || !is_plugin_active('stripe-payments-monitor/stripe-payments-monitor.php') || $plugin['Version'] !== '0.6.0') { WP_CLI::error('Home, activation, or version readback failed.'); }
if (!class_exists('SPM_Monitor') || !class_exists('SPM_Monitor_Actions') || !class_exists('SPM_Monitor_Admin') || !class_exists('SPM_Stripe_Source') || !class_exists('SPM_MoonClerk_Source')) { WP_CLI::error('Monitor runtime classes did not load.'); }
if (!is_array(SPM_Monitor::report()) || spm_get_update_branch() !== 'dev') { WP_CLI::error('Report or effective update-branch readback failed.'); }
echo json_encode(['home' => get_option('home'), 'version' => $plugin['Version'], 'active' => true, 'update_branch' => spm_get_update_branch(), 'runtime' => 'ok'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
PHP
chown "$OWNER_UID:$OWNER_GID" "$BACKUP/"*.php
chmod 0600 "$BACKUP/"*.php
wp_safe core is-installed >>"$LOG" 2>&1
wp_safe eval-file "$BACKUP/preflight.php" >>"$LOG" 2>&1
wp_runtime eval-file "$BACKUP/runtime-before.php" >"$BACKUP/runtime-before.json" 2>>"$LOG"
chown "$OWNER_UID:$OWNER_GID" "$BACKUP/runtime-before.json"
wp_safe eval-file "$BACKUP/guard.php" >>"$LOG" 2>&1 &
GUARD_PID=$!
for attempt in {1..50}; do
    [[ -f "$BACKUP/guard-ready" ]] && break
    kill -0 "$GUARD_PID" 2>/dev/null || die 'Monitor deployment guard did not start.'
    sleep 0.2
done
[[ -f "$BACKUP/guard-ready" ]] || die 'Monitor deployment guard timed out.'

# Final target/ownership checks immediately precede the narrow maintenance window.
[[ ! -e "$DOCROOT/.maintenance" && ! -L "$DOCROOT/.maintenance" ]] || die 'A maintenance marker appeared during preparation.'
wp_safe eval-file "$BACKUP/preflight.php" >>"$LOG" 2>&1
manifest "$PLUGIN" >"$BACKUP/pre-tree.sha256"
tar --acls --xattrs --numeric-owner -czpf "$BACKUP/pre-tree.tar.gz" -C "$(dirname "$PLUGIN")" stripe-payments-monitor >>"$LOG" 2>&1
wp_safe eval-file "$BACKUP/export-options.php" >"$BACKUP/options-before.json" 2>>"$LOG"
chown "$OWNER_UID:$OWNER_GID" "$BACKUP/options-before.json"
printf '<?php $upgrading = %s; /* spm-deploy-%s-%s */\n' "$(date +%s)" "$STAMP" "$$" >"$BACKUP/owned-maintenance"
MARKER_HASH=$(sha256sum -- "$BACKUP/owned-maintenance" | cut -d ' ' -f 1)
# A hard link claims the marker atomically and refuses any pre-existing path.
ln -- "$BACKUP/owned-maintenance" "$DOCROOT/.maintenance"
chmod 0644 "$DOCROOT/.maintenance"
chown "$OWNER_UID:$OWNER_GID" "$DOCROOT/.maintenance"
kill -0 "$GUARD_PID" 2>/dev/null || die 'Monitor deployment guard is no longer active.'
mv -- "$PLUGIN" "$BACKUP/previous-tree"
mv -- "$STAGE" "$PLUGIN"
wp_safe option update spm_update_branch dev --autoload=no >>"$LOG" 2>&1
timeout 60 runuser -u "$OWNER" -- env HTTPS=on SERVER_PORT=443 SPM_BACKUP_DIR="$BACKUP" "$WP_PHP" "$WP_CLI" --path="$DOCROOT" --skip-themes eval-file "$BACKUP/runtime-after.php" >"$BACKUP/runtime-after.json" 2>>"$LOG"
manifest "$PLUGIN" >"$BACKUP/live-tree.sha256"
cmp -s "$BACKUP/package-tree.sha256" "$BACKUP/live-tree.sha256" || die 'Live source differs from the pinned package.'
[[ -z $(find "$PLUGIN" \( ! -user "$OWNER" -o -type l \) -print -quit) ]] || die 'Live ownership or symlink verification failed.'
(cd "$BACKUP" && sha256sum package.tar.gz pre-tree.tar.gz options-before.json source-commit.txt package-tree.sha256 live-tree.sha256 runtime-before.json runtime-after.json > SHA256SUMS)
chmod 0600 "$BACKUP/SHA256SUMS"
kill -0 "$GUARD_PID" 2>/dev/null || die 'Monitor deployment guard expired before acceptance.'
remove_owned_marker || die 'Owned maintenance marker could not be verified and removed.'
COMPLETED=1
release_guard
printf 'Deployment verified: Stripe Payments Monitor 0.6.0; update branch dev.\n'
printf 'Source commit: %s\n' "$COMMIT"
printf 'Live manifest SHA-256: %s\n' "$(sha256sum -- "$BACKUP/live-tree.sha256" | cut -d ' ' -f 1)"
printf 'Private rollback/evidence directory: %s\n' "$BACKUP"
