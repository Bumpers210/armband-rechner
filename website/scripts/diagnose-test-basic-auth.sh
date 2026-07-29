#!/usr/bin/env bash

set -eu

AUTH_FILE='/home/www/carmaja-test-auth/test-website.htpasswd'
TEST_DOMAIN='test.carmaja-perlen.de'
MODE=${1:-}

usage()
{
    printf '%s\n' \
        'Verwendung:' \
        '  bash diagnose-test-basic-auth.sh --diagnose' \
        '  bash diagnose-test-basic-auth.sh --verify' \
        '  bash diagnose-test-basic-auth.sh --reset'
}

fail()
{
    printf 'BASIC_AUTH_DIAGNOSIS_ERROR=%s\n' "$1" >&2
    exit 1
}

yes_no()
{
    if "$@"; then
        printf 'yes'
    else
        printf 'no'
    fi
}

command_available()
{
    command -v "$1" >/dev/null 2>&1
}

safe_stat_value()
{
    local format=$1
    local path=$2

    if command -v stat >/dev/null 2>&1; then
        stat -c "$format" "$path" 2>/dev/null || printf 'unavailable'
    else
        printf 'unavailable'
    fi
}

inspect_directory()
{
    local label=$1
    local path=$2

    printf 'BASIC_AUTH_PARENT_%s_EXISTS=%s\n' \
        "$label" \
        "$(yes_no test -d "$path")"

    if [ -d "$path" ]; then
        printf 'BASIC_AUTH_PARENT_%s_MODE=%s\n' \
            "$label" \
            "$(safe_stat_value '%a' "$path")"
        printf 'BASIC_AUTH_PARENT_%s_OWNER_UID=%s\n' \
            "$label" \
            "$(safe_stat_value '%u' "$path")"
        printf 'BASIC_AUTH_PARENT_%s_GROUP_GID=%s\n' \
            "$label" \
            "$(safe_stat_value '%g' "$path")"
        printf 'BASIC_AUTH_PARENT_%s_SEARCHABLE_BY_SSH_USER=%s\n' \
            "$label" \
            "$(yes_no test -x "$path")"
        printf 'BASIC_AUTH_PARENT_%s_READABLE_BY_SSH_USER=%s\n' \
            "$label" \
            "$(yes_no test -r "$path")"
    fi
}

inspect_password_file_format()
{
    local entry_count
    local duplicate_users

    if entry_count=$(awk -F ':' '
        BEGIN {
            valid = 1
            count = 0
        }
        {
            sub(/\r$/, "", $0)
            count++
            invalid = NF != 2
            invalid = invalid || $1 == ""
            invalid = invalid || $2 == ""
            invalid = invalid || $1 !~ /^[A-Za-z0-9._-]+$/
            invalid = invalid || $2 ~ /[[:space:]]/
            if (invalid) {
                valid = 0
            }
        }
        END {
            print count
            if (valid && count > 0) {
                exit 0
            }
            exit 1
        }
    ' "$AUTH_FILE"); then
        printf 'BASIC_AUTH_FILE_FORMAT=ok\n'
    else
        printf 'BASIC_AUTH_FILE_FORMAT=invalid\n'
    fi

    case "$entry_count" in
        ''|*[!0-9]*) entry_count='unknown' ;;
    esac
    printf 'BASIC_AUTH_ENTRY_COUNT=%s\n' "$entry_count"

    if awk -F ':' '
        {
            sub(/\r$/, "", $0)
            seen[$1]++
        }
        END {
            for (name in seen) {
                if (seen[name] > 1) {
                    exit 0
                }
            }
            exit 1
        }
    ' "$AUTH_FILE"; then
        duplicate_users='yes'
    else
        duplicate_users='no'
    fi
    printf 'BASIC_AUTH_DUPLICATE_USERS=%s\n' "$duplicate_users"
}

