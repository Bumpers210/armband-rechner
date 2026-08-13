#!/bin/sh

set -eu

SOURCE_SCRIPT=${1:?Pfad zum Deploymentskript fehlt.}
ROOT=$(mktemp -d /tmp/carmaja-deploy-shell-test.XXXXXX)
WEBROOT="$ROOT/webroot"
WORKSPACE="$ROOT/workspace"
AUTH_DIRECTORY="$ROOT/external-auth"
AUTH_FILE="$AUTH_DIRECTORY/test-website.htpasswd"
PATCHED_SCRIPT="$ROOT/deploy-test-site.sh"
FAILED_SCRIPT="$ROOT/deploy-test-site-failure.sh"
LATE_FAILED_SCRIPT="$ROOT/deploy-test-site-late-failure.sh"
TAB=$(printf '\t')

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
    "$AUTH_DIRECTORY" \
    "$WORKSPACE/incoming" \
    "$WORKSPACE/releases" \
    "$WORKSPACE/backups" \
    "$WORKSPACE/state" \
    "$WORKSPACE/locks"
chmod 0755 "$WEBROOT"
chmod 0750 \
    "$WORKSPACE" \
    "$WORKSPACE/incoming" \
    "$WORKSPACE/releases" \
    "$WORKSPACE/backups" \
    "$WORKSPACE/state" \
    "$WORKSPACE/locks"

printf '%s\n' 'manually-managed-auth-fixture' > "$AUTH_FILE"
chmod 0711 "$AUTH_DIRECTORY"
chmod 0604 "$AUTH_FILE"
AUTH_FILE_HASH=$(sha256sum "$AUTH_FILE" | awk '{ print $1 }')

sed \
    -e "s#^WEBROOT='/home/www/carmaja-test-site'\$#WEBROOT='$WEBROOT'#" \
    -e "s#^WORKSPACE='/home/www/carmaja-test-deploy'\$#WORKSPACE='$WORKSPACE'#" \
    "$SOURCE_SCRIPT" > "$PATCHED_SCRIPT"
chmod 0700 "$PATCHED_SCRIPT"

assert_auth_file_unchanged()
{
    [ -f "$AUTH_FILE" ]
    [ ! -L "$AUTH_FILE" ]
    [ "$(stat -c '%a' "$AUTH_DIRECTORY")" = '711' ]
    [ "$(stat -c '%a' "$AUTH_FILE")" = '604' ]
    [ "$(sha256sum "$AUTH_FILE" | awk '{ print $1 }')" = "$AUTH_FILE_HASH" ]

    if find "$WEBROOT" "$WORKSPACE" -type f \
        \( -name '.htpasswd' -o -name 'test-website.htpasswd' \) \
        -print -quit | grep -q .; then
        printf '%s\n' 'Deployment hat eine Passwortdatei verwaltet.' >&2
        exit 1
    fi
}

assert_mode()
{
    expected_mode=$1
    checked_path=$2
    actual_mode=$(stat -c '%a' "$checked_path")

    if [ "$actual_mode" != "$expected_mode" ]; then
        printf 'Unerwartete Rechte %s statt %s fuer %s\n' \
            "$actual_mode" \
            "$expected_mode" \
            "$checked_path" >&2
        exit 1
    fi
}

assert_deployment_permissions()
{
    find "$WEBROOT" -type d -print | while IFS= read -r public_directory; do
        assert_mode 755 "$public_directory"
    done
    find "$WEBROOT" -type f -print | while IFS= read -r public_file; do
        assert_mode 644 "$public_file"
    done
    find "$WORKSPACE" -type d -print | while IFS= read -r private_directory; do
        assert_mode 750 "$private_directory"
    done
}

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
mkdir -p \
    "$SOURCE_ONE/armbaender/alt" \
    "$SOURCE_ONE/images/bracelets/alt/gallery" \
    "$SOURCE_ONE/_next"
printf '%s\n' 'version-one' > "$SOURCE_ONE/index.html"
printf '%s\n' 'AuthType Basic' > "$SOURCE_ONE/.htaccess"
printf '%s\n' 'old-product' > "$SOURCE_ONE/armbaender/alt/index.html"
printf '%s\n' 'old-product-image' > "$SOURCE_ONE/images/bracelets/alt/gallery/01.jpg"
printf '%s\n' 'next-metadata' > "$SOURCE_ONE/_next/chunk\$hash~id.js"
create_package "$COMMIT_ONE" "$RELEASE_ONE" "$SOURCE_ONE"
HASH_ONE=$(sha256sum "$WORKSPACE/incoming/$RELEASE_ONE.tar.gz" | awk '{ print $1 }')
run_action "$PATCHED_SCRIPT" deploy "$COMMIT_ONE" "$RELEASE_ONE" "$HASH_ONE"
run_action "$PATCHED_SCRIPT" mark_verified "$COMMIT_ONE" "$RELEASE_ONE"
assert_auth_file_unchanged
assert_deployment_permissions

