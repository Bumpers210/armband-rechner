#!/usr/bin/env bash

set -Eeuo pipefail

SOURCE_SCRIPT=${1:?Pfad zum Orchestrierungsskript fehlt.}
ROOT=$(mktemp -d /tmp/carmaja-smoke-shell-test.XXXXXX)
MOCK_BIN="$ROOT/bin"
AUTH_DIRECTORY="$ROOT/external-auth"
AUTH_FILE="$AUTH_DIRECTORY/test-website.htpasswd"
SCENARIO_COUNT=0
SECRET_USER='diagnostic-secret-user'
SECRET_PASSWORD='diagnostic-secret-password-with-quote-"'

cleanup()
{
    case "$ROOT" in
        /tmp/carmaja-smoke-shell-test.*)
            find "$ROOT" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
            find "$ROOT" -depth -type d -exec rmdir -- {} \;
            ;;
        *)
            printf '%s\n' 'Unsicheres Smoke-Testverzeichnis; Bereinigung abgebrochen.' >&2
            ;;
    esac
}

trap cleanup EXIT HUP INT TERM
mkdir -p "$MOCK_BIN" "$AUTH_DIRECTORY"
printf '%s\n' 'manually-managed-auth-fixture' > "$AUTH_FILE"
chmod 0711 "$AUTH_DIRECTORY"
chmod 0604 "$AUTH_FILE"
AUTH_FILE_HASH=$(sha256sum "$AUTH_FILE" | awk '{ print $1 }')

cat > "$MOCK_BIN/ssh" <<'MOCK_SSH'
#!/usr/bin/env bash
set -eu

[ -f "$CARMAJA_MOCK_AUTH_FILE" ]
[ "$(sha256sum "$CARMAJA_MOCK_AUTH_FILE" | awk '{ print $1 }')" = "$CARMAJA_MOCK_AUTH_HASH" ]

cat > /dev/null
case "$*" in
    *"CARMAJA_DEPLOY_ACTION='deploy'"*) action='deploy' ;;
    *"CARMAJA_DEPLOY_ACTION='rollback'"*) action='rollback' ;;
    *"CARMAJA_DEPLOY_ACTION='mark_verified'"*) action='mark_verified' ;;
    *) action='unknown' ;;
esac
printf '%s\n' "$action" >> "$CARMAJA_MOCK_STATE/actions.log"

if [ "$CARMAJA_MOCK_SCENARIO" = 'mark_failure' ] && [ "$action" = 'mark_verified' ]; then
    exit 1
fi

if [ "$action" = 'rollback' ]; then
    if [ "$CARMAJA_MOCK_SCENARIO" = 'rollback_failure' ]; then
        exit 1
    fi
    printf '%s\n' \
        'ROLLBACK_STATE restored=previous_active release=previous-release commit=1111111111111111111111111111111111111111 verified_new_sha=no'
fi
MOCK_SSH
chmod 0700 "$MOCK_BIN/ssh"

cat > "$MOCK_BIN/curl" <<'MOCK_CURL'
#!/usr/bin/env bash
set -eu

config=''
headers=''
output=''
url=''
auth='none'
original_arguments="$*"

if printf '%s' "$original_arguments" | grep -Fq "$CARMAJA_TEST_BASIC_AUTH_USER" \
    || printf '%s' "$original_arguments" | grep -Fq "$CARMAJA_TEST_BASIC_AUTH_PASSWORD"; then
    printf '%s\n' 'credential_argument_leak' >> "$CARMAJA_MOCK_STATE/leaks.log"
fi
if printf '%s' "$original_arguments" | grep -Eiq 'authorization[[:space:]]*:'; then
    printf '%s\n' 'authorization_argument_leak' >> "$CARMAJA_MOCK_STATE/leaks.log"
fi

while [ "$#" -gt 0 ]; do
    case "$1" in
        --config)
            config=$2
            auth='correct'
            shift 2
            ;;
        --user)
            auth='wrong'
            shift 2
            ;;
        -D)
            headers=$2
            shift 2
            ;;
        -o)
            output=$2
            shift 2
            ;;
        -w|--connect-timeout|--max-time)
            shift 2
            ;;
        --disable|--silent|--show-error)
            shift
            ;;
        http://*|https://*)
            url=$1
            shift
            ;;
        *)
            shift
            ;;
    esac
done

if [ -n "$config" ]; then
    printf '%s\n' "$config" >> "$CARMAJA_MOCK_STATE/config-paths.log"
    stat -c '%a' "$config" >> "$CARMAJA_MOCK_STATE/config-modes.log"
fi

