<?php
/**
 * AfterBill - Header logout button
 *
 * Logout is a POST with a CSRF token (see auth/logout.php), so another site cannot sign the
 * user out with a link or an <img> tag. Styled by each page's existing .btn-logout rules.
 */

require_once __DIR__ . '/../includes/csrf.php';
?>
<form method="POST" action="auth/logout.php" style="margin:0;display:flex;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-logout" style="cursor:pointer;font-family:inherit;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                        </svg>
                        Logout
                    </button>
                </form>
