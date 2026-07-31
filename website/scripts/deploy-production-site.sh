#!/bin/sh

set -eu
umask 027

EXPECTED_REPOSITORY='Bumpers210/armband-rechner'
EXPECTED_BRANCH='main'
EXPECTED_TARGET='production'
EXPECTED_DOMAIN='www.carmaja-perlen.de'
WEBROOT='/home/www/carmaja'
WORKSPACE='/home/www/carmaja-production-deploy'
INCOMING="$WORKSPACE/incoming"
RELEASES="$WORKSPACE/releases"
BACKUPS="$WORKSPACE/backups"
STATE="$WORKSPACE/state"
LOCKS="$WORKSPACE/locks"
TAB="$(printf '\t')"

fail()
{
    printf '%s\n' "$1" >&2
    exit 1
}

assert_equal()
{
    [ "$1" = "$2" ] || fail "$3"
}

validate_relative_path()
{
    relative_path=$1

    case "$relative_path" in
        ''|/*|*\\*|*//*|*[!A-Za-z0-9._~$/-]*)
            fail 'Manifest enthaelt einen unsicheren Dateipfad.'
            ;;
    esac

    case "/$relative_path/" in
        */../*|*/./*)
            fail 'Manifest enthaelt einen Traversalpfad.'
            ;;
    esac

    case "$relative_path" in
        .htaccess|*/.htaccess|products.json|*/products.json|public-products.json|*/public-products.json|\
        click.php|*/click.php|_internal|_internal/*|*/_internal/*|\
        statistik|statistik/*|*/statistik/*|private-data|private-data/*|*/private-data/*|\
        hosting|hosting/*|*/hosting/*|hosting-test|hosting-test/*|*/hosting-test/*|\
        test-api-private|test-api-private/*|*/test-api-private/*|\
        test-api-public|test-api-public/*|*/test-api-public/*|\
        *.php|*/*.php|runtime-config.php|*/runtime-config.php|\
        environment.json|*/environment.json|api-users.json|*/api-users.json|\
        device-tokens.json|*/device-tokens.json|login-attempts.json|*/login-attempts.json|\
        .htpasswd|*/.htpasswd)
            fail 'Manifest enthaelt eine private oder unerlaubte Datei.'
            ;;
    esac
}

manifest_meta()
{
    manifest_path=$1
    meta_key=$2
    awk -F "$TAB" -v key="$meta_key" '
        $1 == "meta" && $2 == key { value = $3; count++ }
        END {
            if (count == 1) {
                print value
            } else {
                exit 2
            }
        }
    ' "$manifest_path"
}

manifest_has_path()
{
    manifest_path=$1
    wanted_path=$2
    awk -F "$TAB" -v wanted="$wanted_path" '
        $1 == "file" && $4 == wanted { found = 1 }
        END { exit found ? 0 : 1 }
    ' "$manifest_path"
}

log_rollback_state()
{
    restored_manifest=$1
    restored_release=$(manifest_meta "$restored_manifest" release)
    restored_commit=$(manifest_meta "$restored_manifest" commit)

    case "$restored_release" in
        initial-empty)
            restored_kind='initial_empty'
            ;;
        *[!0-9A-Za-z._-]*|'')
            restored_kind='unknown'
            restored_release='unknown'
            ;;
        *)
            restored_kind='previous_active'
            ;;
    esac

    case "$restored_commit" in
        *[!0-9a-f]*|'')
            restored_commit='unknown'
            ;;
    esac

    printf 'ROLLBACK_STATE restored=%s release=%s commit=%s verified_new_sha=no\n' \
        "$restored_kind" \
        "$restored_release" \
        "$restored_commit"
}