code='000'
header_location=''
header_challenge=''
header_challenge_present='false'
security_headers='false'
body=''

case "$url|$auth" in
    'http://test.carmaja-perlen.de/|none')
        code='302'
        header_location='https://test.carmaja-perlen.de/'
        case "$CARMAJA_MOCK_SCENARIO" in
            http_301) code='301' ;;
            http_307) code='307' ;;
            http_403) code='403' ;;
            missing_location) header_location='' ;;
            wrong_location) header_location='https://www.carmaja-perlen.de/' ;;
            query_location)
                header_location='https://test.carmaja-perlen.de/?credential=must-not-be-logged'
                ;;
            http_challenge)
                header_challenge='Basic realm="test"'
                header_challenge_present='true'
                ;;
            http_empty_challenge)
                header_challenge_present='true'
                ;;
        esac
        ;;
    'https://test.carmaja-perlen.de/|none')
        code='401'
        header_challenge='bAsIc    realm="Carmaja Test"'
        header_challenge_present='true'
        case "$CARMAJA_MOCK_SCENARIO" in
            unauth_root_not_401) code='403' ;;
            missing_basic_challenge)
                header_challenge=''
                header_challenge_present='false'
                ;;
        esac
        ;;
    'https://test.carmaja-perlen.de/|wrong')
        code='401'
        case "$CARMAJA_MOCK_SCENARIO" in
            wrong_credentials_not_401) code='200' ;;
            wrong_credentials_500) code='500' ;;
        esac
        ;;
    'https://test.carmaja-perlen.de/|correct')
        code='200'
        security_headers='true'
        if [ "$CARMAJA_MOCK_SCENARIO" = 'correct_credentials_not_200' ]; then
            code='401'
        fi
        ;;
    'https://test.carmaja-perlen.de/robots.txt|none')
        code='401'
        if [ "$CARMAJA_MOCK_SCENARIO" = 'robots_unauth_not_401' ]; then
            code='200'
        fi
        ;;
    'https://test.carmaja-perlen.de/robots.txt|correct')
        code='200'
        body=$'User-agent: *\r\nDisallow: /\r\n'
        if [ "$CARMAJA_MOCK_SCENARIO" = 'robots_status' ]; then
            code='404'
        elif [ "$CARMAJA_MOCK_SCENARIO" = 'robots_content' ]; then
            body=$'User-agent: *\nAllow: /\n'
        fi
        ;;
    'https://test.carmaja-perlen.de/armbaender/|none')
        code='401'
        if [ "$CARMAJA_MOCK_SCENARIO" = 'bracelets_unauth_not_401' ]; then
            code='200'
        fi
        ;;
    'https://test.carmaja-perlen.de/armbaender/|correct')
        code='200'
        if [ "$CARMAJA_MOCK_SCENARIO" = 'bracelets_auth_status' ]; then
            code='404'
        fi
        ;;
    'https://test.carmaja-perlen.de/images/bracelets/hero-dunkelrot-braun-holz.jpg|correct')
        code='200'
        case "$CARMAJA_MOCK_SCENARIO" in
            image_status|rollback_failure) code='404' ;;
        esac
        ;;
esac

if [ -n "$headers" ]; then
    : > "$headers"
    if [ -n "$header_location" ]; then
        printf 'lOcAtIoN:    %s\r\n' "$header_location" >> "$headers"
    fi
    if [ "$header_challenge_present" = 'true' ]; then
        printf 'wWw-AuThEnTiCaTe:   %s\r\n' "$header_challenge" >> "$headers"
    fi
    if [ "$security_headers" = 'true' ]; then
        if [ "$CARMAJA_MOCK_SCENARIO" != 'missing_x_robots' ]; then
            if [ "$CARMAJA_MOCK_SCENARIO" = 'partial_x_robots' ]; then
                printf 'x-rObOtS-tAg: nofollow, noindex\r\n' >> "$headers"
            else
                printf 'x-rObOtS-tAg:  nofollow , NOINDEX,  noimageindex \r\n' >> "$headers"
            fi
        fi
        if [ "$CARMAJA_MOCK_SCENARIO" != 'missing_cache' ]; then
            printf 'CACHE-control: no-store , PRIVATE\r\n' >> "$headers"
        fi
        if [ "$CARMAJA_MOCK_SCENARIO" != 'missing_content_type' ]; then
            printf 'X-content-TYPE-options:   NoSnIfF  \r\n' >> "$headers"
        fi
        if [ "$CARMAJA_MOCK_SCENARIO" != 'missing_referrer' ]; then
            printf 'referrer-POLICY:   NO-REFERRER \r\n' >> "$headers"
        fi
    fi
fi

