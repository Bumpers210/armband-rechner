#!/usr/bin/env bash

set -euo pipefail

BOOTSTRAP_SOURCE=${1:?Pfad zum Bootstrap-Skript fehlt.}
DEPLOY_SOURCE=${2:?Pfad zum Produktionsdeploy-Skript fehlt.}
REPAIR_SOURCE=${3:-"$(dirname "$BOOTSTRAP_SOURCE")/repair-production-bootstrap-rollback-records.sh"}
ROOT=$(mktemp -d /tmp/carmaja-production-bootstrap-test.XXXXXX)
COMMIT_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
RELEASE_ID="${COMMIT_SHA}-123-1"

[ -f "$REPAIR_SOURCE" ]
bash -n "$REPAIR_SOURCE"

cleanup() {
    case "$ROOT" in
        /tmp/carmaja-production-bootstrap-test.*)
            find "$ROOT" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
            find "$ROOT" -depth -type d -exec rmdir -- {} \;
            ;;
        *) printf '%s\n' 'Unsicheres Bootstrap-Testverzeichnis; Bereinigung abgebrochen.' >&2 ;;
    esac
}
trap cleanup EXIT HUP INT TERM

assert_mode() {
    [ "$(stat -c '%a' "$2")" = "$1" ] || exit 1
}

tree_digest() {
    (
        cd "$1"
        find -P . -xdev -type f -printf '%P\n' | LC_ALL=C sort | while IFS= read -r file_path; do
            printf '%s\t%s\n' "$file_path" "$(sha256sum "$1/$file_path" | awk '{print $1}')"
        done | sha256sum | awk '{print $1}'
    )
}

snapshot_tree() {
    (
        cd "$1"
        find . -type f -print | LC_ALL=C sort | while IFS= read -r file_path; do
            printf '%s|%s|%s|%s\n' "$file_path" \
                "$(sha256sum "$file_path" | awk '{print $1}')" \
                "$(wc -c < "$file_path" | tr -d ' ')" \
                "$(stat -c '%a' "$file_path")"
        done
    )
}

write_candidate_file() {
    local root=$1 path=$2
    mkdir -p "$(dirname "$root/$path")"
    printf 'candidate:%s\n' "$path" > "$root/$path"
    chmod 0644 "$root/$path"
}

patch_bootstrap() {
    local source=$1 destination=$2 webroot=$3 workspace=$4 archive_hash=$5 manifest_hash=$6 inventory_hash=$7
    sed \
        -e "s#^WEBROOT='/home/www/carmaja'\$#WEBROOT='$webroot'#" \
        -e "s#^WORKSPACE='/home/www/carmaja-production-deploy'\$#WORKSPACE='$workspace'#" \
        -e "s#^EXPECTED_CANDIDATE_COMMIT=.*\$#EXPECTED_CANDIDATE_COMMIT='$COMMIT_SHA'#" \
        -e "s#^EXPECTED_CANDIDATE_RELEASE=.*\$#EXPECTED_CANDIDATE_RELEASE='$RELEASE_ID'#" \
        -e "s#^EXPECTED_CANDIDATE_ARCHIVE_SHA256=.*\$#EXPECTED_CANDIDATE_ARCHIVE_SHA256='$archive_hash'#" \
        -e "s#^EXPECTED_CANDIDATE_MANIFEST_SHA256=.*\$#EXPECTED_CANDIDATE_MANIFEST_SHA256='$manifest_hash'#" \
        -e "s#^EXPECTED_INVENTORY_SHA256=.*\$#EXPECTED_INVENTORY_SHA256='$inventory_hash'#" \
        "$source" > "$destination"
    chmod 0700 "$destination"
}

patch_failing_deploy() {
    local source=$1 destination=$2 webroot=$3 workspace=$4
    sed \
        -e "s#^WEBROOT='/home/www/carmaja'\$#WEBROOT='$webroot'#" \
        -e "s#^WORKSPACE='/home/www/carmaja-production-deploy'\$#WORKSPACE='$workspace'#" \
        "$source" | sed '/CARMAJA_PRODUCTION_ROLLBACK_POINT/a false' > "$destination"
    chmod 0700 "$destination"
}

