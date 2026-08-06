#!/usr/bin/env bash

set -Eeuo pipefail

readonly WEBROOT='/home/www/carmaja'
readonly WORKSPACE='/home/www/carmaja-production-stats-maintenance'
readonly SITE_URL='https://www.carmaja-perlen.de'
readonly PHP_BINARY='/usr/bin/php8.4'
readonly CURL_BINARY='curl'
readonly EXPECTED_REPOSITORY='Bumpers210/armband-rechner'
readonly EXPECTED_BRANCH='main'
readonly -a FILES=(
  '.htaccess'
  'click.php'
  'pageview.php'
  '_internal/.htaccess'
  '_internal/tracking.php'
  'private-data/.htaccess'
  'statistik/.htaccess'
  'statistik/index.php'
)

fail() { printf '%s\n' "$1" >&2; exit 1; }

validate_relative_path() {
  case "$1" in
    '.htaccess'|'click.php'|'pageview.php'|'_internal/.htaccess'|'_internal/tracking.php'|'private-data/.htaccess'|'statistik/.htaccess'|'statistik/index.php') ;;
    *) fail 'Unzulaessiger Hostingpfad.' ;;
  esac
}

for required in CARMAJA_STATS_HOSTING_REPOSITORY CARMAJA_STATS_HOSTING_BRANCH CARMAJA_STATS_HOSTING_COMMIT_SHA CARMAJA_STATS_HOSTING_RELEASE_ID CARMAJA_STATS_HOSTING_ARCHIVE_SHA256 CARMAJA_STATS_HOSTING_ACTION; do
  test -n "${!required:-}" || fail "Erforderlicher Guard fehlt: $required"
done
test "$CARMAJA_STATS_HOSTING_REPOSITORY" = "$EXPECTED_REPOSITORY" || fail 'Falsches Repository.'
test "$CARMAJA_STATS_HOSTING_BRANCH" = "$EXPECTED_BRANCH" || fail 'Falscher Branch.'
test "$CARMAJA_STATS_HOSTING_ACTION" = 'install' || fail 'Unbekannte Wartungsaktion.'
case "$CARMAJA_STATS_HOSTING_COMMIT_SHA" in *[!0-9a-f]*|'') fail 'Commit-SHA ist ungueltig.' ;; esac
test "${#CARMAJA_STATS_HOSTING_COMMIT_SHA}" = 40 || fail 'Commit-SHA ist unvollstaendig.'
case "$CARMAJA_STATS_HOSTING_RELEASE_ID" in
  "$CARMAJA_STATS_HOSTING_COMMIT_SHA"-*[!0-9A-Za-z._-]*|'') fail 'Release-ID ist ungueltig.' ;;
  "$CARMAJA_STATS_HOSTING_COMMIT_SHA"-*) ;;
  *) fail 'Release-ID ist nicht an den Commit gebunden.' ;;
esac
case "$CARMAJA_STATS_HOSTING_ARCHIVE_SHA256" in *[!0-9a-f]*|'') fail 'Archivpruefsumme ist ungueltig.' ;; esac
test "${#CARMAJA_STATS_HOSTING_ARCHIVE_SHA256}" = 64 || fail 'Archivpruefsumme ist unvollstaendig.'

for command_name in awk cp curl date dirname find mkdir mv realpath rm rmdir sha256sum stat tar tr wc "$PHP_BINARY"; do
  command -v "$command_name" >/dev/null 2>&1 || fail "Erforderliches Werkzeug fehlt: $command_name"
done
test "$(realpath "$WEBROOT")" = "$WEBROOT" || fail 'Webroot ist nicht kanonisch.'
test -d "$WEBROOT" && test ! -L "$WEBROOT" && test -w "$WEBROOT" || fail 'Webroot ist ungueltig.'

archive_root="$WORKSPACE/incoming"
backup_root="$WORKSPACE/backups"
release_root="$WORKSPACE/releases"
state_root="$WORKSPACE/state"
lock_root="$WORKSPACE/locks"
mkdir -p "$archive_root" "$backup_root" "$release_root" "$state_root" "$lock_root"
chmod 0750 "$WORKSPACE" "$archive_root" "$backup_root" "$release_root" "$state_root" "$lock_root"
for directory in "$WORKSPACE" "$archive_root" "$backup_root" "$release_root" "$state_root" "$lock_root"; do
  test ! -L "$directory" && test "$(realpath "$directory")" = "$directory" || fail 'Wartungsverzeichnis ist ungueltig.'
done