validate_manifest()
{
    manifest_path=$1
    expected_commit=$2
    expected_release=$3
    allow_empty=$4

    [ -f "$manifest_path" ] || fail 'Deploymentmanifest fehlt.'
    [ "$(head -n 1 "$manifest_path")" = "manifest${TAB}1" ] \
        || fail 'Deploymentmanifest hat eine unbekannte Version.'
    assert_equal "$(manifest_meta "$manifest_path" repository)" "$EXPECTED_REPOSITORY" \
        'Manifest-Repository stimmt nicht.'
    assert_equal "$(manifest_meta "$manifest_path" branch)" "$EXPECTED_BRANCH" \
        'Manifest-Branch stimmt nicht.'
    assert_equal "$(manifest_meta "$manifest_path" target)" "$EXPECTED_TARGET" \
        'Manifest-Ziel stimmt nicht.'
    assert_equal "$(manifest_meta "$manifest_path" domain)" "$EXPECTED_DOMAIN" \
        'Manifest-Domain stimmt nicht.'
    assert_equal "$(manifest_meta "$manifest_path" webroot)" "$WEBROOT" \
        'Manifest-Webroot stimmt nicht.'
    assert_equal "$(manifest_meta "$manifest_path" workspace)" "$WORKSPACE" \
        'Manifest-Workspace stimmt nicht.'

    if [ -n "$expected_commit" ]; then
        assert_equal "$(manifest_meta "$manifest_path" commit)" "$expected_commit" \
            'Manifest-Commit stimmt nicht.'
    fi

    if [ -n "$expected_release" ]; then
        assert_equal "$(manifest_meta "$manifest_path" release)" "$expected_release" \
            'Manifest-Release stimmt nicht.'
    fi

    file_count=$(awk -F "$TAB" '$1 == "file" { count++ } END { print count + 0 }' "$manifest_path")

    if [ "$allow_empty" != 'true' ] && [ "$file_count" -eq 0 ]; then
        fail 'Deploymentmanifest enthaelt keine Dateien.'
    fi

    invalid_records=$(awk -F "$TAB" '
        $1 == "file" {
            if (NF != 4 || length($2) != 64 || $2 !~ /^[0-9a-f]+$/ || $3 !~ /^[0-9]+$/ || $4 == "") {
                count++
            }
        }
        END { print count + 0 }
    ' "$manifest_path")
    [ "$invalid_records" -eq 0 ] || fail 'Deploymentmanifest enthaelt ungueltige Dateieintraege.'

    path_list="$STATE/.manifest-paths-$$"
    awk -F "$TAB" '$1 == "file" { print $4 }' "$manifest_path" > "$path_list"

    if [ -s "$path_list" ] && [ -n "$(LC_ALL=C sort "$path_list" | uniq -d)" ]; then
        rm -f "$path_list"
        fail 'Deploymentmanifest enthaelt doppelte Dateipfade.'
    fi

    while IFS= read -r relative_path; do
        validate_relative_path "$relative_path"
    done < "$path_list"
    rm -f "$path_list"
}

write_status()
{
    status_value=$1
    status_release=$2
    status_commit=$3
    status_temp="$STATE/.status-$$"
    {
        printf 'status=%s\n' "$status_value"
        printf 'release=%s\n' "$status_release"
        printf 'commit=%s\n' "$status_commit"
        printf 'updated_at=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    } > "$status_temp"
    chmod 0640 "$status_temp"
    mv -f "$status_temp" "$STATE/status.env"
}

ensure_public_directory()
{
    public_directory=$1

    case "$public_directory" in
        "$WEBROOT"|"$WEBROOT"/*) ;;
        *) fail 'Oeffentliches Zielverzeichnis liegt ausserhalb des Webroots.' ;;
    esac

    [ -d "$WEBROOT" ] || fail 'Oeffentlicher Webroot fehlt.'
    [ ! -L "$WEBROOT" ] || fail 'Oeffentlicher Webroot darf kein Symlink sein.'
    chmod 0755 "$WEBROOT"

    relative_directory=${public_directory#"$WEBROOT"}
    relative_directory=${relative_directory#/}
    current_directory=$WEBROOT

    while [ -n "$relative_directory" ]; do
        case "$relative_directory" in
            */*)
                directory_segment=${relative_directory%%/*}
                relative_directory=${relative_directory#*/}
                ;;
            *)
                directory_segment=$relative_directory
                relative_directory=''
                ;;
        esac

        case "$directory_segment" in
            ''|.|..) fail 'Oeffentliches Zielverzeichnis ist ungueltig.' ;;
        esac

        current_directory="$current_directory/$directory_segment"

        if [ -L "$current_directory" ]; then
            fail 'Oeffentliches Zielverzeichnis darf keinen Symlink enthalten.'
        fi

        if [ -e "$current_directory" ]; then
            [ -d "$current_directory" ] \
                || fail 'Oeffentlicher Zielpfad kollidiert mit einer Datei.'
        else
            mkdir "$current_directory"
        fi

        [ ! -L "$current_directory" ] \
            || fail 'Oeffentliches Zielverzeichnis darf keinen Symlink enthalten.'
        chmod 0755 "$current_directory"
    done
}