patch_repair() {
    local source=$1 destination=$2 webroot=$3 workspace=$4 archive_hash=$5 manifest_hash=$6 inventory_hash=$7 current_manifest_hash=$8 webroot_digest=$9 webroot_file_count=${10}
    sed \
        -e "s#^WEBROOT='/home/www/carmaja'\$#WEBROOT='$webroot'#" \
        -e "s#^WORKSPACE='/home/www/carmaja-production-deploy'\$#WORKSPACE='$workspace'#" \
        -e "s#^EXPECTED_CANDIDATE_COMMIT=.*\$#EXPECTED_CANDIDATE_COMMIT='$COMMIT_SHA'#" \
        -e "s#^EXPECTED_CANDIDATE_RELEASE=.*\$#EXPECTED_CANDIDATE_RELEASE='$RELEASE_ID'#" \
        -e "s#^EXPECTED_CANDIDATE_ARCHIVE_SHA256=.*\$#EXPECTED_CANDIDATE_ARCHIVE_SHA256='$archive_hash'#" \
        -e "s#^EXPECTED_CANDIDATE_MANIFEST_SHA256=.*\$#EXPECTED_CANDIDATE_MANIFEST_SHA256='$manifest_hash'#" \
        -e "s#^EXPECTED_INVENTORY_SHA256=.*\$#EXPECTED_INVENTORY_SHA256='$inventory_hash'#" \
        -e "s#^EXPECTED_CURRENT_MANIFEST_SHA256=.*\$#EXPECTED_CURRENT_MANIFEST_SHA256='$current_manifest_hash'#" \
        -e "s#^EXPECTED_WEBROOT_SNAPSHOT_SHA256=.*\$#EXPECTED_WEBROOT_SNAPSHOT_SHA256='$webroot_digest'#" \
        -e "s#^EXPECTED_WEBROOT_FILE_COUNT=.*\$#EXPECTED_WEBROOT_FILE_COUNT='$webroot_file_count'#" \
        -e "s#^EXPECTED_BACKUP_ID=.*\$#EXPECTED_BACKUP_ID='bootstrap-unmanaged-$COMMIT_SHA'#" \
        "$source" > "$destination"
    chmod 0700 "$destination"
}

assert_rollback_records() {
    local records=$1 expected="$FIXTURE/expected-rollback-records.txt" actual="$FIXTURE/actual-rollback-records.txt"
    [ "$(wc -l < "$records" | tr -d ' ')" -eq "${#MISSING_PATHS[@]}" ]
    awk -F '|' '
        NF != 2 || $1 != "previously-missing" || $2 == "" { invalid++ }
        END { exit invalid ? 1 : 0 }
    ' "$records"
    ! grep -F '\n' "$records" > /dev/null
    awk -F '|' '{ print $2 }' "$records" | LC_ALL=C sort > "$actual"
    printf '%s\n' "${MISSING_PATHS[@]}" | LC_ALL=C sort > "$expected"
    cmp -s "$expected" "$actual"
    [ "$(uniq -d "$actual" | wc -l | tr -d ' ')" -eq 0 ]
}

write_legacy_broken_records() {
    local inventory=$1 destination=$2
    : > "$destination"
    while IFS='|' read -r record_type _hash _size _mode path; do
        [ "$record_type" = 'missing' ] || continue
        printf 'previously-missing|%s\\n' "$path" >> "$destination"
    done < "$inventory"
    chmod 0640 "$destination"
}

