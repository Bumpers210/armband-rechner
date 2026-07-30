#!/usr/bin/env bash

set +x
set -euo pipefail

umask 0077

readonly PRIVATE_DIR="/home/www/carmaja-private-test"
readonly CONFIG_FILE="${PRIVATE_DIR}/config/runtime-config.php"
readonly TOKEN_FILE="${PRIVATE_DIR}/config/github-token"
readonly DIAGNOSTIC_PROGRAM="${PRIVATE_DIR}/program/product-api-diagnostics.php"
readonly PHP_CLI="/usr/bin/php8.4"
readonly REPOSITORY="Bumpers210/armband-rechner"
readonly BRANCH="test/product-management-beta"
readonly MAX_ATTEMPTS=3

TOKEN_INPUT=""
DIAGNOSTIC_OUTPUT=""
TOKEN_TEMP=""
INPUT_FD=3
OUTPUT_FD=3

cleanup()
{
    set +x
    TOKEN_INPUT=""
    unset TOKEN_INPUT

    if [[ -n "${DIAGNOSTIC_OUTPUT:-}" ]]; then
        rm -f -- "${DIAGNOSTIC_OUTPUT}"
    fi

    if [[ -n "${TOKEN_TEMP:-}" ]]; then
        rm -f -- "${TOKEN_TEMP}"
    fi
}

fail()
{
    printf '%s\n' "$1" >&2
    exit 1
}

prepare_terminal()
{
    if [[ "${CARMAJA_TOKEN_INSTALL_TEST_MODE:-false}" == "true" ]]; then
        if [[ "${PRIVATE_DIR}" != /tmp/carmaja-token-install-test.* ]]; then
            fail "Unsicherer Testmodus wurde abgelehnt."
        fi

        INPUT_FD=0
        OUTPUT_FD=1
        return
    fi

    if ! exec 3<>/dev/tty; then
        fail "Interaktive Token-Eingabe benoetigt ein Terminal."
    fi
}

read_masked_token()
{
    local character=""

    TOKEN_INPUT=""
    printf 'Fine-grained GitHub-Token: ' >&"${OUTPUT_FD}"

    while true; do
        character=""

        if [[ "${CARMAJA_TOKEN_INSTALL_TEST_MODE:-false}" == "true" ]]; then
            if ! IFS= read -r -n 1 character <&"${INPUT_FD}"; then
                printf '\n' >&"${OUTPUT_FD}"
                fail "Token-Eingabe wurde unerwartet beendet."
            fi
        elif ! IFS= read -r -s -n 1 character <&"${INPUT_FD}"; then
            printf '\n' >&"${OUTPUT_FD}"
            fail "Token-Eingabe wurde unerwartet beendet."
        fi

        if [[ -z "${character}" ]]; then
            break
        fi

        case "${character}" in
            $'\177'|$'\b')
                if [[ -n "${TOKEN_INPUT}" ]]; then
                    TOKEN_INPUT="${TOKEN_INPUT%?}"
                    printf '\b \b' >&"${OUTPUT_FD}"
                fi
                ;;
            *)
                TOKEN_INPUT+="${character}"
                printf '*' >&"${OUTPUT_FD}"
                ;;
        esac
    done

    printf '\nToken erkannt: %d Zeichen\n' "${#TOKEN_INPUT}" >&"${OUTPUT_FD}"
}

token_is_valid()
{
    [[ -n "${TOKEN_INPUT}" ]] || return 1
    [[ "${#TOKEN_INPUT}" -le 512 ]] || return 1
    [[ "${TOKEN_INPUT}" != *[[:space:]]* ]] || return 1
    [[ "${TOKEN_INPUT}" == github_pat_* ]] || return 1
    [[ "${TOKEN_INPUT#github_pat_}" != *github_pat_* ]] || return 1
    [[ "${TOKEN_INPUT}" =~ ^github_pat_[A-Za-z0-9_]+$ ]] || return 1
}