lock_directory="$lock_root/installation.lock"
mkdir "$lock_directory" 2>/dev/null || fail 'Eine Statistikwartung laeuft bereits.'
printf '%s\n' "$CARMAJA_STATS_HOSTING_RELEASE_ID" > "$lock_directory/owner"
chmod 0640 "$lock_directory/owner"

applied='false'
backup_directory=''
backup_manifest=''
restore_backup() {
  local kind hash mode relative_path destination backup_file
  test -f "$backup_manifest" || return 1
  while IFS=$'\t' read -r kind hash mode relative_path; do
    case "$kind" in
      file)
        validate_relative_path "$relative_path"
        destination="$WEBROOT/$relative_path"
        backup_file="$backup_directory/files/$relative_path"
        test -f "$backup_file" && test ! -L "$backup_file" || return 1
        cp "$backup_file" "$destination"
        chmod "$mode" "$destination"
        ;;
      absent)
        validate_relative_path "$relative_path"
        rm -f -- "$WEBROOT/$relative_path"
        ;;
      backup|meta) ;;
      *) return 1 ;;
    esac
  done < "$backup_manifest"
}
on_exit() {
  exit_code=$?
  trap - EXIT HUP INT TERM
  if test "$exit_code" -ne 0 && test "$applied" = 'true'; then
    if restore_backup; then printf '%s\n' 'STATISTICS_HOSTING_ROLLBACK_OK'; else printf '%s\n' 'STATISTICS_HOSTING_ROLLBACK_FAILED' >&2; fi
  fi
  rm -f -- "$lock_directory/owner"
  rmdir "$lock_directory" 2>/dev/null || true
  exit "$exit_code"
}
trap on_exit EXIT HUP INT TERM

archive="$archive_root/$CARMAJA_STATS_HOSTING_RELEASE_ID.tar.gz"
manifest="$archive_root/$CARMAJA_STATS_HOSTING_RELEASE_ID.manifest.tsv"
test -f "$archive" && test -f "$manifest" && test ! -L "$archive" && test ! -L "$manifest" || fail 'Wartungspaket ist unvollstaendig.'
test "$(sha256sum "$archive" | awk '{print $1}')" = "$CARMAJA_STATS_HOSTING_ARCHIVE_SHA256" || fail 'Archivpruefsumme stimmt nicht.'

manifest_value() { awk -F '\t' -v key="$1" '$1 == "meta" && $2 == key { print $3; exit }' "$manifest"; }
test "$(manifest_value repository)" = "$EXPECTED_REPOSITORY" || fail 'Manifest hat ein falsches Repository.'
test "$(manifest_value branch)" = "$EXPECTED_BRANCH" || fail 'Manifest hat einen falschen Branch.'
test "$(manifest_value target)" = 'production-statistics-hosting' || fail 'Manifest hat ein falsches Ziel.'
test "$(manifest_value domain)" = 'www.carmaja-perlen.de' || fail 'Manifest hat eine falsche Domain.'
test "$(manifest_value commit)" = "$CARMAJA_STATS_HOSTING_COMMIT_SHA" || fail 'Manifest hat einen falschen Commit.'
test "$(manifest_value release)" = "$CARMAJA_STATS_HOSTING_RELEASE_ID" || fail 'Manifest hat eine falsche Release-ID.'
test "$(awk -F '\t' '$1 == "file" { count += 1 } END { print count + 0 }' "$manifest")" = "${#FILES[@]}" || fail 'Manifest enthaelt unzulaessige Dateien.'
for relative_path in "${FILES[@]}"; do
  awk -F '\t' -v path="$relative_path" '$1 == "file" && $4 == path { found = 1 } END { exit found ? 0 : 1 }' "$manifest" || fail 'Manifestdatei fehlt.'
done

release_directory="$release_root/$CARMAJA_STATS_HOSTING_RELEASE_ID"
test ! -e "$release_directory" || fail 'Release-Verzeichnis existiert bereits.'
mkdir "$release_directory"
chmod 0750 "$release_directory"
while IFS= read -r archive_path; do
  validate_relative_path "${archive_path#./}"
done < <(tar -tzf "$archive")
tar -xzf "$archive" -C "$release_directory"
test -z "$(find "$release_directory" -type l -print -quit)" || fail 'Release enthaelt einen Symlink.'
for relative_path in "${FILES[@]}"; do
  source_file="$release_directory/$relative_path"
  test -f "$source_file" && test ! -L "$source_file" || fail 'Release-Datei fehlt.'
  expected_hash=$(awk -F '\t' -v path="$relative_path" '$1 == "file" && $4 == path { print $2; exit }' "$manifest")
  expected_size=$(awk -F '\t' -v path="$relative_path" '$1 == "file" && $4 == path { print $3; exit }' "$manifest")
  test "$(sha256sum "$source_file" | awk '{print $1}')" = "$expected_hash" || fail 'Release-Hash ist falsch.'
  test "$(wc -c < "$source_file" | tr -d ' ')" = "$expected_size" || fail 'Release-Groesse ist falsch.'
