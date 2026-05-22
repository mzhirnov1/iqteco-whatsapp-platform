<?php
use Iqteco\WaAdmin\Services\View;

$operatorEmail = $user['email'] ?? '';
?>
<link rel="stylesheet" href="/assets/web_chat.css">
<?php
$jsPath  = __DIR__ . '/../../public/assets/support_chat.js';
$cssPath = __DIR__ . '/../../public/assets/support_chat.css';
$jsVer   = is_file($jsPath)  ? (string) filemtime($jsPath)  : '1';
$cssVer  = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
?>
<link rel="stylesheet" href="/assets/support_chat.css?v=<?= View::e($cssVer) ?>">

<?php if (empty($configured)): ?>
    <div class="support-misconfigured">
        <h2>Support chat is not configured</h2>
        <p>Set <code>SUPPORT_SHARED_SECRET</code> and <code>LEGACY_SUPPORT_BASE_URL</code> in <code>admin/.env</code>, and add the same shared secret to <code>/var/www/wa.iqteco.com/config.php</code> on the legacy server (key <code>support_shared_secret</code>).</p>
    </div>
<?php else: ?>

<div class="wweb-root support-root" data-operator-email="<?= View::e($operatorEmail) ?>">
    <aside class="wweb-sidebar">
        <header class="wweb-side-header">
            <div class="wweb-profile">
                <div class="wweb-avatar wweb-avatar-self">S</div>
                <div class="wweb-profile-info">
                    <div class="wweb-profile-name">Support inbox</div>
                    <div class="wweb-profile-state" id="support-poll-status">connecting…</div>
                </div>
                <button class="wweb-icon-btn" id="support-refresh" title="Refresh">⟳</button>
            </div>
            <input type="search" class="wweb-search" id="support-search" placeholder="Search portals…">
        </header>
        <ul id="support-chats" class="wweb-chats">
            <li class="wweb-chats-empty">Loading…</li>
        </ul>
        <footer class="wweb-side-footer">
            <a href="/dashboard">← Back to dashboard</a>
        </footer>
    </aside>

    <main class="wweb-main">
        <div class="wweb-empty" id="support-empty">
            <div class="wweb-empty-icon">💬</div>
            <p>Select a portal on the left to read the conversation.</p>
        </div>

        <section class="wweb-chat" id="support-chat" hidden>
            <header class="wweb-chat-header support-chat-header">
                <div class="wweb-avatar" id="support-chat-avatar">P</div>
                <div class="support-info-card">
                    <div class="support-info-row">
                        <span class="support-info-domain" id="support-domain">—</span>
                        <span class="support-info-sep">·</span>
                        <span id="support-instance">Instance —</span>
                        <span class="support-state-pill" id="support-state-pill">unknown</span>
                        <span class="support-pay-pill" id="support-pay-pill" hidden></span>
                        <span class="support-info-plan" id="support-plan"></span>
                    </div>
                    <div class="support-info-row support-info-row-locale">
                        <span class="support-info-locale" id="support-locale"></span>
                        <span class="support-info-member" id="support-member"></span>
                    </div>
                </div>
                <div class="support-mode-controls">
                    <span class="support-mode-label" id="support-mode-label">mode: ai</span>
                    <button class="support-mode-btn" id="support-mode-btn">Take over</button>
                </div>
            </header>

            <div class="wweb-messages" id="support-messages"></div>

            <footer class="wweb-input">
                <textarea id="support-text" rows="1" placeholder="Switch to Human mode to reply…" disabled></textarea>
                <button class="wweb-send-btn" id="support-send" title="Send" disabled>▶</button>
            </footer>
        </section>
    </main>
</div>

<script src="/assets/support_chat.js?v=<?= View::e($jsVer) ?>"></script>

<?php endif; ?>