copy_atomic()
{
    source_file=$1
    destination_file=$2
    release_suffix=$3
    destination_directory=$(dirname "$destination_file")
    temporary_file="${destination_file}.carmaja-new-${release_suffix}"

    [ -f "$source_file" ] && [ ! -L "$source_file" ] \
        || fail 'Quelldatei fuer die Aktivierung ist ungueltig.'
    ensure_public_directory "$destination_directory"
    [ ! -L "$destination_file" ] \
        || fail 'Oeffentliche Zieldatei darf kein Symlink sein.'
    if [ -e "$destination_file" ] && [ ! -f "$destination_file" ]; then
        fail 'Oeffentliche Zieldatei kollidiert mit einem Verzeichnis.'
    fi
    if [ -e "$temporary_file" ] || [ -L "$temporary_file" ]; then
        fail 'Temporare Aktivierungsdatei existiert bereits.'
    fi

    cp "$source_file" "$temporary_file"
    chmod 0644 "$temporary_file"
    mv -f "$temporary_file" "$destination_file"
}

remove_empty_parents()
{
    candidate=$(dirname "$1")

    while [ "$candidate" != "$WEBROOT" ] && [ "$candidate" != '/' ]; do
        rmdir "$candidate" 2>/dev/null || true
        candidate=$(dirname "$candidate")
    done
}

