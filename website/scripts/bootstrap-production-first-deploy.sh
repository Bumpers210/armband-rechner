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

EXPECTED_CANDIDATE_COMMIT='d68dae76df53e5aa554f0139ce7c85301d63c81c'
EXPECTED_CANDIDATE_RELEASE='d68dae76df53e5aa554f0139ce7c85301d63c81c-30661820173-1'
EXPECTED_CANDIDATE_ARCHIVE_SHA256='568c5ce7a67248e803f029d707854946f5ff284e6722fdf5361cf0aab1c9b043'
EXPECTED_CANDIDATE_MANIFEST_SHA256='5fc0be10376bec605b747e09922295821c92e8a099eeeeffd224e1e200de24ad'
EXPECTED_INVENTORY_SHA256='bbc5de61b8d94d5711d936a574d0b23a2e46c1e5f1849fb0bb104c9c0c7e402f'
EXPECTED_MANIFEST_PATH_COUNT='69'
EXPECTED_EXISTING_PATH_COUNT='50'
EXPECTED_MISSING_PATH_COUNT='19'
BOOTSTRAP_COMMIT_SENTINEL='0000000000000000000000000000000000000000'
BOOTSTRAP_RELEASE='bootstrap-unmanaged-inventory-v1'

SCRIPT_DIRECTORY=$(CDPATH= cd "$(dirname "$0")" && pwd)
INVENTORY_FILE="$SCRIPT_DIRECTORY/production-first-deploy-inventory.v1"
TAB=$(printf '\t')

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
        ''|/*|*\\*|*//*|*'|'*|*[!A-Za-z0-9._~$/-]*)
            fail 'Bootstrap-Inventur enthaelt einen unsicheren Dateipfad.'
            ;;
    esac

    case "/$relative_path/" in
        */../*|*/./*)
            fail 'Bootstrap-Inventur enthaelt einen Traversalpfad.'
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
            fail 'Bootstrap-Inventur enthaelt eine geschuetzte oder unerlaubte Datei.'
            ;;
    esac
}

assert_private_directory()
{
    directory_path=$1

    [ -d "$directory_path" ] || fail 'Erforderliches privates Deploymentverzeichnis fehlt.'
    [ ! -L "$directory_path" ] || fail 'Privates Deploymentverzeichnis darf kein Symlink sein.'
    assert_equal "$(realpath "$directory_path")" "$directory_path" \
        'Privates Deploymentverzeichnis ist nicht kanonisch.'
}

assert_webroot_path_without_symlinks()
{
    relative_path=$1
    remaining_path=$relative_path
    current_path=$WEBROOT

    [ -d "$WEBROOT" ] || fail 'Produktions-Webroot fehlt.'
    [ ! -L "$WEBROOT" ] || fail 'Produktions-Webroot darf kein Symlink sein.'

    while [ -n "$remaining_path" ]; do
        case "$remaining_path" in
            */*)
                segment=${remaining_path%%/*}
                remaining_path=${remaining_path#*/}
                ;;
            *)
                segment=$remaining_path
                remaining_path=''
                ;;
        esac

        case "$segment" in
            ''|.|..) fail 'Bootstrap-Zielpfad ist ungueltig.' ;;
        esac

        current_path="$current_path/$segment"
        [ ! -L "$current_path" ] || fail 'Bootstrap-Zielpfad darf keinen Symlink enthalten.'

        if [ -n "$remaining_path" ] && [ -e "$current_path" ]; then
            [ -d "$current_path" ] || fail 'Bootstrap-Zielpfad kollidiert mit einer Datei.'
        fi
    done
}

