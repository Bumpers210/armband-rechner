#!/bin/sh

set -eu

SOURCE_SCRIPT=${1:?Pfad zum Produktionsdeploymentskript fehlt.}
ROOT=$(mktemp -d /tmp/carmaja-production-deploy-test.XXXXXX)
WEBROOT="$ROOT/webroot"
WORKSPACE="$ROOT/workspace"
PATCHED_SCRIPT="$ROOT/deploy-production-site.sh"
FAILED_SCRIPT="$ROOT/deploy-production-site-failure.sh"

cleanup()
{
    case "$ROOT" in
        /tmp/carmaja-production-deploy-test.*)
            find "$ROOT" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
            find "$ROOT" -depth -type d -exec rmdir -- {} \;
            ;;
        *)
            printf '%s\n' 'Unsicheres Produktions-Testverzeichnis; Bereinigung abgebrochen.' >&2
            ;;
    esac
}

trap cleanup EXIT HUP INT TERM

mkdir -p \
    "$WEBROOT/_internal" \
    "$WEBROOT/statistik" \
    "$WEBROOT/private-data" \
    "$WORKSPACE/incoming" \
    "$WORKSPACE/releases" \
    "$WORKSPACE/backups" \
    "$WORKSPACE/state" \
    "$WORKSPACE/locks"
printf '%s\n' 'manual-server-config' > "$WEBROOT/.htaccess"
printf '%s\n' 'manual-click-handler' > "$WEBROOT/click.php"
printf '%s\n' 'manual-private-state' > "$WEBROOT/private-data/clicks.json"
printf '%s\n' 'unmanaged-content' > "$WEBROOT/legacy.txt"
chmod 0600 "$WEBROOT/.htaccess" "$WEBROOT/click.php" "$WEBROOT/private-data/clicks.json"
chmod 0700 "$WEBROOT/_internal" "$WEBROOT/statistik" "$WEBROOT/private-data"
chmod 0755 "$WEBROOT"
chmod 0750 \
    "$WORKSPACE" \
    "$WORKSPACE/incoming" \
    "$WORKSPACE/releases" \
    "$WORKSPACE/backups" \
    "$WORKSPACE/state" \
    "$WORKSPACE/locks"

HTACCESS_HASH=$(sha256sum "$WEBROOT/.htaccess" | awk '{print $1}')
CLICK_HASH=$(sha256sum "$WEBROOT/click.php" | awk '{print $1}')
PRIVATE_HASH=$(sha256sum "$WEBROOT/private-data/clicks.json" | awk '{print $1}')

sed \
    -e "s#^WEBROOT='/home/www/carmaja'\$#WEBROOT='$WEBROOT'#" \
    -e "s#^WORKSPACE='/home/www/carmaja-production-deploy'\$#WORKSPACE='$WORKSPACE'#" \
    "$SOURCE_SCRIPT" > "$PATCHED_SCRIPT"
sed '/CARMAJA_PRODUCTION_ROLLBACK_POINT/a false' "$PATCHED_SCRIPT" > "$FAILED_SCRIPT"
chmod 0700 "$PATCHED_SCRIPT" "$FAILED_SCRIPT"

assert_mode()
{
    expected_mode=$1
    checked_path=$2
    actual_mode=$(stat -c '%a' "$checked_path")
    [ "$actual_mode" = "$expected_mode" ] || {
        printf 'Unerwartete Rechte %s statt %s fuer %s\n' "$actual_mode" "$expected_mode" "$checked_path" >&2
        exit 1
    }
}

assert_private_paths_unchanged()
{
    [ "$(sha256sum "$WEBROOT/.htaccess" | awk '{print $1}')" = "$HTACCESS_HASH" ]
    [ "$(sha256sum "$WEBROOT/click.php" | awk '{print $1}')" = "$CLICK_HASH" ]
    [ "$(sha256sum "$WEBROOT/private-data/clicks.json" | awk '{print $1}')" = "$PRIVATE_HASH" ]
    assert_mode 600 "$WEBROOT/.htaccess"
    assert_mode 600 "$WEBROOT/click.php"
    assert_mode 600 "$WEBROOT/private-data/clicks.json"
    assert_mode 700 "$WEBROOT/_internal"
    assert_mode 700 "$WEBROOT/statistik"
    assert_mode 700 "$WEBROOT/private-data"
}

