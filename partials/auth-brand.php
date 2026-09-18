<?php
/**
 * AfterBill – Left brand panel (shared by login & register)
 *
 * Optional variables you may set BEFORE including this file:
 *   $brandHeadline  (string, may contain <br> and <span>)
 *   $brandSub       (string)
 */
$brandHeadline = $brandHeadline ?? 'Stay on top of<br>your bills, <span>always.</span>';
$brandSub      = $brandSub      ?? 'Track. Organize. Pay. All in one place.';
?>
<div class="auth-brand">
    <div class="brand-top">
        <div class="brand-tagline">Monthly Bill Tracker</div>

        <h1 class="brand-headline"><?= $brandHeadline ?></h1>
        <p class="brand-sub"><?= $brandSub ?></p>
    </div>

    <!-- Icon illustration (pure SVG – no raster image) -->
    <div class="brand-art" aria-hidden="true">
        <svg viewBox="0 0 348 250" xmlns="http://www.w3.org/2000/svg" role="presentation" focusable="false">
            <defs>
                <linearGradient id="abGlow" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#3B82F6" stop-opacity=".45"/>
                    <stop offset="100%" stop-color="#3B82F6" stop-opacity="0"/>
                </linearGradient>
                <linearGradient id="abCard" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#FFFFFF"/>
                    <stop offset="100%" stop-color="#E9EFFB"/>
                </linearGradient>
            </defs>

            <!-- soft backdrop -->
            <circle cx="150" cy="118" r="112" fill="url(#abGlow)"/>

            <!-- calendar -->
            <g class="art-calendar">
                <rect x="26" y="34" width="176" height="176" rx="20" fill="url(#abCard)"/>
                <path d="M26 54a20 20 0 0 1 20-20h136a20 20 0 0 1 20 20v24H26z" fill="#3B82F6"/>
                <rect x="66" y="18" width="12" height="30" rx="6" fill="#E9EFFB"/>
                <rect x="150" y="18" width="12" height="30" rx="6" fill="#E9EFFB"/>

                <!-- date grid -->
                <g fill="#CBD9F2">
                    <rect x="50" y="96" width="24" height="20" rx="6"/>
                    <rect x="86" y="96" width="24" height="20" rx="6"/>
                    <rect x="122" y="96" width="24" height="20" rx="6"/>
                    <rect x="158" y="96" width="20" height="20" rx="6"/>
                    <rect x="50" y="128" width="24" height="20" rx="6"/>
                    <rect x="122" y="128" width="24" height="20" rx="6"/>
                    <rect x="158" y="128" width="20" height="20" rx="6"/>
                    <rect x="50" y="160" width="24" height="20" rx="6"/>
                    <rect x="86" y="160" width="24" height="20" rx="6"/>
                    <rect x="122" y="160" width="24" height="20" rx="6"/>
                    <rect x="158" y="160" width="20" height="20" rx="6"/>
                </g>
                <!-- highlighted due date -->
                <rect x="86" y="128" width="24" height="20" rx="6" fill="#3B82F6"/>
            </g>

            <!-- bill / receipt -->
            <g class="art-receipt" transform="translate(174 102) rotate(-7)">
                <path d="M0 16A16 16 0 0 1 16 0h116a16 16 0 0 1 16 16v100l-14.8 8-14.8-8-14.8 8-14.8-8-14.8 8-14.8-8-14.8 8-14.8-8L0 124z"
                      fill="#FFFFFF"/>
                <rect x="20" y="24" width="62" height="11" rx="5.5" fill="#17233C"/>
                <rect x="20" y="43" width="40" height="8" rx="4" fill="#C7D5EE"/>

                <g>
                    <circle cx="26" cy="72" r="8" fill="#3B82F6"/>
                    <path d="M22.5 72l2.6 2.7 4.6-5" fill="none" stroke="#FFFFFF" stroke-width="2.2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                    <rect x="42" y="67" width="86" height="10" rx="5" fill="#E4EBF8"/>
                </g>
                <g>
                    <circle cx="26" cy="96" r="8" fill="#3B82F6"/>
                    <path d="M22.5 96l2.6 2.7 4.6-5" fill="none" stroke="#FFFFFF" stroke-width="2.2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                    <rect x="42" y="91" width="64" height="10" rx="5" fill="#E4EBF8"/>
                </g>
            </g>

            <!-- paid badge -->
            <g class="art-badge">
                <circle cx="292" cy="62" r="28" fill="#3B82F6"/>
                <circle cx="292" cy="62" r="28" fill="none" stroke="#FFFFFF" stroke-opacity=".35" stroke-width="2"/>
                <path d="M281 62.5l7.5 7.5L304 55" fill="none" stroke="#FFFFFF" stroke-width="4.5"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </g>

            <!-- floating accents -->
            <circle class="art-dot art-dot-a" cx="34" cy="226" r="6" fill="#3B82F6"/>
            <circle class="art-dot art-dot-b" cx="318" cy="168" r="9" fill="#FFFFFF" fill-opacity=".28"/>
            <circle class="art-dot art-dot-c" cx="246" cy="22" r="5" fill="#FFFFFF" fill-opacity=".45"/>
        </svg>
    </div>

    <ul class="brand-features">
        <li class="feature-item">
            <span class="feature-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                </svg>
            </span>
            Due dates in one calendar
        </li>
        <li class="feature-item">
            <span class="feature-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>
            </span>
            Reminders before you're late
        </li>
        <li class="feature-item">
            <span class="feature-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
            </span>
            See where your money goes
        </li>
    </ul>
</div>