confirm_token()
{
    local confirmation=""

    printf 'Token nach erfolgreicher Diagnose speichern? [y/N] ' >&"${OUTPUT_FD}"
    IFS= read -r confirmation <&"${INPUT_FD}" || confirmation=""

    [[ "${confirmation}" == "y" || "${confirmation}" == "Y" ]]
}

diagnostic_output_is_safe_success()
{
    grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' "${DIAGNOSTIC_OUTPUT}" \
        && grep -Eq \
            '"repository"[[:space:]]*:[[:space:]]*"Bumpers210/armband-rechner"' \
            "${DIAGNOSTIC_OUTPUT}" \
        && grep -Eq \
            '"branch"[[:space:]]*:[[:space:]]*"test/product-management-beta"' \
            "${DIAGNOSTIC_OUTPUT}" \
        && grep -Eq '"productsReadable"[[:space:]]*:[[:space:]]*true' \
            "${DIAGNOSTIC_OUTPUT}" \
        && grep -Eq '"writePerformed"[[:space:]]*:[[:space:]]*false' \
            "${DIAGNOSTIC_OUTPUT}"
}

run_readonly_diagnostic()
{
    local diagnostic_status=0

    DIAGNOSTIC_OUTPUT="$(mktemp \
        "${PRIVATE_DIR}/config/.github-token-diagnostic.XXXXXX")"
    chmod 0600 "${DIAGNOSTIC_OUTPUT}"

    if printf '%s\n' "${TOKEN_INPUT}" \
        | "${PHP_CLI}" "${DIAGNOSTIC_PROGRAM}" \
            --github-readonly-token-stdin \
            >"${DIAGNOSTIC_OUTPUT}" 2>&1; then
        diagnostic_status=0
    else
        diagnostic_status=$?
    fi

    if [[ "${diagnostic_status}" -ne 0 ]]; then
        if grep -Eq '"statusCode"[[:space:]]*:[[:space:]]*401' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 10
        fi

        if grep -Eq '"statusCode"[[:space:]]*:[[:space:]]*403' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 12
        fi

        if grep -Eq '"statusCode"[[:space:]]*:[[:space:]]*404' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 13
        fi

        if grep -Eq '"statusCode"[[:space:]]*:[[:space:]]*429' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 14
        fi

        if grep -Eq '"statusCode"[[:space:]]*:[[:space:]]*500' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 20
        fi

        if grep -Eq '"statusCode"[[:space:]]*:[[:space:]]*502' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 21
        fi

        if grep -Eq '"statusCode"[[:space:]]*:[[:space:]]*503' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 22
        fi

        if grep -Eq '"code"[[:space:]]*:[[:space:]]*"github_head_invalid"' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 15
        fi

        if grep -Eq '"code"[[:space:]]*:[[:space:]]*"github_products_unreadable"' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 16
        fi

        if grep -Eq '"code"[[:space:]]*:[[:space:]]*"github_token_invalid"' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 17
        fi

        if grep -Eq \
            '"code"[[:space:]]*:[[:space:]]*"(github_readonly_configuration_invalid|service_unavailable)"' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 18
        fi

        if grep -Eq '"code"[[:space:]]*:[[:space:]]*"diagnostic_failed"' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 23
        fi

        if grep -Eq '"code"[[:space:]]*:[[:space:]]*"upstream_error"' \
            "${DIAGNOSTIC_OUTPUT}"; then
            rm -f -- "${DIAGNOSTIC_OUTPUT}"
            DIAGNOSTIC_OUTPUT=""
            return 24
        fi

        rm -f -- "${DIAGNOSTIC_OUTPUT}"
        DIAGNOSTIC_OUTPUT=""
        return 11
    fi

    if ! diagnostic_output_is_safe_success; then
        rm -f -- "${DIAGNOSTIC_OUTPUT}"
        DIAGNOSTIC_OUTPUT=""
        return 19
    fi

    rm -f -- "${DIAGNOSTIC_OUTPUT}"
    DIAGNOSTIC_OUTPUT=""
    return 0
}

