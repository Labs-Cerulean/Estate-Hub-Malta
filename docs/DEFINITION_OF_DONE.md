# Definition of Done — Estate Hub Malta

Use this checklist before merging to **staging**, and again before **staging → main**.  
Goal: catch security, schema, and markup issues **before** GitHub review tools (e.g. Gemini Code Assist), not after.

---

## 1. When does this apply?

| Track | Examples | Required checks |
|-------|----------|-----------------|
| **A — Low risk** | CSS tweaks, copy, read-only display | Syntax + quick visual check |
| **B — Sensitive** | Auth, permissions, APIs, SQL, uploads, ERP, user save, bulk actions | **Full checklist below** |

If in doubt, treat it as **Track B**.

---

## 2. Pre-commit (developer or Cursor agent)

### Understand first
1. Read the files you will touch; trace DB calls and permission checks.
2. Identify the Hub: **Plant** | **PM** | **Sales**.
3. State threat model + controls in the PR or agent reply (IDOR, XSS, cap escalation, data leak).

### Code rules (non-negotiable)
- **SQL:** PDO prepared statements only — no variable interpolation in query strings.
- **Schema:** DDL lives in `sql/` for manual phpMyAdmin — **never** new `ALTER`/`CREATE` in PHP.
- **Auth:** Protected pages/APIs → `init.php` + `session-check.php` at the top.
- **IDOR:** Verify `project_id`, `client_id`, `quote_id`, etc. against user access — casting to `int` is not enough.
- **XSS:** Escape HTML output (`htmlspecialchars` / `safe_html()`); use `json_encode` with hex flags in JS.
- **Hub boundaries:** Plant / PM / Sales permissions are separate — never assume cross-hub access.
- **Plant ERP:** Do not modify `pushBookingToERP()` or J2 JSON payload without explicit approval.
- **Timezone:** `Europe/Malta` for date comparisons and cron.
- **Uploads:** `S3FileManager.php` only.

### Markup & UX
- `<thead>` column count must match `<tbody>` cells per row.
- **Never** nest `<form>` inside `<form>`.
- AJAX/API errors → JSON + proper status (403/400), not HTML redirects.
- Avoid N+1: no DB query inside a loop over records when a batch query is possible.

---

## 3. Automated checks (CI + local)

### On every PR to `staging` or `main`

GitHub Actions workflow **Quality** (`.github/workflows/quality.yml`) runs:

| Check | What it catches |
|-------|-----------------|
| PHP syntax | Parse errors in changed `.php` files |
| No DDL in PHP | New `ALTER`/`CREATE`/`TRUNCATE` in changed PHP (use `sql/`) |
| API session-check | Changed `api/*.php` missing auth (with known exempt list) |
| SQL interpolation | `$pdo->exec("...$var...")` in changed files |
| Nested forms | Two `<form>` open before `</form>` in changed templates |

### Run locally before push

```bash
bash scripts/ci/quality_checks.sh
```

Requires PHP 8+ in PATH. On a feature branch, compares against `HEAD~1`; set `GITHUB_BASE_REF=staging` to simulate a PR.

---

## 4. Cursor agent workflow (before opening PR)

1. Implement on `cursor/<name>-cfe5` branch.
2. Run **security-review** subagent on branch diff (sensitive changes).
3. Run **Bugbot** subagent on branch diff.
4. Run `bash scripts/ci/quality_checks.sh`.
5. Fix all findings, then commit and push.
6. Open PR with template checkboxes completed.

**End-of-session prompt (paste into Cursor):**

> Review the full diff against `docs/DEFINITION_OF_DONE.md` and `.cursorrules`. List violations: IDOR, missing session-check, SQL interpolation, unescaped output, DDL in PHP, nested forms, table column mismatch. Fix before commit.

---

## 5. Staging merge gate

Do **not** merge to `staging` unless:

- [ ] PR template checkboxes completed
- [ ] CI **Quality** workflow green
- [ ] At least one **smoke test** in the affected Hub (see below)
- [ ] For Track B: second pair of eyes (human or agent review) on the diff

### Hub smoke tests (pick what you touched)

| Hub | Quick test |
|-----|------------|
| **Plant** | Open bookings, click a booking, calendar loads; non-plant user gets 403 on plant API |
| **PM** | Projects list/filter; project detail access for assigned vs unassigned user |
| **Sales** | Quote create/edit/print; OHSA add-from-catalogue; permissions per quote type |
| **Admin** | System Users save permissions; capability columns persist correctly |

---

## 6. Production merge gate (staging → main)

- [ ] Staging has been stable (smoke tests pass)
- [ ] No pending manual SQL in `sql/` that production still needs
- [ ] Rollback commit SHA noted (previous `main` tip)
- [ ] Team informed if behaviour visible to users changes (nav, quotes, plant)

---

## 7. API endpoint exempt list (CI)

These `api/` files intentionally skip `session-check.php`:

| File | Reason |
|------|--------|
| `api/auth.php` | Public login |
| `api/logout.php` | Session destroy only |
| `api/cron_delivery_reports.php` | Cron / token auth |
| `api/pa-sync.php` | External API key auth |
| `api/auth_bak.php` | Legacy backup auth (avoid new changes) |

New API endpoints are **not** exempt unless explicitly documented here and in the PR.

---

## 8. New API endpoint template

```php
<?php
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../session-check.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$allowed = ['some_action'];
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

if (!hasPermission('required_capability') && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// PDO prepared statements only; verify record IDs against user access
```

---

## 9. Related files

- `.cursorrules` — agent rules (security, hubs, ERP, schema policy)
- `.github/pull_request_template.md` — PR checklist
- `scripts/ci/quality_checks.sh` — local/CI script
- `.github/workflows/quality.yml` — GitHub Actions workflow