classify_log_stream()
{
    awk -v domain="$TEST_DOMAIN" '
        function select_class(candidate, candidate_rank) {
            if (candidate_rank > rank) {
                classification = candidate
                rank = candidate_rank
            }
        }
        BEGIN {
            classification = "unknown"
            rank = 0
            relevant_count = 0
            redacted_ip_count = 0
        }
        {
            line = $0
            lower = tolower(line)
            relevant = index(lower, tolower(domain)) > 0
            relevant = relevant || index(lower, "authuserfile") > 0
            relevant = relevant || index(lower, "password file") > 0
            relevant = relevant || index(lower, "htpasswd") > 0
            relevant = relevant || lower ~ /ah[0-9][0-9][0-9][0-9][0-9]/
            relevant = relevant || lower ~ /(^|[[:space:]])500([[:space:]]|$)/

            if (!relevant) {
                next
            }

            relevant_count++
            if (line ~ /[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?/) {
                redacted_ip_count++
            }
            gsub(/[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?\.[0-9][0-9]?[0-9]?/, "[ip-redacted]", line)
            gsub(/\[[Cc]lient [^]]*\]/, "[client-redacted]", line)

            if (lower ~ /permission denied|access denied/) {
                select_class("auth_file_permission_denied", 60)
            }
            if (lower ~ /could not open password file|password file.*not found|no such file|ah01620/) {
                select_class("auth_file_not_found", 50)
            }
            if (lower ~ /invalid password file|password file.*invalid|malformed.*password|could not parse.*password|error parsing.*password/) {
                select_class("auth_file_invalid_format", 40)
            }
            if (lower ~ /\.htaccess.*syntax error|syntax error.*\.htaccess|invalid command|not allowed here|ah00526/) {
                select_class("htaccess_syntax_error", 30)
            }
            if (lower ~ /authn provider|auth_basic|authentication module|ah01619|ah01796/) {
                select_class("auth_module_error", 20)
            }
        }
        END {
            printf "BASIC_AUTH_LOG_RELEVANT_COUNT=%d\n", relevant_count
            printf "BASIC_AUTH_LOG_REDACTED_IP_COUNT=%d\n", redacted_ip_count
            printf "BASIC_AUTH_LOG_LINES_EMITTED=0\n"
            printf "BASIC_AUTH_LOG_CLASSIFICATION=%s\n", classification
        }
    '
}

inspect_apache_log()
{
    if command -v log-cat >/dev/null 2>&1; then
        printf 'BASIC_AUTH_LOG_CAT_AVAILABLE=yes\n'
        log-cat 2>/dev/null | classify_log_stream
    else
        printf 'BASIC_AUTH_LOG_CAT_AVAILABLE=no\n'
        printf 'BASIC_AUTH_LOG_RELEVANT_COUNT=0\n'
        printf 'BASIC_AUTH_LOG_REDACTED_IP_COUNT=0\n'
        printf 'BASIC_AUTH_LOG_LINES_EMITTED=0\n'
        printf 'BASIC_AUTH_LOG_CLASSIFICATION=unknown\n'
    fi
}

run_diagnosis()
{
    local resolved_path

    printf 'BASIC_AUTH_DIAGNOSIS_MODE=read_only\n'
    printf 'BASIC_AUTH_FILE_EXISTS=%s\n' "$(yes_no test -e "$AUTH_FILE")"
    printf 'BASIC_AUTH_FILE_SYMLINK=%s\n' "$(yes_no test -L "$AUTH_FILE")"
    printf 'BASIC_AUTH_HTPASSWD_AVAILABLE=%s\n' \
        "$(yes_no command_available htpasswd)"

    inspect_directory 'TEST_AUTH' "$(dirname "$AUTH_FILE")"
    inspect_directory 'WWW' '/home/www'
    inspect_directory 'HOME' '/home'
    inspect_directory 'ROOT' '/'

    if [ ! -e "$AUTH_FILE" ]; then
        printf 'BASIC_AUTH_FILE_REALPATH=unavailable\n'
        printf 'BASIC_AUTH_FILE_REGULAR=no\n'
        printf 'BASIC_AUTH_FILE_READABLE_BY_SSH_USER=no\n'
        printf 'BASIC_AUTH_FILE_FORMAT=missing\n'
        printf 'BASIC_AUTH_ENTRY_COUNT=0\n'
        printf 'BASIC_AUTH_DUPLICATE_USERS=unknown\n'
        inspect_apache_log
        return 0
    fi

    if command -v realpath >/dev/null 2>&1 \
        && resolved_path=$(realpath "$AUTH_FILE" 2>/dev/null); then
        if [ "$resolved_path" = "$AUTH_FILE" ]; then
            printf 'BASIC_AUTH_FILE_REALPATH=expected\n'
        else
            printf 'BASIC_AUTH_FILE_REALPATH=unexpected\n'
        fi
    else
        printf 'BASIC_AUTH_FILE_REALPATH=unavailable\n'
    fi

    printf 'BASIC_AUTH_FILE_REGULAR=%s\n' "$(yes_no test -f "$AUTH_FILE")"
    printf 'BASIC_AUTH_FILE_OWNER_UID=%s\n' "$(safe_stat_value '%u' "$AUTH_FILE")"
    printf 'BASIC_AUTH_FILE_GROUP_GID=%s\n' "$(safe_stat_value '%g' "$AUTH_FILE")"
    printf 'BASIC_AUTH_FILE_MODE=%s\n' "$(safe_stat_value '%a' "$AUTH_FILE")"
    printf 'BASIC_AUTH_FILE_READABLE_BY_SSH_USER=%s\n' \
        "$(yes_no test -r "$AUTH_FILE")"

    if [ -f "$AUTH_FILE" ] && [ -r "$AUTH_FILE" ]; then
        inspect_password_file_format
    else
        printf 'BASIC_AUTH_FILE_FORMAT=unreadable\n'
        printf 'BASIC_AUTH_ENTRY_COUNT=unknown\n'
        printf 'BASIC_AUTH_DUPLICATE_USERS=unknown\n'
    fi

    inspect_apache_log
}

validate_auth_user()
{
    local value=$1

    [[ "$value" =~ ^[A-Za-z0-9._-]{1,64}$ ]]
}

redact_htpasswd_output()
{
    sed -E \
        -e 's/([Uu]ser(name)?[[:space:]]+)[A-Za-z0-9._-]+/\1[redacted]/g' \
        -e 's/(for[[:space:]]+)[A-Za-z0-9._-]+/\1[redacted]/g' \
        -e 's/^[A-Za-z0-9._-]+:/[user-redacted]:/' \
        -e 's#\$2[aby]\$[A-Za-z0-9./$]+#[hash-redacted]#g' \
        -e 's#\$apr1\$[A-Za-z0-9./$]+#[hash-redacted]#g'
}

read_auth_user()
{
    read -r -p 'Basic-Auth-Benutzername: ' AUTH_USER
    if ! validate_auth_user "$AUTH_USER"; then
        unset AUTH_USER
        fail 'invalid_username'
    fi
}

verify_password_interactively()
{
    command -v htpasswd >/dev/null 2>&1 || fail 'htpasswd_unavailable'
    [ -f "$AUTH_FILE" ] || fail 'auth_file_missing'
    [ -r "$AUTH_FILE" ] || fail 'auth_file_unreadable'

    read_auth_user
    printf 'BASIC_AUTH_PASSWORD_CHECK=interactive_htpasswd\n'
    if htpasswd -v "$AUTH_FILE" "$AUTH_USER" \
        > >(redact_htpasswd_output) \
        2> >(redact_htpasswd_output >&2); then
        printf 'BASIC_AUTH_PASSWORD_VERIFY=ok\n'
        verify_code=0
    else
        printf 'BASIC_AUTH_PASSWORD_VERIFY=failed\n'
        verify_code=1
    fi
    unset AUTH_USER
    return "$verify_code"
}

reset_password_interactively()
{
    local reset_reply

    command -v htpasswd >/dev/null 2>&1 || fail 'htpasswd_unavailable'
    [ -f "$AUTH_FILE" ] || fail 'auth_file_missing'
    [ -r "$AUTH_FILE" ] || fail 'auth_file_unreadable'

    read -r -p 'Passwort für bestehenden Benutzer interaktiv neu setzen? [y/N] ' reset_reply
    case "$reset_reply" in
        y|Y) ;;
        *)
            unset reset_reply
            printf 'BASIC_AUTH_PASSWORD_RESET=cancelled\n'
            return 0
            ;;
    esac
    unset reset_reply

    read_auth_user
    if ! awk -F ':' -v wanted="$AUTH_USER" '
        $1 == wanted {
            found = 1
        }
        END {
            exit found ? 0 : 1
        }
    ' "$AUTH_FILE"; then
        unset AUTH_USER
        fail 'existing_user_not_found'
    fi

    printf 'BASIC_AUTH_PASSWORD_RESET=interactive_htpasswd\n'
    if htpasswd -B "$AUTH_FILE" "$AUTH_USER" \
        > >(redact_htpasswd_output) \
        2> >(redact_htpasswd_output >&2); then
        printf 'BASIC_AUTH_PASSWORD_RESET=ok\n'
        reset_code=0
    else
        printf 'BASIC_AUTH_PASSWORD_RESET=failed\n'
        reset_code=1
    fi
    unset AUTH_USER
    return "$reset_code"
}

[ "$#" -eq 1 ] || {
    usage
    fail 'unsupported_arguments'
}

case "$MODE" in
    --diagnose)
        run_diagnosis
        ;;
    --verify)
        run_diagnosis
        verify_password_interactively
        ;;
    --reset)
        run_diagnosis
        reset_password_interactively
        ;;
    *)
        usage
        fail 'unsupported_mode'
        ;;
esac