store_token_atomically()
{
    if [[ -L "${TOKEN_FILE}" ]]; then
        fail "Token-Ziel darf kein Symlink sein."
    fi

    TOKEN_TEMP="$(mktemp "${PRIVATE_DIR}/config/.github-token.XXXXXX")"
    chmod 0600 "${TOKEN_TEMP}"
    printf '%s\n' "${TOKEN_INPUT}" >"${TOKEN_TEMP}"
    chmod 0640 "${TOKEN_TEMP}"
    mv -f -- "${TOKEN_TEMP}" "${TOKEN_FILE}"
    TOKEN_TEMP=""
}

main()
{
    local attempt=0
    local diagnostic_result=0

    trap cleanup EXIT
    trap 'exit 129' HUP
    trap 'exit 130' INT
    trap 'exit 143' TERM
    prepare_terminal

    [[ -x "${PHP_CLI}" ]] || fail "PHP 8.4 ist nicht ausfuehrbar."
    [[ -f "${CONFIG_FILE}" && ! -L "${CONFIG_FILE}" ]] \
        || fail "Private Runtime-Konfiguration ist nicht sicher erreichbar."
    [[ -f "${DIAGNOSTIC_PROGRAM}" && ! -L "${DIAGNOSTIC_PROGRAM}" ]] \
        || fail "Diagnoseprogramm ist nicht sicher erreichbar."
    [[ -d "${PRIVATE_DIR}/config" && ! -L "${PRIVATE_DIR}/config" ]] \
        || fail "Privates Konfigurationsverzeichnis ist nicht sicher erreichbar."

    for ((attempt = 1; attempt <= MAX_ATTEMPTS; attempt++)); do
        set +x
        read_masked_token

        if ! token_is_valid; then
            TOKEN_INPUT=""
            unset TOKEN_INPUT
            TOKEN_INPUT=""
            printf 'Token-Eingabe ist ungueltig.\n' >&"${OUTPUT_FD}"
            continue
        fi

        if ! confirm_token; then
            TOKEN_INPUT=""
            unset TOKEN_INPUT
            printf 'Token wurde nicht gespeichert.\n' >&"${OUTPUT_FD}"
            return 1
        fi

        if run_readonly_diagnostic; then
            store_token_atomically
            TOKEN_INPUT=""
            unset TOKEN_INPUT
            printf 'Read-only-GitHub-Diagnose erfolgreich. Token gespeichert.\n' \
                >&"${OUTPUT_FD}"
            return 0
        else
            diagnostic_result=$?
        fi

        TOKEN_INPUT=""
        unset TOKEN_INPUT
        TOKEN_INPUT=""

        if [[ "${diagnostic_result}" -eq 10 ]]; then
            printf 'GitHub hat den Token abgelehnt (HTTP 401). Erneute Eingabe.\n' \
                >&"${OUTPUT_FD}"
            continue
        fi

        if [[ "${diagnostic_result}" -eq 12 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (HTTP 403). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 13 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (HTTP 404). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 14 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (HTTP 429). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 15 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (Remote-HEAD ungueltig). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 16 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (Produktdatei nicht lesbar). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 17 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (Tokenformat ungueltig). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 18 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (Testkonfiguration ungueltig). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 19 ]]; then
            fail "GitHub-Diagnose lieferte unvollstaendige Erfolgsdaten. Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 20 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (HTTP 500). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 21 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (HTTP 502). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 22 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (HTTP 503). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 23 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (interner Diagnosefehler). Token wurde nicht gespeichert."
        fi

        if [[ "${diagnostic_result}" -eq 24 ]]; then
            fail "GitHub-Diagnose fehlgeschlagen (GitHub-Antwort nicht auswertbar). Token wurde nicht gespeichert."
        fi

        fail "Read-only-GitHub-Diagnose fehlgeschlagen. Token wurde nicht gespeichert."
    done

    fail "Maximale Anzahl von drei Token-Versuchen erreicht."
}

main "$@"