if [ -n "$output" ] && [ "$output" != '/dev/null' ]; then
    printf '%s' "$body" > "$output"
fi

printf '%s' "$code"
MOCK_CURL
chmod 0700 "$MOCK_BIN/curl"

count_action()
{
    local action=$1
    local action_file=$2
    local count

    count=$(grep -Fxc "$action" "$action_file" 2>/dev/null || true)
    printf '%s' "${count:-0}"
}

assert_secret_safety()
{
    local output_file=$1
    local state_directory=$2

    if grep -Fq "$SECRET_USER" "$output_file" || grep -Fq "$SECRET_PASSWORD" "$output_file"; then
        printf '%s\n' 'Smoke-Testlog enthaelt echte Testzugangsdaten.' >&2
        exit 1
    fi
    if grep -Eiq 'authorization[[:space:]]*:' "$output_file"; then
        printf '%s\n' 'Smoke-Testlog enthaelt einen Authorization-Header.' >&2
        exit 1
    fi
    [ ! -s "$state_directory/leaks.log" ]

    if [ -f "$state_directory/config-modes.log" ]; then
        if grep -Ev '^600$' "$state_directory/config-modes.log" > /dev/null; then
            printf '%s\n' 'Temporaere curl-Konfiguration hatte falsche Rechte.' >&2
            exit 1
        fi
    fi

    if [ -f "$state_directory/config-paths.log" ]; then
        while IFS= read -r config_path; do
            [ ! -e "$config_path" ] || {
                printf '%s\n' 'Temporaere curl-Konfiguration wurde nicht entfernt.' >&2
                exit 1
            }
        done < "$state_directory/config-paths.log"
    fi
}

assert_auth_file_unchanged()
{
    [ -f "$AUTH_FILE" ]
    [ ! -L "$AUTH_FILE" ]
    [ "$(stat -c '%a' "$AUTH_DIRECTORY")" = '711' ]
    [ "$(stat -c '%a' "$AUTH_FILE")" = '604' ]
    [ "$(sha256sum "$AUTH_FILE" | awk '{ print $1 }')" = "$AUTH_FILE_HASH" ]
}

run_case()
{
    local scenario=$1
    local expected_result=$2
    local expected_check=${3:-}
    local state_directory="$ROOT/state-$scenario"
    local runner_temp="$ROOT/temp-$scenario"
    local output_file="$ROOT/output-$scenario.log"
    local exit_code
    local deploy_count
    local rollback_count
    local mark_count
    local rollback_result_count

    SCENARIO_COUNT=$((SCENARIO_COUNT + 1))
    mkdir -p "$state_directory" "$runner_temp"

    set +e
    PATH="$MOCK_BIN:$PATH" \
    RUNNER_TEMP="$runner_temp" \
    CARMAJA_MOCK_STATE="$state_directory" \
    CARMAJA_MOCK_SCENARIO="$scenario" \
    CARMAJA_MOCK_AUTH_FILE="$AUTH_FILE" \
    CARMAJA_MOCK_AUTH_HASH="$AUTH_FILE_HASH" \
    GITHUB_REPOSITORY='Bumpers210/armband-rechner' \
    GITHUB_REF='refs/heads/test/product-management-beta' \
    GITHUB_REF_NAME='test/product-management-beta' \
    GITHUB_SHA='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' \
    CARMAJA_SITE_TARGET='test' \
    CARMAJA_SITE_DOMAIN='test.carmaja-perlen.de' \
    CARMAJA_TEST_WEBROOT='/home/www/carmaja-test-site' \
    CARMAJA_TEST_DEPLOY_WORKSPACE='/home/www/carmaja-test-deploy' \
    CARMAJA_PRODUCTION_PUBLISH_ENABLED='false' \
    CARMAJA_PRODUCTION_DEPLOY_ENABLED='false' \
    CARMAJA_TEST_DEPLOY_ENABLED='true' \
    CARMAJA_RELEASE_ID='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-123-1' \
    CARMAJA_ARCHIVE_SHA256='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' \
    CARMAJA_TEST_SSH_HOST='ssh.example.test' \
    CARMAJA_TEST_SSH_USER='test-deploy' \
    CARMAJA_TEST_SSH_PORT='22' \
    CARMAJA_TEST_BASIC_AUTH_USER="$SECRET_USER" \
    CARMAJA_TEST_BASIC_AUTH_PASSWORD="$SECRET_PASSWORD" \
        bash "$SOURCE_SCRIPT" > "$output_file" 2>&1
    exit_code=$?
    set -e

    deploy_count=$(count_action 'deploy' "$state_directory/actions.log")
    rollback_count=$(count_action 'rollback' "$state_directory/actions.log")
    mark_count=$(count_action 'mark_verified' "$state_directory/actions.log")
    rollback_result_count=$(grep -Ec '^ROLLBACK_(OK|FAILED)( |$)' "$output_file" || true)

    [ "$deploy_count" -eq 1 ]
    assert_secret_safety "$output_file" "$state_directory"
    assert_auth_file_unchanged

    if [ "$expected_result" = 'success' ]; then
        [ "$exit_code" -eq 0 ]
        [ "$rollback_count" -eq 0 ]
        [ "$rollback_result_count" -eq 0 ]
        [ "$mark_count" -eq 1 ]
        grep -Fx \
            'DEPLOY_ACTIVATION_OK commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa release=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-123-1' \
            "$output_file" > /dev/null
        grep -Fx \
            'SMOKE_TEST_OK commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa release=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-123-1' \
            "$output_file" > /dev/null
        grep -Fx \
            'SMOKE_CHECK_OK name=https_wrong_credentials_status status=401' \
            "$output_file" > /dev/null
        grep -Fx \
            'MARK_VERIFIED_OK commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa release=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-123-1' \
            "$output_file" > /dev/null
        return
    fi

    [ "$exit_code" -ne 0 ]
    grep -F "name=$expected_check" "$output_file" > /dev/null
    [ "$rollback_count" -eq 1 ]
    [ "$rollback_result_count" -eq 1 ]

    if [ "$scenario" = 'mark_failure' ]; then
        [ "$mark_count" -eq 1 ]
        grep -F 'MARK_VERIFIED_FAILED' "$output_file" > /dev/null
    else
        [ "$mark_count" -eq 0 ]
        grep -F "SMOKE_TEST_FAILED name=$expected_check" "$output_file" > /dev/null
    fi

    if [ "$scenario" = 'rollback_failure' ]; then
        grep -F 'ROLLBACK_FAILED' "$output_file" > /dev/null
    else
        grep -F 'ROLLBACK_OK' "$output_file" > /dev/null
    fi

    if [ "$scenario" = 'wrong_credentials_500' ]; then
        grep -Fx \
            'SMOKE_CHECK_FAILED name=https_wrong_credentials_status expected=401 actual=500' \
            "$output_file" > /dev/null
    fi

    if [ "$scenario" = 'query_location' ]; then
        ! grep -Fq 'must-not-be-logged' "$output_file"
    fi
}