remove_tree_guarded()
{
    tree_path=$1
    allowed_root=$2

    case "$tree_path" in
        "$allowed_root"/*) ;;
        *) fail 'Bootstrap-Bereinigung hat einen unsicheren Zielpfad.' ;;
    esac

    [ "$tree_path" != "$allowed_root" ] || fail 'Bootstrap-Bereinigung darf keinen Wurzelpfad loeschen.'
    [ ! -L "$tree_path" ] || fail 'Bootstrap-Bereinigung verweigert Symlinks.'
    [ -d "$tree_path" ] || return 0

    find "$tree_path" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
    find "$tree_path" -depth -type d -exec rmdir -- {} \;
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
        $1 == "file" && $4 == wanted { count++ }
        END { exit count == 1 ? 0 : 1 }
    ' "$manifest_path"
}

inventory_meta()
{
    meta_key=$1

    awk -F '|' -v key="$meta_key" '
        $1 == "meta" && $2 == key { value = $3; count++ }
        END {
            if (count == 1) {
                print value
            } else {
                exit 2
            }
        }
    ' "$INVENTORY_FILE"
}

inventory_has_path()
{
    wanted_path=$1

    awk -F '|' -v wanted="$wanted_path" '
        ($1 == "existing" || $1 == "missing") && $5 == wanted { count++ }
        END { exit count == 1 ? 0 : 1 }
    ' "$INVENTORY_FILE"
}

validate_candidate_manifest()
{
    candidate_manifest=$1

    [ -f "$candidate_manifest" ] && [ ! -L "$candidate_manifest" ] \
        || fail 'Kandidatenmanifest fehlt oder ist kein regulaere Datei.'
    assert_equal "$(sha256sum "$candidate_manifest" | awk '{ print $1 }')" \
        "$EXPECTED_CANDIDATE_MANIFEST_SHA256" \
        'Kandidatenmanifest stimmt nicht mit der verifizierten Inventur ueberein.'
    [ "$(head -n 1 "$candidate_manifest")" = "manifest${TAB}1" ] \
        || fail 'Kandidatenmanifest hat eine unbekannte Version.'

    assert_equal "$(manifest_meta "$candidate_manifest" repository)" "$EXPECTED_REPOSITORY" \
        'Kandidatenmanifest hat ein falsches Repository.'
    assert_equal "$(manifest_meta "$candidate_manifest" branch)" "$EXPECTED_BRANCH" \
        'Kandidatenmanifest hat einen falschen Branch.'
    assert_equal "$(manifest_meta "$candidate_manifest" target)" "$EXPECTED_TARGET" \
        'Kandidatenmanifest hat ein falsches Ziel.'
    assert_equal "$(manifest_meta "$candidate_manifest" domain)" "$EXPECTED_DOMAIN" \
        'Kandidatenmanifest hat eine falsche Domain.'
    assert_equal "$(manifest_meta "$candidate_manifest" webroot)" "$WEBROOT" \
        'Kandidatenmanifest hat einen falschen Webroot.'
    assert_equal "$(manifest_meta "$candidate_manifest" workspace)" "$WORKSPACE" \
        'Kandidatenmanifest hat einen falschen Workspace.'
    assert_equal "$(manifest_meta "$candidate_manifest" commit)" "$EXPECTED_CANDIDATE_COMMIT" \
        'Kandidatenmanifest hat eine falsche Commit-Provenienz.'
    assert_equal "$(manifest_meta "$candidate_manifest" release)" "$EXPECTED_CANDIDATE_RELEASE" \
        'Kandidatenmanifest hat eine falsche Release-Provenienz.'

    manifest_path_count=$(awk -F "$TAB" '$1 == "file" { count++ } END { print count + 0 }' "$candidate_manifest")
    assert_equal "$manifest_path_count" "$EXPECTED_MANIFEST_PATH_COUNT" \
        'Kandidatenmanifest hat eine unerwartete Anzahl von Dateipfaden.'

    invalid_record_count=$(awk -F "$TAB" '
        $1 == "file" {
            if (NF != 4 || length($2) != 64 || $2 !~ /^[0-9a-f]+$/ || $3 !~ /^[0-9]+$/ || $4 == "") {
                count++
            }
        }
        END { print count + 0 }
    ' "$candidate_manifest")
    [ "$invalid_record_count" -eq 0 ] || fail 'Kandidatenmanifest enthaelt ungueltige Dateieintraege.'

    duplicate_count=$(awk -F "$TAB" '$1 == "file" { seen[$4]++ } END { for (path in seen) if (seen[path] != 1) count++ } END { print count + 0 }' "$candidate_manifest")
    [ "$duplicate_count" -eq 0 ] || fail 'Kandidatenmanifest enthaelt doppelte Dateipfade.'

    while IFS="$TAB" read -r record_type _file_hash _file_size relative_path; do
        [ "$record_type" = 'file' ] || continue
        validate_relative_path "$relative_path"
    done < "$candidate_manifest"
}

validate_inventory()
{
    [ -f "$INVENTORY_FILE" ] && [ ! -L "$INVENTORY_FILE" ] \
        || fail 'Bootstrap-Inventur fehlt oder ist kein regulaere Datei.'
    assert_equal "$(sha256sum "$INVENTORY_FILE" | awk '{ print $1 }')" \
        "$EXPECTED_INVENTORY_SHA256" \
        'Bootstrap-Inventur stimmt nicht mit ihrem verifizierten Hash ueberein.'
    [ "$(head -n 1 "$INVENTORY_FILE")" = 'inventory|1' ] \
        || fail 'Bootstrap-Inventur hat eine unbekannte Version.'

    assert_equal "$(inventory_meta repository)" "$EXPECTED_REPOSITORY" 'Bootstrap-Inventur hat ein falsches Repository.'
    assert_equal "$(inventory_meta branch)" "$EXPECTED_BRANCH" 'Bootstrap-Inventur hat einen falschen Branch.'
    assert_equal "$(inventory_meta target)" "$EXPECTED_TARGET" 'Bootstrap-Inventur hat ein falsches Ziel.'
    assert_equal "$(inventory_meta domain)" "$EXPECTED_DOMAIN" 'Bootstrap-Inventur hat eine falsche Domain.'
    assert_equal "$(inventory_meta webroot)" "$WEBROOT" 'Bootstrap-Inventur hat einen falschen Webroot.'
    assert_equal "$(inventory_meta workspace)" "$WORKSPACE" 'Bootstrap-Inventur hat einen falschen Workspace.'
    assert_equal "$(inventory_meta candidate-commit)" "$EXPECTED_CANDIDATE_COMMIT" 'Bootstrap-Inventur hat einen falschen Kandidatencommit.'
    assert_equal "$(inventory_meta candidate-release)" "$EXPECTED_CANDIDATE_RELEASE" 'Bootstrap-Inventur hat eine falsche Kandidatenrelease.'
    assert_equal "$(inventory_meta candidate-archive-sha256)" "$EXPECTED_CANDIDATE_ARCHIVE_SHA256" 'Bootstrap-Inventur hat eine falsche Kandidatenpruefsumme.'
    assert_equal "$(inventory_meta candidate-manifest-sha256)" "$EXPECTED_CANDIDATE_MANIFEST_SHA256" 'Bootstrap-Inventur hat eine falsche Manifestpruefsumme.'

    existing_count=$(awk -F '|' '$1 == "existing" { count++ } END { print count + 0 }' "$INVENTORY_FILE")
    missing_count=$(awk -F '|' '$1 == "missing" { count++ } END { print count + 0 }' "$INVENTORY_FILE")
    assert_equal "$existing_count" "$EXPECTED_EXISTING_PATH_COUNT" 'Bootstrap-Inventur hat eine unerwartete Anzahl vorhandener Dateien.'
    assert_equal "$missing_count" "$EXPECTED_MISSING_PATH_COUNT" 'Bootstrap-Inventur hat eine unerwartete Anzahl fehlender Dateien.'

    invalid_record_count=$(awk -F '|' '
        $1 == "existing" {
            if (NF != 5 || length($2) != 64 || $2 !~ /^[0-9a-f]+$/ || $3 !~ /^[0-9]+$/ || $4 !~ /^[0-7][0-7][0-7][0-7]?$/ || $5 == "") {
                count++
            }
        }
        $1 == "missing" {
            if (NF != 5 || $2 != "-" || $3 != "-" || $4 != "-" || $5 == "") {
                count++
            }
        }
        END { print count + 0 }
    ' "$INVENTORY_FILE")
    [ "$invalid_record_count" -eq 0 ] || fail 'Bootstrap-Inventur enthaelt ungueltige Dateieintraege.'

    duplicate_count=$(awk -F '|' '($1 == "existing" || $1 == "missing") { seen[$5]++ } END { for (path in seen) if (seen[path] != 1) count++ } END { print count + 0 }' "$INVENTORY_FILE")
    [ "$duplicate_count" -eq 0 ] || fail 'Bootstrap-Inventur enthaelt doppelte Dateipfade.'

    while IFS='|' read -r record_type _file_hash _file_size _file_mode relative_path; do
        case "$record_type" in
            existing|missing)
                validate_relative_path "$relative_path"
                manifest_has_path "$CANDIDATE_MANIFEST" "$relative_path" \
                    || fail 'Bootstrap-Inventur enthaelt einen unbekannten Kandidatenpfad.'
                ;;
        esac
    done < "$INVENTORY_FILE"

    while IFS="$TAB" read -r record_type _file_hash _file_size relative_path; do
        [ "$record_type" = 'file' ] || continue
        inventory_has_path "$relative_path" \
            || fail 'Kandidatenmanifest enthaelt einen nicht inventarisierten Pfad.'
    done < "$CANDIDATE_MANIFEST"
}

verify_live_inventory()
{
    while IFS='|' read -r record_type expected_hash expected_size expected_mode relative_path; do
        case "$record_type" in
            existing)
                assert_webroot_path_without_symlinks "$relative_path"
                live_file="$WEBROOT/$relative_path"
                [ -f "$live_file" ] && [ ! -L "$live_file" ] \
                    || fail 'Eine bestaetigte Bestandsdatei fehlt oder ist kein regulaeres Ziel.'
                assert_equal "$(sha256sum "$live_file" | awk '{ print $1 }')" "$expected_hash" \
                    'Eine Bestandsdatei hat sich seit der Bestaetigungsinventur geaendert.'
                assert_equal "$(wc -c < "$live_file" | tr -d ' ')" "$expected_size" \
                    'Eine Bestandsdatei hat seit der Bestaetigungsinventur eine andere Groesse.'
                assert_equal "$(stat -Lc '%a' "$live_file")" "$expected_mode" \
                    'Eine Bestandsdatei hat seit der Bestaetigungsinventur andere Rechte.'
                ;;
            missing)
                assert_webroot_path_without_symlinks "$relative_path"
                live_file="$WEBROOT/$relative_path"
                [ ! -e "$live_file" ] && [ ! -L "$live_file" ] \
                    || fail 'Ein zuvor fehlender Kandidatenpfad ist inzwischen vorhanden.'
                ;;
        esac
    done < "$INVENTORY_FILE"
}

write_bootstrap_manifest()
{
    destination_path=$1

    {
        printf 'manifest\t1\n'
        printf 'meta\trepository\t%s\n' "$EXPECTED_REPOSITORY"
        printf 'meta\tbranch\t%s\n' "$EXPECTED_BRANCH"
        printf 'meta\ttarget\t%s\n' "$EXPECTED_TARGET"
        printf 'meta\tdomain\t%s\n' "$EXPECTED_DOMAIN"
        printf 'meta\twebroot\t%s\n' "$WEBROOT"
        printf 'meta\tworkspace\t%s\n' "$WORKSPACE"
        printf 'meta\tcommit\t%s\n' "$BOOTSTRAP_COMMIT_SENTINEL"
        printf 'meta\trelease\t%s\n' "$BOOTSTRAP_RELEASE"
        printf 'meta\tbootstrap-source\tverified-unmanaged-live-webroot-inventory\n'
        printf 'meta\tbootstrap-provenance\tno-repository-commit\n'
        printf 'meta\tbootstrap-candidate-commit\t%s\n' "$EXPECTED_CANDIDATE_COMMIT"
        printf 'meta\tbootstrap-candidate-release\t%s\n' "$EXPECTED_CANDIDATE_RELEASE"
        printf 'meta\tbootstrap-candidate-archive-sha256\t%s\n' "$EXPECTED_CANDIDATE_ARCHIVE_SHA256"
        printf 'meta\tbootstrap-candidate-manifest-sha256\t%s\n' "$EXPECTED_CANDIDATE_MANIFEST_SHA256"
        printf 'meta\tbootstrap-inventory-sha256\t%s\n' "$EXPECTED_INVENTORY_SHA256"

        while IFS='|' read -r record_type file_hash file_size _file_mode relative_path; do
            [ "$record_type" = 'existing' ] || continue
            printf 'file\t%s\t%s\t%s\n' "$file_hash" "$file_size" "$relative_path"
        done < "$INVENTORY_FILE"
    } > "$destination_path"
    chmod 0640 "$destination_path"
}

validate_bootstrap_manifest()
{
    bootstrap_manifest=$1

    [ "$(head -n 1 "$bootstrap_manifest")" = "manifest${TAB}1" ] \
        || fail 'Bootstrap-Zustandsmanifest hat eine unbekannte Version.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" repository)" "$EXPECTED_REPOSITORY" 'Bootstrap-Zustandsmanifest hat ein falsches Repository.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" branch)" "$EXPECTED_BRANCH" 'Bootstrap-Zustandsmanifest hat einen falschen Branch.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" target)" "$EXPECTED_TARGET" 'Bootstrap-Zustandsmanifest hat ein falsches Ziel.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" domain)" "$EXPECTED_DOMAIN" 'Bootstrap-Zustandsmanifest hat eine falsche Domain.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" webroot)" "$WEBROOT" 'Bootstrap-Zustandsmanifest hat einen falschen Webroot.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" workspace)" "$WORKSPACE" 'Bootstrap-Zustandsmanifest hat einen falschen Workspace.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" commit)" "$BOOTSTRAP_COMMIT_SENTINEL" 'Bootstrap-Zustandsmanifest wuerde eine falsche Commit-Provenienz behaupten.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" release)" "$BOOTSTRAP_RELEASE" 'Bootstrap-Zustandsmanifest hat eine falsche Kennzeichnung.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" bootstrap-source)" 'verified-unmanaged-live-webroot-inventory' 'Bootstrap-Zustandsmanifest hat keine sichere Herkunftskennzeichnung.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" bootstrap-provenance)" 'no-repository-commit' 'Bootstrap-Zustandsmanifest hat eine unzulaessige Commit-Provenienz.'

    bootstrap_path_count=$(awk -F "$TAB" '$1 == "file" { count++ } END { print count + 0 }' "$bootstrap_manifest")
    assert_equal "$bootstrap_path_count" "$EXPECTED_EXISTING_PATH_COUNT" 'Bootstrap-Zustandsmanifest hat eine unerwartete Anzahl von Bestandsdateien.'
}

copy_bootstrap_backup()
{
    backup_files_directory=$1

    while IFS='|' read -r record_type expected_hash expected_size _expected_mode relative_path; do
        [ "$record_type" = 'existing' ] || continue
        source_file="$WEBROOT/$relative_path"
        backup_file="$backup_files_directory/$relative_path"
        backup_parent=$(dirname "$backup_file")

        mkdir -p "$backup_parent"
        find "$backup_files_directory" -type d -exec chmod 0750 {} \;
        cp "$source_file" "$backup_file"
        chmod 0640 "$backup_file"
        assert_equal "$(sha256sum "$backup_file" | awk '{ print $1 }')" "$expected_hash" \
            'Bootstrap-Sicherung hat eine falsche Pruefsumme.'
        assert_equal "$(wc -c < "$backup_file" | tr -d ' ')" "$expected_size" \
            'Bootstrap-Sicherung hat eine falsche Groesse.'
    done < "$INVENTORY_FILE"
}

write_missing_rollback_records()
{
    records_file=$1

    : > "$records_file"
    while IFS='|' read -r record_type _file_hash _file_size _file_mode relative_path; do
        [ "$record_type" = 'missing' ] || continue
        printf 'previously-missing|%s\n' "$relative_path" >> "$records_file"
    done < "$INVENTORY_FILE"
    chmod 0640 "$records_file"
}

validate_missing_rollback_records()
{
    records_file=$1
    candidate_manifest=$2
    bootstrap_manifest=$3
    records_paths_file="$records_file.paths"
    expected_paths_file="$records_file.expected"
    sorted_records_file="$records_file.sorted"
    sorted_expected_file="$records_file.expected.sorted"
    records_count=0

    [ -f "$records_file" ] && [ ! -L "$records_file" ] \
        || fail 'Bootstrap-Rollbackdatei fehlt oder ist kein regulaere Datei.'
    : > "$records_paths_file"

    while IFS= read -r record || [ -n "$record" ]; do
        [ -n "$record" ] || fail 'Bootstrap-Rollbackdatei enthaelt einen leeren Datensatz.'
        record_type=${record%%|*}
        relative_path=${record#*|}

        [ "$record_type" = 'previously-missing' ] && [ "$relative_path" != "$record" ] \
            || fail 'Bootstrap-Rollbackdatei enthaelt ein ungueltiges Datensatzformat.'
        validate_relative_path "$relative_path"
        manifest_has_path "$candidate_manifest" "$relative_path" \
            || fail 'Bootstrap-Rollbackdatei enthaelt einen unbekannten Kandidatenpfad.'
        if manifest_has_path "$bootstrap_manifest" "$relative_path"; then
            fail 'Bootstrap-Rollbackdatei enthaelt einen bereits vorhandenen Pfad.'
        fi

        printf '%s\n' "$relative_path" >> "$records_paths_file"
        records_count=$((records_count + 1))
    done < "$records_file"

    assert_equal "$records_count" "$EXPECTED_MISSING_PATH_COUNT" \
        'Bootstrap-Rollbackdatei hat eine unerwartete Anzahl von Datensaetzen.'
    assert_equal "$(wc -l < "$records_file" | tr -d ' ')" "$EXPECTED_MISSING_PATH_COUNT" \
        'Bootstrap-Rollbackdatei hat keine vollstaendigen physischen Datensaetze.'

    duplicate_count=$(LC_ALL=C sort "$records_paths_file" | uniq -d | wc -l | tr -d ' ')
    [ "$duplicate_count" -eq 0 ] || fail 'Bootstrap-Rollbackdatei enthaelt doppelte Pfade.'

    awk -F "$TAB" '
        NR == FNR {
            if ($1 == "file") {
                existing[$4] = 1
            }
            next
        }
        $1 == "file" && !($4 in existing) {
            print $4
        }
    ' "$bootstrap_manifest" "$candidate_manifest" | LC_ALL=C sort > "$expected_paths_file"

    assert_equal "$(wc -l < "$expected_paths_file" | tr -d ' ')" "$EXPECTED_MISSING_PATH_COUNT" \
        'Bootstrap-Manifeste haben eine unerwartete Pfaddifferenz.'
    LC_ALL=C sort "$records_paths_file" > "$sorted_records_file"
    LC_ALL=C sort "$expected_paths_file" > "$sorted_expected_file"
    cmp -s "$sorted_records_file" "$sorted_expected_file" \
        || fail 'Bootstrap-Rollbackdatei entspricht nicht der Kandidatendifferenz.'

    rm -f "$records_paths_file" "$expected_paths_file" "$sorted_records_file" "$sorted_expected_file"
}

[ "$#" -eq 0 ] || fail 'Das Bootstrap-Skript akzeptiert keine Argumente.'
assert_equal "$BOOTSTRAP_COMMIT_SENTINEL" '0000000000000000000000000000000000000000' \
    'Sichere Bootstrap-Provenienz ist nicht darstellbar.'
assert_equal "$BOOTSTRAP_RELEASE" 'bootstrap-unmanaged-inventory-v1' \
    'Sichere Bootstrap-Kennzeichnung ist nicht darstellbar.'

for required_directory in "$WEBROOT" "$WORKSPACE" "$INCOMING" "$RELEASES" "$BACKUPS" "$STATE" "$LOCKS"; do
    assert_private_directory "$required_directory"
done
[ -d "$WEBROOT" ] && [ ! -L "$WEBROOT" ] || fail 'Produktions-Webroot ist ungueltig.'
assert_equal "$(realpath "$WEBROOT")" "$WEBROOT" 'Produktions-Webroot ist nicht kanonisch.'

CANDIDATE_ARCHIVE="$INCOMING/$EXPECTED_CANDIDATE_RELEASE.tar.gz"
CANDIDATE_CHECKSUM="$INCOMING/$EXPECTED_CANDIDATE_RELEASE.tar.gz.sha256"
CANDIDATE_MANIFEST="$INCOMING/$EXPECTED_CANDIDATE_RELEASE.manifest.tsv"
CURRENT_MANIFEST="$STATE/current-manifest.tsv"
BOOTSTRAP_MARKER="$STATE/bootstrap-first-deploy.env"
BACKUP_DIRECTORY="$BACKUPS/bootstrap-unmanaged-$EXPECTED_CANDIDATE_COMMIT"
LOCK_DIRECTORY="$LOCKS/bootstrap-first-deploy.lock"
STAGE_DIRECTORY=''
STATE_TEMP=''
MARKER_TEMP=''
LOCK_HELD='false'
BACKUP_COMMITTED='false'
STATE_COMMITTED='false'
MARKER_COMMITTED='false'

cleanup()
{
    exit_code=$?
    trap - EXIT HUP INT TERM
    set +e

    rm -f "$STATE_TEMP" "$MARKER_TEMP"
    if [ "$exit_code" -ne 0 ]; then
        if [ "$MARKER_COMMITTED" = 'true' ]; then
            rm -f "$BOOTSTRAP_MARKER"
        fi
        if [ "$STATE_COMMITTED" = 'true' ]; then
            rm -f "$CURRENT_MANIFEST"
        fi
        if [ "$BACKUP_COMMITTED" = 'true' ]; then
            remove_tree_guarded "$BACKUP_DIRECTORY" "$BACKUPS"
        fi
        if [ -n "$STAGE_DIRECTORY" ]; then
            remove_tree_guarded "$STAGE_DIRECTORY" "$BACKUPS"
        fi
    fi
    if [ "$LOCK_HELD" = 'true' ]; then
        rm -f "$LOCK_DIRECTORY/owner"
        rmdir "$LOCK_DIRECTORY" 2>/dev/null || true
    fi
    exit "$exit_code"
}

trap cleanup EXIT HUP INT TERM

mkdir "$LOCK_DIRECTORY" 2>/dev/null || fail 'Ein Bootstrap oder Produktionsdeploy haelt bereits den privaten Lock.'
LOCK_HELD='true'
printf '%s\n' "$EXPECTED_CANDIDATE_RELEASE" > "$LOCK_DIRECTORY/owner"
chmod 0640 "$LOCK_DIRECTORY/owner"

[ ! -e "$CURRENT_MANIFEST" ] && [ ! -L "$CURRENT_MANIFEST" ] \
    || fail 'Ein aktives Deploymentmanifest existiert bereits; Bootstrap wird verweigert.'
[ ! -e "$BOOTSTRAP_MARKER" ] && [ ! -L "$BOOTSTRAP_MARKER" ] \
    || fail 'Ein Bootstrap-Zustand existiert bereits; Bootstrap wird verweigert.'
[ ! -e "$BACKUP_DIRECTORY" ] && [ ! -L "$BACKUP_DIRECTORY" ] \
    || fail 'Ein Bootstrap-Backup existiert bereits; Bootstrap wird verweigert.'

[ -f "$CANDIDATE_ARCHIVE" ] && [ ! -L "$CANDIDATE_ARCHIVE" ] \
    || fail 'Das verifizierte Kandidatenarchiv fehlt.'
[ -f "$CANDIDATE_CHECKSUM" ] && [ ! -L "$CANDIDATE_CHECKSUM" ] \
    || fail 'Die Kandidatenpruefsumme fehlt.'
[ -f "$CANDIDATE_MANIFEST" ] && [ ! -L "$CANDIDATE_MANIFEST" ] \
    || fail 'Das verifizierte Kandidatenmanifest fehlt.'
assert_equal "$(sha256sum "$CANDIDATE_ARCHIVE" | awk '{ print $1 }')" "$EXPECTED_CANDIDATE_ARCHIVE_SHA256" \
    'Kandidatenarchiv stimmt nicht mit der verifizierten Pruefsumme ueberein.'
assert_equal "$(cat "$CANDIDATE_CHECKSUM")" "$EXPECTED_CANDIDATE_ARCHIVE_SHA256  site.tar.gz" \
    'Kandidatenpruefsummendatei stimmt nicht mit dem verifizierten Archiv ueberein.'

validate_candidate_manifest "$CANDIDATE_MANIFEST"
validate_inventory
verify_live_inventory

for private_directory in "$WORKSPACE" "$INCOMING" "$RELEASES" "$BACKUPS" "$STATE" "$LOCKS"; do
    chmod 0750 "$private_directory"
done

STAGE_DIRECTORY="$BACKUPS/.bootstrap-stage-$EXPECTED_CANDIDATE_COMMIT-$$"
[ ! -e "$STAGE_DIRECTORY" ] && [ ! -L "$STAGE_DIRECTORY" ] \
    || fail 'Temporarer Bootstrappfad existiert bereits.'
mkdir "$STAGE_DIRECTORY" "$STAGE_DIRECTORY/files"
chmod 0750 "$STAGE_DIRECTORY" "$STAGE_DIRECTORY/files"

write_bootstrap_manifest "$STAGE_DIRECTORY/current-manifest.tsv"
validate_bootstrap_manifest "$STAGE_DIRECTORY/current-manifest.tsv"
copy_bootstrap_backup "$STAGE_DIRECTORY/files"
cp "$INVENTORY_FILE" "$STAGE_DIRECTORY/inventory.v1"
cp "$CANDIDATE_MANIFEST" "$STAGE_DIRECTORY/candidate-manifest.tsv"
write_missing_rollback_records "$STAGE_DIRECTORY/previously-missing-paths.v1"
# CARMAJA_BOOTSTRAP_TEST_CORRUPT_ROLLBACK_RECORDS_POINT
validate_missing_rollback_records \
    "$STAGE_DIRECTORY/previously-missing-paths.v1" \
    "$STAGE_DIRECTORY/candidate-manifest.tsv" \
    "$STAGE_DIRECTORY/current-manifest.tsv"
{
    printf 'state=bootstrap_pending\n'
    printf 'source=verified-unmanaged-live-webroot-inventory\n'
    printf 'provenance=no-repository-commit\n'
    printf 'backup=%s\n' "$(basename "$BACKUP_DIRECTORY")"
    printf 'candidate_commit=%s\n' "$EXPECTED_CANDIDATE_COMMIT"
    printf 'candidate_release=%s\n' "$EXPECTED_CANDIDATE_RELEASE"
    printf 'candidate_archive_sha256=%s\n' "$EXPECTED_CANDIDATE_ARCHIVE_SHA256"
    printf 'candidate_manifest_sha256=%s\n' "$EXPECTED_CANDIDATE_MANIFEST_SHA256"
    printf 'inventory_sha256=%s\n' "$EXPECTED_INVENTORY_SHA256"
    printf 'previous_missing_paths=%s\n' "$EXPECTED_MISSING_PATH_COUNT"
    printf 'rollback_restore_existing_paths=%s\n' "$EXPECTED_EXISTING_PATH_COUNT"
    printf 'rollback_remove_candidate_only_paths=%s\n' "$EXPECTED_MISSING_PATH_COUNT"
} > "$STAGE_DIRECTORY/bootstrap-state.env"
find "$STAGE_DIRECTORY" -type d -exec chmod 0750 {} \;
find "$STAGE_DIRECTORY" -type f -exec chmod 0640 {} \;

backup_file_count=$(find "$STAGE_DIRECTORY/files" -type f | wc -l | tr -d ' ')
assert_equal "$backup_file_count" "$EXPECTED_EXISTING_PATH_COUNT" 'Bootstrap-Sicherung ist nicht vollstaendig.'

# CARMAJA_BOOTSTRAP_TEST_ABORT_POINT

mv "$STAGE_DIRECTORY" "$BACKUP_DIRECTORY"
STAGE_DIRECTORY=''
BACKUP_COMMITTED='true'

STATE_TEMP="$STATE/.current-manifest-bootstrap-$$"
cp "$BACKUP_DIRECTORY/current-manifest.tsv" "$STATE_TEMP"
chmod 0640 "$STATE_TEMP"
mv "$STATE_TEMP" "$CURRENT_MANIFEST"
STATE_TEMP=''
STATE_COMMITTED='true'

MARKER_TEMP="$STATE/.bootstrap-first-deploy-$$"
cp "$BACKUP_DIRECTORY/bootstrap-state.env" "$MARKER_TEMP"
chmod 0640 "$MARKER_TEMP"
mv "$MARKER_TEMP" "$BOOTSTRAP_MARKER"
MARKER_TEMP=''
MARKER_COMMITTED='true'

printf 'BOOTSTRAP_FIRST_DEPLOY_STATE_OK existing_paths=%s missing_paths=%s provenance=no-repository-commit\n' \
    "$EXPECTED_EXISTING_PATH_COUNT" \
    "$EXPECTED_MISSING_PATH_COUNT"