remove_tree_guarded()
{
    tree_path=$1
    allowed_root=$2

    case "$tree_path" in
        "$allowed_root"/*) ;;
        *) fail 'Interne Bereinigung hat einen unsicheren Zielpfad.' ;;
    esac

    [ "$tree_path" != "$allowed_root" ] || fail 'Interne Bereinigung darf keinen Wurzelpfad loeschen.'
    [ -d "$tree_path" ] || return 0
    find "$tree_path" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
    find "$tree_path" -depth -type d -exec rmdir -- {} \;
}

prune_directories()
{
    directory_root=$1
    keep_count=$2
    index=0

    for directory in $(ls -1dt "$directory_root"/* 2>/dev/null || true); do
        index=$((index + 1))

        if [ "$index" -gt "$keep_count" ]; then
            remove_tree_guarded "$directory" "$directory_root"
        fi
    done
}

rollback_files()
{
    active_manifest=$1
    previous_manifest=$2
    backup_directory=$3
    rollback_suffix=$4

    while IFS="$TAB" read -r record_type file_hash file_size relative_path; do
        [ "$record_type" = 'file' ] || continue
        validate_relative_path "$relative_path"
        destination="$WEBROOT/$relative_path"
        ensure_public_directory "$(dirname "$destination")"

        [ ! -L "$destination" ] \
            || fail 'Rollback-Zieldatei darf kein Symlink sein.'
        if [ -f "$destination" ]; then
            rm -f "$destination"
            remove_empty_parents "$destination"
        elif [ -e "$destination" ]; then
            fail 'Rollback-Zieldatei kollidiert mit einem Verzeichnis.'
        fi
    done < "$active_manifest"

    while IFS="$TAB" read -r record_type file_hash file_size relative_path; do
        [ "$record_type" = 'file' ] || continue
        validate_relative_path "$relative_path"
        backup_file="$backup_directory/files/$relative_path"
        [ -f "$backup_file" ] || fail 'Rollback-Sicherung ist unvollstaendig.'
        copy_atomic "$backup_file" "$WEBROOT/$relative_path" "$rollback_suffix"
    done < "$previous_manifest"
}

create_initial_manifest()
{
    initial_manifest=$1
    {
        printf 'manifest\t1\n'
        printf 'meta\trepository\t%s\n' "$EXPECTED_REPOSITORY"
        printf 'meta\tbranch\t%s\n' "$EXPECTED_BRANCH"
        printf 'meta\ttarget\t%s\n' "$EXPECTED_TARGET"
        printf 'meta\tdomain\t%s\n' "$EXPECTED_DOMAIN"
        printf 'meta\twebroot\t%s\n' "$WEBROOT"
        printf 'meta\tworkspace\t%s\n' "$WORKSPACE"
        printf 'meta\tcommit\t%s\n' '0000000000000000000000000000000000000000'
        printf 'meta\trelease\t%s\n' 'initial-empty'
    } > "$initial_manifest"
    chmod 0640 "$initial_manifest"
}

for required_name in \
    CARMAJA_REPOSITORY \
    CARMAJA_BRANCH \
    CARMAJA_SITE_TARGET \
    CARMAJA_SITE_DOMAIN \
    CARMAJA_PRODUCTION_WEBROOT \
    CARMAJA_PRODUCTION_DEPLOY_WORKSPACE \
    CARMAJA_PRODUCTION_PUBLISH_ENABLED \
    CARMAJA_PRODUCTION_DEPLOY_ENABLED \
    CARMAJA_COMMIT_SHA \
    CARMAJA_RELEASE_ID \
    CARMAJA_DEPLOY_ACTION
do
    eval "required_value=\${$required_name:-}"
    [ -n "$required_value" ] || fail "Erforderlicher Guard fehlt: $required_name"
done

assert_equal "$CARMAJA_REPOSITORY" "$EXPECTED_REPOSITORY" 'Falsches Repository.'
assert_equal "$CARMAJA_BRANCH" "$EXPECTED_BRANCH" 'Falscher Branch.'
assert_equal "$CARMAJA_SITE_TARGET" "$EXPECTED_TARGET" 'Falsches Site-Ziel.'
assert_equal "$CARMAJA_SITE_DOMAIN" "$EXPECTED_DOMAIN" 'Falsche Produktionsdomain.'
assert_equal "$CARMAJA_PRODUCTION_WEBROOT" "$WEBROOT" 'Falscher Webroot.'
assert_equal "$CARMAJA_PRODUCTION_DEPLOY_WORKSPACE" "$WORKSPACE" 'Falscher Deployworkspace.'
assert_equal "$CARMAJA_PRODUCTION_PUBLISH_ENABLED" 'false' 'Produktionspublish ist nicht gesperrt.'
assert_equal "$CARMAJA_PRODUCTION_DEPLOY_ENABLED" 'true' 'Produktionsdeploy ist nicht explizit freigegeben.'

case "$CARMAJA_COMMIT_SHA" in
    *[!0-9a-f]*|'') fail 'Commit-SHA ist ungueltig.' ;;
esac
[ "${#CARMAJA_COMMIT_SHA}" -eq 40 ] || fail 'Commit-SHA ist unvollstaendig.'

case "$CARMAJA_RELEASE_ID" in
    *[!0-9A-Za-z._-]*|'') fail 'Release-ID ist ungueltig.' ;;
esac

case "$CARMAJA_RELEASE_ID" in
    "$CARMAJA_COMMIT_SHA"-*) ;;
    *) fail 'Release-ID ist nicht an den Commit gebunden.' ;;
esac

case "$CARMAJA_DEPLOY_ACTION" in
    deploy|rollback|mark_verified) ;;
    *) fail 'Unbekannte Deploymentaktion.' ;;
esac

for required_directory in "$WEBROOT" "$WORKSPACE" "$INCOMING" "$RELEASES" "$BACKUPS" "$STATE" "$LOCKS"; do
    [ -d "$required_directory" ] || fail 'Erforderliches Deploymentverzeichnis fehlt.'
    [ ! -L "$required_directory" ] || fail 'Deploymentverzeichnis darf kein Symlink sein.'
    assert_equal "$(realpath "$required_directory")" "$required_directory" \
        'Deploymentverzeichnis ist nicht kanonisch.'
done

[ -w "$WEBROOT" ] || fail 'Webroot ist nicht schreibbar.'
[ -w "$WORKSPACE" ] || fail 'Deployworkspace ist nicht schreibbar.'

lock_directory="$LOCKS/deploy.lock"
mkdir "$lock_directory" 2>/dev/null || fail 'Ein anderer Produktionsdeploy haelt den Lock.'
printf '%s\n' "$CARMAJA_RELEASE_ID" > "$lock_directory/owner"
chmod 0640 "$lock_directory/owner"

rollback_active='false'
rollback_new_manifest=''
rollback_old_manifest=''
rollback_backup_directory=''
rollback_current_manifest_changed='false'
rollback_pointer_path=''

on_exit()
{
    exit_code=$?
    trap - EXIT HUP INT TERM

    if [ "$exit_code" -ne 0 ] \
        && [ "$CARMAJA_DEPLOY_ACTION" = 'deploy' ] \
        && [ "$rollback_active" = 'true' ]; then
        set +e
        rollback_files \
            "$rollback_new_manifest" \
            "$rollback_old_manifest" \
            "$rollback_backup_directory" \
            "rollback-$CARMAJA_RELEASE_ID"
        rollback_code=$?

        if [ "$rollback_code" -eq 0 ] && [ "$rollback_current_manifest_changed" = 'true' ]; then
            rollback_state_temp="$STATE/.current-manifest-rollback-$$"
            cp "$rollback_old_manifest" "$rollback_state_temp" \
                && chmod 0640 "$rollback_state_temp" \
                && mv -f "$rollback_state_temp" "$current_manifest"
            rollback_code=$?
        fi

        if [ "$rollback_code" -eq 0 ] && [ -n "$rollback_pointer_path" ]; then
            rm -f "$rollback_pointer_path"
            rollback_code=$?
        fi

        if [ "$rollback_code" -eq 0 ]; then
            write_status 'failed_rolled_back' "$CARMAJA_RELEASE_ID" "$CARMAJA_COMMIT_SHA"
            rollback_code=$?
        fi

        if [ "$rollback_code" -eq 0 ]; then
            log_rollback_state "$rollback_old_manifest"
            rollback_code=$?
        fi

        if [ "$rollback_code" -eq 0 ]; then
            printf 'ROLLBACK_OK phase=activation\n'
        else
            write_status 'rollback_failed' "$CARMAJA_RELEASE_ID" "$CARMAJA_COMMIT_SHA"
            printf 'ROLLBACK_FAILED phase=activation\n' >&2
        fi
        set -e
    elif [ "$exit_code" -ne 0 ] && [ "$CARMAJA_DEPLOY_ACTION" = 'deploy' ]; then
        printf 'ROLLBACK_OK phase=activation action=not_required verified_new_sha=no\n'
    fi

    rm -f "$lock_directory/owner"
    rmdir "$lock_directory" 2>/dev/null || true
    exit "$exit_code"
}

trap on_exit EXIT HUP INT TERM

current_manifest="$STATE/current-manifest.tsv"

if [ "$CARMAJA_DEPLOY_ACTION" = 'mark_verified' ]; then
    [ -f "$current_manifest" ] || fail 'Es gibt keinen aktiven Produktionsexport.'
    validate_manifest "$current_manifest" "$CARMAJA_COMMIT_SHA" "$CARMAJA_RELEASE_ID" 'false'
    write_status 'verified' "$CARMAJA_RELEASE_ID" "$CARMAJA_COMMIT_SHA"
    exit 0
fi

if [ "$CARMAJA_DEPLOY_ACTION" = 'rollback' ]; then
    [ -f "$current_manifest" ] || fail 'Es gibt keinen aktiven Produktionsexport fuer Rollback.'
    validate_manifest "$current_manifest" "$CARMAJA_COMMIT_SHA" "$CARMAJA_RELEASE_ID" 'false'
    rollback_pointer="$STATE/rollback-$CARMAJA_RELEASE_ID.txt"
    [ -f "$rollback_pointer" ] || fail 'Rollback-Zuordnung fehlt.'
    backup_id=$(head -n 1 "$rollback_pointer")

    case "$backup_id" in
        before-"$CARMAJA_RELEASE_ID") ;;
        *) fail 'Rollback-Zuordnung ist ungueltig.' ;;
    esac

    backup_directory="$BACKUPS/$backup_id"
    previous_manifest="$backup_directory/manifest.tsv"
    validate_manifest "$previous_manifest" '' '' 'true'
    rollback_files \
        "$current_manifest" \
        "$previous_manifest" \
        "$backup_directory" \
        "manual-$CARMAJA_RELEASE_ID"
    state_temp="$STATE/.current-manifest-$$"
    cp "$previous_manifest" "$state_temp"
    chmod 0640 "$state_temp"
    mv -f "$state_temp" "$current_manifest"
    write_status \
        'rolled_back' \
        "$(manifest_meta "$previous_manifest" release)" \
        "$(manifest_meta "$previous_manifest" commit)"
    log_rollback_state "$previous_manifest"
    exit 0
fi

: "${CARMAJA_ARCHIVE_SHA256:?Archivpruefsumme fehlt.}"
case "$CARMAJA_ARCHIVE_SHA256" in
    *[!0-9a-f]*|'') fail 'Archivpruefsumme ist ungueltig.' ;;
esac
[ "${#CARMAJA_ARCHIVE_SHA256}" -eq 64 ] || fail 'Archivpruefsumme ist unvollstaendig.'

archive="$INCOMING/$CARMAJA_RELEASE_ID.tar.gz"
archive_checksum="$INCOMING/$CARMAJA_RELEASE_ID.tar.gz.sha256"
manifest="$INCOMING/$CARMAJA_RELEASE_ID.manifest.tsv"
[ -f "$archive" ] && [ -f "$archive_checksum" ] && [ -f "$manifest" ] \
    || fail 'Eingehendes Deploymentpaket ist unvollstaendig.'
validate_manifest "$manifest" "$CARMAJA_COMMIT_SHA" "$CARMAJA_RELEASE_ID" 'false'

checksum_value=$(awk 'NR == 1 { print $1 }' "$archive_checksum")
checksum_name=$(awk 'NR == 1 { print $2 }' "$archive_checksum")
assert_equal "$checksum_value" "$CARMAJA_ARCHIVE_SHA256" 'Archivpruefsumme stimmt nicht.'
assert_equal "$checksum_name" 'site.tar.gz' 'Pruefsummendatei benennt ein falsches Archiv.'
assert_equal "$(sha256sum "$archive" | awk '{ print $1 }')" "$CARMAJA_ARCHIVE_SHA256" \
    'Hochgeladenes Archiv ist beschaedigt.'

release_directory="$RELEASES/$CARMAJA_RELEASE_ID"
[ ! -e "$release_directory" ] || fail 'Release-Verzeichnis existiert bereits.'
mkdir "$release_directory"
chmod 0750 "$release_directory"
archive_list="$STATE/.archive-paths-$CARMAJA_RELEASE_ID"

tar -tzf "$archive" > "$archive_list"
while IFS= read -r archive_path; do
    normalized_path=${archive_path#./}
    [ -n "$normalized_path" ] || continue

    case "$normalized_path" in
        */) normalized_path=${normalized_path%/} ;;
    esac

    [ -n "$normalized_path" ] || continue
    validate_relative_path "$normalized_path"
