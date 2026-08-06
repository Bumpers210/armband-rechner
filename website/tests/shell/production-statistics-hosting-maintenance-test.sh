#!/usr/bin/env bash

set -Eeuo pipefail

prepare_script=${1:?Pfad zum Paketvorbereiter fehlt.}
installer_script=${2:?Pfad zum Installationsskript fehlt.}
prepare_script="$(cd -- "$(dirname -- "$prepare_script")" && pwd)/$(basename -- "$prepare_script")"
installer_script="$(cd -- "$(dirname -- "$installer_script")" && pwd)/$(basename -- "$installer_script")"
root=$(mktemp -d /tmp/carmaja-statistics-hosting-test.XXXXXX)
cleanup() {
  case "$root" in
    /tmp/carmaja-statistics-hosting-test.*) rm -rf -- "$root" ;;
    *) printf '%s\n' 'Unsicheres Testverzeichnis; Bereinigung abgebrochen.' >&2 ;;
  esac
}
trap cleanup EXIT HUP INT TERM

source_root="$root/source"
webroot="$root/webroot"
workspace="$root/workspace"
package="$root/package"
mock_bin="$root/mock-bin"
patched_installer="$root/install.sh"
failed_installer="$root/install-failure.sh"
mkdir -p "$source_root/hosting" "$webroot/_internal" "$webroot/private-data" "$webroot/statistik" "$mock_bin"
cp -R "$(dirname "$prepare_script")/../hosting/." "$source_root/hosting/"
printf '<html>live export</html>\n' > "$webroot/index.html"
printf 'old root\n' > "$webroot/.htaccess"
printf 'old click\n' > "$webroot/click.php"
printf 'old internal config\n' > "$webroot/_internal/.htaccess"
printf 'old tracking\n' > "$webroot/_internal/tracking.php"
printf 'private protection\n' > "$webroot/private-data/.htaccess"
printf 'statistics protection\n' > "$webroot/statistik/.htaccess"
printf 'old statistics\n' > "$webroot/statistik/index.php"
printf 'private counter data\n' > "$webroot/private-data/clicks.json"
chmod 0600 "$webroot/click.php" "$webroot/private-data/clicks.json"

cat > "$mock_bin/curl" <<'MOCK'
#!/usr/bin/env bash
case "$*" in
  *pageview.php*) printf 204 ;;
  *statistik/*) printf 401 ;;
  *) exit 1 ;;
esac
MOCK
chmod 0700 "$mock_bin/curl"
cat > "$mock_bin/php" <<'MOCK'
#!/usr/bin/env bash
test "$1" = -l
test -f "$2"
MOCK
chmod 0700 "$mock_bin/php"

sed \
  -e "s#^readonly WEBROOT=.*#readonly WEBROOT='$webroot'#" \
  -e "s#^readonly WORKSPACE=.*#readonly WORKSPACE='$workspace'#" \
  -e "s#^readonly SITE_URL=.*#readonly SITE_URL='https://example.invalid'#" \
  -e "s#^readonly PHP_BINARY=.*#readonly PHP_BINARY='$mock_bin/php'#" \
  -e "s#^readonly CURL_BINARY=.*#readonly CURL_BINARY='$mock_bin/curl'#" \
  "$installer_script" > "$patched_installer"
sed '/CARMAJA_STATS_HOSTING_ROLLBACK_POINT/a false' "$patched_installer" > "$failed_installer"
chmod 0700 "$patched_installer" "$failed_installer"

create_package() {
  local commit_sha=$1 release_id=$2
  rm -rf -- "$package"
  (
    cd "$source_root"
    CARMAJA_STATS_HOSTING_COMMIT_SHA="$commit_sha" CARMAJA_STATS_HOSTING_RELEASE_ID="$release_id" CARMAJA_STATS_HOSTING_PACKAGE_DIRECTORY="$package" bash "$prepare_script" >/dev/null
  )
  mkdir -p "$workspace/incoming"
  cp "$package/statistics-hosting.tar.gz" "$workspace/incoming/$release_id.tar.gz"
  cp "$package/manifest.tsv" "$workspace/incoming/$release_id.manifest.tsv"
  sha256sum "$package/statistics-hosting.tar.gz" | awk '{print $1}'
}
run_install() {
  CARMAJA_STATS_HOSTING_REPOSITORY='Bumpers210/armband-rechner' CARMAJA_STATS_HOSTING_BRANCH='main' CARMAJA_STATS_HOSTING_COMMIT_SHA="$2" CARMAJA_STATS_HOSTING_RELEASE_ID="$3" CARMAJA_STATS_HOSTING_ARCHIVE_SHA256="$4" CARMAJA_STATS_HOSTING_ACTION='install' bash "$1"
}

commit_one=1111111111111111111111111111111111111111
release_one="$commit_one-1-1"
hash_one=$(create_package "$commit_one" "$release_one")
run_install "$patched_installer" "$commit_one" "$release_one" "$hash_one"
cmp "$source_root/hosting/pageview.php" "$webroot/pageview.php"
cmp "$source_root/hosting/_internal/tracking.php" "$webroot/_internal/tracking.php"
test "$(cat "$webroot/private-data/clicks.json")" = 'private counter data'
test "$(stat -c '%a' "$webroot/click.php")" = 600
test -f "$workspace/backups/before-$release_one/files/click.php"
grep -Fx 'status	installed' "$workspace/state/current.tsv" >/dev/null

printf 'changed click\n' >> "$source_root/hosting/click.php"
commit_two=2222222222222222222222222222222222222222
release_two="$commit_two-2-1"
hash_two=$(create_package "$commit_two" "$release_two")
set +e
run_install "$failed_installer" "$commit_two" "$release_two" "$hash_two" > "$root/failed.log" 2>&1
failure_code=$?
set -e
test "$failure_code" -ne 0
grep -Fx 'STATISTICS_HOSTING_ROLLBACK_OK' "$root/failed.log" >/dev/null
! grep -Fq 'changed click' "$webroot/click.php"
test "$(cat "$webroot/private-data/clicks.json")" = 'private counter data'
printf '%s\n' 'Produktions-Statistikhosting-Shell-Test erfolgreich.'
