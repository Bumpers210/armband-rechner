#!/usr/bin/env bash

set -euo pipefail

BOOTSTRAP_SOURCE=${1:?Pfad zum Bootstrap-Skript fehlt.}
DEPLOY_SOURCE=${2:?Pfad zum Produktionsdeploy-Skript fehlt.}
ROOT=$(mktemp -d /tmp/carmaja-production-bootstrap-test.XXXXXX)
COMMIT_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
RELEASE_ID="${COMMIT_SHA}-123-1"

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
for directory in "$WORKSPACE" "$WORKSPACE/incoming" "$WORKSPACE/releases" "$WORKSPACE/backups" "$WORKSPACE/state" "$WORKSPACE/locks"; do
    assert_mode 750 "$directory"
done

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

printf 'Produktions-Bootstrap-Test erfolgreich: Inventur, Abbruch, Schutz und Rollback geprueft.\n'
