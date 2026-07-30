#!/usr/bin/env bash

set -euo pipefail

SOURCE_SCRIPT="${1:?Pfad zum Token-Installationsskript fehlt.}"
TEST_ROOT="$(mktemp -d /tmp/carmaja-token-install-test.XXXXXX)"
PRIVATE_DIR="${TEST_ROOT}/private"
PROGRAM_DIR="${PRIVATE_DIR}/program"
CONFIG_DIR="${PRIVATE_DIR}/config"
TEST_SCRIPT="${TEST_ROOT}/install-test-github-token.sh"
MOCK_PHP="${TEST_ROOT}/php8.4"
MOCK_STATE="${TEST_ROOT}/mock-state"
MOCK_MODE_FILE="${TEST_ROOT}/mock-mode"

cleanup()
{
    rm -rf -- "${TEST_ROOT}"
}

fail()
{
    printf 'FEHLER: %s\n' "$1" >&2
    exit 1
}

trap cleanup EXIT HUP INT TERM

mkdir -p "${PROGRAM_DIR}" "${CONFIG_DIR}"
printf '<?php return [];\n' >"${CONFIG_DIR}/runtime-config.php"
printf '<?php\n' >"${PROGRAM_DIR}/product-api-diagnostics.php"

sed \
    -e "s|/home/www/carmaja-private-test|${PRIVATE_DIR}|g" \
    -e "s|/usr/bin/php8.4|${MOCK_PHP}|g" \
    "${SOURCE_SCRIPT}" >"${TEST_SCRIPT}"
chmod 0750 "${TEST_SCRIPT}"

printf '%s\n' \
    '#!/usr/bin/env bash' \
    'set -euo pipefail' \
    '[[ "$#" -eq 2 ]] || exit 90' \
    '[[ "$2" == "--github-readonly-token-stdin" ]] || exit 91' \
    'IFS= read -r candidate' \
    'count=0' \
    '[[ ! -f "$CARMAJA_TOKEN_TEST_STATE" ]] || count="$(cat "$CARMAJA_TOKEN_TEST_STATE")"' \
    'count=$((count + 1))' \
    'printf "%d\n" "$count" >"$CARMAJA_TOKEN_TEST_STATE"' \
    'mode="$(cat "$CARMAJA_TOKEN_TEST_MODE_FILE")"' \
    'if [[ "$mode" == "always-401" || ( "$mode" == "first-401" && "$count" -eq 1 ) ]]; then' \
    '  printf "%s\n" "{\"ok\":false,\"error\":{\"fields\":{\"statusCode\":401}}}" >&2' \
    '  candidate=""' \
    '  unset candidate' \
    '  exit 5' \
    'fi' \
    'if [[ "$mode" == "always-403" ]]; then' \
    '  printf "%s\n" "{\"ok\":false,\"error\":{\"fields\":{\"statusCode\":403}}}" >&2' \
    '  candidate=""' \
    '  unset candidate' \
    '  exit 5' \
    'fi' \
    'candidate=""' \
    'unset candidate' \
    'printf "%s\n" "{\"ok\":true,\"github\":{\"repository\":\"Bumpers210/armband-rechner\",\"branch\":\"test/product-management-beta\",\"productsReadable\":true,\"writePerformed\":false}}"' \
    >"${MOCK_PHP}"
chmod 0750 "${MOCK_PHP}"

export CARMAJA_TOKEN_INSTALL_TEST_MODE=true
export CARMAJA_TOKEN_TEST_STATE="${MOCK_STATE}"
export CARMAJA_TOKEN_TEST_MODE_FILE="${MOCK_MODE_FILE}"

run_installer()
{
    local input="$1"
    local output_file="$2"

    set +e
    printf '%b' "${input}" | "${TEST_SCRIPT}" >"${output_file}" 2>&1
    local status=$?
    set -e
    return "${status}"
}

INVALID_OUTPUT="${TEST_ROOT}/invalid-output"
printf 'success\n' >"${MOCK_MODE_FILE}"
rm -f "${MOCK_STATE}" "${CONFIG_DIR}/github-token"

if run_installer \
    '\ngithub_pat_FAKE TOKEN\ngithub_''pat_FIRSTgithub_''pat_SECOND\n' \
    "${INVALID_OUTPUT}"; then
    fail "Drei ungueltige Eingaben wurden akzeptiert."
fi

grep -q 'Token erkannt: 0 Zeichen' "${INVALID_OUTPUT}" \
    || fail "Leere Eingabe wurde nicht erkannt."
grep -q 'Maximale Anzahl von drei Token-Versuchen erreicht.' "${INVALID_OUTPUT}" \
    || fail "Versuchslimit fehlt."
[[ ! -e "${CONFIG_DIR}/github-token" ]] \
    || fail "Ungueltiger Token wurde gespeichert."
[[ ! -e "${MOCK_STATE}" ]] \
    || fail "Ungueltige Tokens haben die Diagnose erreicht."

