<?php
/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$status = $_['status'];
$preflightProblems = array_values(array_filter(
    $_['preflight'],
    static fn (array $check): bool => $check['severity'] !== 'ok',
));
?>
<div class="section" id="graph_mail">
    <h2><?php p($l->t('Graph Mail')); ?></h2>
    <p class="settings-hint">
        <?php p($l->t('Sends Nextcloud system mail through Microsoft 365 using Microsoft Graph. Once active, Graph Mail sends file shares, password resets and system notifications from your Microsoft 365 mailbox. Message content is sent to Microsoft.')); ?>
    </p>

    <?php foreach ($_['notices'] as $notice): ?>
        <div class="msg <?php p($notice['type']); ?>"><?php p($notice['text']); ?></div>
    <?php endforeach; ?>

    <p>
        <strong><?php p($l->t('Status:')); ?></strong>
        <span class="graph-mail-status graph-mail-status--<?php p($status['state']); ?>"><?php p($status['label']); ?></span>
        <span class="settings-hint"><?php p($l->t('Checking keeps your previous mail route in use until activation can finish.')); ?></span>
    </p>

    <?php if ($preflightProblems !== []): ?>
        <h3><?php p($l->t('Local checks')); ?></h3>
        <ul class="graph-mail-preflight">
            <?php foreach ($preflightProblems as $check): ?>
                <li class="graph-mail-preflight--<?php p($check['severity']); ?>">
                    <code><?php p($check['id']); ?></code>
                    <?php if ($check['detail'] !== ''): ?>
                        — <?php p($check['detail']); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h3><?php p($l->t('Microsoft 365 sender')); ?></h3>
    <form method="post" action="<?php p($_['urls']['save']); ?>">
        <input type="hidden" name="requesttoken" value="<?php p($_['csrf']); ?>">

        <p>
            <label for="graph_mail_tenant"><?php p($l->t('Directory (tenant) ID')); ?></label><br>
            <input id="graph_mail_tenant" name="tenant_id" type="text" size="40" maxlength="254"
                   value="<?php p($_['settings']['tenant_id']); ?>" autocomplete="off" required>
        </p>
        <p>
            <label for="graph_mail_client"><?php p($l->t('Application (client) ID')); ?></label><br>
            <input id="graph_mail_client" name="client_id" type="text" size="40" maxlength="36"
                   value="<?php p($_['settings']['client_id']); ?>" autocomplete="off" required>
        </p>
        <p>
            <label for="graph_mail_mailbox"><?php p($l->t('Sender mailbox')); ?></label><br>
            <input id="graph_mail_mailbox" name="mailbox" type="email" size="40" maxlength="320"
                   value="<?php p($_['settings']['mailbox']); ?>" autocomplete="off" required>
            <br>
            <span class="settings-hint"><?php p($l->t('System messages use this mailbox. Reply-To addresses are preserved; Exchange controls the sender display name.')); ?></span>
        </p>
        <p>
            <label for="graph_mail_secret"><?php p($l->t('Client secret')); ?></label><br>
            <input id="graph_mail_secret" name="secret" type="password" size="40" maxlength="2048"
                   value="" autocomplete="new-password"
                   placeholder="<?php p($_['settings']['has_secret'] ? $l->t('Stored — leave blank to keep it') : $l->t('Not stored yet')); ?>">
        </p>

        <p>
            <button type="submit" class="primary"><?php p($l->t('Save and activate')); ?></button>
        </p>
    </form>

    <h3><?php p($l->t('Actions')); ?></h3>
    <form method="post" action="<?php p($_['urls']['check']); ?>" style="display:inline">
        <input type="hidden" name="requesttoken" value="<?php p($_['csrf']); ?>">
        <button type="submit"><?php p($l->t('Check credentials')); ?></button>
    </form>
    <form method="post" action="<?php p($_['urls']['deactivate']); ?>" style="display:inline">
        <input type="hidden" name="requesttoken" value="<?php p($_['csrf']); ?>">
        <button type="submit"><?php p($l->t('Deactivate Graph routing')); ?></button>
    </form>

    <form method="post" action="<?php p($_['urls']['test']); ?>">
        <input type="hidden" name="requesttoken" value="<?php p($_['csrf']); ?>">
        <p>
            <label for="graph_mail_test_recipient"><?php p($l->t('Test recipient')); ?></label><br>
            <input id="graph_mail_test_recipient" name="recipient" type="email" size="40" maxlength="320" required>
            <button type="submit"><?php p($l->t('Send one test message')); ?></button>
        </p>
        <p class="settings-hint">
            <?php p($l->t('The test is sent once, to this recipient only. It is not repeated automatically, and Microsoft acceptance does not prove inbox delivery.')); ?>
        </p>
    </form>

    <h3><?php p($l->t('Recovery')); ?></h3>
    <p class="settings-hint">
        <?php p($l->t('Disabling the app restores mail settings it still owns. Changes made elsewhere are preserved. Follow the included operator recovery instructions if you need manual recovery.')); ?>
    </p>
</div>
