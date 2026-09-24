<?php
/**
 * AfterBill - Renders flash messages set via flash_set().
 */
$__flashes = flash_get_all();
if (!empty($__flashes)):
?>
<div class="page-alerts">
    <?php foreach (($__flashes['success'] ?? []) as $msg): ?>
        <div class="page-alert success">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <?= e($msg) ?>
        </div>
    <?php endforeach; ?>
    <?php foreach (($__flashes['error'] ?? []) as $msg): ?>
        <div class="page-alert error">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;flex-shrink:0;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
            <?= e($msg) ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
