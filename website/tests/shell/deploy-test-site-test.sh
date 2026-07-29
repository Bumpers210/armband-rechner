#!/bin/sh

set -eu

SOURCE_SCRIPT=${1:?Pfad zum Deploymentskript fehlt.}
ROOT=$(mktemp -d /tmp/carmaja-deploy-shell-test.XXXXXX)
WEBROOT="$ROOT/webroot"
WORKSPACE="$ROOT/workspace"
PATCHED_SCRIPT="$ROOT/deploy-test-site.sh"
FAILED_SCRIPT="$ROOT/deploy-test-site-failure.sh"
LATE_FAILED_SCRIPT="$ROOT/deploy-test-site-late-failure.sh"

cleanup()
{
    case "$ROOT" in
        /tmp/carmaja-deploy-shell-test.*)
            find "$ROOT" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
            find "$ROOT" -depth -type d -exec rmdir -- {} \;
            ;;
        *)
            printf '%s\n' 'Unsicheres Testverzeichnis; Bereinigung abgebrochen.' >&2
            ;;
    esac
}

trap cleanup EXIT HUP INT TERM

mkdir -p \
    "$WEBROOT" \
    "$WORKSPACE/incoming" \
    "$WORKSPACE/releases" \
    "$WORKSPACE/backups" \
    "$WORKSPACE/state" \
    "$WORKSPACE/locks"

sed \
    -e "s#^WEBROOT='/home/www/carmaja-test-site'\$#WEBROOT='$WEBROOT'#" \
    -e "s#^WORKSPACE='/home/www/carmaja-test-deploy'\$#WORKSPACE='$WORKSPACE'#" \
    "$SOURCE_SCRIPT" > "$PATCHED_SCRIPT"
chmod 0700 "$PATCHED_SCRIPT"

create_package()
{
    commit_sha=$1
    release_id=$2
    source_directory=$3
    manifest="$WORKSPACE/incoming/$release_id.manifest.tsv"
    archive="$WORKSPACE/incoming/$release_id.tar.gz"

    {
        printf 'manifest\t1\n'
        printf 'meta\trepository\tBumpers210/armband-rechner\n'
        printf 'meta\tbranch\ttest/product-management-beta\n'
        printf 'meta\ttarget\ttest\n'
        printf 'meta\tdomain\ttest.carmaja-perlen.de\n'
        printf 'meta\twebroot\t%s\n' "$WEBROOT"
        printf 'meta\tworkspace\t%s\n' "$WORKSPACE"
        printf 'meta\tcommit\t%s\n' "$commit_sha"
        printf 'meta\trelease\t%s\n' "$release_id"

        (
            cd "$source_directory"
            find . -type f -print \
                | sed 's#^\./##' \
                | LC_ALL=C sort \
                | while IFS= read -r relative_path; do
                    file_hash=$(sha256sum "$relative_path" | awk '{ print $1 }')
                    file_size=$(wc -c < "$relative_path" | tr -d ' ')
                    printf 'file\t%s\t%s\t%s\n' "$file_hash" "$file_size" "$relative_path"
                done
        )
    } > "$manifest"

    tar -C "$source_directory" -czf "$archive" .
    archive_hash=$(sha256sum "$archive" | awk '{ print $1 }')
    printf '%s  site.tar.gz\n' "$archive_hash" > "$archive.sha256"
}

run_action()
{
    script=$1
    action=$2
    commit_sha=$3
    release_id=$4
    archive_hash=${5:-}

    CARMAJA_REPOSITORY='Bumpers210/armband-rechner' \
    CARMAJA_BRANCH='test/product-management-beta' \
    CARMAJA_SITE_TARGET='test' \
    CARMAJA_SITE_DOMAIN='test.carmaja-perlen.de' \
    CARMAJA_TEST_WEBROOT="$WEBROOT" \
    CARMAJA_TEST_DEPLOY_WORKSPACE="$WORKSPACE" \
    CARMAJA_PRODUCTION_PUBLISH_ENABLED='false' \
    CARMAJA_PRODUCTION_DEPLOY_ENABLED='false' \
    CARMAJA_COMMIT_SHA="$commit_sha" \
    CARMAJA_RELEASE_ID="$release_id" \
    CARMAJA_ARCHIVE_SHA256="$archive_hash" \
    CARMAJA_DEPLOY_ACTION="$action" \
        sh "$script"
}

