#!/usr/bin/env bash
# Disposable GitHub-hosted runner only; never reads existing IONOS credentials.
set -euo pipefail
umask 077
test "${GITHUB_ACTIONS:-}" = true
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
test "${RUNNER_OS:-}" = Linux
test "${GITHUB_REPOSITORY:-}" = Bumpers210/armband-rechner
test "${GITHUB_REF:-}" = refs/heads/codex/cart-model4-preparation || test "${GITHUB_HEAD_REF:-}" = codex/cart-model4-preparation
[[ "${GITHUB_SHA:-}" =~ ^[a-f0-9]{40}$ ]]
[[ "${GITHUB_RUN_ID:-}" =~ ^[0-9]+$ && "${GITHUB_RUN_ATTEMPT:-}" =~ ^[0-9]+$ ]]
root="$RUNNER_TEMP/model4-caller-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
container="cmj-model4-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

cleanup() {
  local failed=0
  if docker container inspect "$container" >/dev/null 2>&1; then
    test "$(docker inspect --format '{{index .Config.Labels "carmaja.ci-owner"}}' "$container")" = "$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT" || return 1
    docker rm -f -v "$container" >/dev/null || failed=1
  fi
  if test -e "$root" || test -L "$root"; then
    test ! -L "$root" && test "$(realpath "$root")" = "$root" || return 1
    test "$(cat "$root/.owner")" = "$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT" || return 1
    sudo rm -rf -- "$root" || failed=1
  fi
  test ! -e "$root" && ! docker container inspect "$container" >/dev/null 2>&1 || failed=1
  if test "$failed" = 0; then
    php "$script_dir/model4-caller-mysql-ci.php" cleanup-report
    echo 'CLEANUP_OK: disposable MySQL container, keys and fixture files absent'
  fi
  return "$failed"
}
if test "${1:-}" = cleanup; then cleanup; exit; fi
test "${1:-}" = run
test ! -e "$root" && test ! -L "$root"
! docker container inspect "$container" >/dev/null 2>&1
mkdir -m 0700 "$root"
printf '%s' "$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT" > "$root/.owner"
trap cleanup EXIT
php "$script_dir/model4-caller-mysql-ci.php" setup
mkdir "$root/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj '/CN=Carmaja isolated model4 CA' -keyout "$root/tls/ca-key.pem" -out "$root/tls/ca.pem" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -subj '/CN=localhost' -keyout "$root/tls/server-key.pem" -out "$root/tls/server.csr" >/dev/null 2>&1
printf 'subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n' > "$root/tls/extensions"
openssl x509 -req -in "$root/tls/server.csr" -CA "$root/tls/ca.pem" -CAkey "$root/tls/ca-key.pem" -CAcreateserial -days 2 -extfile "$root/tls/extensions" -out "$root/tls/server-cert.pem" >/dev/null 2>&1
chmod 0755 "$root/tls"
chmod 0644 "$root/tls/ca.pem" "$root/tls/server-cert.pem"
sudo chown 999:999 "$root/tls/server-key.pem"
sudo chmod 0600 "$root/tls/server-key.pem"
docker pull mysql:8.0 >/dev/null
docker run --detach --name "$container" --label "carmaja.ci-owner=$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT" \
  --publish 127.0.0.1:13306:3306 --tmpfs /var/lib/mysql:rw,noexec,nosuid,size=1g \
  --mount "type=bind,src=$root/setup,dst=/run/setup,readonly" \
  --mount "type=bind,src=$root/tls,dst=/run/tls,readonly" \
  --env MYSQL_ROOT_PASSWORD_FILE=/run/setup/root-password \
  mysql:8.0 --require-secure-transport=ON --ssl-ca=/run/tls/ca.pem \
  --ssl-cert=/run/tls/server-cert.pem --ssl-key=/run/tls/server-key.pem \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
ready=false
for attempt in $(seq 1 60); do
  if docker exec "$container" mysql --defaults-extra-file=/run/setup/root.cnf --protocol=TCP --host=127.0.0.1 --ssl-mode=REQUIRED --batch --skip-column-names -e 'SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
  sleep 2
done
test "$ready" = true || { echo 'MySQL fixture startup failed; raw logs suppressed'; exit 1; }
docker exec -i "$container" mysql --defaults-extra-file=/run/setup/root.cnf < "$root/setup/init.sql" >/dev/null 2> "$root/setup/init-error"
docker image inspect --format '{{index .RepoDigests 0}}' mysql:8.0 > "$root/image-digest"
php "$script_dir/model4-caller-mysql-ci.php" test
