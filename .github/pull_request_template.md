## Summary

<!-- What changed and why (1–3 sentences) -->

## Hub / area

<!-- Plant Hub | PM | Sales Hub | System / cross-cutting -->

- [ ] Plant Hub
- [ ] Project Management
- [ ] Sales Hub
- [ ] System / admin / infra

## Security & access (required)

- [ ] New/changed protected pages include `init.php` + `session-check.php`
- [ ] New/changed API files include `session-check.php` (or are documented public/cron exempt)
- [ ] Permissions checked **server-side** (not only hidden UI buttons)
- [ ] IDs from URL/POST verified against user access (client / project / quote type)
- [ ] No raw user input in SQL, shell, or unescaped HTML/JS output
- [ ] No secrets, credentials, or verbose SQL/stack traces in the diff

## Schema & data

- [ ] No `ALTER TABLE` / `CREATE TABLE` / ENUM changes in PHP — DDL is in `sql/` for manual phpMyAdmin run
- [ ] Multi-step DB writes use transactions where appropriate
- [ ] Plant ERP / `pushBookingToERP()` untouched (unless explicitly approved)

## Markup & UX

- [ ] Table header column count matches body rows
- [ ] No nested `<form>` elements
- [ ] Destructive actions have confirmation where the app already uses that pattern

## Testing

<!-- What was smoke-tested on staging -->

- [ ] Smoke-tested on staging (describe below)
- [ ] CI quality workflow green

**Smoke test notes:**

## Screenshots / demo

<!-- Optional -->
