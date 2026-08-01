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
EXPECTED_CURRENT_MANIFEST_SHA256='09919cd198475b8c6d0ff47a7ca3ed39242a45db654731633d81612b881f696b'
EXPECTED_WEBROOT_SNAPSHOT_SHA256='90e555d8c23a03e19e8cf5ca5c5da4ee624b5cdd893c20f3085d06a044c8d8b9'
EXPECTED_WEBROOT_FILE_COUNT='69'
EXPECTED_EXISTING_PATH_COUNT='50'
EXPECTED_MISSING_PATH_COUNT='19'
EXPECTED_BACKUP_ID='bootstrap-unmanaged-d68dae76df53e5aa554f0139ce7c85301d63c81c'
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

assert_private_directory()
{
    directory_path=$1

    [ -d "$directory_path" ] || fail 'Erforderliches privates Deploymentverzeichnis fehlt.'
    [ ! -L "$directory_path" ] || fail 'Privates Deploymentverzeichnis darf kein Symlink sein.'
    assert_equal "$(realpath "$directory_path")" "$directory_path" \
        'Privates Deploymentverzeichnis ist nicht kanonisch.'
}

validate_relative_path()
{
    relative_path=$1

    case "$relative_path" in
        ''|/*|*\\*|*//*|*'|'*|*[!A-Za-z0-9._~$/-]*)
            fail 'Reparaturzustand enthaelt einen unsicheren Dateipfad.'
            ;;
    esac

    case "/$relative_path/" in
        */../*|*/./*)
            fail 'Reparaturzustand enthaelt einen Traversalpfad.'
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
            fail 'Reparaturzustand enthaelt eine geschuetzte oder unerlaubte Datei.'
            ;;
    esac
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
            ''|.|..) fail 'Reparaturzielpfad ist ungueltig.' ;;
        esac

        current_path="$current_path/$segment"
        [ ! -L "$current_path" ] || fail 'Reparaturzielpfad darf keinen Symlink enthalten.'

        if [ -n "$remaining_path" ] && [ -e "$current_path" ]; then
            [ -d "$current_path" ] || fail 'Reparaturzielpfad kollidiert mit einer Datei.'
        fi
    done
}

remove_tree_guarded()
{
    tree_path=$1
    allowed_root=$2

    case "$tree_path" in
        "$allowed_root"/*) ;;
        *) fail 'Reparaturbereinigung hat einen unsicheren Zielpfad.' ;;
    esac

    [ "$tree_path" != "$allowed_root" ] || fail 'Reparaturbereinigung darf keinen Wurzelpfad loeschen.'
    [ ! -L "$tree_path" ] || fail 'Reparaturbereinigung verweigert Symlinks.'
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

validate_candidate_manifest()
{
    candidate_manifest=$1

    [ -f "$candidate_manifest" ] && [ ! -L "$candidate_manifest" ] \
        || fail 'Kandidatenmanifest fehlt oder ist kein regulaere Datei.'
    assert_equal "$(sha256sum "$candidate_manifest" | awk '{ print $1 }')" \
        "$EXPECTED_CANDIDATE_MANIFEST_SHA256" \
        'Kandidatenmanifest stimmt nicht mit der freigegebenen Pruefsumme ueberein.'
    [ "$(head -n 1 "$candidate_manifest")" = "manifest${TAB}1" ] \
        || fail 'Kandidatenmanifest hat eine unbekannte Version.'

    assert_equal "$(manifest_meta "$candidate_manifest" repository)" "$EXPECTED_REPOSITORY" 'Kandidatenmanifest hat ein falsches Repository.'
    assert_equal "$(manifest_meta "$candidate_manifest" branch)" "$EXPECTED_BRANCH" 'Kandidatenmanifest hat einen falschen Branch.'
    assert_equal "$(manifest_meta "$candidate_manifest" target)" "$EXPECTED_TARGET" 'Kandidatenmanifest hat ein falsches Ziel.'
    assert_equal "$(manifest_meta "$candidate_manifest" domain)" "$EXPECTED_DOMAIN" 'Kandidatenmanifest hat eine falsche Domain.'
    assert_equal "$(manifest_meta "$candidate_manifest" webroot)" "$WEBROOT" 'Kandidatenmanifest hat einen falschen Webroot.'
    assert_equal "$(manifest_meta "$candidate_manifest" workspace)" "$WORKSPACE" 'Kandidatenmanifest hat einen falschen Workspace.'
    assert_equal "$(manifest_meta "$candidate_manifest" commit)" "$EXPECTED_CANDIDATE_COMMIT" 'Kandidatenmanifest hat eine falsche Commit-Provenienz.'
    assert_equal "$(manifest_meta "$candidate_manifest" release)" "$EXPECTED_CANDIDATE_RELEASE" 'Kandidatenmanifest hat eine falsche Release-Provenienz.'

    manifest_path_count=$(awk -F "$TAB" '$1 == "file" { count++ } END { print count + 0 }' "$candidate_manifest")
    assert_equal "$manifest_path_count" '69' 'Kandidatenmanifest hat eine unerwartete Anzahl von Dateipfaden.'

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
        'Bootstrap-Inventur stimmt nicht mit ihrem freigegebenen Hash ueberein.'
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

    while IFS='|' read -r record_type _file_hash _file_size _file_mode relative_path; do
        case "$record_type" in
            existing|missing) validate_relative_path "$relative_path" ;;
        esac
    done < "$INVENTORY_FILE"
}

validate_bootstrap_manifest()
{
    bootstrap_manifest=$1

    [ -f "$bootstrap_manifest" ] && [ ! -L "$bootstrap_manifest" ] \
        || fail 'Bootstrap-Zustandsmanifest fehlt oder ist kein regulaere Datei.'
    assert_equal "$(sha256sum "$bootstrap_manifest" | awk '{ print $1 }')" \
        "$EXPECTED_CURRENT_MANIFEST_SHA256" \
        'Bootstrap-Zustandsmanifest hat nicht die erwartete Pruefsumme.'
    [ "$(head -n 1 "$bootstrap_manifest")" = "manifest${TAB}1" ] \
        || fail 'Bootstrap-Zustandsmanifest hat eine unbekannte Version.'

    assert_equal "$(manifest_meta "$bootstrap_manifest" repository)" "$EXPECTED_REPOSITORY" 'Bootstrap-Zustandsmanifest hat ein falsches Repository.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" branch)" "$EXPECTED_BRANCH" 'Bootstrap-Zustandsmanifest hat einen falschen Branch.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" target)" "$EXPECTED_TARGET" 'Bootstrap-Zustandsmanifest hat ein falsches Ziel.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" domain)" "$EXPECTED_DOMAIN" 'Bootstrap-Zustandsmanifest hat eine falsche Domain.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" webroot)" "$WEBROOT" 'Bootstrap-Zustandsmanifest hat einen falschen Webroot.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" workspace)" "$WORKSPACE" 'Bootstrap-Zustandsmanifest hat einen falschen Workspace.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" commit)" "$BOOTSTRAP_COMMIT_SENTINEL" 'Bootstrap-Zustandsmanifest hat eine falsche Commit-Provenienz.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" release)" "$BOOTSTRAP_RELEASE" 'Bootstrap-Zustandsmanifest hat eine falsche Bootstrap-Kennzeichnung.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" bootstrap-source)" 'verified-unmanaged-live-webroot-inventory' 'Bootstrap-Zustandsmanifest hat keine sichere Herkunftskennzeichnung.'
    assert_equal "$(manifest_meta "$bootstrap_manifest" bootstrap-provenance)" 'no-repository-commit' 'Bootstrap-Zustandsmanifest hat eine unzulaessige Commit-Provenienz.'

    manifest_path_count=$(awk -F "$TAB" '$1 == "file" { count++ } END { print count + 0 }' "$bootstrap_manifest")
    assert_equal "$manifest_path_count" "$EXPECTED_EXISTING_PATH_COUNT" 'Bootstrap-Zustandsmanifest hat eine unerwartete Anzahl von Bestandsdateien.'

    while IFS="$TAB" read -r record_type _file_hash _file_size relative_path; do
        [ "$record_type" = 'file' ] || continue
        validate_relative_path "$relative_path"
    done < "$bootstrap_manifest"
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

webroot_snapshot()
{
    (
        cd "$WEBROOT"
        find -P . -xdev -type f -printf '%P\n' | LC_ALL=C sort | while IFS= read -r relative_path; do
            printf '%s\t' "$relative_path"
            sha256sum "$WEBROOT/$relative_path" | awk '{ print $1 }'
        done | sha256sum | awk '{ print $1 }'
    )
}

verify_backup_files()
{
    backup_files_directory=$1
    bootstrap_manifest=$2
    backup_count=0

    [ -d "$backup_files_directory" ] && [ ! -L "$backup_files_directory" ] \
        || fail 'Bootstrap-Sicherung fehlt oder ist kein regulaeres Verzeichnis.'
    if find "$backup_files_directory" -type l -print -quit | grep -q .; then
        fail 'Bootstrap-Sicherung enthaelt einen Symlink.'
    fi

    while IFS="$TAB" read -r record_type expected_hash expected_size relative_path; do
        [ "$record_type" = 'file' ] || continue
        backup_file="$backup_files_directory/$relative_path"
        [ -f "$backup_file" ] && [ ! -L "$backup_file" ] \
            || fail 'Bootstrap-Sicherung ist unvollstaendig.'
        assert_equal "$(sha256sum "$backup_file" | awk '{ print $1 }')" "$expected_hash" \
            'Bootstrap-Sicherung hat eine falsche Pruefsumme.'
        assert_equal "$(wc -c < "$backup_file" | tr -d ' ')" "$expected_size" \
            'Bootstrap-Sicherung hat eine falsche Groesse.'
        backup_count=$((backup_count + 1))
    done < "$bootstrap_manifest"

    assert_equal "$backup_count" "$EXPECTED_EXISTING_PATH_COUNT" 'Bootstrap-Sicherung hat eine unerwartete Anzahl von Dateien.'
    assert_equal "$(find "$backup_files_directory" -type f | wc -l | tr -d ' ')" "$EXPECTED_EXISTING_PATH_COUNT" \
        'Bootstrap-Sicherung enthaelt unbekannte Dateien.'
}

write_correct_rollback_records()
{
    records_file=$1

    : > "$records_file"
    while IFS='|' read -r record_type _file_hash _file_size _file_mode relative_path; do
        [ "$record_type" = 'missing' ] || continue
        printf 'previously-missing|%s\n' "$relative_path" >> "$records_file"
    done < "$INVENTORY_FILE"
    chmod 0640 "$records_file"
}

write_expected_legacy_broken_records()
{
    records_file=$1

    : > "$records_file"
    while IFS='|' read -r record_type _file_hash _file_size _file_mode relative_path; do
        [ "$record_type" = 'missing' ] || continue
        printf 'previously-missing|%s\\n' "$relative_path" >> "$records_file"
    done < "$INVENTORY_FILE"
    chmod 0640 "$records_file"
}

validate_correct_rollback_records()
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
        || fail 'Reparatur-Rollbackdatei fehlt oder ist kein regulaere Datei.'
    : > "$records_paths_file"

    while IFS= read -r record || [ -n "$record" ]; do
        [ -n "$record" ] || fail 'Reparatur-Rollbackdatei enthaelt einen leeren Datensatz.'
        record_type=${record%%|*}
        relative_path=${record#*|}

        [ "$record_type" = 'previously-missing' ] && [ "$relative_path" != "$record" ] \
            || fail 'Reparatur-Rollbackdatei enthaelt ein ungueltiges Datensatzformat.'
        validate_relative_path "$relative_path"
        manifest_has_path "$candidate_manifest" "$relative_path" \
            || fail 'Reparatur-Rollbackdatei enthaelt einen unbekannten Kandidatenpfad.'
        if manifest_has_path "$bootstrap_manifest" "$relative_path"; then
            fail 'Reparatur-Rollbackdatei enthaelt einen bereits vorhandenen Pfad.'
        fi

        printf '%s\n' "$relative_path" >> "$records_paths_file"
        records_count=$((records_count + 1))
    done < "$records_file"

    assert_equal "$records_count" "$EXPECTED_MISSING_PATH_COUNT" \
        'Reparatur-Rollbackdatei hat eine unerwartete Anzahl von Datensaetzen.'
    assert_equal "$(wc -l < "$records_file" | tr -d ' ')" "$EXPECTED_MISSING_PATH_COUNT" \
        'Reparatur-Rollbackdatei hat keine vollstaendigen physischen Datensaetze.'

    duplicate_count=$(LC_ALL=C sort "$records_paths_file" | uniq -d | wc -l | tr -d ' ')
    [ "$duplicate_count" -eq 0 ] || fail 'Reparatur-Rollbackdatei enthaelt doppelte Pfade.'

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
        'Kandidaten- und Bootstrapmanifest haben eine unerwartete Pfaddifferenz.'
    LC_ALL=C sort "$records_paths_file" > "$sorted_records_file"
    LC_ALL=C sort "$expected_paths_file" > "$sorted_expected_file"
    cmp -s "$sorted_records_file" "$sorted_expected_file" \
        || fail 'Reparatur-Rollbackdatei entspricht nicht der Kandidatendifferenz.'

    rm -f "$records_paths_file" "$expected_paths_file" "$sorted_records_file" "$sorted_expected_file"
}

simulate_rollback()
{
    simulation_directory=$1
    candidate_archive=$2
    candidate_manifest=$3
    bootstrap_manifest=$4
    backup_files_directory=$5
    records_file=$6

    mkdir "$simulation_directory"
    chmod 0750 "$simulation_directory"
    tar -xzf "$candidate_archive" -C "$simulation_directory"
    if find "$simulation_directory" -type l -print -quit | grep -q .; then
        fail 'Rollbacksimulation verweigert Symlinks aus dem Kandidatenarchiv.'
    fi

    while IFS="$TAB" read -r record_type expected_hash expected_size relative_path; do
        [ "$record_type" = 'file' ] || continue
        candidate_file="$simulation_directory/$relative_path"
        [ -f "$candidate_file" ] && [ ! -L "$candidate_file" ] \
            || fail 'Rollbacksimulation enthaelt keine erwartete Kandidatendatei.'
        assert_equal "$(sha256sum "$candidate_file" | awk '{ print $1 }')" "$expected_hash" \
            'Rollbacksimulation hat eine falsche Kandidatendatei.'
        assert_equal "$(wc -c < "$candidate_file" | tr -d ' ')" "$expected_size" \
            'Rollbacksimulation hat eine Kandidatendatei mit falscher Groesse.'
    done < "$candidate_manifest"

    while IFS="$TAB" read -r record_type expected_hash expected_size relative_path; do
        [ "$record_type" = 'file' ] || continue
        simulated_file="$simulation_directory/$relative_path"
        backup_file="$backup_files_directory/$relative_path"
        mkdir -p "$(dirname "$simulated_file")"
        [ ! -L "$simulated_file" ] || fail 'Rollbacksimulation verweigert Symlinks im Ziel.'
        cp "$backup_file" "$simulated_file"
        chmod 0644 "$simulated_file"
        assert_equal "$(sha256sum "$simulated_file" | awk '{ print $1 }')" "$expected_hash" \
            'Rollbacksimulation konnte eine Altdatei nicht wiederherstellen.'
        assert_equal "$(wc -c < "$simulated_file" | tr -d ' ')" "$expected_size" \
            'Rollbacksimulation hat eine wiederhergestellte Datei mit falscher Groesse.'
    done < "$bootstrap_manifest"

    while IFS= read -r record || [ -n "$record" ]; do
        relative_path=${record#*|}
        simulated_file="$simulation_directory/$relative_path"
        [ -f "$simulated_file" ] && [ ! -L "$simulated_file" ] \
            || fail 'Rollbacksimulation kann einen Kandidatenpfad nicht entfernen.'
        rm -f "$simulated_file"
        [ ! -e "$simulated_file" ] && [ ! -L "$simulated_file" ] \
            || fail 'Rollbacksimulation hat einen neuen Kandidatenpfad nicht entfernt.'
    done < "$records_file"
}

[ "$#" -eq 1 ] && [ "$1" = '--repair-bootstrap-rollback-records' ] \
    || fail 'Nur der explizite Reparaturmodus ist erlaubt.'
assert_equal "${CARMAJA_PRODUCTION_DEPLOY_ENABLED:-}" 'false' \
    'Die Produktionsdeployfreigabe muss fuer die Reparatur exakt false sein.'
assert_equal "${CARMAJA_PRODUCTION_PUBLISH_ENABLED:-}" 'false' \
    'Die Produktionspublishfreigabe muss fuer die Reparatur exakt false sein.'

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
BACKUP_DIRECTORY="$BACKUPS/$EXPECTED_BACKUP_ID"
BACKUP_FILES_DIRECTORY="$BACKUP_DIRECTORY/files"
ROLLBACK_RECORDS="$BACKUP_DIRECTORY/previously-missing-paths.v1"
LOCK_DIRECTORY="$LOCKS/bootstrap-repair-rollback-records.lock"
QUARANTINE_ROOT="$STATE/quarantine"
QUARANTINE_DIRECTORY="$QUARANTINE_ROOT/$EXPECTED_BACKUP_ID"
STAGE_DIRECTORY=''
QUARANTINE_STAGE=''
FAULTY_RECORD_STAGED='false'
CORRECTED_RECORD_INSTALLED='false'
QUARANTINE_COMMITTED='false'
LOCK_HELD='false'

cleanup()
{
    exit_code=$?
    trap - EXIT HUP INT TERM
    set +e

    if [ "$exit_code" -ne 0 ]; then
        if [ "$QUARANTINE_COMMITTED" = 'true' ]; then
            rm -f "$ROLLBACK_RECORDS"
            mv "$QUARANTINE_DIRECTORY/previously-missing-paths.v1" "$ROLLBACK_RECORDS"
            remove_tree_guarded "$QUARANTINE_DIRECTORY" "$QUARANTINE_ROOT"
        elif [ "$FAULTY_RECORD_STAGED" = 'true' ]; then
            if [ "$CORRECTED_RECORD_INSTALLED" = 'true' ]; then
                rm -f "$ROLLBACK_RECORDS"
            fi
            mv "$QUARANTINE_STAGE/previously-missing-paths.v1" "$ROLLBACK_RECORDS"
        fi
        if [ -n "$QUARANTINE_STAGE" ]; then
            remove_tree_guarded "$QUARANTINE_STAGE" "$STATE"
        fi
        if [ -n "$STAGE_DIRECTORY" ]; then
            remove_tree_guarded "$STAGE_DIRECTORY" "$STATE"
        fi
        if [ -d "$QUARANTINE_ROOT" ] && [ ! -L "$QUARANTINE_ROOT" ] \
            && [ -z "$(find "$QUARANTINE_ROOT" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
            rmdir "$QUARANTINE_ROOT"
        fi
    fi
    if [ "$LOCK_HELD" = 'true' ]; then
        rm -f "$LOCK_DIRECTORY/owner"
        rmdir "$LOCK_DIRECTORY" 2>/dev/null || true
    fi
    exit "$exit_code"
}

trap cleanup EXIT HUP INT TERM

[ -f "$CANDIDATE_ARCHIVE" ] && [ ! -L "$CANDIDATE_ARCHIVE" ] \
    || fail 'Das verifizierte Kandidatenarchiv fehlt.'
[ -f "$CANDIDATE_CHECKSUM" ] && [ ! -L "$CANDIDATE_CHECKSUM" ] \
    || fail 'Die Kandidatenpruefsumme fehlt.'
[ -f "$CANDIDATE_MANIFEST" ] && [ ! -L "$CANDIDATE_MANIFEST" ] \
    || fail 'Das verifizierte Kandidatenmanifest fehlt.'
assert_equal "$(sha256sum "$CANDIDATE_ARCHIVE" | awk '{ print $1 }')" "$EXPECTED_CANDIDATE_ARCHIVE_SHA256" \
    'Kandidatenarchiv stimmt nicht mit der freigegebenen Pruefsumme ueberein.'
assert_equal "$(cat "$CANDIDATE_CHECKSUM")" "$EXPECTED_CANDIDATE_ARCHIVE_SHA256  site.tar.gz" \
    'Kandidatenpruefsummendatei stimmt nicht mit dem freigegebenen Archiv ueberein.'
validate_candidate_manifest "$CANDIDATE_MANIFEST"
validate_inventory

[ -f "$CURRENT_MANIFEST" ] && [ ! -L "$CURRENT_MANIFEST" ] \
    || fail 'Der erwartete Bootstrap-Zustand fehlt.'
[ -f "$BOOTSTRAP_MARKER" ] && [ ! -L "$BOOTSTRAP_MARKER" ] \
    || fail 'Die erwartete Bootstrap-Kennzeichnung fehlt.'
[ -d "$BACKUP_DIRECTORY" ] && [ ! -L "$BACKUP_DIRECTORY" ] \
    || fail 'Das erwartete Bootstrap-Backup fehlt.'
[ -f "$ROLLBACK_RECORDS" ] && [ ! -L "$ROLLBACK_RECORDS" ] \
    || fail 'Die fehlerhafte Bootstrap-Rollbackdatei fehlt.'
[ ! -e "$LOCK_DIRECTORY" ] && [ ! -L "$LOCK_DIRECTORY" ] \
    || fail 'Ein anderer Bootstrap oder eine Reparatur haelt den privaten Lock.'
[ ! -e "$QUARANTINE_DIRECTORY" ] && [ ! -L "$QUARANTINE_DIRECTORY" ] \
    || fail 'Eine Reparaturquarantaene fuer diesen Bootstrap existiert bereits.'
[ -z "$(find "$RELEASES" -mindepth 1 -maxdepth 1 -print -quit)" ] \
    || fail 'Ein Produktionsrelease ist vorhanden; Bootstrap-Reparatur wird verweigert.'
[ ! -e "$STATE/status.env" ] && [ ! -e "$STATE/active-release.env" ] \
    || fail 'Ein Produktionsdeploystatus ist vorhanden; Bootstrap-Reparatur wird verweigert.'
[ -z "$(find "$STATE" -maxdepth 1 -type f -name 'rollback-*.txt' -print -quit)" ] \
    || fail 'Eine Rollback-Zuordnung ist vorhanden; Bootstrap-Reparatur wird verweigert.'

validate_bootstrap_manifest "$CURRENT_MANIFEST"
cmp -s "$CURRENT_MANIFEST" "$BACKUP_DIRECTORY/current-manifest.tsv" \
    || fail 'Bootstrap-Backupmanifest stimmt nicht mit dem aktiven Zustand ueberein.'
assert_equal "$(sha256sum "$BACKUP_DIRECTORY/inventory.v1" | awk '{ print $1 }')" "$EXPECTED_INVENTORY_SHA256" \
    'Bootstrap-Backuppinventur stimmt nicht mit der freigegebenen Inventur ueberein.'
assert_equal "$(sha256sum "$BACKUP_DIRECTORY/candidate-manifest.tsv" | awk '{ print $1 }')" "$EXPECTED_CANDIDATE_MANIFEST_SHA256" \
    'Bootstrap-Backupkandidat stimmt nicht mit dem freigegebenen Manifest ueberein.'
verify_backup_files "$BACKUP_FILES_DIRECTORY" "$CURRENT_MANIFEST"

STAGE_DIRECTORY="$STATE/.bootstrap-repair-stage-$EXPECTED_CANDIDATE_COMMIT-$$"
[ ! -e "$STAGE_DIRECTORY" ] && [ ! -L "$STAGE_DIRECTORY" ] \
    || fail 'Temporarer Bootstrap-Reparaturpfad existiert bereits.'
mkdir "$STAGE_DIRECTORY"
chmod 0750 "$STAGE_DIRECTORY"
cp "$CURRENT_MANIFEST" "$STAGE_DIRECTORY/current-manifest.tsv"
cp "$BOOTSTRAP_MARKER" "$STAGE_DIRECTORY/bootstrap-first-deploy.env"
chmod 0640 "$STAGE_DIRECTORY/current-manifest.tsv" "$STAGE_DIRECTORY/bootstrap-first-deploy.env"
validate_bootstrap_manifest "$STAGE_DIRECTORY/current-manifest.tsv"
cmp -s "$CURRENT_MANIFEST" "$STAGE_DIRECTORY/current-manifest.tsv" \
    || fail 'Das gestagte Bootstrapmanifest ist nicht unveraendert.'

write_expected_legacy_broken_records "$STAGE_DIRECTORY/expected-legacy-broken-records.v1"
cmp -s "$ROLLBACK_RECORDS" "$STAGE_DIRECTORY/expected-legacy-broken-records.v1" \
    || fail 'Die vorhandene Rollbackdatei entspricht nicht dem exakt bekannten Escape-Fehler.'
write_correct_rollback_records "$STAGE_DIRECTORY/previously-missing-paths.v1"
validate_correct_rollback_records \
    "$STAGE_DIRECTORY/previously-missing-paths.v1" \
    "$CANDIDATE_MANIFEST" \
    "$STAGE_DIRECTORY/current-manifest.tsv"
simulate_rollback \
    "$STAGE_DIRECTORY/rollback-simulation" \
    "$CANDIDATE_ARCHIVE" \
    "$CANDIDATE_MANIFEST" \
    "$STAGE_DIRECTORY/current-manifest.tsv" \
    "$BACKUP_FILES_DIRECTORY" \
    "$STAGE_DIRECTORY/previously-missing-paths.v1"
remove_tree_guarded "$STAGE_DIRECTORY/rollback-simulation" "$STAGE_DIRECTORY"
rm -f "$STAGE_DIRECTORY/expected-legacy-broken-records.v1"
find "$STAGE_DIRECTORY" -type d -exec chmod 0750 {} \;
find "$STAGE_DIRECTORY" -type f -exec chmod 0640 {} \;

verify_live_inventory
assert_equal "$(webroot_snapshot)" "$EXPECTED_WEBROOT_SNAPSHOT_SHA256" \
    'Der Produktions-Webroot hat sich seit der Bestaetigungsinventur geaendert.'
assert_equal "$(find -P "$WEBROOT" -xdev -type f | wc -l | tr -d ' ')" "$EXPECTED_WEBROOT_FILE_COUNT" \
    'Der Produktions-Webroot hat eine unerwartete Dateianzahl.'

mkdir "$LOCK_DIRECTORY" 2>/dev/null || fail 'Ein anderer Bootstrap oder eine Reparatur haelt den privaten Lock.'
LOCK_HELD='true'
printf '%s\n' "$EXPECTED_BACKUP_ID" > "$LOCK_DIRECTORY/owner"
chmod 0640 "$LOCK_DIRECTORY/owner"

verify_live_inventory
assert_equal "$(webroot_snapshot)" "$EXPECTED_WEBROOT_SNAPSHOT_SHA256" \
    'Der Produktions-Webroot hat sich vor der Reparatur geaendert.'
assert_equal "$(sha256sum "$CURRENT_MANIFEST" | awk '{ print $1 }')" "$EXPECTED_CURRENT_MANIFEST_SHA256" \
    'Das aktive Bootstrapmanifest hat sich vor der Reparatur geaendert.'
cmp -s "$CURRENT_MANIFEST" "$STAGE_DIRECTORY/current-manifest.tsv" \
    || fail 'Das aktive Bootstrapmanifest wuerde durch die Reparatur veraendert.'

if [ ! -d "$QUARANTINE_ROOT" ]; then
    mkdir "$QUARANTINE_ROOT"
    chmod 0750 "$QUARANTINE_ROOT"
fi
[ ! -L "$QUARANTINE_ROOT" ] || fail 'Bootstrap-Reparaturquarantaene darf kein Symlink sein.'
assert_equal "$(realpath "$QUARANTINE_ROOT")" "$QUARANTINE_ROOT" \
    'Bootstrap-Reparaturquarantaene ist nicht kanonisch.'
QUARANTINE_STAGE="$STATE/.bootstrap-repair-quarantine-$EXPECTED_CANDIDATE_COMMIT-$$"
[ ! -e "$QUARANTINE_STAGE" ] && [ ! -L "$QUARANTINE_STAGE" ] \
    || fail 'Temporare Bootstrap-Reparaturquarantaene existiert bereits.'
mkdir "$QUARANTINE_STAGE"
chmod 0750 "$QUARANTINE_STAGE"

mv "$ROLLBACK_RECORDS" "$QUARANTINE_STAGE/previously-missing-paths.v1"
FAULTY_RECORD_STAGED='true'
mv "$STAGE_DIRECTORY/previously-missing-paths.v1" "$ROLLBACK_RECORDS"
CORRECTED_RECORD_INSTALLED='true'
chmod 0640 "$ROLLBACK_RECORDS"
mv "$QUARANTINE_STAGE" "$QUARANTINE_DIRECTORY"
QUARANTINE_STAGE=''
QUARANTINE_COMMITTED='true'
chmod 0750 "$QUARANTINE_DIRECTORY"
chmod 0640 "$QUARANTINE_DIRECTORY/previously-missing-paths.v1"

validate_correct_rollback_records "$ROLLBACK_RECORDS" "$CANDIDATE_MANIFEST" "$CURRENT_MANIFEST"
assert_equal "$(sha256sum "$CURRENT_MANIFEST" | awk '{ print $1 }')" "$EXPECTED_CURRENT_MANIFEST_SHA256" \
    'Das aktive Bootstrapmanifest hat sich waehrend der Reparatur geaendert.'
verify_live_inventory
assert_equal "$(webroot_snapshot)" "$EXPECTED_WEBROOT_SNAPSHOT_SHA256" \
    'Die Reparatur hat den Produktions-Webroot veraendert.'
remove_tree_guarded "$STAGE_DIRECTORY" "$STATE"
STAGE_DIRECTORY=''

printf 'BOOTSTRAP_ROLLBACK_RECORDS_REPAIR_OK backup=%s records=%s quarantine=%s\n' \
    "$EXPECTED_BACKUP_ID" "$EXPECTED_MISSING_PATH_COUNT" "$QUARANTINE_DIRECTORY"