BACKSPACE_OUTPUT="${TEST_ROOT}/backspace-output"
rm -f "${CONFIG_DIR}/github-token"

if run_installer 'github_pat_abX\bcd\nn\n' "${BACKSPACE_OUTPUT}"; then
    fail "Abgelehnte Bestaetigung war erfolgreich."
fi

grep -q 'Token erkannt: 15 Zeichen' "${BACKSPACE_OUTPUT}" \
    || fail "Backspace hat die Tokenlaenge nicht korrigiert."
grep -q $'\b \b' "${BACKSPACE_OUTPUT}" \
    || fail "Backspace wurde nicht sichtbar verarbeitet."
grep -q 'Token wurde nicht gespeichert.' "${BACKSPACE_OUTPUT}" \
    || fail "Ablehnung der Bestaetigung fehlt."
[[ ! -e "${CONFIG_DIR}/github-token" ]] \
    || fail "Token wurde ohne Bestaetigung gespeichert."

RETRY_OUTPUT="${TEST_ROOT}/retry-output"
printf 'first-401\n' >"${MOCK_MODE_FILE}"
rm -f "${MOCK_STATE}" "${CONFIG_DIR}/github-token"

if ! run_installer \
    'github_pat_FAKE_REJECTED\ny\ngithub_pat_FAKE_ACCEPTED\ny\n' \
    "${RETRY_OUTPUT}"; then
    fail "401-Retry mit zweitem gueltigem Token ist fehlgeschlagen."
fi

grep -q 'GitHub hat den Token abgelehnt (HTTP 401).' "${RETRY_OUTPUT}" \
    || fail "HTTP 401 wurde nicht sicher klassifiziert."
grep -q 'Read-only-GitHub-Diagnose erfolgreich. Token gespeichert.' \
    "${RETRY_OUTPUT}" \
    || fail "Erfolgreiche Diagnose wurde nicht bestaetigt."
grep -q 'github_pat_FAKE' "${RETRY_OUTPUT}" \
    && fail "Token wurde in der Ausgabe offengelegt."
[[ "$(cat "${MOCK_STATE}")" == "2" ]] \
    || fail "401-Retry hat nicht exakt zwei Diagnosen ausgefuehrt."
[[ "$(cat "${CONFIG_DIR}/github-token")" == "github_pat_FAKE_ACCEPTED" ]] \
    || fail "Nicht ausschliesslich der erfolgreich diagnostizierte Token wurde gespeichert."

if [[ "$(uname -s)" != MINGW* && "$(uname -s)" != MSYS* ]]; then
    [[ "$(stat -c '%a' "${CONFIG_DIR}/github-token")" == "640" ]] \
        || fail "Token-Datei besitzt nicht Modus 0640."
fi

LIMIT_OUTPUT="${TEST_ROOT}/limit-output"
printf 'always-401\n' >"${MOCK_MODE_FILE}"
rm -f "${MOCK_STATE}" "${CONFIG_DIR}/github-token"

if run_installer \
    'github_pat_FAKE_ONE\ny\ngithub_pat_FAKE_TWO\ny\ngithub_pat_FAKE_THREE\ny\n' \
    "${LIMIT_OUTPUT}"; then
    fail "Drei HTTP-401-Antworten wurden akzeptiert."
fi

[[ "$(cat "${MOCK_STATE}")" == "3" ]] \
    || fail "Versuchslimit hat nicht nach drei Diagnosen gestoppt."
[[ ! -e "${CONFIG_DIR}/github-token" ]] \
    || fail "Nach drei HTTP-401-Antworten wurde ein Token gespeichert."
grep -q 'github_pat_FAKE' "${LIMIT_OUTPUT}" \
    && fail "Abgelehnter Token wurde in der Ausgabe offengelegt."

FORBIDDEN_OUTPUT="${TEST_ROOT}/forbidden-output"
printf 'always-403\n' >"${MOCK_MODE_FILE}"
rm -f "${MOCK_STATE}" "${CONFIG_DIR}/github-token"

if run_installer 'github_pat_FAKE_FORBIDDEN\ny\n' "${FORBIDDEN_OUTPUT}"; then
    fail "HTTP 403 wurde akzeptiert."
fi

grep -q 'GitHub-Diagnose fehlgeschlagen (HTTP 403).' "${FORBIDDEN_OUTPUT}" \
    || fail "HTTP 403 wurde nicht sicher klassifiziert."
grep -q 'github_pat_FAKE' "${FORBIDDEN_OUTPUT}" \
    && fail "Token wurde bei HTTP 403 in der Ausgabe offengelegt."
[[ "$(cat "${MOCK_STATE}")" == "1" ]] \
    || fail "HTTP 403 darf keinen automatischen Wiederholungsversuch starten."
[[ ! -e "${CONFIG_DIR}/github-token" ]] \
    || fail "Nach HTTP 403 wurde ein Token gespeichert."

printf 'Sicherer GitHub-Token-Installer-Shell-Test erfolgreich.\n'