done < "$archive_list"

tar -xzf "$archive" -C "$release_directory"
rm -f "$archive_list"

if [ -n "$(find "$release_directory" -type l -print -quit)" ]; then
    fail 'Release enthaelt einen Symlink.'
fi
find "$release_directory" -type d -exec chmod 0750 {} \;
find "$release_directory" -type f -exec chmod 0640 {} \;

actual_list="$STATE/.actual-paths-$CARMAJA_RELEASE_ID"
manifest_list="$STATE/.expected-paths-$CARMAJA_RELEASE_ID"
(
    cd "$release_directory"
    find . -type f -print | sed 's#^\./##' | LC_ALL=C sort
) > "$actual_list"
awk -F "$TAB" '$1 == "file" { print $4 }' "$manifest" | LC_ALL=C sort > "$manifest_list"
cmp -s "$actual_list" "$manifest_list" || fail 'Archiv und Manifest enthalten unterschiedliche Dateien.'
rm -f "$actual_list" "$manifest_list"

while IFS="$TAB" read -r record_type expected_hash expected_size relative_path; do
    [ "$record_type" = 'file' ] || continue
    release_file="$release_directory/$relative_path"
    [ -f "$release_file" ] || fail 'Manifestdatei fehlt im Release.'
    assert_equal "$(sha256sum "$release_file" | awk '{ print $1 }')" "$expected_hash" \
        'Release-Datei hat eine falsche Pruefsumme.'
    assert_equal "$(wc -c < "$release_file" | tr -d ' ')" "$expected_size" \
        'Release-Datei hat eine falsche Groesse.'
