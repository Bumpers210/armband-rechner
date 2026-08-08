#!/usr/bin/env bash

set -Eeuo pipefail
umask 077

EXPECTED_REPOSITORY='Bumpers210/armband-rechner'
EXPECTED_BRANCH='test/product-management-beta'
EXPECTED_DOMAIN='test.carmaja-perlen.de'
EXPECTED_WEBROOT='/home/www/carmaja-test-site'
EXPECTED_WORKSPACE='/home/www/carmaja-test-deploy'
HTTP_ROOT='http://test.carmaja-perlen.de/'
HTTPS_ROOT='https://test.carmaja-perlen.de/'
HTTPS_ROBOTS='https://test.carmaja-perlen.de/robots.txt'
HTTPS_BRACELETS='https://test.carmaja-perlen.de/armbaender/'
HTTPS_STATIC_IMAGE='https://test.carmaja-perlen.de/images/bracelets/hero-dunkelrot-braun-holz.jpg'

smoke_directory=''
auth_config=''
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
        "${RUNNER_TEMP:-/tmp}"/carmaja-smoke.*|/tmp/carmaja-smoke.*)
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
    CARMAJA_TEST_WEBROOT \
    CARMAJA_TEST_DEPLOY_WORKSPACE \
    CARMAJA_PRODUCTION_PUBLISH_ENABLED \
    CARMAJA_PRODUCTION_DEPLOY_ENABLED \
    CARMAJA_TEST_DEPLOY_ENABLED \
    CARMAJA_RELEASE_ID \
    CARMAJA_ARCHIVE_SHA256 \
    CARMAJA_TEST_SSH_HOST \
    CARMAJA_TEST_SSH_USER \
    CARMAJA_TEST_BASIC_AUTH_USER \
    CARMAJA_TEST_BASIC_AUTH_PASSWORD
do
    if [ -z "${!required_name:-}" ]; then
        configuration_failed "missing_${required_name}"
    fi
done

assert_guard "$GITHUB_REPOSITORY" "$EXPECTED_REPOSITORY" 'repository'
assert_guard "$GITHUB_REF" "refs/heads/$EXPECTED_BRANCH" 'branch_ref'
assert_guard "$GITHUB_REF_NAME" "$EXPECTED_BRANCH" 'branch_name'
assert_guard "$CARMAJA_SITE_TARGET" 'test' 'target'
assert_guard "$CARMAJA_SITE_DOMAIN" "$EXPECTED_DOMAIN" 'domain'
assert_guard "$CARMAJA_TEST_WEBROOT" "$EXPECTED_WEBROOT" 'webroot'
assert_guard "$CARMAJA_TEST_DEPLOY_WORKSPACE" "$EXPECTED_WORKSPACE" 'workspace'
assert_guard "$CARMAJA_PRODUCTION_PUBLISH_ENABLED" 'false' 'production_publish'
assert_guard "$CARMAJA_PRODUCTION_DEPLOY_ENABLED" 'false' 'production_deploy'
assert_guard "$CARMAJA_TEST_DEPLOY_ENABLED" 'true' 'test_deploy'

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

case "$CARMAJA_TEST_SSH_HOST" in
    *[!0-9A-Za-z.-]*|'') configuration_failed 'ssh_host' ;;
esac
case "$CARMAJA_TEST_SSH_USER" in
    *[!0-9A-Za-z._-]*|'') configuration_failed 'ssh_user' ;;
esac
case "${CARMAJA_TEST_SSH_PORT:-22}" in
    *[!0-9]*|'') configuration_failed 'ssh_port' ;;