run_case 'http_301' 'success'
run_case 'http_302' 'success'
run_case 'header_whitespace' 'success'
run_case 'http_307' 'failure' 'http_redirect_status'
run_case 'http_403' 'failure' 'http_redirect_status'
run_case 'missing_location' 'failure' 'http_redirect_location'
run_case 'wrong_location' 'failure' 'http_redirect_location'
run_case 'query_location' 'failure' 'http_redirect_location'
run_case 'http_challenge' 'failure' 'http_redirect_without_auth_challenge'
run_case 'http_empty_challenge' 'failure' 'http_redirect_without_auth_challenge'
run_case 'unauth_root_not_401' 'failure' 'https_unauthenticated_status'
run_case 'missing_basic_challenge' 'failure' 'https_basic_challenge'
run_case 'wrong_credentials_not_401' 'failure' 'https_wrong_credentials_status'
run_case 'wrong_credentials_500' 'failure' 'https_wrong_credentials_status'
run_case 'correct_credentials_not_200' 'failure' 'https_authenticated_status'
run_case 'missing_x_robots' 'failure' 'header_x_robots_tag'
run_case 'partial_x_robots' 'failure' 'header_x_robots_tag'
run_case 'missing_cache' 'failure' 'header_cache_control'
run_case 'missing_content_type' 'failure' 'header_content_type_options'
run_case 'missing_referrer' 'failure' 'header_referrer_policy'
run_case 'robots_unauth_not_401' 'failure' 'robots_unauthenticated_status'
run_case 'robots_status' 'failure' 'robots_status'
run_case 'robots_content' 'failure' 'robots_content'
run_case 'bracelets_unauth_not_401' 'failure' 'bracelets_unauthenticated_status'
run_case 'bracelets_auth_status' 'failure' 'bracelets_page_status'
run_case 'image_status' 'failure' 'static_image_status'
run_case 'mark_failure' 'failure' 'mark_verified'
run_case 'rollback_failure' 'failure' 'static_image_status'

[ "$SCENARIO_COUNT" -ge 25 ]
printf 'Smoke-Orchestrierungs-Test erfolgreich: %s Szenarien.\n' "$SCENARIO_COUNT"