done < "$manifest"

if [ -f "$current_manifest" ]; then
    old_manifest="$current_manifest"
    validate_manifest "$old_manifest" '' '' 'true'
else
    old_manifest="$STATE/.initial-manifest-$CARMAJA_RELEASE_ID"
    create_initial_manifest "$old_manifest"
fi

while IFS="$TAB" read -r record_type file_hash file_size relative_path; do
    [ "$record_type" = 'file' ] || continue
    destination="$WEBROOT/$relative_path"
    ensure_public_directory "$(dirname "$destination")"
    [ ! -L "$destination" ] || fail 'Deploymentziel darf kein Symlink sein.'

    if [ -e "$destination" ] && ! manifest_has_path "$old_manifest" "$relative_path"; then
        fail 'Deployment wuerde eine nicht verwaltete Datei ueberschreiben.'
    elif [ -e "$destination" ] && [ ! -f "$destination" ]; then
        fail 'Deploymentziel kollidiert mit einem Verzeichnis.'
    fi
done < "$manifest"

backup_id="before-$CARMAJA_RELEASE_ID"
backup_directory="$BACKUPS/$backup_id"
[ ! -e "$backup_directory" ] || fail 'Rollback-Sicherung existiert bereits.'
mkdir -p "$backup_directory/files"
chmod 0750 "$backup_directory" "$backup_directory/files"
cp "$old_manifest" "$backup_directory/manifest.tsv"
chmod 0640 "$backup_directory/manifest.tsv"

