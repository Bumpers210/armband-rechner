#!/usr/bin/env bash

set -Eeuo pipefail
trap 'printf "Basic-Auth-Diagnose-Testfehler in Zeile %s.\n" "$LINENO" >&2' ERR

SOURCE_SCRIPT=${1:?Pfad zum Basic-Auth-Diagnoseskript fehlt.}
ROOT=$(mktemp -d /tmp/carmaja-basic-auth-diagnostic-test.XXXXXX)
MOCK_BIN="$ROOT/bin"
AUTH_DIRECTORY="$ROOT/auth"
AUTH_FILE="$AUTH_DIRECTORY/test-website.htpasswd"
PATCHED_SCRIPT="$ROOT/diagnose-test-basic-auth.sh"
STATE_DIRECTORY="$ROOT/state"
FAKE_USER='fixture-user'
FAKE_HASH='$2y$10$012345678901234567890u012345678901234567890123456789012'

cleanup()
{
    case "$ROOT" in
        /tmp/carmaja-basic-auth-diagnostic-test.*)
            find "$ROOT" -depth \( -type f -o -type l \) -exec rm -f -- {} \;
            find "$ROOT" -depth -type d -exec rmdir -- {} \;
            ;;
        *)
            printf '%s\n' 'Unsicheres Diagnose-Testverzeichnis; Bereinigung abgebrochen.' >&2
            ;;
    esac
}

trap cleanup EXIT HUP INT TERM
mkdir -p "$MOCK_BIN" "$AUTH_DIRECTORY" "$STATE_DIRECTORY"

sed \
    "s#^AUTH_FILE='/home/www/carmaja-private-test/auth/test-website.htpasswd'\$#AUTH_FILE='$AUTH_FILE'#" \
    "$SOURCE_SCRIPT" > "$PATCHED_SCRIPT"
chmod 0700 "$PATCHED_SCRIPT"

cat > "$MOCK_BIN/log-cat" <<'MOCK_LOG_CAT'
#!/usr/bin/env bash
set -eu

case "${CARMAJA_LOG_SCENARIO:-unknown}" in
    not_found)
        printf '%s\n' \
            '203.0.113.42 test.carmaja-perlen.de AH01620: Could not open password file'
        ;;
    permission)
        printf '%s\n' \
            '[client 198.51.100.23:443] AuthUserFile password file: Permission denied'
        ;;
    invalid)
        printf '%s\n' \
            'test.carmaja-perlen.de invalid password file: malformed password entry'
        ;;
    syntax)
        printf '%s\n' \
            '192.0.2.44 test.carmaja-perlen.de .htaccess syntax error AH00526'
        ;;
    module)
        printf '%s\n' \
            'test.carmaja-perlen.de AH01619: authn provider configuration failed'
        ;;
    unknown)
        printf '%s\n' \
            '203.0.113.99 test.carmaja-perlen.de GET / HTTP/1.1 500'
        ;;
esac
MOCK_LOG_CAT
chmod 0700 "$MOCK_BIN/log-cat"

cat > "$MOCK_BIN/htpasswd" <<'MOCK_HTPASSWD'
#!/usr/bin/env bash
set -eu

printf '%s\n' "$*" >> "$CARMAJA_MOCK_STATE/htpasswd-arguments.log"
printf '%s\n' \
    'Password for fixture-user accepted.' \
    'fixture-user:$2y$10$012345678901234567890u012345678901234567890123456789012'
MOCK_HTPASSWD
chmod 0700 "$MOCK_BIN/htpasswd"

run_diagnosis()
{
    local scenario=$1
    local output_file=$2

    if ! PATH="$MOCK_BIN:$PATH" \
        CARMAJA_LOG_SCENARIO="$scenario" \
        CARMAJA_MOCK_STATE="$STATE_DIRECTORY" \
            bash "$PATCHED_SCRIPT" --diagnose > "$output_file" 2>&1; then
        grep '^BASIC_AUTH_' "$output_file" >&2 || true
        grep -v '^BASIC_AUTH_' "$output_file" >&2 || true
        return 1
    fi
}

assert_no_sensitive_output()
{
    local output_file=$1

    ! grep -Fq "$FAKE_USER" "$output_file"
    ! grep -Fq "$FAKE_HASH" "$output_file"
    ! grep -Eq \
        '(^|[^0-9])([0-9]{1,3}\.){3}[0-9]{1,3}([^0-9]|$)' \
        "$output_file"
}

MISSING_OUTPUT="$ROOT/missing.log"
run_diagnosis 'unknown' "$MISSING_OUTPUT"
grep -Fx 'BASIC_AUTH_FILE_EXISTS=no' "$MISSING_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_FILE_FORMAT=missing' "$MISSING_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_LOG_CLASSIFICATION=unknown' "$MISSING_OUTPUT" > /dev/null
assert_no_sensitive_output "$MISSING_OUTPUT"

printf '%s:%s\n' "$FAKE_USER" "$FAKE_HASH" > "$AUTH_FILE"
chmod 0640 "$AUTH_FILE"