COMMIT_ONE='1111111111111111111111111111111111111111'
RELEASE_ONE="$COMMIT_ONE-1"
SOURCE_ONE="$ROOT/source-one"
mkdir -p "$SOURCE_ONE/armbaender/alt" "$SOURCE_ONE/_next"
printf '%s\n' 'version-one' > "$SOURCE_ONE/index.html"
printf '%s\n' 'AuthType Basic' > "$SOURCE_ONE/.htaccess"
printf '%s\n' 'old-product' > "$SOURCE_ONE/armbaender/alt/index.html"
printf '%s\n' 'next-metadata' > "$SOURCE_ONE/_next/chunk\$hash~id.js"
create_package "$COMMIT_ONE" "$RELEASE_ONE" "$SOURCE_ONE"
HASH_ONE=$(sha256sum "$WORKSPACE/incoming/$RELEASE_ONE.tar.gz" | awk '{ print $1 }')
run_action "$PATCHED_SCRIPT" deploy "$COMMIT_ONE" "$RELEASE_ONE" "$HASH_ONE"
run_action "$PATCHED_SCRIPT" mark_verified "$COMMIT_ONE" "$RELEASE_ONE"

grep -Fx 'version-one' "$WEBROOT/index.html" > /dev/null
grep -Fx 'AuthType Basic' "$WEBROOT/.htaccess" > /dev/null
grep -Fx 'old-product' "$WEBROOT/armbaender/alt/index.html" > /dev/null
grep -Fx 'next-metadata' "$WEBROOT/_next/chunk\$hash~id.js" > /dev/null
grep -Fx 'status=verified' "$WORKSPACE/state/status.env" > /dev/null

COMMIT_TWO='2222222222222222222222222222222222222222'
RELEASE_TWO="$COMMIT_TWO-2"
SOURCE_TWO="$ROOT/source-two"
mkdir -p "$SOURCE_TWO/armbaender/neu"
printf '%s\n' 'version-two' > "$SOURCE_TWO/index.html"
printf '%s\n' 'AuthType Basic' > "$SOURCE_TWO/.htaccess"
printf '%s\n' 'new-product' > "$SOURCE_TWO/armbaender/neu/index.html"
create_package "$COMMIT_TWO" "$RELEASE_TWO" "$SOURCE_TWO"
HASH_TWO=$(sha256sum "$WORKSPACE/incoming/$RELEASE_TWO.tar.gz" | awk '{ print $1 }')
run_action "$PATCHED_SCRIPT" deploy "$COMMIT_TWO" "$RELEASE_TWO" "$HASH_TWO"

grep -Fx 'version-two' "$WEBROOT/index.html" > /dev/null
grep -Fx 'new-product' "$WEBROOT/armbaender/neu/index.html" > /dev/null
[ ! -e "$WEBROOT/armbaender/alt/index.html" ]
[ -f "$WORKSPACE/backups/before-$RELEASE_TWO/files/index.html" ]

run_action "$PATCHED_SCRIPT" rollback "$COMMIT_TWO" "$RELEASE_TWO"
grep -Fx 'version-one' "$WEBROOT/index.html" > /dev/null
grep -Fx 'old-product' "$WEBROOT/armbaender/alt/index.html" > /dev/null
grep -Fx 'next-metadata' "$WEBROOT/_next/chunk\$hash~id.js" > /dev/null
[ ! -e "$WEBROOT/armbaender/neu/index.html" ]
grep -Fx 'status=rolled_back' "$WORKSPACE/state/status.env" > /dev/null