while IFS="$TAB" read -r record_type file_hash file_size relative_path; do
    [ "$record_type" = 'file' ] || continue
    current_file="$WEBROOT/$relative_path"
    ensure_public_directory "$(dirname "$current_file")"
    [ ! -L "$current_file" ] || fail 'Aktive Produktionsdatei darf kein Symlink sein.'
    [ -f "$current_file" ] || fail 'Aktiver Produktionsexport stimmt nicht mit seinem Manifest ueberein.'
    backup_file="$backup_directory/files/$relative_path"
    mkdir -p "$(dirname "$backup_file")"
    cp "$current_file" "$backup_file"
    chmod 0640 "$backup_file"
done < "$old_manifest"
find "$backup_directory" -type d -exec chmod 0750 {} \;

rollback_active='true'
rollback_new_manifest="$manifest"
rollback_old_manifest="$backup_directory/manifest.tsv"
rollback_backup_directory="$backup_directory"
write_status 'activating' "$CARMAJA_RELEASE_ID" "$CARMAJA_COMMIT_SHA"

while IFS="$TAB" read -r record_type file_hash file_size relative_path; do
    [ "$record_type" = 'file' ] || continue
    copy_atomic \
        "$release_directory/$relative_path" \
        "$WEBROOT/$relative_path" \
        "$CARMAJA_RELEASE_ID"
done < "$manifest"

# CARMAJA_PRODUCTION_ROLLBACK_POINT

while IFS="$TAB" read -r record_type file_hash file_size relative_path; do
    [ "$record_type" = 'file' ] || continue

    if ! manifest_has_path "$manifest" "$relative_path"; then
        stale_file="$WEBROOT/$relative_path"
        ensure_public_directory "$(dirname "$stale_file")"
        [ ! -L "$stale_file" ] || fail 'Veraltete Zieldatei darf kein Symlink sein.'
        if [ -f "$stale_file" ]; then
            rm -f "$stale_file"
        elif [ -e "$stale_file" ]; then
            fail 'Veralteter Zielpfad kollidiert mit einem Verzeichnis.'
        fi
        remove_empty_parents "$stale_file"
    fi
done < "$old_manifest"

state_temp="$STATE/.current-manifest-$$"
cp "$manifest" "$state_temp"
chmod 0640 "$state_temp"
mv -f "$state_temp" "$current_manifest"
rollback_current_manifest_changed='true'
rollback_pointer="$STATE/rollback-$CARMAJA_RELEASE_ID.txt"
rollback_pointer_path="$rollback_pointer"
printf '%s\n' "$backup_id" > "$rollback_pointer"
chmod 0640 "$rollback_pointer"
write_status 'deployed_unverified' "$CARMAJA_RELEASE_ID" "$CARMAJA_COMMIT_SHA"

# CARMAJA_PRODUCTION_POST_STATE_ROLLBACK_POINT

rm -f "$archive" "$archive_checksum" "$manifest"
prune_directories "$RELEASES" 4
prune_directories "$BACKUPS" 3
rollback_active='false'
exit 0
