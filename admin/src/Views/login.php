<?php
use Iqteco\WaAdmin\Services\Csrf;
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;
?>
<div class="card card-narrow">
    <h1><?= View::e(I18n::t('login.title')) ?></h1>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= View::e(I18n::t('login.invalid')) ?></div>
    <?php endif; ?>
    <form method="post" action="/login">
        <?= Csrf::field() ?>
        <label>
            <span><?= View::e(I18n::t('login.email')) ?></span>
            <input type="email" name="email" required autofocus>
        </label>
        <label>
            <span><?= View::e(I18n::t('login.password')) ?></span>
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn btn-primary"><?= View::e(I18n::t('login.submit')) ?></button>
    </form>
</div>