grep -Fx 'version-one' "$WEBROOT/index.html" > /dev/null
grep -Fx 'AuthType Basic' "$WEBROOT/.htaccess" > /dev/null
grep -Fx 'old-product' "$WEBROOT/armbaender/alt/index.html" > /dev/null
grep -Fx 'old-product-image' "$WEBROOT/images/bracelets/alt/gallery/01.jpg" > /dev/null
grep -Fx 'next-metadata' "$WEBROOT/_next/chunk\$hash~id.js" > /dev/null
grep -Fx 'status=verified' "$WORKSPACE/state/status.env" > /dev/null

LEGACY_PREVIOUS_BRANCH='codex/ap7-v2-chain'
LEGACY_PREVIOUS_COMMIT='6dcc2c51d7448ce3c71a02aae83b49fdd7ba33d2'
LEGACY_PREVIOUS_RELEASE='6dcc2c51d7448ce3c71a02aae83b49fdd7ba33d2-2026080801-1'
LEGACY_MANIFEST_TEMP="$WORKSPACE/state/.legacy-manifest"
sed \
    -e "s#^meta${TAB}branch${TAB}.*\$#meta${TAB}branch${TAB}$LEGACY_PREVIOUS_BRANCH#" \
    -e "s#^meta${TAB}commit${TAB}.*\$#meta${TAB}commit${TAB}$LEGACY_PREVIOUS_COMMIT#" \
    -e "s#^meta${TAB}release${TAB}.*\$#meta${TAB}release${TAB}$LEGACY_PREVIOUS_RELEASE#" \
    "$WORKSPACE/state/current-manifest.tsv" > "$LEGACY_MANIFEST_TEMP"
chmod 0640 "$LEGACY_MANIFEST_TEMP"
mv -f "$LEGACY_MANIFEST_TEMP" "$WORKSPACE/state/current-manifest.tsv"

COMMIT_TWO='2222222222222222222222222222222222222222'
RELEASE_TWO="$COMMIT_TWO-2"
SOURCE_TWO="$ROOT/source-two"
mkdir -p \
    "$SOURCE_TWO/armbaender/neu" \
    "$SOURCE_TWO/images/bracelets/neu/gallery"
printf '%s\n' 'version-two' > "$SOURCE_TWO/index.html"
printf '%s\n' 'AuthType Basic' > "$SOURCE_TWO/.htaccess"
printf '%s\n' 'new-product' > "$SOURCE_TWO/armbaender/neu/index.html"
printf '%s\n' 'new-product-image' > "$SOURCE_TWO/images/bracelets/neu/gallery/02.jpg"
create_package "$COMMIT_TWO" "$RELEASE_TWO" "$SOURCE_TWO"
HASH_TWO=$(sha256sum "$WORKSPACE/incoming/$RELEASE_TWO.tar.gz" | awk '{ print $1 }')
run_action "$PATCHED_SCRIPT" deploy "$COMMIT_TWO" "$RELEASE_TWO" "$HASH_TWO"
assert_auth_file_unchanged
assert_deployment_permissions

grep -Fx 'version-two' "$WEBROOT/index.html" > /dev/null
grep -Fx 'new-product' "$WEBROOT/armbaender/neu/index.html" > /dev/null
grep -Fx 'new-product-image' "$WEBROOT/images/bracelets/neu/gallery/02.jpg" > /dev/null
[ ! -e "$WEBROOT/armbaender/alt/index.html" ]
[ ! -e "$WEBROOT/images/bracelets/alt/gallery/01.jpg" ]
[ -f "$WORKSPACE/backups/before-$RELEASE_TWO/files/index.html" ]

