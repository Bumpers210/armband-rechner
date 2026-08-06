#!/usr/bin/env bash

set -Eeuo pipefail

release_id=${CARMAJA_STATS_HOSTING_RELEASE_ID:?CARMAJA_STATS_HOSTING_RELEASE_ID fehlt.}
commit_sha=${CARMAJA_STATS_HOSTING_COMMIT_SHA:?CARMAJA_STATS_HOSTING_COMMIT_SHA fehlt.}
output_directory=${CARMAJA_STATS_HOSTING_PACKAGE_DIRECTORY:-.statistics-hosting-package}

case "$release_id" in
  "$commit_sha"-*) ;;
  *) printf '%s\n' 'Die Release-ID ist nicht an den Commit gebunden.' >&2; exit 1 ;;
esac

case "$commit_sha" in
  *[!0-9a-f]*|'') printf '%s\n' 'Der Commit-SHA ist ungueltig.' >&2; exit 1 ;;
esac
test "${#commit_sha}" -eq 40

files=(
  .htaccess
  click.php
  pageview.php
  _internal/.htaccess
  _internal/tracking.php
  private-data/.htaccess
  statistik/.htaccess
  statistik/index.php
)

for relative_path in "${files[@]}"; do
  test -f "hosting/$relative_path"
  test ! -L "hosting/$relative_path"
done

test ! -e "$output_directory" || {
  printf '%s\n' 'Das Ausgabe-Verzeichnis existiert bereits.' >&2
  exit 1
}
mkdir -p -- "$output_directory"
manifest="$output_directory/manifest.tsv"
archive="$output_directory/statistics-hosting.tar.gz"

{
  printf 'manifest\t1\n'
  printf 'meta\trepository\tBumpers210/armband-rechner\n'
  printf 'meta\tbranch\tmain\n'
  printf 'meta\ttarget\tproduction-statistics-hosting\n'
  printf 'meta\tdomain\twww.carmaja-perlen.de\n'
  printf 'meta\tcommit\t%s\n' "$commit_sha"
  printf 'meta\trelease\t%s\n' "$release_id"
  for relative_path in "${files[@]}"; do
    file_hash=$(sha256sum "hosting/$relative_path" | awk '{print $1}')
    file_size=$(wc -c < "hosting/$relative_path" | tr -d ' ')
    printf 'file\t%s\t%s\t%s\n' "$file_hash" "$file_size" "$relative_path"
  done
} > "$manifest"

tar -C hosting -czf "$archive" "${files[@]}"
archive_hash=$(sha256sum "$archive" | awk '{print $1}')
printf '%s  statistics-hosting.tar.gz\n' "$archive_hash" > "$archive.sha256"
printf 'STATISTICS_HOSTING_PACKAGE_OK=%s\n' "$archive_hash"