NOT_FOUND_OUTPUT="$ROOT/not-found.log"
run_diagnosis 'not_found' "$NOT_FOUND_OUTPUT"
grep -Fx 'BASIC_AUTH_FILE_EXISTS=yes' "$NOT_FOUND_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_FILE_SYMLINK=no' "$NOT_FOUND_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_FILE_FORMAT=ok' "$NOT_FOUND_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_ENTRY_COUNT=1' "$NOT_FOUND_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_HTPASSWD_AVAILABLE=yes' "$NOT_FOUND_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_LOG_CAT_AVAILABLE=yes' "$NOT_FOUND_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_LOG_CLASSIFICATION=auth_file_not_found' "$NOT_FOUND_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_LOG_REDACTED_IP_COUNT=1' "$NOT_FOUND_OUTPUT" > /dev/null
assert_no_sensitive_output "$NOT_FOUND_OUTPUT"

PERMISSION_OUTPUT="$ROOT/permission.log"
run_diagnosis 'permission' "$PERMISSION_OUTPUT"
grep -Fx \
    'BASIC_AUTH_LOG_CLASSIFICATION=auth_file_permission_denied' \
    "$PERMISSION_OUTPUT" > /dev/null
assert_no_sensitive_output "$PERMISSION_OUTPUT"

LOG_INVALID_OUTPUT="$ROOT/log-invalid.log"
run_diagnosis 'invalid' "$LOG_INVALID_OUTPUT"
grep -Fx \
    'BASIC_AUTH_LOG_CLASSIFICATION=auth_file_invalid_format' \
    "$LOG_INVALID_OUTPUT" > /dev/null
assert_no_sensitive_output "$LOG_INVALID_OUTPUT"

SYNTAX_OUTPUT="$ROOT/syntax.log"
run_diagnosis 'syntax' "$SYNTAX_OUTPUT"
grep -Fx 'BASIC_AUTH_LOG_CLASSIFICATION=htaccess_syntax_error' "$SYNTAX_OUTPUT" > /dev/null
assert_no_sensitive_output "$SYNTAX_OUTPUT"

MODULE_OUTPUT="$ROOT/module.log"
run_diagnosis 'module' "$MODULE_OUTPUT"
grep -Fx 'BASIC_AUTH_LOG_CLASSIFICATION=auth_module_error' "$MODULE_OUTPUT" > /dev/null
assert_no_sensitive_output "$MODULE_OUTPUT"

UNKNOWN_OUTPUT="$ROOT/unknown.log"
run_diagnosis 'unknown' "$UNKNOWN_OUTPUT"
grep -Fx 'BASIC_AUTH_LOG_CLASSIFICATION=unknown' "$UNKNOWN_OUTPUT" > /dev/null
assert_no_sensitive_output "$UNKNOWN_OUTPUT"

printf '%s\n' 'invalid-without-colon' > "$AUTH_FILE"
INVALID_FILE_OUTPUT="$ROOT/invalid-file.log"
run_diagnosis 'unknown' "$INVALID_FILE_OUTPUT"
grep -Fx 'BASIC_AUTH_FILE_FORMAT=invalid' "$INVALID_FILE_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_ENTRY_COUNT=1' "$INVALID_FILE_OUTPUT" > /dev/null
assert_no_sensitive_output "$INVALID_FILE_OUTPUT"

printf '%s:%s\n' "$FAKE_USER" "$FAKE_HASH" > "$AUTH_FILE"
VERIFY_OUTPUT="$ROOT/verify.log"
printf '%s\n' "$FAKE_USER" \
    | PATH="$MOCK_BIN:$PATH" \
      CARMAJA_LOG_SCENARIO='unknown' \
      CARMAJA_MOCK_STATE="$STATE_DIRECTORY" \
        bash "$PATCHED_SCRIPT" --verify > "$VERIFY_OUTPUT" 2>&1
grep -Fx 'BASIC_AUTH_PASSWORD_CHECK=interactive_htpasswd' "$VERIFY_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_PASSWORD_VERIFY=ok' "$VERIFY_OUTPUT" > /dev/null
assert_no_sensitive_output "$VERIFY_OUTPUT"
grep -Fx -- "-v $AUTH_FILE $FAKE_USER" "$STATE_DIRECTORY/htpasswd-arguments.log" > /dev/null

RESET_OUTPUT="$ROOT/reset.log"
printf 'y\n%s\n' "$FAKE_USER" \
    | PATH="$MOCK_BIN:$PATH" \
      CARMAJA_LOG_SCENARIO='unknown' \
      CARMAJA_MOCK_STATE="$STATE_DIRECTORY" \
        bash "$PATCHED_SCRIPT" --reset > "$RESET_OUTPUT" 2>&1
grep -Fx 'BASIC_AUTH_PASSWORD_RESET=interactive_htpasswd' "$RESET_OUTPUT" > /dev/null
grep -Fx 'BASIC_AUTH_PASSWORD_RESET=ok' "$RESET_OUTPUT" > /dev/null
assert_no_sensitive_output "$RESET_OUTPUT"
grep -Fx -- "-B $AUTH_FILE $FAKE_USER" "$STATE_DIRECTORY/htpasswd-arguments.log" > /dev/null

if bash "$PATCHED_SCRIPT" --verify 'password-value' > /dev/null 2>&1; then
    printf '%s\n' 'Diagnoseskript akzeptiert unerwartete Zusatzargumente.' >&2
    exit 1
fi

printf '%s\n' 'Basic-Auth-Diagnose-Shell-Test erfolgreich.'