done
"$PHP_BINARY" -l "$release_directory/click.php" >/dev/null
"$PHP_BINARY" -l "$release_directory/pageview.php" >/dev/null
"$PHP_BINARY" -l "$release_directory/_internal/tracking.php" >/dev/null
"$PHP_BINARY" -l "$release_directory/statistik/index.php" >/dev/null

for directory in "$WEBROOT/_internal" "$WEBROOT/private-data" "$WEBROOT/statistik"; do
  test -d "$directory" && test ! -L "$directory" || fail 'Erforderliches Hostingverzeichnis fehlt.'
done
test -f "$WEBROOT/index.html" || fail 'Der aktive Websiteexport fehlt.'

backup_directory="$backup_root/before-$CARMAJA_STATS_HOSTING_RELEASE_ID"
test ! -e "$backup_directory" || fail 'Backup-Verzeichnis existiert bereits.'
mkdir -p "$backup_directory/files"
chmod 0750 "$backup_directory" "$backup_directory/files"
backup_manifest="$backup_directory/manifest.tsv"
{
  printf 'backup\t1\n'
  printf 'meta\tcommit\t%s\n' "$CARMAJA_STATS_HOSTING_COMMIT_SHA"
  printf 'meta\trelease\t%s\n' "$CARMAJA_STATS_HOSTING_RELEASE_ID"
  for relative_path in "${FILES[@]}"; do
    destination="$WEBROOT/$relative_path"
    if test -e "$destination"; then
      test -f "$destination" && test ! -L "$destination" || fail 'Vorhandene Hostingdatei ist unzulaessig.'
      hash=$(sha256sum "$destination" | awk '{print $1}')
      mode=$(stat -c '%a' "$destination")
      backup_file="$backup_directory/files/$relative_path"
      mkdir -p "$(dirname "$backup_file")"
      cp "$destination" "$backup_file"
      chmod "$mode" "$backup_file"
      printf 'file\t%s\t%s\t%s\n' "$hash" "$mode" "$relative_path"
    else
      printf 'absent\t-\t-\t%s\n' "$relative_path"
    fi
  done
} > "$backup_manifest"
chmod 0640 "$backup_manifest"

install_file() {
  local relative_path=$1 source_file destination mode temporary_file
  source_file="$release_directory/$relative_path"
  destination="$WEBROOT/$relative_path"
  if test -e "$destination"; then
    test -f "$destination" && test ! -L "$destination" || fail 'Hostingziel ist unzulaessig.'
    mode=$(stat -c '%a' "$destination")
  else
    mode='0644'
  fi
  temporary_file="$destination.tmp-$CARMAJA_STATS_HOSTING_RELEASE_ID"
  test ! -e "$temporary_file" || fail 'Temporäres Hostingziel existiert bereits.'
  cp "$source_file" "$temporary_file"
  chmod "$mode" "$temporary_file"
  mv -f "$temporary_file" "$destination"
}
applied='true'
for relative_path in "${FILES[@]}"; do install_file "$relative_path"; done

# CARMAJA_STATS_HOSTING_ROLLBACK_POINT

pageview_status=$("$CURL_BINARY" --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' --request POST --header 'Content-Type: application/x-www-form-urlencoded' --data 'path=%2F&source=direct-unknown' "$SITE_URL/pageview.php")
test "$pageview_status" = '204' || fail 'Seitenaufruf-Smoketest ist fehlgeschlagen.'
statistics_status=$("$CURL_BINARY" --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' --head "$SITE_URL/statistik/")
test "$statistics_status" = '401' || fail 'Statistikschutz-Smoketest ist fehlgeschlagen.'
{
  printf 'status\tinstalled\n'
  printf 'commit\t%s\n' "$CARMAJA_STATS_HOSTING_COMMIT_SHA"
  printf 'release\t%s\n' "$CARMAJA_STATS_HOSTING_RELEASE_ID"
  printf 'installed_at\t%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf 'smoke_pageview\tdirect-unknown:/\n'
} > "$state_root/current.tsv"
chmod 0640 "$state_root/current.tsv"
rm -f -- "$archive" "$manifest"
printf '%s\n' 'STATISTICS_HOSTING_INSTALL_OK'
applied='false'