setup_fixture() {
    local name=$1 mode=$2 path number destination inventory_path
    FIXTURE="$ROOT/$name"
    WEBROOT="$FIXTURE/webroot"
    WORKSPACE="$FIXTURE/workspace"
    CANDIDATE_SOURCE="$FIXTURE/candidate"
    BIN="$FIXTURE/bin"
    BOOTSTRAP_SCRIPT="$BIN/bootstrap-production-first-deploy.sh"
    DEPLOY_SCRIPT="$BIN/deploy-production-site-failure.sh"
    INVENTORY="$BIN/production-first-deploy-inventory.v1"
    MANIFEST="$WORKSPACE/incoming/$RELEASE_ID.manifest.tsv"
    ARCHIVE="$WORKSPACE/incoming/$RELEASE_ID.tar.gz"
    CHECKSUM="$WORKSPACE/incoming/$RELEASE_ID.tar.gz.sha256"
    CURRENT_MANIFEST="$WORKSPACE/state/current-manifest.tsv"
    BOOTSTRAP_MARKER="$WORKSPACE/state/bootstrap-first-deploy.env"
    BACKUP_DIRECTORY="$WORKSPACE/backups/bootstrap-unmanaged-$COMMIT_SHA"
    ROLLBACK_RECORDS="$BACKUP_DIRECTORY/previously-missing-paths.v1"
    CANDIDATE_PATHS=()
    EXISTING_PATHS=()
    DIFFERENT_PATHS=()
    MISSING_PATHS=()

    mkdir -p "$WEBROOT" "$WORKSPACE/incoming" "$WORKSPACE/releases" "$WORKSPACE/backups" "$WORKSPACE/state" "$WORKSPACE/locks" "$BIN"
    chmod 0755 "$WEBROOT"
    chmod 0750 "$WORKSPACE" "$WORKSPACE"/*

    for number in $(seq -w 1 18); do
        path="existing/identical-${number}.txt"
        CANDIDATE_PATHS+=("$path")
        EXISTING_PATHS+=("$path")
    done
    for number in $(seq -w 1 32); do
        path="existing/different-${number}.txt"
        CANDIDATE_PATHS+=("$path")
        EXISTING_PATHS+=("$path")
        DIFFERENT_PATHS+=("$path")
    done
    for number in $(seq -w 1 19); do
        path="new/missing-${number}.txt"
        CANDIDATE_PATHS+=("$path")
        MISSING_PATHS+=("$path")
    done
    MISSING_PATHS[0]='new/missing-special_~$-name..txt'
    CANDIDATE_PATHS[50]="${MISSING_PATHS[0]}"
    if [ "$mode" = 'protected' ]; then
        CANDIDATE_PATHS[0]='.htaccess'
        EXISTING_PATHS[0]='.htaccess'
    fi

    for path in "${CANDIDATE_PATHS[@]}"; do
        write_candidate_file "$CANDIDATE_SOURCE" "$path"
    done
    for path in "${EXISTING_PATHS[@]}"; do
        destination="$WEBROOT/$path"
        mkdir -p "$(dirname "$destination")"
        cp "$CANDIDATE_SOURCE/$path" "$destination"
        chmod 0644 "$destination"
    done
    for path in "${DIFFERENT_PATHS[@]}"; do
        printf 'previous:%s\n' "$path" > "$WEBROOT/$path"
        chmod 0644 "$WEBROOT/$path"
    done

    if [ "$mode" != 'protected' ]; then
        printf 'protected-htaccess\n' > "$WEBROOT/.htaccess"
    fi
    printf 'protected-click-handler\n' > "$WEBROOT/click.php"
    mkdir -p "$WEBROOT/_internal" "$WEBROOT/statistik" "$WEBROOT/private-data"
    printf 'protected-tracking\n' > "$WEBROOT/_internal/tracking.php"
    printf 'protected-statistics\n' > "$WEBROOT/statistik/index.php"
    printf 'protected-private\n' > "$WEBROOT/private-data/state.json"
    printf 'unknown-unmanaged\n' > "$WEBROOT/legacy-unmanaged.txt"
    chmod 0644 "$WEBROOT/.htaccess" "$WEBROOT/click.php" "$WEBROOT/_internal/tracking.php" "$WEBROOT/statistik/index.php" "$WEBROOT/private-data/state.json" "$WEBROOT/legacy-unmanaged.txt"
    chmod 0755 "$WEBROOT/_internal" "$WEBROOT/statistik" "$WEBROOT/private-data"

    {
        printf 'manifest\t1\n'
        printf 'meta\trepository\tBumpers210/armband-rechner\n'
        printf 'meta\tbranch\tmain\n'
        printf 'meta\ttarget\tproduction\n'
        printf 'meta\tdomain\twww.carmaja-perlen.de\n'
        printf 'meta\twebroot\t%s\n' "$WEBROOT"
        printf 'meta\tworkspace\t%s\n' "$WORKSPACE"
        printf 'meta\tcommit\t%s\n' "$COMMIT_SHA"
        printf 'meta\trelease\t%s\n' "$RELEASE_ID"
        for path in "${CANDIDATE_PATHS[@]}"; do
            printf 'file\t%s\t%s\t%s\n' "$(sha256sum "$CANDIDATE_SOURCE/$path" | awk '{print $1}')" "$(wc -c < "$CANDIDATE_SOURCE/$path" | tr -d ' ')" "$path"
        done
    } > "$MANIFEST"
    tar -C "$CANDIDATE_SOURCE" -czf "$ARCHIVE" .
    ARCHIVE_HASH=$(sha256sum "$ARCHIVE" | awk '{print $1}')
    printf '%s  site.tar.gz\n' "$ARCHIVE_HASH" > "$CHECKSUM"
    MANIFEST_HASH=$(sha256sum "$MANIFEST" | awk '{print $1}')

    {
        printf 'inventory|1\n'
        printf 'meta|repository|Bumpers210/armband-rechner\n'
        printf 'meta|branch|main\n'
        printf 'meta|target|production\n'
        printf 'meta|domain|www.carmaja-perlen.de\n'
        printf 'meta|webroot|%s\n' "$WEBROOT"
        printf 'meta|workspace|%s\n' "$WORKSPACE"
        printf 'meta|candidate-commit|%s\n' "$COMMIT_SHA"
        printf 'meta|candidate-release|%s\n' "$RELEASE_ID"
        printf 'meta|candidate-archive-sha256|%s\n' "$ARCHIVE_HASH"
        printf 'meta|candidate-manifest-sha256|%s\n' "$MANIFEST_HASH"
        for path in "${EXISTING_PATHS[@]}"; do
            inventory_path=$path
            if [ "$mode" = 'unknown' ] && [ "$path" = "${EXISTING_PATHS[0]}" ]; then
                inventory_path='unknown/not-in-candidate.txt'
            fi
            printf 'existing|%s|%s|%s|%s\n' "$(sha256sum "$WEBROOT/$path" | awk '{print $1}')" "$(wc -c < "$WEBROOT/$path" | tr -d ' ')" "$(stat -c '%a' "$WEBROOT/$path")" "$inventory_path"
        done
        for path in "${MISSING_PATHS[@]}"; do
            printf 'missing|-|-|-|%s\n' "$path"
        done
    } > "$INVENTORY"
    INVENTORY_HASH=$(sha256sum "$INVENTORY" | awk '{print $1}')
    patch_bootstrap "$BOOTSTRAP_SOURCE" "$BOOTSTRAP_SCRIPT" "$WEBROOT" "$WORKSPACE" "$ARCHIVE_HASH" "$MANIFEST_HASH" "$INVENTORY_HASH"
    patch_failing_deploy "$DEPLOY_SOURCE" "$DEPLOY_SCRIPT" "$WEBROOT" "$WORKSPACE"
}

assert_no_bootstrap_state() {
    [ ! -e "$CURRENT_MANIFEST" ]
    [ ! -e "$BOOTSTRAP_MARKER" ]
    [ ! -e "$BACKUP_DIRECTORY" ]
    ! find "$WORKSPACE/backups" -mindepth 1 -maxdepth 1 -print -quit | grep -q .
}

expect_bootstrap_failure() {
    set +e
    bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log" 2>&1
    local exit_code=$?
    set -e
    [ "$exit_code" -ne 0 ]
}

prepare_repair_script() {
    local current_hash webroot_hash webroot_file_count
    REPAIR_SCRIPT="$BIN/repair-production-bootstrap-rollback-records.sh"
    current_hash=$(sha256sum "$CURRENT_MANIFEST" | awk '{print $1}')
    webroot_hash=$(tree_digest "$WEBROOT")
    webroot_file_count=$(find -P "$WEBROOT" -xdev -type f | wc -l | tr -d ' ')
    patch_repair \
        "$REPAIR_SOURCE" \
        "$REPAIR_SCRIPT" \
        "$WEBROOT" \
        "$WORKSPACE" \
        "$ARCHIVE_HASH" \
        "$MANIFEST_HASH" \
        "$INVENTORY_HASH" \
        "$current_hash" \
        "$webroot_hash" \
        "$webroot_file_count"
    bash -n "$REPAIR_SCRIPT"
}

expect_repair_failure() {
    set +e
    CARMAJA_PRODUCTION_DEPLOY_ENABLED="${1:-false}" \
    CARMAJA_PRODUCTION_PUBLISH_ENABLED="${2:-false}" \
        bash "$REPAIR_SCRIPT" --repair-bootstrap-rollback-records > "$FIXTURE/repair.log" 2>&1
    local exit_code=$?
    set -e
    [ "$exit_code" -ne 0 ]
}

setup_fixture success normal
[ "$(grep -c '^existing|' "$INVENTORY")" -eq 50 ]
[ "$(grep -c '^missing|' "$INVENTORY")" -eq 19 ]
for path in "${DIFFERENT_PATHS[@]}"; do
    [ "$(sha256sum "$WEBROOT/$path" | awk '{print $1}')" != "$(sha256sum "$CANDIDATE_SOURCE/$path" | awk '{print $1}')" ]
done
SUCCESS_SNAPSHOT="$FIXTURE/webroot-before.txt"
snapshot_tree "$WEBROOT" > "$SUCCESS_SNAPSHOT"
bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log"
grep -F 'BOOTSTRAP_FIRST_DEPLOY_STATE_OK existing_paths=50 missing_paths=19' "$FIXTURE/bootstrap.log" > /dev/null
cmp -s "$SUCCESS_SNAPSHOT" <(snapshot_tree "$WEBROOT")
assert_mode 640 "$CURRENT_MANIFEST"
assert_mode 640 "$BOOTSTRAP_MARKER"
grep -F $'meta\tbootstrap-provenance\tno-repository-commit' "$CURRENT_MANIFEST" > /dev/null
[ "$(grep -c $'^file\t' "$CURRENT_MANIFEST")" -eq 50 ]
[ "$(find "$BACKUP_DIRECTORY/files" -type f | wc -l | tr -d ' ')" -eq 50 ]
assert_rollback_records "$ROLLBACK_RECORDS"
grep -Fx 'previously-missing|new/missing-special_~$-name..txt' "$ROLLBACK_RECORDS" > /dev/null
for directory in "$WORKSPACE" "$WORKSPACE/incoming" "$WORKSPACE/releases" "$WORKSPACE/backups" "$WORKSPACE/state" "$WORKSPACE/locks"; do
    assert_mode 750 "$directory"
done

LEGACY_BROKEN_RECORDS="$FIXTURE/legacy-broken-records.v1"
write_legacy_broken_records "$INVENTORY" "$LEGACY_BROKEN_RECORDS"
[ "$(awk 'END { print NR + 0 }' "$LEGACY_BROKEN_RECORDS")" -eq 1 ]
[ "$(wc -l < "$LEGACY_BROKEN_RECORDS" | tr -d ' ')" -eq 0 ]
grep -F '\n' "$LEGACY_BROKEN_RECORDS" > /dev/null

set +e
CARMAJA_REPOSITORY='Bumpers210/armband-rechner' CARMAJA_BRANCH='main' CARMAJA_SITE_TARGET='production' CARMAJA_SITE_DOMAIN='www.carmaja-perlen.de' CARMAJA_PRODUCTION_WEBROOT="$WEBROOT" CARMAJA_PRODUCTION_DEPLOY_WORKSPACE="$WORKSPACE" CARMAJA_PRODUCTION_PUBLISH_ENABLED='false' CARMAJA_PRODUCTION_DEPLOY_ENABLED='true' CARMAJA_COMMIT_SHA="$COMMIT_SHA" CARMAJA_RELEASE_ID="$RELEASE_ID" CARMAJA_ARCHIVE_SHA256="$ARCHIVE_HASH" CARMAJA_DEPLOY_ACTION='deploy' sh "$DEPLOY_SCRIPT" > "$FIXTURE/deploy-rollback.log" 2>&1
DEPLOY_EXIT=$?
set -e
[ "$DEPLOY_EXIT" -ne 0 ]
grep -F 'ROLLBACK_OK phase=activation' "$FIXTURE/deploy-rollback.log" > /dev/null
cmp -s "$SUCCESS_SNAPSHOT" <(snapshot_tree "$WEBROOT")
for path in "${MISSING_PATHS[@]}"; do [ ! -e "$WEBROOT/$path" ]; done

setup_fixture live-change normal
printf 'changed-after-inventory\n' > "$WEBROOT/${DIFFERENT_PATHS[0]}"
snapshot_tree "$WEBROOT" > "$FIXTURE/before.txt"
expect_bootstrap_failure
grep -F 'hat sich seit der Bestaetigungsinventur geaendert' "$FIXTURE/bootstrap.log" > /dev/null
cmp -s "$FIXTURE/before.txt" <(snapshot_tree "$WEBROOT")
assert_no_bootstrap_state

setup_fixture protected protected
snapshot_tree "$WEBROOT" > "$FIXTURE/before.txt"
expect_bootstrap_failure
grep -F 'geschuetzte oder unerlaubte Datei' "$FIXTURE/bootstrap.log" > /dev/null
cmp -s "$FIXTURE/before.txt" <(snapshot_tree "$WEBROOT")
assert_no_bootstrap_state

setup_fixture unknown unknown
snapshot_tree "$WEBROOT" > "$FIXTURE/before.txt"
expect_bootstrap_failure
grep -F 'unbekannten Kandidatenpfad' "$FIXTURE/bootstrap.log" > /dev/null
cmp -s "$FIXTURE/before.txt" <(snapshot_tree "$WEBROOT")
assert_no_bootstrap_state

setup_fixture current-manifest normal
printf 'preexisting-state\n' > "$CURRENT_MANIFEST"
chmod 0640 "$CURRENT_MANIFEST"
CURRENT_HASH=$(sha256sum "$CURRENT_MANIFEST" | awk '{print $1}')
expect_bootstrap_failure
grep -F 'aktives Deploymentmanifest existiert bereits' "$FIXTURE/bootstrap.log" > /dev/null
[ "$(sha256sum "$CURRENT_MANIFEST" | awk '{print $1}')" = "$CURRENT_HASH" ]
[ ! -e "$BOOTSTRAP_MARKER" ]
[ ! -e "$BACKUP_DIRECTORY" ]

setup_fixture atomic-abort normal
sed '/CARMAJA_BOOTSTRAP_TEST_ABORT_POINT/a false' "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap-abort.sh"
mv "$FIXTURE/bootstrap-abort.sh" "$BOOTSTRAP_SCRIPT"
chmod 0700 "$BOOTSTRAP_SCRIPT"
snapshot_tree "$WEBROOT" > "$FIXTURE/before.txt"
expect_bootstrap_failure
cmp -s "$FIXTURE/before.txt" <(snapshot_tree "$WEBROOT")
assert_no_bootstrap_state

setup_fixture incomplete-rollback-record normal
sed '/CARMAJA_BOOTSTRAP_TEST_CORRUPT_ROLLBACK_RECORDS_POINT/a\
printf "%s\\n" "previously-missing|" >> "$STAGE_DIRECTORY/previously-missing-paths.v1"' \
    "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap-incomplete-record.sh"
mv "$FIXTURE/bootstrap-incomplete-record.sh" "$BOOTSTRAP_SCRIPT"
chmod 0700 "$BOOTSTRAP_SCRIPT"
snapshot_tree "$WEBROOT" > "$FIXTURE/before.txt"
expect_bootstrap_failure
grep -F 'enthaelt einen unsicheren Dateipfad' "$FIXTURE/bootstrap.log" > /dev/null
cmp -s "$FIXTURE/before.txt" <(snapshot_tree "$WEBROOT")
assert_no_bootstrap_state

setup_fixture repair-success normal
bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log"
write_legacy_broken_records "$INVENTORY" "$ROLLBACK_RECORDS"
BROKEN_ROLLBACK_HASH=$(sha256sum "$ROLLBACK_RECORDS" | awk '{print $1}')
CURRENT_MANIFEST_HASH=$(sha256sum "$CURRENT_MANIFEST" | awk '{print $1}')
BACKUP_FILES_SNAPSHOT="$FIXTURE/backup-files-before.txt"
snapshot_tree "$BACKUP_DIRECTORY/files" > "$BACKUP_FILES_SNAPSHOT"
REPAIR_WEBROOT_SNAPSHOT="$FIXTURE/webroot-before-repair.txt"
snapshot_tree "$WEBROOT" > "$REPAIR_WEBROOT_SNAPSHOT"
prepare_repair_script
CARMAJA_PRODUCTION_DEPLOY_ENABLED=false CARMAJA_PRODUCTION_PUBLISH_ENABLED=false \
    bash "$REPAIR_SCRIPT" --repair-bootstrap-rollback-records > "$FIXTURE/repair.log"
grep -F 'BOOTSTRAP_ROLLBACK_RECORDS_REPAIR_OK' "$FIXTURE/repair.log" > /dev/null
assert_rollback_records "$ROLLBACK_RECORDS"
[ "$(sha256sum "$CURRENT_MANIFEST" | awk '{print $1}')" = "$CURRENT_MANIFEST_HASH" ]
cmp -s "$BACKUP_FILES_SNAPSHOT" <(snapshot_tree "$BACKUP_DIRECTORY/files")
cmp -s "$REPAIR_WEBROOT_SNAPSHOT" <(snapshot_tree "$WEBROOT")
QUARANTINE_RECORDS="$WORKSPACE/state/quarantine/$(basename "$BACKUP_DIRECTORY")/previously-missing-paths.v1"
[ -f "$QUARANTINE_RECORDS" ]
[ "$(sha256sum "$QUARANTINE_RECORDS" | awk '{print $1}')" = "$BROKEN_ROLLBACK_HASH" ]
assert_mode 750 "$WORKSPACE/state/quarantine"
assert_mode 750 "$(dirname "$QUARANTINE_RECORDS")"
assert_mode 640 "$QUARANTINE_RECORDS"
! find "$WORKSPACE/state" -maxdepth 1 -name '.bootstrap-repair-*' -print -quit | grep -q .

setup_fixture repair-gate normal
bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log"
write_legacy_broken_records "$INVENTORY" "$ROLLBACK_RECORDS"
REPAIR_BEFORE="$FIXTURE/repair-before.txt"
snapshot_tree "$WEBROOT" > "$REPAIR_BEFORE"
prepare_repair_script
expect_repair_failure true false
grep -F 'Produktionsdeployfreigabe muss fuer die Reparatur exakt false sein' "$FIXTURE/repair.log" > /dev/null
cmp -s "$REPAIR_BEFORE" <(snapshot_tree "$WEBROOT")
[ ! -e "$WORKSPACE/state/quarantine" ]
[ "$(awk 'END { print NR + 0 }' "$ROLLBACK_RECORDS")" -eq 1 ]

setup_fixture repair-current-manifest normal
bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log"
write_legacy_broken_records "$INVENTORY" "$ROLLBACK_RECORDS"
prepare_repair_script
sed "s/^EXPECTED_CURRENT_MANIFEST_SHA256=.*/EXPECTED_CURRENT_MANIFEST_SHA256='$(printf '0%.0s' {1..64})'/" \
    "$REPAIR_SCRIPT" > "$FIXTURE/repair-wrong-manifest.sh"
mv "$FIXTURE/repair-wrong-manifest.sh" "$REPAIR_SCRIPT"
chmod 0700 "$REPAIR_SCRIPT"
expect_repair_failure
grep -F 'hat nicht die erwartete Pruefsumme' "$FIXTURE/repair.log" > /dev/null
[ ! -e "$WORKSPACE/state/quarantine" ]
[ "$(awk 'END { print NR + 0 }' "$ROLLBACK_RECORDS")" -eq 1 ]

setup_fixture repair-live-change normal
bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log"
write_legacy_broken_records "$INVENTORY" "$ROLLBACK_RECORDS"
prepare_repair_script
printf 'changed-after-bootstrap\n' > "$WEBROOT/${DIFFERENT_PATHS[0]}"
expect_repair_failure
grep -F 'hat sich seit der Bestaetigungsinventur geaendert' "$FIXTURE/repair.log" > /dev/null
[ ! -e "$WORKSPACE/state/quarantine" ]
[ "$(awk 'END { print NR + 0 }' "$ROLLBACK_RECORDS")" -eq 1 ]

setup_fixture repair-release-present normal
bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log"
write_legacy_broken_records "$INVENTORY" "$ROLLBACK_RECORDS"
prepare_repair_script
mkdir "$WORKSPACE/releases/active-release"
chmod 0750 "$WORKSPACE/releases/active-release"
expect_repair_failure
grep -F 'Ein Produktionsrelease ist vorhanden' "$FIXTURE/repair.log" > /dev/null
[ ! -e "$WORKSPACE/state/quarantine" ]
[ "$(awk 'END { print NR + 0 }' "$ROLLBACK_RECORDS")" -eq 1 ]

setup_fixture repair-backup-symlink normal
bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log"
write_legacy_broken_records "$INVENTORY" "$ROLLBACK_RECORDS"
prepare_repair_script
SYMLINKED_BACKUP_FILE="$BACKUP_DIRECTORY/files/${EXISTING_PATHS[0]}"
rm "$SYMLINKED_BACKUP_FILE"
ln -s "$WEBROOT/${EXISTING_PATHS[0]}" "$SYMLINKED_BACKUP_FILE"
expect_repair_failure
grep -F 'Bootstrap-Sicherung enthaelt einen Symlink' "$FIXTURE/repair.log" > /dev/null
[ ! -e "$WORKSPACE/state/quarantine" ]
[ "$(awk 'END { print NR + 0 }' "$ROLLBACK_RECORDS")" -eq 1 ]

setup_fixture repair-incomplete-record normal
bash "$BOOTSTRAP_SCRIPT" > "$FIXTURE/bootstrap.log"
printf 'previously-missing|incomplete' > "$ROLLBACK_RECORDS"
chmod 0640 "$ROLLBACK_RECORDS"
prepare_repair_script
expect_repair_failure
grep -F 'entspricht nicht dem exakt bekannten Escape-Fehler' "$FIXTURE/repair.log" > /dev/null
[ ! -e "$WORKSPACE/state/quarantine" ]
[ "$(cat "$ROLLBACK_RECORDS")" = 'previously-missing|incomplete' ]

printf 'Produktions-Bootstrap-Test erfolgreich: Inventur, Abbruch, Schutz, Rollback und Reparatur geprueft.\n'