assert_managed_permissions()
{
    assert_mode 755 "$WEBROOT"
    for managed_root in "$WEBROOT/armbaender" "$WEBROOT/assets"; do
        [ -d "$managed_root" ] || continue
        find "$managed_root" -type d -print | while IFS= read -r directory; do
            assert_mode 755 "$directory"
        done
        find "$managed_root" -type f -print | while IFS= read -r file; do
            assert_mode 644 "$file"
        done
    done
    find "$WORKSPACE" -type d -print | while IFS= read -r directory; do
        assert_mode 750 "$directory"
    done
    ! find "$WEBROOT" "$WORKSPACE" -type l -print -quit | grep -q .
}

create_source()
{
    source_directory=$1
    marker=$2
    include_image=$3

    mkdir -p "$source_directory/armbaender" "$source_directory/assets/images"
    printf '<html>%s</html>\n' "$marker" > "$source_directory/index.html"
    printf 'User-agent: *\n' > "$source_directory/robots.txt"
    printf '<urlset>%s</urlset>\n' "$marker" > "$source_directory/sitemap.xml"
    printf '<html>armbaender-%s</html>\n' "$marker" > "$source_directory/armbaender/index.html"
    if [ "$include_image" = 'yes' ]; then
        printf 'image-%s\n' "$marker" > "$source_directory/assets/images/product.jpg"
    fi
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
        printf 'meta\tbranch\tmain\n'
        printf 'meta\ttarget\tproduction\n'
        printf 'meta\tdomain\twww.carmaja-perlen.de\n'
        printf 'meta\twebroot\t%s\n' "$WEBROOT"
        printf 'meta\tworkspace\t%s\n' "$WORKSPACE"
        printf 'meta\tcommit\t%s\n' "$commit_sha"
        printf 'meta\trelease\t%s\n' "$release_id"
        (
            cd "$source_directory"
            find . -type f -print | sed 's#^\./##' | LC_ALL=C sort | while IFS= read -r relative_path; do
                file_hash=$(sha256sum "$relative_path" | awk '{print $1}')
                file_size=$(wc -c < "$relative_path" | tr -d ' ')
                printf 'file\t%s\t%s\t%s\n' "$file_hash" "$file_size" "$relative_path"
            done
        )
    } > "$manifest"
    tar -C "$source_directory" -czf "$archive" .
    archive_hash=$(sha256sum "$archive" | awk '{print $1}')
    printf '%s  site.tar.gz\n' "$archive_hash" > "$archive.sha256"
}

run_action()
{
    script=$1
    action=$2
    commit_sha=$3
    release_id=$4
    archive_hash=$(sha256sum "$WORKSPACE/incoming/$release_id.tar.gz" 2>/dev/null | awk '{print $1}' || true)
    : "${archive_hash:=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb}"

    CARMAJA_REPOSITORY='Bumpers210/armband-rechner' \
    CARMAJA_BRANCH='main' \
    CARMAJA_SITE_TARGET='production' \
    CARMAJA_SITE_DOMAIN='www.carmaja-perlen.de' \
    CARMAJA_PRODUCTION_WEBROOT="$WEBROOT" \
    CARMAJA_PRODUCTION_DEPLOY_WORKSPACE="$WORKSPACE" \
    CARMAJA_PRODUCTION_PUBLISH_ENABLED='false' \
    CARMAJA_PRODUCTION_DEPLOY_ENABLED='true' \
    CARMAJA_COMMIT_SHA="$commit_sha" \
    CARMAJA_RELEASE_ID="$release_id" \
    CARMAJA_ARCHIVE_SHA256="$archive_hash" \
    CARMAJA_DEPLOY_ACTION="$action" \
        sh "$script"
}