run_action "$PATCHED_SCRIPT" rollback "$COMMIT_TWO" "$RELEASE_TWO"
assert_auth_file_unchanged
assert_deployment_permissions
grep -Fx 'version-one' "$WEBROOT/index.html" > /dev/null
grep -Fx 'old-product' "$WEBROOT/armbaender/alt/index.html" > /dev/null
grep -Fx 'old-product-image' "$WEBROOT/images/bracelets/alt/gallery/01.jpg" > /dev/null
grep -Fx 'next-metadata' "$WEBROOT/_next/chunk\$hash~id.js" > /dev/null
[ ! -e "$WEBROOT/armbaender/neu/index.html" ]
[ ! -e "$WEBROOT/images/bracelets/neu/gallery/02.jpg" ]
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
assert_auth_file_unchanged
assert_deployment_permissions

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
grep -Fx "meta	commit	$LEGACY_PREVIOUS_COMMIT" "$WORKSPACE/state/current-manifest.tsv" > /dev/null
[ ! -e "$WORKSPACE/state/rollback-$RELEASE_FOUR.txt" ]
assert_auth_file_unchanged
assert_deployment_permissions

COMMIT_FIVE='5555555555555555555555555555555555555555'
RELEASE_FIVE="$COMMIT_FIVE-5"
SOURCE_FIVE="$ROOT/source-five"
OUTSIDE_DIRECTORY="$ROOT/outside-webroot"
mkdir -p "$SOURCE_FIVE/linked/nested" "$OUTSIDE_DIRECTORY"
printf '%s\n' 'version-five' > "$SOURCE_FIVE/index.html"
printf '%s\n' 'AuthType Basic' > "$SOURCE_FIVE/.htaccess"
printf '%s\n' 'must-not-escape' > "$SOURCE_FIVE/linked/nested/index.html"
printf '%s\n' 'outside-sentinel' > "$OUTSIDE_DIRECTORY/sentinel.txt"
ln -s "$OUTSIDE_DIRECTORY" "$WEBROOT/linked"
create_package "$COMMIT_FIVE" "$RELEASE_FIVE" "$SOURCE_FIVE"
HASH_FIVE=$(sha256sum "$WORKSPACE/incoming/$RELEASE_FIVE.tar.gz" | awk '{ print $1 }')

if run_action "$PATCHED_SCRIPT" deploy "$COMMIT_FIVE" "$RELEASE_FIVE" "$HASH_FIVE"; then
    printf '%s\n' 'Symlink im oeffentlichen Zielpfad wurde nicht abgelehnt.' >&2
    exit 1
fi

grep -Fx 'outside-sentinel' "$OUTSIDE_DIRECTORY/sentinel.txt" > /dev/null
[ ! -e "$OUTSIDE_DIRECTORY/nested/index.html" ]
grep -Fx 'version-one' "$WEBROOT/index.html" > /dev/null
grep -Fx 'old-product' "$WEBROOT/armbaender/alt/index.html" > /dev/null
rm -f "$WEBROOT/linked"
assert_auth_file_unchanged
assert_deployment_permissions

COMMIT_SIX='6666666666666666666666666666666666666666'
RELEASE_SIX="$COMMIT_SIX-6"
SOURCE_SIX="$ROOT/source-six"
mkdir -p "$SOURCE_SIX/armbaender/ungueltig"
printf '%s\n' 'version-six' > "$SOURCE_SIX/index.html"
printf '%s\n' 'AuthType Basic' > "$SOURCE_SIX/.htaccess"
printf '%s\n' 'invalid-legacy-transition' > "$SOURCE_SIX/armbaender/ungueltig/index.html"
create_package "$COMMIT_SIX" "$RELEASE_SIX" "$SOURCE_SIX"
HASH_SIX=$(sha256sum "$WORKSPACE/incoming/$RELEASE_SIX.tar.gz" | awk '{ print $1 }')
INVALID_LEGACY_MANIFEST="$WORKSPACE/state/.invalid-legacy-manifest"
sed \
    "s#^meta${TAB}commit${TAB}.*\$#meta${TAB}commit${TAB}7777777777777777777777777777777777777777#" \
    "$WORKSPACE/state/current-manifest.tsv" > "$INVALID_LEGACY_MANIFEST"
chmod 0640 "$INVALID_LEGACY_MANIFEST"
mv -f "$INVALID_LEGACY_MANIFEST" "$WORKSPACE/state/current-manifest.tsv"

if run_action "$PATCHED_SCRIPT" deploy "$COMMIT_SIX" "$RELEASE_SIX" "$HASH_SIX"; then
    printf '%s\n' 'Nicht exakt gebundenes Legacy-Manifest wurde akzeptiert.' >&2
    exit 1
fi

grep -Fx 'version-one' "$WEBROOT/index.html" > /dev/null
[ ! -e "$WEBROOT/armbaender/ungueltig/index.html" ]
assert_auth_file_unchanged
assert_deployment_permissions

printf '%s\n' 'Deployment-Shell-Test erfolgreich.'
