# Security guide

How the plugin protects the confirmation flow, how the automated scans work, and what to do when something fails.

## What the plugin protects

Registration sends a confirmation link. The link must prove that the person owns the mailbox, so the link token is the only credential. Everything below exists to keep that token safe.

| Mechanism | Detail |
|---|---|
| Token | 32 random bytes from `random_bytes()`, hex encoded (64 chars). |
| Storage | Only `sha256(token)` is stored in user meta `confirm_email_key_hash`. A database leak gives no usable links. |
| Validation | Format check, then `hash_equals()` (constant time). An empty or missing key never matches. |
| Expiry | `confirm_email_expires` meta, default 48 h. Override with `define('LD_EMAIL_CONFIRMATION_TTL_HOURS', 24);` in `wp-config.php`. |
| Single use | Hash and expiry are deleted on success, so a leaked link cannot be replayed. |
| Login gate | Filter `wp_authenticate_user` refuses login while `has_confirmed_email` is `no`. Users without the meta (created before the plugin) are not blocked. |
| Trusted creation | Users created in wp-admin, over REST, WP-CLI, AJAX or cron are marked confirmed and get no email, and the plugin does not redirect those requests. |
| Admin confirm | Capability `edit_user` plus nonce via `check_admin_referer()`. |
| Output | Everything echoed or put in the email is escaped (`esc_html`, `esc_url`, `esc_attr`, `wp_json_encode`). |
| Redirects | `wp_safe_redirect()` only. |
| Notices | A whitelisted `?ld_notice=` query value, not global transients (those were shown to the next random visitor). |

## Vulnerabilities fixed in 1.1.0

1. **Authentication bypass (critical).** `?action=confirm_email&user=1` with no key compared `''` to the empty meta of any user that had no token (every pre-existing user, including admins) and then logged the visitor in as that user. Now the key must be a 64-char hex token matching a stored hash.
2. Tokens never expired and were reusable forever, and were stored in plaintext.
3. Unconfirmed users could still log in, contrary to the README. Now blocked.
4. `user_register` redirected and called `exit` for every registration source, which broke admin user creation, REST, imports and WP-CLI.
5. Unescaped `user_login` and site name in the HTML email, and unvalidated `From` header input.
6. Password from `$_POST` was passed through `sanitize_text_field()`, silently altering passwords that contain special characters.
7. Confirmation notices were global transients visible to whichever visitor loaded a page next.
8. Admin action used `$_GET['_wpnonce']` unchecked for existence and `wp_redirect()`.

## Automated checks

All run from `.github/workflows/security.yml` on every push, pull request, weekly (Monday) and on demand.

- **PHPCS + WordPress Coding Standards**: security sniffs only (output escaping, nonces, input sanitising, safe redirects, SQL). Fails on any error.
- **Semgrep**: `p/php`, `p/security-audit`, `p/github-actions` rules.
- **Gitleaks**: scans full git history for committed secrets.
- **PHP lint**: syntax check on PHP 7.4, 8.0, 8.2 and 8.3.
- **Dependabot** (`.github/dependabot.yml`): weekly pull requests to bump the pinned GitHub Actions.

All actions are pinned to commit SHAs. Workflow permissions are `contents: read`.

CodeQL is not used because it has no PHP support.

### Run the PHPCS check locally

```
composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer global require wp-coding-standards/wpcs:"^3.0"
phpcs --standard=WordPress --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.SafeRedirect --extensions=php .
```

## Troubleshooting

- **Gitleaks fails on a personal-account repo**: check the run log; organisation-owned repos need a `GITLEAKS_LICENSE` secret.
- **PHPCS flags a line you know is safe**: add `// phpcs:ignore <Sniff.Name> -- reason`. Never disable a sniff globally.
- **A user says the link is invalid**: it expired (48 h) or was already used. Use *Users -> Confirm Account* in wp-admin.
- **Users registered under 1.0.x with a pending link**: legacy plaintext keys are no longer accepted. Confirm them manually or ask them to re-register.
- **Page-cache plugins**: the `?ld_notice=` notice is rendered in `wp_footer`; exclude those query args from full-page cache if notices do not show.

## Rollback

`git revert` the 1.1.0 commit. Note this restores the authentication bypass, so do not run the old version on a live site.

## Not done / next

- No resend-confirmation feature and no rate limiting on the confirm endpoint (a 256-bit token makes guessing infeasible; rate limiting would only reduce log noise).
- Auto-login after confirmation is kept. It is as strong as a password-reset link, but a stricter option is to redirect to the login page instead.
- Report vulnerabilities via `SECURITY.md`. Also enable in repo settings: Private vulnerability reporting, Secret scanning with push protection, Dependabot alerts (not settable from code).
