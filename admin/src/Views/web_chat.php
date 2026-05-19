<?php
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;

$id = (string)$instance['idInstance'];
$state = (string)($instance['state'] ?? 'unknown');
$wid = (string)($instance['wid'] ?? '');
?>
<link rel="stylesheet" href="/assets/web_chat.css">

<div class="wweb-root"
     data-instance-id="<?= View::e($id) ?>"
     data-instance-state="<?= View::e($state) ?>"
     data-instance-wid="<?= View::e($wid) ?>">

    <aside class="wweb-sidebar">
        <header class="wweb-side-header">
            <div class="wweb-profile">
                <div class="wweb-avatar wweb-avatar-self"></div>
                <div class="wweb-profile-info">
                    <div class="wweb-profile-name"><?= View::e($wid ?: $id) ?></div>
                    <div class="wweb-profile-state badge-state-<?= View::e($state) ?>"><?= View::e($state) ?></div>
                </div>
                <button class="wweb-icon-btn" id="wweb-newchat" title="<?= View::e(I18n::t('wweb.new_chat')) ?>">＋</button>
            </div>
            <input type="search" class="wweb-search" id="wweb-search" placeholder="<?= View::e(I18n::t('wweb.search')) ?>">
        </header>
        <ul id="wweb-chats" class="wweb-chats">
            <li class="wweb-chats-empty"><?= View::e(I18n::t('wweb.loading')) ?></li>
        </ul>
        <footer class="wweb-side-footer">
            <a href="/instances/<?= View::e($id) ?>"><?= View::e(I18n::t('wweb.back_instance')) ?></a>
        </footer>
    </aside>

    <main class="wweb-main">
        <div class="wweb-empty" id="wweb-empty">
            <div class="wweb-empty-icon">💬</div>
            <p><?= View::e(I18n::t('wweb.pick_chat')) ?></p>
        </div>

        <section class="wweb-chat" id="wweb-chat" hidden>
            <header class="wweb-chat-header">
                <div class="wweb-avatar" id="wweb-chat-avatar"></div>
                <div class="wweb-chat-meta">
                    <div class="wweb-chat-name" id="wweb-chat-name"></div>
                    <div class="wweb-chat-id" id="wweb-chat-id"></div>
                </div>
                <button class="wweb-icon-btn" id="wweb-refresh" title="<?= View::e(I18n::t('wweb.refresh')) ?>">⟳</button>
                <button class="wweb-icon-btn" id="wweb-mark-read" title="<?= View::e(I18n::t('wweb.mark_read')) ?>">✓</button>
                <button class="wweb-icon-btn" id="wweb-contact-info" title="<?= View::e(I18n::t('wweb.contact_info')) ?>">ℹ</button>
                <button class="wweb-icon-btn" id="wweb-archive" title="<?= View::e(I18n::t('wweb.archive')) ?>">📥</button>
            </header>

            <div class="wweb-messages" id="wweb-messages"></div>

            <footer class="wweb-input">
                <button class="wweb-icon-btn" id="wweb-attach" title="<?= View::e(I18n::t('wweb.attach')) ?>">📎</button>
                <div class="wweb-attach-menu" id="wweb-attach-menu" hidden>
                    <button data-kind="file">📄 <?= View::e(I18n::t('wweb.attach_file')) ?></button>
                    <button data-kind="image">🖼 <?= View::e(I18n::t('wweb.attach_image')) ?></button>
                    <button data-kind="file_url">🔗 <?= View::e(I18n::t('wweb.attach_url')) ?></button>
                    <button data-kind="location">📍 <?= View::e(I18n::t('wweb.attach_location')) ?></button>
                    <button data-kind="contact">👤 <?= View::e(I18n::t('wweb.attach_contact')) ?></button>
                </div>
                <textarea id="wweb-text" rows="1" placeholder="<?= View::e(I18n::t('wweb.type_message')) ?>"></textarea>
                <button class="wweb-send-btn" id="wweb-send" title="<?= View::e(I18n::t('wweb.send')) ?>">▶</button>
            </footer>
        </section>
    </main>
</div>

<!-- Modal templates -->
<div class="wweb-modal" id="wweb-modal" hidden>
    <div class="wweb-modal-card">
        <header><span id="wweb-modal-title"></span><button id="wweb-modal-close" class="wweb-icon-btn">×</button></header>
        <div id="wweb-modal-body"></div>
    </div>
</div>

<script src="/assets/web_chat.js"></script>
