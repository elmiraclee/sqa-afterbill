<?php
/**
 * AfterBill - Topbar.
 * Expects $pageTitle and $pageSubtitle set by the caller.
 */
?>
<header class="topbar">
    <div class="topbar-left" style="display:flex; align-items:center; gap:12px;">
        <button class="menu-toggle" type="button" aria-label="Toggle menu">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>
        <div>
            <h2><?= e($pageTitle ?? '') ?></h2>
            <p><?= e($pageSubtitle ?? '') ?></p>
        </div>
    </div>
    <div class="topbar-right">
        <a href="auth/logout.php" class="btn-logout">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
            </svg>
            Logout
        </a>
    </div>
</header>
