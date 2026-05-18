<?php
use Iqteco\WaAdmin\Services\Csrf;
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;
?>
<h1><?= View::e(I18n::t('instance.new.title')) ?></h1>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="post" action="/instances/new" class="form">
    <?= Csrf::field() ?>

    <fieldset>
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

    <label>
        <span><?= View::e(I18n::t('instance.new.webhook_url')) ?></span>
        <input type="url" name="webhook_url" value="<?= View::e($default_webhook_url ?? '') ?>" placeholder="https://example.com/wa-webhook">
        <small><?= View::e(I18n::t('instance.new.webhook_hint')) ?></small>
    </label>

    <button type="submit" class="btn btn-primary"><?= View::e(I18n::t('instance.new.create')) ?></button>
    <a href="/dashboard" class="btn"><?= View::e(I18n::t('common.cancel')) ?></a>
</form>
