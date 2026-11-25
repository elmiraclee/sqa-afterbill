<?php
/**
 * AfterBill - Account page sections: Two-factor login, Signed-in devices, Download my data.
 * Included by account.php, which handles the forms and sets the variables used here.
 */

$showErrors = function (string $panel) use ($securityErrors, $securityPanel): void {
    if ($securityPanel === $panel && $securityErrors) {
        echo '<div class="alert alert-error"><ul>';
        foreach ($securityErrors as $err) {
            echo '<li>' . htmlspecialchars($err) . '</li>';
        }
        echo '</ul></div>';
    }
};
$updated = $_GET['updated'] ?? '';
?>
<style>
    .sec-status { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 700;
                  padding: 3px 10px; border-radius: 999px; margin-bottom: 14px; }
    .sec-status.on { background: #ECFDF5; color: #047857; }
    .sec-status.off { background: var(--light-gray); color: var(--text-muted); }
    .qr-box { display: flex; gap: 18px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
    .qr-box img { width: 168px; height: 168px; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
    .qr-steps { flex: 1; min-width: 200px; font-size: 0.82rem; color: var(--text-dark); line-height: 1.55; }
    .secret-key { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.85rem; letter-spacing: 1px;
                  background: var(--light-gray); padding: 6px 8px; border-radius: 6px; word-break: normal; overflow-wrap: normal; display: inline-block; margin-top: 4px; }
    .codes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px 18px; margin: 10px 0 6px; padding: 14px 16px;
             background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px;
             font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.92rem; }
    .row-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .btn-secondary-sm { padding: 9px 16px; border: 1.5px solid var(--border); border-radius: 8px; background: #fff;
                        font: inherit; font-size: 0.82rem; font-weight: 600; color: var(--text-dark); cursor: pointer; }
    .btn-secondary-sm:hover { border-color: var(--text-muted); }
    .btn-secondary-sm.red { color: #B91C1C; }
    .btn-secondary-sm.red:hover { border-color: var(--danger); background: #FEF2F2; }
    .inline-fields { display: flex; gap: 10px; flex-wrap: wrap; }
    .inline-fields .form-group { flex: 1; min-width: 160px; }
    .device { display: flex; justify-content: space-between; align-items: center; gap: 12px;
              padding: 12px 0; border-bottom: 1px solid var(--border); }
    .device:last-of-type { border-bottom: none; }
    .device-name { font-size: 0.88rem; font-weight: 600; color: var(--text-dark); }
    .device-meta { font-size: 0.76rem; color: var(--text-muted); margin-top: 2px; }
    .this-device { font-size: 0.72rem; font-weight: 700; color: #047857; background: #ECFDF5; padding: 2px 8px; border-radius: 999px; margin-left: 6px; }
    details.sec-more { margin-top: 16px; border-top: 1px solid var(--border); padding-top: 14px; }
    details.sec-more summary { cursor: pointer; font-size: 0.84rem; font-weight: 600; color: var(--text-dark); margin-bottom: 12px; }
</style>

<!-- ---- Two-factor login ------------------------------------------------------------------ -->
<div class="form-panel" id="two-factor">
    <h1>Two-Factor Login</h1>
    <p>Ask for a 6-digit code from an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy…) every time you log in, so a stolen password isn't enough.</p>

    <?php if ($updated === '2fa_on'): ?>
        <div class="alert alert-success">Two-factor login is on. Save your recovery codes below.</div>
    <?php elseif ($updated === '2fa_off'): ?>
        <div class="alert alert-success">Two-factor login is off.</div>
    <?php elseif ($updated === '2fa_codes'): ?>
        <div class="alert alert-success">New recovery codes created. The old ones no longer work.</div>
    <?php endif; ?>
    <?php if ($recoveryUsedLeft !== null): ?>
        <div class="alert alert-warning">
            You logged in with a recovery code. <?= (int)$recoveryUsedLeft ?> left.
            <?= (int)$recoveryUsedLeft <= 2 ? 'Create new ones below so you don\'t get locked out.' : '' ?>
        </div>
    <?php endif; ?>
    <?php $showErrors('two-factor'); ?>

    <?php if (!$twoFactorReady): ?>
        <div class="alert alert-error">Not set up in this database yet. Run database/migrations/005_more_features.sql.</div>
    <?php elseif ($recoveryCodes): ?>
        <strong style="font-size:0.88rem;">Your recovery codes</strong>
        <div class="codes" aria-label="Recovery codes">
            <?php foreach ($recoveryCodes as $code): ?><span><?= htmlspecialchars($code) ?></span><?php endforeach; ?>
        </div>
        <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:0;">
            Write these down or save them somewhere safe. <strong>They are shown only this once.</strong>
            Each works one time, instead of a code from your phone.
        </p>
    <?php elseif ($twoFactorOn): ?>
        <span class="sec-status on">● On</span>
        <p style="font-size:0.82rem;margin-bottom:0;">
            <?= $recoveryLeft ?> unused recovery code<?= $recoveryLeft === 1 ? '' : 's' ?> left.
        </p>
        <details class="sec-more" <?= $securityPanel === 'two-factor' ? 'open' : '' ?>>
            <summary>Recovery codes or turn off</summary>
            <form method="POST" action="#two-factor" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="2fa_recovery">
                <div class="form-group">
                    <label for="rc_password">Current password</label>
                    <input type="password" id="rc_password" name="current_password" autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn-secondary-sm">Create new recovery codes</button>
            </form>
            <form method="POST" action="#two-factor" novalidate style="margin-top:18px;"
                  data-confirm="Turn off two-factor login? Your password alone will be enough to log in.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="2fa_disable">
                <div class="inline-fields">
                    <div class="form-group">
                        <label for="off_password">Current password</label>
                        <input type="password" id="off_password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label for="off_code">Code from your app</label>
                        <input type="text" id="off_code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" required>
                    </div>
                </div>
                <button type="submit" class="btn-secondary-sm red">Turn off two-factor login</button>
            </form>
        </details>
    <?php elseif ($totpSetup): ?>
        <div class="qr-box">
            <img src="<?= htmlspecialchars(totpQrDataUri(totpUri($totpSetup['secret'], $user['email']))) ?>"
                 alt="QR code to add AfterBill to your authenticator app">
            <div class="qr-steps">
                1. Open your authenticator app and add an account by scanning this code.<br>
                2. Can't scan? Type this key instead:<br>
                <span class="secret-key"><?= htmlspecialchars(totpGroup($totpSetup['secret'])) ?></span><br>
                3. Enter the 6-digit code the app shows, with your password.
            </div>
        </div>
        <form method="POST" action="#two-factor" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="2fa_enable">
            <div class="inline-fields">
                <div class="form-group">
                    <label for="setup_code">6-digit code</label>
                    <input type="text" id="setup_code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                </div>
                <div class="form-group">
                    <label for="setup_password">Current password</label>
                    <input type="password" id="setup_password" name="current_password" autocomplete="current-password" required>
                </div>
            </div>
            <div class="row-actions">
                <button type="submit" class="btn-primary">Turn on</button>
            </div>
        </form>
        <form method="POST" action="#two-factor" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="2fa_cancel">
            <button type="submit" class="btn-secondary-sm">Cancel</button>
        </form>
    <?php else: ?>
        <span class="sec-status off">○ Off</span>
        <form method="POST" action="#two-factor">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="2fa_start">
            <button type="submit" class="btn-primary">Set up two-factor login</button>
        </form>
    <?php endif; ?>
</div>

<!-- ---- Signed-in devices ----------------------------------------------------------------- -->
<div class="form-panel" id="devices">
    <h1>Signed-In Devices</h1>
    <p>Browsers where your account is signed in right now. Sign out any you don't recognise, then change your password.</p>

    <?php if ($updated === 'devices'): ?>
        <?php $n = (int)($_GET['n'] ?? 0); ?>
        <div class="alert alert-success"><?= $n > 0 ? $n . ' device' . ($n === 1 ? '' : 's') . ' signed out.' : 'No other devices were signed in.' ?></div>
    <?php endif; ?>
    <?php $showErrors('devices'); ?>

    <?php if (!$devices): ?>
        <p class="muted" style="font-size:0.82rem;">Device list isn't available yet.</p>
    <?php else: ?>
        <?php foreach ($devices as $d): ?>
            <div class="device">
                <div>
                    <div class="device-name">
                        <?= htmlspecialchars(sessionDeviceLabel($d['user_agent'])) ?>
                        <?php if ($d['current']): ?><span class="this-device">This device</span><?php endif; ?>
                    </div>
                    <div class="device-meta">
                        <?= in_array($d['ip_address'], ['::1', '127.0.0.1'], true) ? 'This computer' : 'IP ' . htmlspecialchars((string)$d['ip_address']) ?>
                        · signed in <?= htmlspecialchars((new DateTime($d['created_at']))->format('M j, g:i A')) ?>
                        · active <?= htmlspecialchars((new DateTime($d['last_seen_at']))->format('g:i A')) ?>
                    </div>
                </div>
                <?php if (!$d['current']): ?>
                    <form method="POST" action="#devices">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="revoke_session">
                        <input type="hidden" name="session_id" value="<?= (int)$d['id'] ?>">
                        <button type="submit" class="btn-secondary-sm red">Sign out</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (count($devices) > 1): ?>
            <form method="POST" action="#devices" style="margin-top:14px;"
                  data-confirm="Sign out every other device? This one stays signed in.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke_others">
                <button type="submit" class="btn-secondary-sm red">Sign out all other devices</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ---- Download my data ------------------------------------------------------------------- -->
<div class="form-panel" id="export">
    <h1>Download My Data</h1>
    <p>A ZIP file with everything in your account: bills, payments, receipts, budgets, custom categories, settings and your activity log, as spreadsheets (CSV) plus the receipt files.</p>
    <?php $showErrors('export'); ?>
    <form method="POST" action="#export" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="export_data">
        <div class="form-group">
            <label for="export_password">Current password</label>
            <input type="password" id="export_password" name="current_password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn-primary">Download ZIP</button>
    </form>
</div>
