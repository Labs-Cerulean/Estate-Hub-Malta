#!/usr/bin/env bash
# Estate Hub Malta — CI quality gates (run locally or in GitHub Actions)
#
# HEURISTIC CHECKS — not a substitute for code review or security-review.
# Known limitations (see also docs/DEFINITION_OF_DONE.md §3):
#   - SQL interpolation: line-oriented; misses multi-line strings, concat ("..." . $var),
#     and queries built in $sql then passed to exec/query.
#   - DDL in PHP: may false-positive on keywords in // comments or string literals.
#   - Nested forms: tag-count heuristic; may false-positive on <form in comments/strings.
#
# Local runs: set QUALITY_BASE_REF=staging (default) to diff all commits on your branch
# vs merge-base with origin/staging. GITHUB_BASE_REF is set automatically in Actions.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

fail() { echo -e "${RED}FAIL:${NC} $1" >&2; exit 1; }
pass() { echo -e "${GREEN}OK:${NC} $1"; }
warn() { echo -e "${YELLOW}NOTE:${NC} $1"; }

# ---------------------------------------------------------------------------
# Determine which files changed (PR diff vs branch merge-base vs last commit)
# ---------------------------------------------------------------------------
resolve_changed_files() {
    local base="${GITHUB_BASE_REF:-${QUALITY_BASE_REF:-staging}}"

    if [[ -n "${GITHUB_BASE_REF:-}" ]] || [[ -n "${QUALITY_BASE_REF:-}" ]] || git rev-parse "origin/${base}" >/dev/null 2>&1; then
        git fetch origin "${base}" --depth=80 2>/dev/null || true
    fi

    if git rev-parse "origin/${base}" >/dev/null 2>&1; then
        local merge_base
        merge_base="$(git merge-base HEAD "origin/${base}" 2>/dev/null || true)"
        if [[ -n "$merge_base" ]]; then
            git diff --name-only --diff-filter=ACMR "${merge_base}...HEAD"
            return
        fi
        git diff --name-only --diff-filter=ACMR "origin/${base}...HEAD"
        return
    fi

    if git rev-parse HEAD~1 >/dev/null 2>&1; then
        warn "Could not find origin/${base} — checking only the last commit (HEAD~1..HEAD)"
        git diff --name-only --diff-filter=ACMR HEAD~1 HEAD
        return
    fi

    git ls-files '*.php'
}

CHANGED="$(resolve_changed_files)"
CHANGED_PHP="$(echo "$CHANGED" | grep -E '\.php$' || true)"

if [[ -z "$CHANGED_PHP" ]]; then
    pass "No PHP files changed — skipping file-scoped checks"
else
    echo "Changed PHP files (vs merge-base / base ref):"
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
# Heuristic: ignores whole lines that are // comments; may still match keywords in strings.
# ---------------------------------------------------------------------------
DDL_PATTERN='(ALTER[[:space:]]+TABLE|CREATE[[:space:]]+TABLE|MODIFY[[:space:]]+COLUMN|DROP[[:space:]]+TABLE|TRUNCATE[[:space:]]+TABLE)'

file_has_ddl() {
    local file="$1"
    grep -viE '^\s*//' "$file" | grep -qiE "$DDL_PATTERN"
}

if [[ -n "$CHANGED_PHP" ]]; then
    DDL_HITS=""
    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        [[ "$file" == sql/* ]] && continue
        if file_has_ddl "$file"; then
            DDL_HITS+="${file}"$'\n'
        fi
    done <<< "$CHANGED_PHP"

    if [[ -n "$DDL_HITS" ]]; then
        echo -e "${RED}Schema DDL detected in PHP (use sql/ + phpMyAdmin instead):${NC}"
        echo "$DDL_HITS" | sed 's/^/  /'
        fail "Move schema changes to sql/ — do not add runtime DDL in PHP"
    fi
    pass "No new schema DDL in changed PHP files (heuristic)"
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
# 4. Dangerous SQL interpolation (heuristic — see header limitations)
# ---------------------------------------------------------------------------
SQL_INTERP_PATTERNS=(
    '\$pdo->(exec|query)\("[^"]*\$[a-zA-Z_]'
    '\$pdo->(exec|query)\([^)]*\.[[:space:]]*\$[a-zA-Z_]'
    '\$sql[[:space:]]*=[[:space:]]*"[^"]*\$[a-zA-Z_]'
)

file_has_sql_risk() {
    local file="$1"
    local pat
    for pat in "${SQL_INTERP_PATTERNS[@]}"; do
        if grep -nE "$pat" "$file" >/dev/null 2>&1; then
            return 0
        fi
    done
    return 1
}

if [[ -n "$CHANGED_PHP" ]]; then
    SQL_RISK=""
    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        if file_has_sql_risk "$file"; then
            SQL_RISK+="${file}"$'\n'
        fi
    done <<< "$CHANGED_PHP"

    if [[ -n "$SQL_RISK" ]]; then
        echo -e "${RED}Possible SQL string interpolation (use prepared statements):${NC}"
        while IFS= read -r f; do
            [[ -z "$f" ]] && continue
            for pat in "${SQL_INTERP_PATTERNS[@]}"; do
                grep -nE "$pat" "$f" 2>/dev/null | sed "s/^/  ${f}:/" || true
            done
        done <<< "$SQL_RISK"
        fail "Use PDO prepared statements — do not interpolate variables into SQL strings"
    fi
    pass "No obvious SQL interpolation in changed files (heuristic)"
fi

# ---------------------------------------------------------------------------
# 5. Nested HTML forms (heuristic — process </form> before <form> per line)
# ---------------------------------------------------------------------------
file_has_nested_forms() {
    local file="$1"
    awk '
        BEGIN { depth = 0; bad = 0 }
        {
            line = tolower($0)
            while (match(line, /<\/form/)) {
                depth--
                if (depth < 0) depth = 0
                line = substr(line, RSTART + RLENGTH)
            }
            while (match(line, /<form/)) {
                depth++
                if (depth > 1) bad = 1
                line = substr(line, RSTART + RLENGTH)
            }
        }
        END { exit bad ? 1 : 0 }
    ' "$file"
}

if [[ -n "$CHANGED_PHP" ]]; then
    NESTED=""
    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        if ! file_has_nested_forms "$file"; then
            NESTED+="${file}"$'\n'
        fi
    done <<< "$CHANGED_PHP"

    if [[ -n "$NESTED" ]]; then
        echo -e "${RED}Nested <form> elements detected (heuristic):${NC}"
        echo "$NESTED" | sed 's/^/  /'
        fail "HTML forms cannot be nested — move inner forms outside the parent form"
    fi
    pass "No nested forms detected in changed files (heuristic)"
fi

echo ""
pass "All quality checks passed"