sed \
    's/^# CARMAJA_TEST_ROLLBACK_POINT$/false # simulated activation failure/' \
    "$PATCHED_SCRIPT" > "$FAILED_SCRIPT"
chmod 0700 "$FAILED_SCRIPT"

COMMIT_THREE='3333333333333333333333333333333333333333'
RELEASE_THREE="$COMMIT_THREE-3"
SOURCE_THREE="$ROOT/source-three"
mkdir -p "$SOURCE_THREE/armbaender/fehler"
printf '%s\n' 'version-three' > "$SOURCE_THREE/index.html"
printf '%s\n' 'AuthType Basic' > "$SOURCE_THREE/.htaccess"
printf '%s\n' 'failed-product' > "$SOURCE_THREE/armbaender/fehler/index.html"
create_package "$COMMIT_THREE" "$RELEASE_THREE" "$SOURCE_THREE"
HASH_THREE=$(sha256sum "$WORKSPACE/incoming/$RELEASE_THREE.tar.gz" | awk '{ print $1 }')

if run_action "$FAILED_SCRIPT" deploy "$COMMIT_THREE" "$RELEASE_THREE" "$HASH_THREE"; then
    printf '%s\n' 'Simulierter Aktivierungsfehler wurde nicht erkannt.' >&2
    exit 1
fi

grep -Fx 'version-one' "$WEBROOT/index.html" > /dev/null
grep -Fx 'old-product' "$WEBROOT/armbaender/alt/index.html" > /dev/null
grep -Fx 'next-metadata' "$WEBROOT/_next/chunk\$hash~id.js" > /dev/null
[ ! -e "$WEBROOT/armbaender/fehler/index.html" ]
grep -Fx 'status=failed_rolled_back' "$WORKSPACE/state/status.env" > /dev/null

sed \
    's/^# CARMAJA_TEST_POST_STATE_ROLLBACK_POINT$/false # simulated late activation failure/' \
    "$PATCHED_SCRIPT" > "$LATE_FAILED_SCRIPT"
chmod 0700 "$LATE_FAILED_SCRIPT"

COMMIT_FOUR='4444444444444444444444444444444444444444'
RELEASE_FOUR="$COMMIT_FOUR-4"
SOURCE_FOUR="$ROOT/source-four"
mkdir -p "$SOURCE_FOUR/armbaender/spaet"
printf '%s\n' 'version-four' > "$SOURCE_FOUR/index.html"
printf '%s\n' 'AuthType Basic' > "$SOURCE_FOUR/.htaccess"
printf '%s\n' 'late-failed-product' > "$SOURCE_FOUR/armbaender/spaet/index.html"
create_package "$COMMIT_FOUR" "$RELEASE_FOUR" "$SOURCE_FOUR"
HASH_FOUR=$(sha256sum "$WORKSPACE/incoming/$RELEASE_FOUR.tar.gz" | awk '{ print $1 }')

if run_action "$LATE_FAILED_SCRIPT" deploy "$COMMIT_FOUR" "$RELEASE_FOUR" "$HASH_FOUR"; then
    printf '%s\n' 'Spaeter simulierter Aktivierungsfehler wurde nicht erkannt.' >&2
    exit 1
fi

grep -Fx 'version-one' "$WEBROOT/index.html" > /dev/null
grep -Fx 'old-product' "$WEBROOT/armbaender/alt/index.html" > /dev/null
grep -Fx 'next-metadata' "$WEBROOT/_next/chunk\$hash~id.js" > /dev/null
[ ! -e "$WEBROOT/armbaender/spaet/index.html" ]
grep -Fx 'status=failed_rolled_back' "$WORKSPACE/state/status.env" > /dev/null
grep -Fx "meta	commit	$COMMIT_ONE" "$WORKSPACE/state/current-manifest.tsv" > /dev/null
[ ! -e "$WORKSPACE/state/rollback-$RELEASE_FOUR.txt" ]

printf '%s\n' 'Deployment-Shell-Test erfolgreich.'