count_directories()
{
    find "$1" -mindepth 1 -maxdepth 1 -type d -print | wc -l | tr -d ' '
}

SOURCE_ONE="$ROOT/source-one"
SOURCE_TWO="$ROOT/source-two"
SOURCE_COLLISION="$ROOT/source-collision"
create_source "$SOURCE_ONE" 'one' 'yes'
create_source "$SOURCE_TWO" 'two' 'no'
create_source "$SOURCE_COLLISION" 'collision' 'no'
printf '%s\n' 'attempted-overwrite' > "$SOURCE_COLLISION/legacy.txt"

SHA_ONE=1111111111111111111111111111111111111111
RELEASE_ONE="$SHA_ONE-1-1"
create_package "$SHA_ONE" "$RELEASE_ONE" "$SOURCE_ONE"
run_action "$PATCHED_SCRIPT" deploy "$SHA_ONE" "$RELEASE_ONE"
grep -Fx "<html>one</html>" "$WEBROOT/index.html" > /dev/null
[ -f "$WEBROOT/assets/images/product.jpg" ]
assert_private_paths_unchanged
assert_managed_permissions

SHA_FAILURE=2222222222222222222222222222222222222222
RELEASE_FAILURE="$SHA_FAILURE-2-1"
create_package "$SHA_FAILURE" "$RELEASE_FAILURE" "$SOURCE_TWO"
set +e
run_action "$FAILED_SCRIPT" deploy "$SHA_FAILURE" "$RELEASE_FAILURE" > "$ROOT/failed.log" 2>&1
failure_code=$?
set -e
[ "$failure_code" -ne 0 ]
grep -F 'ROLLBACK_OK phase=activation' "$ROOT/failed.log" > /dev/null
grep -Fx "<html>one</html>" "$WEBROOT/index.html" > /dev/null
[ -f "$WEBROOT/assets/images/product.jpg" ]
assert_private_paths_unchanged

SHA_TWO=3333333333333333333333333333333333333333
RELEASE_TWO="$SHA_TWO-3-1"
create_package "$SHA_TWO" "$RELEASE_TWO" "$SOURCE_TWO"
run_action "$PATCHED_SCRIPT" deploy "$SHA_TWO" "$RELEASE_TWO"
grep -Fx "<html>two</html>" "$WEBROOT/index.html" > /dev/null
[ ! -e "$WEBROOT/assets/images/product.jpg" ]
[ -f "$WEBROOT/legacy.txt" ]
assert_private_paths_unchanged
assert_managed_permissions

SHA_COLLISION=4444444444444444444444444444444444444444
RELEASE_COLLISION="$SHA_COLLISION-4-1"
create_package "$SHA_COLLISION" "$RELEASE_COLLISION" "$SOURCE_COLLISION"
set +e
run_action "$PATCHED_SCRIPT" deploy "$SHA_COLLISION" "$RELEASE_COLLISION" > "$ROOT/collision.log" 2>&1
collision_code=$?
set -e
[ "$collision_code" -ne 0 ]
grep -F 'nicht verwaltete Datei ueberschreiben' "$ROOT/collision.log" > /dev/null
grep -Fx 'unmanaged-content' "$WEBROOT/legacy.txt" > /dev/null

for number in 5 6 7; do
    commit=$(printf '%040d' "$number")
    release="$commit-$number-1"
    create_package "$commit" "$release" "$SOURCE_TWO"
    run_action "$PATCHED_SCRIPT" deploy "$commit" "$release"
done

[ "$(count_directories "$WORKSPACE/releases")" -eq 4 ]
[ "$(count_directories "$WORKSPACE/backups")" -eq 3 ]
assert_private_paths_unchanged
assert_managed_permissions

printf 'Produktionsdeployment-Shell-Test erfolgreich.\n'
