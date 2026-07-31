#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

EXPECTED_REPOSITORY='Bumpers210/armband-rechner'
EXPECTED_BRANCH='main'
EXPECTED_TARGET='production'
EXPECTED_DOMAIN='www.carmaja-perlen.de'
EXPECTED_WEBROOT='/home/www/carmaja'
EXPECTED_WORKSPACE='/home/www/carmaja-production-deploy'
FORBIDDEN_TEST_DOMAIN="test"'.carmaja-perlen.de'
FORBIDDEN_TEST_API_DOMAIN="test-api"'.carmaja-perlen.de'
HTTP_ROOT='http://www.carmaja-perlen.de/'
HTTPS_ROOT='https://www.carmaja-perlen.de/'
HTTPS_ROBOTS='https://www.carmaja-perlen.de/robots.txt'
HTTPS_BRACELETS='https://www.carmaja-perlen.de/armbaender/'
HTTPS_STATIC_IMAGE='https://www.carmaja-perlen.de/images/bracelets/hero-dunkelrot-braun-holz.jpg'

smoke_directory=''
failed_check='unknown'
rollback_attempted='false'

configuration_failed()
{
    printf 'DEPLOY_CONFIGURATION_FAILED name=%s\n' "$1" >&2
    exit 1
}

assert_guard()
{
    [ "$1" = "$2" ] || configuration_failed "$3"
}

cleanup()
{
    [ -n "$smoke_directory" ] || return 0

    case "$smoke_directory" in
        "${RUNNER_TEMP:-/tmp}"/carmaja-production-smoke.*|/tmp/carmaja-production-smoke.*)
            if [ -d "$smoke_directory" ]; then
                find "$smoke_directory" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
                find "$smoke_directory" -depth -type d -exec rmdir -- {} \;
            fi
            ;;
        *)
            printf 'SMOKE_CLEANUP_FAILED reason=unsafe_temporary_path\n' >&2
            ;;
    esac
}

trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

for required_name in \
    GITHUB_REPOSITORY \
    GITHUB_REF \
    GITHUB_REF_NAME \
    GITHUB_SHA \
    CARMAJA_SITE_TARGET \
    CARMAJA_SITE_DOMAIN \
    CARMAJA_PRODUCTION_WEBROOT \
    CARMAJA_PRODUCTION_DEPLOY_WORKSPACE \
    CARMAJA_PRODUCTION_PUBLISH_ENABLED \
    CARMAJA_PRODUCTION_DEPLOY_ENABLED \
    CARMAJA_RELEASE_ID \
    CARMAJA_ARCHIVE_SHA256 \
    CARMAJA_PRODUCTION_SSH_HOST \
    CARMAJA_PRODUCTION_SSH_USER
do
    if [ -z "${!required_name:-}" ]; then
        configuration_failed "missing_${required_name}"
    fi
done

assert_guard "$GITHUB_REPOSITORY" "$EXPECTED_REPOSITORY" 'repository'
assert_guard "$GITHUB_REF" "refs/heads/$EXPECTED_BRANCH" 'branch_ref'
assert_guard "$GITHUB_REF_NAME" "$EXPECTED_BRANCH" 'branch_name'
assert_guard "$CARMAJA_SITE_TARGET" "$EXPECTED_TARGET" 'target'
assert_guard "$CARMAJA_SITE_DOMAIN" "$EXPECTED_DOMAIN" 'domain'
assert_guard "$CARMAJA_PRODUCTION_WEBROOT" "$EXPECTED_WEBROOT" 'webroot'
assert_guard "$CARMAJA_PRODUCTION_DEPLOY_WORKSPACE" "$EXPECTED_WORKSPACE" 'workspace'
assert_guard "$CARMAJA_PRODUCTION_PUBLISH_ENABLED" 'false' 'production_publish'
assert_guard "$CARMAJA_PRODUCTION_DEPLOY_ENABLED" 'true' 'production_deploy'

case "$GITHUB_SHA" in
    *[!0-9a-f]*|'') configuration_failed 'commit_sha' ;;
esac
[ "${#GITHUB_SHA}" -eq 40 ] || configuration_failed 'commit_sha'

case "$CARMAJA_RELEASE_ID" in
    "$GITHUB_SHA"-*) ;;
    *) configuration_failed 'release_id' ;;
esac
case "$CARMAJA_RELEASE_ID" in
    *[!0-9A-Za-z._-]*|'') configuration_failed 'release_id' ;;
esac

case "$CARMAJA_ARCHIVE_SHA256" in
    *[!0-9a-f]*|'') configuration_failed 'archive_sha256' ;;
esac
[ "${#CARMAJA_ARCHIVE_SHA256}" -eq 64 ] || configuration_failed 'archive_sha256'

