# Graph Mail

Send Nextcloud system email through a Microsoft 365 mailbox using Microsoft Graph. File shares, password resets, notifications and other apps using the central mailer follow this route. The Mail app's separate user accounts are unaffected.

## Requirements

- Nextcloud 35, PHP 8.3+, Linux, and a working background-job runner.
- Writable system configuration and the same runtime user for web, cron and CLI PHP.
- Enabled exec/proc_open, a CLI PHP interpreter, and timeout with kill-after (-k) support.
- An executable app path without whitespace or shell metacharacters.
- No other sendmail executable taking precedence in PATH or configured search directories.
- A Microsoft 365 work or school tenant, Entra application and system sender mailbox.

Every web and cron node must have the app at the same path before routing is enabled. Unsupported hosts keep their previous mail route. External app directories need GRAPH_MAIL_NC_ROOT and, when necessary, GRAPH_MAIL_PHP_BIN in the web and cron environments.

## Microsoft 365 authorization

Create a dedicated single-tenant Entra application and credential. Grant Application Mail.Send for the sender mailbox through [Exchange Application RBAC](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac). Remove overlapping tenant-wide grants; they remain effective alongside a scoped assignment. Mail.Read, Mail.ReadWrite and User.Read are not required.

Token acquisition does not prove mailbox permission. Verify the grant with your Exchange administrator, including a negative authorization check for another mailbox.

## Setup

Install under the directory name graph_mail, enable the app, and open Administration settings > Graph Mail. Enter the tenant ID, client ID, client secret and sender mailbox, then save. Message content will be sent to Microsoft 365.

Saving checks credentials and requests activation automatically. Your previous transport stays selected while helper lookup caches expire. With normal cron scheduling, activation takes approximately five to ten minutes. Wait for Active before sending a test. If Checking persists, check background jobs and local diagnostics.

System messages use the configured mailbox as From; existing Reply-To addresses are preserved. Exchange controls the sender display name. Blank secret input keeps the stored value. Replacement credentials are checked before saving. The secret is encrypted with the instance secret and omitted from the UI and diagnostics. Back up both the instance secret and encrypted app configuration.

```sh
php occ graph_mail:configure --stdin --secret-file /run/secrets/graph-mail < sender.json
php occ graph_mail:status --handshake
php occ graph_mail:test-send --to admin@example.com
php occ graph_mail:deactivate
```

JSON fields are tenant_id, client_id and mailbox. Supply the secret through the protected file or stdin JSON, never as an argument. configure --no-activate saves and keeps Graph routing off. A test message requires an explicit request and active Graph routing.

Disabling restores settings the app still owns and retains credentials for re-enable. Manual deactivation stays in effect until you activate or save again. See [operator recovery](docs/operator-recovery.md) for conflicts and filesystem removal.

## Delivery and privacy

Microsoft's 202 response means accepted for submission, not confirmed delivery. MIME bodies, attachments, inline images, threading and hidden recipients are retained within the supported message profile. The whole MIME message cap is 3,000,000 bytes; the base64 request cap is 4,000,000 bytes, with at most 100 recipients per message. Use a Nextcloud share link for larger files.

Uncertain sends are not replayed or sent through an SMTP fallback. Check Microsoft 365 before resending after an unknown outcome. Rate-limit handling is bounded; longer waits become temporary failures. There is no durable outgoing queue, so the originating Nextcloud feature determines subsequent failure handling.

S/MIME and header-level signatures need separate compatibility validation. Provider limits and Microsoft 365 mail policies still apply. Use this transport for transactional system mail rather than bulk campaigns.

The ledger retains content-free outcome metadata for 30 days: no message body, subject, recipient list, client secret or bearer token. Logs use sanitized reason codes. There is no vendor telemetry or hosted relay.

## Build from source

Build an unsigned application archive with:

```sh
python3 tools/package.py
```

The archive is written to `dist/`. Build tooling, tests and development dependencies are excluded from the application package.

## License

AGPL-3.0-or-later. See [LICENSE](LICENSE).
