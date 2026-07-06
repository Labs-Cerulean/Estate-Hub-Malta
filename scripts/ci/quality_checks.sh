#!/usr/bin/env bash
# Estate Hub Malta — CI quality gates (run locally or in GitHub Actions)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

fail() { echo -e "${RED}FAIL:${NC} $1" >&2; exit 1; }
pass() { echo -e "${GREEN}OK:${NC} $1"; }

# ---------------------------------------------------------------------------
# Determine which files changed (PR diff vs last commit vs all PHP)
# ---------------------------------------------------------------------------
resolve_changed_files() {
    if [[ -n "${GITHUB_BASE_REF:-}" ]] && git rev-parse "origin/${GITHUB_BASE_REF}" >/dev/null 2>&1; then
        git diff --name-only --diff-filter=ACMR "origin/${GITHUB_BASE_REF}...HEAD"
    elif [[ -n "${GITHUB_BASE_REF:-}" ]]; then
        git fetch origin "${GITHUB_BASE_REF}" --depth=50 2>/dev/null || true
        if git rev-parse "origin/${GITHUB_BASE_REF}" >/dev/null 2>&1; then
            git diff --name-only --diff-filter=ACMR "origin/${GITHUB_BASE_REF}...HEAD"
        else
            git diff --name-only --diff-filter=ACMR HEAD~1 HEAD 2>/dev/null || true
        fi
    elif git rev-parse HEAD~1 >/dev/null 2>&1; then
        git diff --name-only --diff-filter=ACMR HEAD~1 HEAD
    else
        # First commit on branch — check all tracked PHP
        git ls-files '*.php'
    fi
}

CHANGED="$(resolve_changed_files)"
CHANGED_PHP="$(echo "$CHANGED" | grep -E '\.php$' || true)"

if [[ -z "$CHANGED_PHP" ]]; then
    pass "No PHP files changed — skipping file-scoped checks"
else
    echo "Changed PHP files:"
    echo "$CHANGED_PHP" | sed 's/^/  /'
    echo ""
fi

# ---------------------------------------------------------------------------
# 1. PHP syntax (changed files only)
# ---------------------------------------------------------------------------
if [[ -n "$CHANGED_PHP" ]]; then
    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        php -l "$file" >/dev/null
    done <<< "$CHANGED_PHP"
    pass "PHP syntax valid on changed files"
fi

# ---------------------------------------------------------------------------
# 2. No new schema DDL in PHP (manual sql/ only)
# ---------------------------------------------------------------------------
DDL_PATTERN='(ALTER[[:space:]]+TABLE|CREATE[[:space:]]+TABLE|MODIFY[[:space:]]+COLUMN|DROP[[:space:]]+TABLE|TRUNCATE[[:space:]]+TABLE)'

if [[ -n "$CHANGED_PHP" ]]; then
    DDL_HITS=""
    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        [[ "$file" == sql/* ]] && continue
        if grep -qiE "$DDL_PATTERN" "$file"; then
            DDL_HITS+="${file}"$'\n'
        fi
    done <<< "$CHANGED_PHP"

    if [[ -n "$DDL_HITS" ]]; then
        echo -e "${RED}Schema DDL detected in PHP (use sql/ + phpMyAdmin instead):${NC}"
        echo "$DDL_HITS" | sed 's/^/  /'
        fail "Move schema changes to sql/ — do not add runtime DDL in PHP"
    fi
    pass "No new schema DDL in changed PHP files"
fi

# ---------------------------------------------------------------------------
# 3. API endpoints must include session-check (or be explicitly exempt)
# ---------------------------------------------------------------------------
API_SESSION_EXEMPT=(
    "api/auth.php"
    "api/auth_bak.php"
    "api/logout.php"
    "api/cron_delivery_reports.php"
    "api/pa-sync.php"
)

is_exempt_api() {
    local f="$1"
    for ex in "${API_SESSION_EXEMPT[@]}"; do
        [[ "$f" == "$ex" ]] && return 0
    done
    return 1
}

if [[ -n "$CHANGED" ]]; then
    API_HITS="$(echo "$CHANGED" | grep -E '^api/.*\.php$' || true)"
    if [[ -n "$API_HITS" ]]; then
        while IFS= read -r file; do
            [[ -f "$file" ]] || continue
            is_exempt_api "$file" && continue
            if ! grep -q 'session-check\.php' "$file"; then
                fail "$file is missing require for session-check.php"
            fi
        done <<< "$API_HITS"
        pass "Changed API files include session-check (or are exempt)"
    fi
fi

# ---------------------------------------------------------------------------
# 4. Dangerous SQL interpolation in changed PHP (pdo->exec/query with \$var)
# ---------------------------------------------------------------------------
if [[ -n "$CHANGED_PHP" ]]; then
    SQL_RISK=""
    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        # Flag double-quoted exec/query strings containing $variable (not $pdo)
        if grep -nE '\$pdo->(exec|query)\("[^"]*\$[a-zA-Z_]' "$file" >/dev/null 2>&1; then
            SQL_RISK+="${file}"$'\n'
        fi
    done <<< "$CHANGED_PHP"

    if [[ -n "$SQL_RISK" ]]; then
        echo -e "${RED}Possible SQL string interpolation (use prepared statements):${NC}"
        while IFS= read -r f; do
            [[ -z "$f" ]] && continue
            grep -nE '\$pdo->(exec|query)\("[^"]*\$[a-zA-Z_]' "$f" | sed "s/^/  ${f}:/" || true
        done <<< "$SQL_RISK"
        fail "Use PDO prepared statements — do not interpolate variables into SQL strings"
    fi
    pass "No obvious SQL interpolation in changed files"
fi

# ---------------------------------------------------------------------------
# 5. Nested HTML forms in changed PHP templates
# ---------------------------------------------------------------------------
if [[ -n "$CHANGED_PHP" ]]; then
    NESTED=""
    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        if awk '
            BEGIN { depth = 0; bad = 0 }
            tolower($0) ~ /<form/ { depth++; if (depth > 1) bad = 1 }
            tolower($0) ~ /<\/form/ { depth--; if (depth < 0) depth = 0 }
            END { exit bad }
        ' "$file"; then
            :
        else
            NESTED+="${file}"$'\n'
        fi
    done <<< "$CHANGED_PHP"

    if [[ -n "$NESTED" ]]; then
        echo -e "${RED}Nested <form> elements detected:${NC}"
        echo "$NESTED" | sed 's/^/  /'
        fail "HTML forms cannot be nested — move inner forms outside the parent form"
    fi
    pass "No nested forms detected in changed files"
fi

echo ""
pass "All quality checks passed"