case "$CARMAJA_PRODUCTION_SSH_HOST" in
    *[!0-9A-Za-z.-]*|'') configuration_failed 'ssh_host' ;;
esac
case "$CARMAJA_PRODUCTION_SSH_USER" in
    *[!0-9A-Za-z._-]*|'') configuration_failed 'ssh_user' ;;
esac
case "${CARMAJA_PRODUCTION_SSH_PORT:-22}" in
    *[!0-9]*|'') configuration_failed 'ssh_port' ;;
esac
port_number=$((10#${CARMAJA_PRODUCTION_SSH_PORT:-22}))
[ "$port_number" -ge 1 ] && [ "$port_number" -le 65535 ] || configuration_failed 'ssh_port'

PORT="${CARMAJA_PRODUCTION_SSH_PORT:-22}"
REMOTE="${CARMAJA_PRODUCTION_SSH_USER}@${CARMAJA_PRODUCTION_SSH_HOST}"
SSH=(
    ssh
    -i "$HOME/.ssh/carmaja_production_deploy"
    -p "$PORT"
    -o BatchMode=yes
    -o IdentitiesOnly=yes
    -o StrictHostKeyChecking=yes
    -o UserKnownHostsFile="$HOME/.ssh/known_hosts"
)

run_remote_script()
{
    local action=$1

    case "$action" in
        deploy|rollback|mark_verified) ;;
        *) configuration_failed 'remote_action' ;;
    esac

    "${SSH[@]}" "$REMOTE" \
        "CARMAJA_REPOSITORY='$EXPECTED_REPOSITORY' \
         CARMAJA_BRANCH='$EXPECTED_BRANCH' \
         CARMAJA_SITE_TARGET='$EXPECTED_TARGET' \
         CARMAJA_SITE_DOMAIN='$EXPECTED_DOMAIN' \
         CARMAJA_PRODUCTION_WEBROOT='$EXPECTED_WEBROOT' \
         CARMAJA_PRODUCTION_DEPLOY_WORKSPACE='$EXPECTED_WORKSPACE' \
         CARMAJA_PRODUCTION_PUBLISH_ENABLED='false' \
         CARMAJA_PRODUCTION_DEPLOY_ENABLED='true' \
         CARMAJA_COMMIT_SHA='$GITHUB_SHA' \
         CARMAJA_RELEASE_ID='$CARMAJA_RELEASE_ID' \
         CARMAJA_ARCHIVE_SHA256='$CARMAJA_ARCHIVE_SHA256' \
         CARMAJA_DEPLOY_ACTION='$action' \
         sh -s" < scripts/deploy-production-site.sh
}

safe_status()
{
    case "$1" in
        [0-9][0-9][0-9]) printf '%s' "$1" ;;
        *) printf 'invalid' ;;
    esac
}

