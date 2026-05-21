<?php
use Iqteco\WaAdmin\Services\I18n;
use Iqteco\WaAdmin\Services\View;
$user = $_SESSION['user_email'] ?? null;
?><!DOCTYPE html>
<html lang="<?= View::e(I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title ?? 'iqteco-wa-admin') ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <a href="/dashboard" class="brand">iqteco-wa</a>
    <nav>
        <a href="/dashboard"><?= View::e(I18n::t('nav.dashboard')) ?></a>
        <a href="/instances/new"><?= View::e(I18n::t('nav.new_instance')) ?></a>
        <a href="/support">Support</a>
        <a href="/settings"><?= View::e(I18n::t('nav.settings')) ?></a>
    </nav>
    <?php if ($user): ?>
        <form method="post" action="/logout" class="logout-form">
            <?= \Iqteco\WaAdmin\Services\Csrf::field() ?>
            <span><?= View::e($user) ?></span>
            <button type="submit"><?= View::e(I18n::t('nav.logout')) ?></button>
        </form>
    <?php endif; ?>
</header>
<main class="container">
    <?= $content ?? '' ?>
</main>
<footer class="footer">
    <small>iqteco-whatsapp-platform · <?= View::e(date('Y')) ?></small>
</footer>
</body>
</html>
