# Operator recovery

Graph Mail changes mail_smtpmode, mail_sendmailmode and binary_search_paths. It does not change SMTP hosts or other providers' credentials. Its presence-aware routing journal is stored in app configuration.

## Inspect first

```sh
php occ graph_mail:status --handshake
php occ config:system:get mail_smtpmode
php occ config:system:get mail_sendmailmode
php occ config:system:get binary_search_paths
```

Checking means activation is pending. The previous transport remains selected while old helper lookup caches expire. Normal cron completes activation after the waiting period; no message is sent as part of these checks. The scheduled job also handles a configured app after re-enable. Manual deactivation stays off until you explicitly save or activate again.

## Restore the previous route

```sh
php occ graph_mail:deactivate
php occ app:disable graph_mail
```

Both restore only values Graph Mail still owns. The app preserves search paths added by other software and any helper path that existed before activation. It keeps the encrypted sender configuration for re-enable.

A conflict does not discard the rollback journal. Owned values are restored where possible; values changed elsewhere are left untouched. Inspect and resolve those changes before activating again. A subsequent activation must not overwrite an unresolved recovery snapshot.

## Manual recovery

If the app was removed directly from the filesystem, its repair step cannot run. Inspect the current configuration and your original routing values or backup before changing anything. Do not remove a system sendmail executable to make this app win selection; use a supported configuration or another host.

For a previous SMTP route, restore its original values, for example:

```sh
php occ config:system:set mail_smtpmode --value=smtp
php occ config:system:set mail_sendmailmode --value=smtp
```

If a key was absent originally, use config:system:delete instead of inventing a value. Remove only the app's bin directory from binary_search_paths; retain unrelated directories and additions. Restore an absent search-path key only if the original key was absent and no other application has added entries. Do not delete the whole list as a generic recovery step.

After manual recovery, run graph_mail:deactivate again if the app files are available. If all current values match the recorded original values, cleanup completes. Otherwise the retained journal remains available to an administrator; do not erase it to force activation.

## Sender credentials

Use Administration settings > Graph Mail or graph_mail:configure --stdin --secret-file to rotate a client secret. The replacement is checked before it replaces the working sender. An empty UI secret field preserves the saved value.

To forget the saved secret, deactivate the route first and use graph_mail:configure --clear-secret. Revoke unused credentials in Entra separately. Keep the Nextcloud instance secret, app configuration and routing journal in your backup: ciphertext alone cannot restore the sender.

## Multiple nodes

Install the same application version and executable helper at the same path on every web and cron node before enabling routing. Use shared, working Nextcloud locking and distributed caching. Coordinate updates; cache waiting cannot compensate for a node that lacks the app or uses a different PHP/PATH environment.