esac
port_number=$((10#${CARMAJA_TEST_SSH_PORT:-22}))
[ "$port_number" -ge 1 ] && [ "$port_number" -le 65535 ] \
    || configuration_failed 'ssh_port'

PORT="${CARMAJA_TEST_SSH_PORT:-22}"
REMOTE="${CARMAJA_TEST_SSH_USER}@${CARMAJA_TEST_SSH_HOST}"
SSH=(
    ssh
    -i "$HOME/.ssh/carmaja_test_deploy"
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
         CARMAJA_SITE_TARGET='test' \
         CARMAJA_SITE_DOMAIN='$EXPECTED_DOMAIN' \
         CARMAJA_TEST_WEBROOT='$EXPECTED_WEBROOT' \
         CARMAJA_TEST_DEPLOY_WORKSPACE='$EXPECTED_WORKSPACE' \
         CARMAJA_PRODUCTION_PUBLISH_ENABLED='false' \
         CARMAJA_PRODUCTION_DEPLOY_ENABLED='false' \
         CARMAJA_COMMIT_SHA='$GITHUB_SHA' \
         CARMAJA_RELEASE_ID='$CARMAJA_RELEASE_ID' \
         CARMAJA_ARCHIVE_SHA256='$CARMAJA_ARCHIVE_SHA256' \
         CARMAJA_DEPLOY_ACTION='$action' \
         sh -s" < scripts/deploy-test-site.sh
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

header_exists()
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
            if (tolower(name) == tolower(wanted)) {
                found = 1
            }
        }
        END { exit found ? 0 : 1 }
    ' "$header_file"
}

header_starts_with_basic()
{
    local header_file=$1

    awk '
        {
            separator = index($0, ":")
            if (separator == 0) {
                next
            }
            name = substr($0, 1, separator - 1)
            if (tolower(name) != "www-authenticate") {
                next
            }
            value = substr($0, separator + 1)
            sub(/\r$/, "", value)
            gsub(/^[ \t]+|[ \t]+$/, "", value)
            if (tolower(value) ~ /^basic([ \t]|$)/) {
                found = 1
            }
        }
        END { exit found ? 0 : 1 }
    ' "$header_file"
}

header_has_token()
{
    local header_file=$1
    local header_name=$2
    local wanted_token=$3

    awk -v wanted_header="$header_name" -v wanted_token="$wanted_token" '
        {
            separator = index($0, ":")
            if (separator == 0) {
                next
            }
            name = substr($0, 1, separator - 1)
            if (tolower(name) != tolower(wanted_header)) {
                next
            }
            value = substr($0, separator + 1)
            sub(/\r$/, "", value)
            count = split(value, tokens, ",")
            for (index_value = 1; index_value <= count; index_value++) {
                gsub(/^[ \t]+|[ \t]+$/, "", tokens[index_value])
                if (tolower(tokens[index_value]) == tolower(wanted_token)) {
                    found = 1
                }
            }
        }
        END { exit found ? 0 : 1 }
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

escape_curl_config_value()
{
    local value=$1

    case "$value" in
        *$'\r'*|*$'\n'*) return 1 ;;
    esac

    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    printf '%s' "$value"
}

prepare_smoke_directory()
{
    local temp_root=${RUNNER_TEMP:-/tmp}
    local escaped_user
    local escaped_password

    smoke_directory=$(mktemp -d "$temp_root/carmaja-smoke.XXXXXX") || return 1
    chmod 0700 "$smoke_directory" || return 1
    auth_config="$smoke_directory/curl-auth.conf"
    escaped_user=$(escape_curl_config_value "$CARMAJA_TEST_BASIC_AUTH_USER") || return 1
    escaped_password=$(escape_curl_config_value "$CARMAJA_TEST_BASIC_AUTH_PASSWORD") || return 1
    printf 'user = "%s:%s"\n' "$escaped_user" "$escaped_password" > "$auth_config" \
        || return 1
    chmod 0600 "$auth_config" || return 1
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

curl_authenticated()
{
    curl \
        --disable \
        --config "$auth_config" \
        --silent \
        --show-error \
        --connect-timeout 15 \
        --max-time 30 \
        "$@"
}

run_smoke_tests()
{
    local code
    local location

    if ! prepare_smoke_directory; then
        smoke_check_start 'temporary_auth_config'
        smoke_check_failed 'temporary_auth_config' 'protected_file' 'creation_failed'
        return 1
    fi

    smoke_check_start 'http_redirect_status'
    if ! code=$(curl_public \
        -D "$smoke_directory/http.headers" \
        -o /dev/null \
        -w '%{http_code}' \
        "$HTTP_ROOT"); then
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
        smoke_check_failed \
            'http_redirect_location' \
            'https_test_domain_exact' \
            "$(safe_location "${location:-missing}")"
        return 1
    fi
    smoke_check_ok 'http_redirect_location' 'exact_https_test_domain'

    smoke_check_start 'http_redirect_without_auth_challenge'
    if header_exists 'WWW-Authenticate' "$smoke_directory/http.headers"; then
        smoke_check_failed 'http_redirect_without_auth_challenge' 'no' 'yes'
        return 1
    fi
    smoke_check_ok 'http_redirect_without_auth_challenge' 'no'

    smoke_check_start 'https_unauthenticated_status'
    if ! code=$(curl_public \
        -D "$smoke_directory/unauth-root.headers" \
        -o /dev/null \
        -w '%{http_code}' \
        "$HTTPS_ROOT"); then
        smoke_check_failed 'https_unauthenticated_status' '401' 'curl_error'
        return 1
    fi
    if [ "$code" != '401' ]; then
        smoke_check_failed 'https_unauthenticated_status' '401' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok 'https_unauthenticated_status' '401'

    smoke_check_start 'https_basic_challenge'
    if ! header_starts_with_basic "$smoke_directory/unauth-root.headers"; then
        smoke_check_failed 'https_basic_challenge' 'yes' 'no'
        return 1
    fi
    smoke_check_ok 'https_basic_challenge' 'yes'

    smoke_check_start 'https_wrong_credentials_status'
    if ! code=$(curl_public \
        --user 'carmaja-invalid:carmaja-invalid' \
        -o /dev/null \
        -w '%{http_code}' \
        "$HTTPS_ROOT"); then
        smoke_check_failed 'https_wrong_credentials_status' '401' 'curl_error'
        return 1
    fi
    if [ "$code" != '401' ]; then
        smoke_check_failed 'https_wrong_credentials_status' '401' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok 'https_wrong_credentials_status' '401'

    smoke_check_start 'https_authenticated_status'
    if ! code=$(curl_authenticated \
        -D "$smoke_directory/auth-root.headers" \
        -o /dev/null \
        -w '%{http_code}' \
        "$HTTPS_ROOT"); then
        smoke_check_failed 'https_authenticated_status' '200' 'curl_error'
        return 1
    fi
    if [ "$code" != '200' ]; then
        smoke_check_failed 'https_authenticated_status' '200' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok 'https_authenticated_status' '200'

    smoke_check_start 'header_x_robots_tag'
    if ! header_has_token "$smoke_directory/auth-root.headers" 'X-Robots-Tag' 'noindex' \
        || ! header_has_token "$smoke_directory/auth-root.headers" 'X-Robots-Tag' 'nofollow' \
        || ! header_has_token "$smoke_directory/auth-root.headers" 'X-Robots-Tag' 'noimageindex'; then
        smoke_check_failed 'header_x_robots_tag' 'yes' 'no'
        return 1
    fi
    smoke_check_ok 'header_x_robots_tag' 'yes'

    smoke_check_start 'header_cache_control'
    if ! header_has_token "$smoke_directory/auth-root.headers" 'Cache-Control' 'private' \
        || ! header_has_token "$smoke_directory/auth-root.headers" 'Cache-Control' 'no-store'; then
        smoke_check_failed 'header_cache_control' 'yes' 'no'
        return 1
    fi
    smoke_check_ok 'header_cache_control' 'yes'

    smoke_check_start 'header_content_type_options'
    if ! header_equals "$smoke_directory/auth-root.headers" 'X-Content-Type-Options' 'nosniff'; then
        smoke_check_failed 'header_content_type_options' 'yes' 'no'
        return 1
    fi
    smoke_check_ok 'header_content_type_options' 'yes'

    smoke_check_start 'header_referrer_policy'
    if ! header_equals "$smoke_directory/auth-root.headers" 'Referrer-Policy' 'no-referrer'; then
        smoke_check_failed 'header_referrer_policy' 'yes' 'no'
        return 1
    fi
    smoke_check_ok 'header_referrer_policy' 'yes'

    smoke_check_start 'robots_unauthenticated_status'
    if ! code=$(curl_public -o /dev/null -w '%{http_code}' "$HTTPS_ROBOTS"); then
        smoke_check_failed 'robots_unauthenticated_status' '401' 'curl_error'
        return 1
    fi
    if [ "$code" != '401' ]; then
        smoke_check_failed 'robots_unauthenticated_status' '401' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok 'robots_unauthenticated_status' '401'

    smoke_check_start 'robots_status'
    if ! code=$(curl_authenticated \
        -o "$smoke_directory/robots.txt" \
        -w '%{http_code}' \
        "$HTTPS_ROBOTS"); then
        smoke_check_failed 'robots_status' '200' 'curl_error'
        return 1
    fi
    if [ "$code" != '200' ]; then
        smoke_check_failed 'robots_status' '200' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok 'robots_status' '200'

    smoke_check_start 'robots_content'
    if ! tr -d '\r' \
        < "$smoke_directory/robots.txt" \
        > "$smoke_directory/robots.normalized"; then
        smoke_check_failed 'robots_content' 'exact_disallow_all' 'read_error'
        return 1
    fi
    if ! printf 'User-agent: *\nDisallow: /\n' > "$smoke_directory/robots.expected"; then
        smoke_check_failed 'robots_content' 'exact_disallow_all' 'write_error'
        return 1
    fi
    if ! cmp -s "$smoke_directory/robots.normalized" "$smoke_directory/robots.expected"; then
        smoke_check_failed 'robots_content' 'exact_disallow_all' 'different'
        return 1
    fi
    smoke_check_ok 'robots_content' 'exact_disallow_all'

    smoke_check_start 'bracelets_unauthenticated_status'
    if ! code=$(curl_public -o /dev/null -w '%{http_code}' "$HTTPS_BRACELETS"); then
        smoke_check_failed 'bracelets_unauthenticated_status' '401' 'curl_error'
        return 1
    fi
    if [ "$code" != '401' ]; then
        smoke_check_failed 'bracelets_unauthenticated_status' '401' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok 'bracelets_unauthenticated_status' '401'

    smoke_check_start 'bracelets_page_status'
    if ! code=$(curl_authenticated -o /dev/null -w '%{http_code}' "$HTTPS_BRACELETS"); then
        smoke_check_failed 'bracelets_page_status' '200' 'curl_error'
        return 1
    fi
    if [ "$code" != '200' ]; then
        smoke_check_failed 'bracelets_page_status' '200' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok 'bracelets_page_status' '200'

    smoke_check_start 'static_image_status'
    if ! code=$(curl_authenticated -o /dev/null -w '%{http_code}' "$HTTPS_STATIC_IMAGE"); then
        smoke_check_failed 'static_image_status' '200' 'curl_error'
        return 1
    fi
    if [ "$code" != '200' ]; then
        smoke_check_failed 'static_image_status' '200' "$(safe_status "$code")"
        return 1
    fi
    smoke_check_ok 'static_image_status' '200'
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

    printf 'ROLLBACK_FAILED commit=%s release=%s\n' \
        "$GITHUB_SHA" \
        "$CARMAJA_RELEASE_ID" >&2
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
