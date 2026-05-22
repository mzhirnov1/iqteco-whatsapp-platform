<?php
use Iqteco\WaAdmin\Services\Csrf;
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;
?>
<h1><?= View::e(I18n::t('instance.new.title')) ?></h1>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="post" action="/instances/new" class="form" id="instance-new-form">
    <?= Csrf::field() ?>

    <fieldset>
        <legend><?= View::e(I18n::t('instance.new.type')) ?></legend>
        <label class="radio">
            <input type="radio" name="type" value="whatsapp" checked data-type="wa">
            <span><strong>WhatsApp</strong> — <?= View::e(I18n::t('instance.new.type_wa_hint')) ?></span>
        </label>
        <label class="radio">
            <input type="radio" name="type" value="telegram" data-type="tg">
            <span><strong>Telegram</strong> — <?= View::e(I18n::t('instance.new.type_tg_hint')) ?></span>
        </label>
    </fieldset>

    <fieldset data-show-for="wa">
        <legend><?= View::e(I18n::t('instance.new.auth_method')) ?></legend>
        <label class="radio">
            <input type="radio" name="auth_method" value="qr" checked>
            <span><strong><?= View::e(I18n::t('instance.new.qr')) ?></strong> — <?= View::e(I18n::t('instance.new.qr_hint')) ?></span>
        </label>
        <label class="radio">
            <input type="radio" name="auth_method" value="pairing_code">
            <span><strong><?= View::e(I18n::t('instance.new.pairing_code')) ?></strong> — <?= View::e(I18n::t('instance.new.pairing_code_hint')) ?></span>
        </label>
    </fieldset>

    <fieldset data-show-for="tg" style="display:none">
        <legend><?= View::e(I18n::t('instance.new.tg_auth_method')) ?></legend>
        <label class="radio">
            <input type="radio" name="tg_auth_method" value="tg_qr" checked>
            <span><strong><?= View::e(I18n::t('instance.new.tg_qr')) ?></strong> — <?= View::e(I18n::t('instance.new.tg_qr_hint')) ?></span>
        </label>
        <label class="radio">
            <input type="radio" name="tg_auth_method" value="tg_phone_code">
            <span><strong><?= View::e(I18n::t('instance.new.tg_phone_code')) ?></strong> — <?= View::e(I18n::t('instance.new.tg_phone_code_hint')) ?></span>
        </label>
        <label style="margin-top:.5rem;display:block">
            <span><?= View::e(I18n::t('instance.new.tg_phone')) ?></span>
            <input type="tel" name="tg_phone" placeholder="+380501234567" autocomplete="off">
            <small><?= View::e(I18n::t('instance.new.tg_phone_hint')) ?></small>
        </label>
    </fieldset>

    <label>
        <span><?= View::e(I18n::t('instance.new.webhook_url')) ?></span>
        <input type="url" name="webhook_url" value="<?= View::e($default_webhook_url ?? '') ?>" placeholder="https://example.com/wa-webhook">
        <small><?= View::e(I18n::t('instance.new.webhook_hint')) ?></small>
    </label>

    <button type="submit" class="btn btn-primary"><?= View::e(I18n::t('instance.new.create')) ?></button>
    <a href="/dashboard" class="btn"><?= View::e(I18n::t('common.cancel')) ?></a>
</form>

<script>
(function () {
    var form = document.getElementById('instance-new-form');
    if (!form) return;
    function applyType() {
        var t = (form.querySelector('input[name="type"]:checked') || {}).value || 'whatsapp';
        var key = t === 'telegram' ? 'tg' : 'wa';
        form.querySelectorAll('[data-show-for]').forEach(function (el) {
            el.style.display = el.dataset.showFor === key ? '' : 'none';
        });
    }
    form.querySelectorAll('input[name="type"]').forEach(function (el) {
        el.addEventListener('change', applyType);
    });
    applyType();
})();
</script>