safe_location()
{
    local value=${1%%\?*}
    value=${value%%#*}
    printf '%s' "$value" | tr -cd '0-9A-Za-z._:/-'
}

smoke_check_start()
{
    printf 'SMOKE_CHECK_START name=%s\n' "$1"
}

smoke_check_ok()
{
    printf 'SMOKE_CHECK_OK name=%s status=%s\n' "$1" "$2"
}

smoke_check_failed()
{
    local name=$1
    local expected=$2
    local actual=$3
    failed_check=$name
    printf 'SMOKE_CHECK_FAILED name=%s expected=%s actual=%s\n' \
        "$name" \
        "$expected" \
        "$actual" >&2
    return 1
}

header_value()
{
    local header_name=$1
    local header_file=$2

    awk -v wanted="$header_name" '
        {
            separator = index($0, ":")
            if (separator == 0) {
                next
            }
            name = substr($0, 1, separator - 1)
            if (tolower(name) != tolower(wanted)) {
                next
            }
            value = substr($0, separator + 1)
            sub(/\r$/, "", value)
            gsub(/^[ \t]+|[ \t]+$/, "", value)
            print value
            exit
        }
    ' "$header_file"
}

header_equals()
{
    local header_file=$1
    local header_name=$2
    local wanted_value=$3
    local actual_value

    actual_value=$(header_value "$header_name" "$header_file")
    [ "$(printf '%s' "$actual_value" | tr '[:upper:]' '[:lower:]')" \
        = "$(printf '%s' "$wanted_value" | tr '[:upper:]' '[:lower:]')" ]
}

prepare_smoke_directory()
{
    local temp_root=${RUNNER_TEMP:-/tmp}

    smoke_directory=$(mktemp -d "$temp_root/carmaja-production-smoke.XXXXXX") || return 1
    chmod 0700 "$smoke_directory"
}

curl_public()
{
    curl \
        --disable \
        --silent \
        --show-error \
        --connect-timeout 15 \
        --max-time 30 \
        "$@"
}

expect_public_status()
{
    local name=$1
    local url=$2
    local output_file=$3
    local code

    smoke_check_start "$name"
    if ! code=$(curl_public -o "$output_file" -w '%{http_code}' "$url"); then
        smoke_check_failed "$name" '200' 'curl_error'
        return 1
    fi
    if [ "$code" != '200' ]; then
        smoke_check_failed "$name" '200' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok "$name" '200'
}

run_smoke_tests()
{
    local code
    local location

    if ! prepare_smoke_directory; then
        smoke_check_start 'temporary_directory'
        smoke_check_failed 'temporary_directory' 'created' 'creation_failed'
        return 1
    fi

    smoke_check_start 'http_redirect_status'
    if ! code=$(curl_public -D "$smoke_directory/http.headers" -o /dev/null -w '%{http_code}' "$HTTP_ROOT"); then
        smoke_check_failed 'http_redirect_status' '301_or_302' 'curl_error'
        return 1
    fi
    case "$code" in
        301|302) smoke_check_ok 'http_redirect_status' "$(safe_status "$code")" ;;
        *)
            smoke_check_failed 'http_redirect_status' '301_or_302' "$(safe_status "$code")"
            return 1
            ;;
    esac

    smoke_check_start 'http_redirect_location'
    location=$(header_value 'Location' "$smoke_directory/http.headers")
    if [ "$location" != "$HTTPS_ROOT" ]; then
        smoke_check_failed 'http_redirect_location' 'exact_https_domain' "$(safe_location "${location:-missing}")"
        return 1
    fi
    smoke_check_ok 'http_redirect_location' 'exact_https_domain'

    expect_public_status 'https_root_status' "$HTTPS_ROOT" "$smoke_directory/root.html" || return 1
    expect_public_status 'robots_status' "$HTTPS_ROBOTS" "$smoke_directory/robots.txt" || return 1
    expect_public_status 'bracelets_page_status' "$HTTPS_BRACELETS" "$smoke_directory/armbaender.html" || return 1
    expect_public_status 'static_image_status' "$HTTPS_STATIC_IMAGE" "$smoke_directory/image.jpg" || return 1

    smoke_check_start 'header_content_type_options'
    if ! curl_public -D "$smoke_directory/headers" -o /dev/null "$HTTPS_ROOT" \
        || ! header_equals "$smoke_directory/headers" 'X-Content-Type-Options' 'nosniff'; then
        smoke_check_failed 'header_content_type_options' 'nosniff' 'missing_or_different'
        return 1
    fi
    smoke_check_ok 'header_content_type_options' 'nosniff'

    smoke_check_start 'production_content'
    if grep -Fq "$FORBIDDEN_TEST_DOMAIN" "$smoke_directory/root.html" \
        || grep -Fq "$FORBIDDEN_TEST_API_DOMAIN" "$smoke_directory/root.html"; then
        smoke_check_failed 'production_content' 'no_test_domain' 'test_domain_found'
        return 1
    fi
    smoke_check_ok 'production_content' 'no_test_domain'
}

perform_rollback()
{
    if [ "$rollback_attempted" = 'true' ]; then
        printf 'ROLLBACK_FAILED reason=duplicate_attempt_blocked\n' >&2
        return 1
    fi

    rollback_attempted='true'
    if run_remote_script rollback; then
        printf 'ROLLBACK_OK commit=%s release=%s verified_new_sha=no\n' \
            "$GITHUB_SHA" \
            "$CARMAJA_RELEASE_ID"
        return 0
    fi

    printf 'ROLLBACK_FAILED commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID" >&2
    return 1
}

printf 'DEPLOY_ACTIVATION_START commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID"
if ! run_remote_script deploy; then
    printf 'DEPLOY_ACTIVATION_FAILED commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID" >&2
    exit 1
fi
printf 'DEPLOY_ACTIVATION_OK commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID"

printf 'SMOKE_TEST_START commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID"
if ! run_smoke_tests; then
    printf 'SMOKE_TEST_FAILED name=%s\n' "$failed_check" >&2
    perform_rollback || true
    exit 1
fi
printf 'SMOKE_TEST_OK commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID"

printf 'MARK_VERIFIED_START commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID"
if ! run_remote_script mark_verified; then
    printf 'SMOKE_CHECK_FAILED name=mark_verified expected=success actual=remote_error\n' >&2
    printf 'MARK_VERIFIED_FAILED commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID" >&2
    perform_rollback || true
    exit 1
fi
printf 'SMOKE_CHECK_OK name=mark_verified status=success\n'
printf 'MARK_VERIFIED_OK commit=%s release=%s\n' "$GITHUB_SHA" "$CARMAJA_RELEASE_ID"
