#!/usr/bin/env bash

set -Eeuo pipefail

SOURCE_SCRIPT=${1:?Pfad zum Produktionsorchestrator fehlt.}
ROOT=$(mktemp -d /tmp/carmaja-production-smoke-test.XXXXXX)
MOCK_BIN="$ROOT/bin"
mkdir -p "$MOCK_BIN"

cleanup()
{
    case "$ROOT" in
        /tmp/carmaja-production-smoke-test.*)
            find "$ROOT" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
            find "$ROOT" -depth -type d -exec rmdir -- {} \;
            ;;
    esac
}
trap cleanup EXIT HUP INT TERM

cat > "$MOCK_BIN/ssh" <<'SSH'
#!/usr/bin/env bash
set -Eeuo pipefail
action=$(printf '%s' "$*" | sed -n "s/.*CARMAJA_DEPLOY_ACTION='\([^']*\)'.*/\1/p")
printf '%s\n' "$action" >> "$CARMAJA_MOCK_STATE/actions.log"
if [[ "$CARMAJA_MOCK_SCENARIO" == 'deploy_failure' && "$action" == 'deploy' ]]; then
  exit 1
fi
if [[ "$CARMAJA_MOCK_SCENARIO" == 'mark_failure' && "$action" == 'mark_verified' ]]; then
  exit 1
fi
exit 0
SSH

cat > "$MOCK_BIN/curl" <<'CURL'
#!/usr/bin/env bash
set -Eeuo pipefail
scenario=$CARMAJA_MOCK_SCENARIO
headers=''
output=''
want_code='false'
args=("$@")
for ((index=0; index<${#args[@]}; index++)); do
  case "${args[$index]}" in
    -D) headers=${args[$((index + 1))]} ;;
    -o) output=${args[$((index + 1))]} ;;
    -w) want_code='true' ;;
  esac
done
url=${args[$(( ${#args[@]} - 1 ))]}
code=200
body='<html>production</html>'
header_text=$'HTTP/1.1 200 OK\r\nX-Content-Type-Options: nosniff\r\n\r\n'
if [[ "$url" == http://www.carmaja-perlen.de/* ]]; then
  code=301
  header_text=$'HTTP/1.1 301 Moved\r\nLocation: https://www.carmaja-perlen.de/\r\n\r\n'
  [[ "$scenario" == 'http_307' ]] && code=307
elif [[ "$url" == https://www.carmaja-perlen.de/ ]]; then
  [[ "$scenario" == 'root_500' ]] && code=500
  [[ "$scenario" == 'header_missing' ]] && header_text=$'HTTP/1.1 200 OK\r\n\r\n'
  [[ "$scenario" == 'test_domain' ]] && body='test.carmaja-perlen.de'
fi
[[ -n "$headers" ]] && printf '%s' "$header_text" > "$headers"
[[ -n "$output" && "$output" != '/dev/null' ]] && printf '%s' "$body" > "$output"
[[ "$want_code" == 'true' ]] && printf '%s' "$code"
exit 0
CURL
chmod 0700 "$MOCK_BIN/ssh" "$MOCK_BIN/curl"

run_case()
{
    local scenario=$1
    local expected=$2
    local expected_check=${3:-}
    local state="$ROOT/$scenario"
    local output="$ROOT/$scenario.log"
    local exit_code

    mkdir -p "$state"
    set +e
    PATH="$MOCK_BIN:$PATH" \
    RUNNER_TEMP="$ROOT" \
    CARMAJA_MOCK_STATE="$state" \
    CARMAJA_MOCK_SCENARIO="$scenario" \
    GITHUB_REPOSITORY='Bumpers210/armband-rechner' \
    GITHUB_REF='refs/heads/main' \
    GITHUB_REF_NAME='main' \
    GITHUB_SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    CARMAJA_SITE_TARGET='production' \
    CARMAJA_SITE_DOMAIN='www.carmaja-perlen.de' \
    CARMAJA_PRODUCTION_WEBROOT='/home/www/carmaja' \
    CARMAJA_PRODUCTION_DEPLOY_WORKSPACE='/home/www/carmaja-production-deploy' \
    CARMAJA_PRODUCTION_PUBLISH_ENABLED='false' \
    CARMAJA_PRODUCTION_DEPLOY_ENABLED='true' \
    CARMAJA_RELEASE_ID='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-123-1' \
    CARMAJA_ARCHIVE_SHA256='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' \
    CARMAJA_PRODUCTION_SSH_HOST='ssh.example.invalid' \
    CARMAJA_PRODUCTION_SSH_USER='production-deploy' \
    CARMAJA_PRODUCTION_SSH_PORT='22' \
      bash "$SOURCE_SCRIPT" > "$output" 2>&1
    exit_code=$?
    set -e

    if [[ "$expected" == 'success' ]]; then
        [[ "$exit_code" -eq 0 ]]
        grep -F 'SMOKE_TEST_OK' "$output" > /dev/null
        grep -F 'MARK_VERIFIED_OK' "$output" > /dev/null
        [[ "$(grep -c '^deploy$' "$state/actions.log")" -eq 1 ]]
        [[ "$(grep -c '^mark_verified$' "$state/actions.log")" -eq 1 ]]
        [[ "$(grep -c '^rollback$' "$state/actions.log" || true)" -eq 0 ]]
    else
        [[ "$exit_code" -ne 0 ]]
        if [[ "$scenario" == 'deploy_failure' ]]; then
            grep -F "$expected_check" "$output" > /dev/null
            [[ "$(grep -c '^rollback$' "$state/actions.log" || true)" -eq 0 ]]
        else
            grep -F "name=$expected_check" "$output" > /dev/null
            [[ "$(grep -c '^rollback$' "$state/actions.log")" -eq 1 ]]
        fi
    fi
}

run_case 'success' 'success'
run_case 'http_307' 'failure' 'http_redirect_status'
run_case 'root_500' 'failure' 'https_root_status'
run_case 'header_missing' 'failure' 'header_content_type_options'
run_case 'test_domain' 'failure' 'production_content'
run_case 'mark_failure' 'failure' 'mark_verified'
run_case 'deploy_failure' 'failure' 'DEPLOY_ACTIVATION_FAILED'

printf 'Produktions-Smoke-Orchestrierungs-Test erfolgreich: 7 Szenarien.\n'
