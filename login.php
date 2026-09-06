<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = t('auth.err_session');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = t('auth.err_empty');
        } else {
            $error = attempt_login($username, $password);
            if ($error === null) {
                redirect('dashboard.php');
            }
        }
    }
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(dir_attr()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(t('auth.sign_in')) ?> — SAMMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">

<div class="auth-split">

    <section class="auth-brand">
        <?= logo_svg(46, 'logo logo-lg') ?>
        <h1>SAMMS</h1>
        <p class="tagline"><?= e(t('app.tagline')) ?></p>
        <ul class="brand-points">
            <li><?= e(t('app.point1')) ?></li>
            <li><?= e(t('app.point2')) ?></li>
            <li><?= e(t('app.point3')) ?></li>
        </ul>
    </section>

    <section class="auth-panel">
        <form class="auth-form" method="post" action="login.php">
            <?= csrf_field() ?>

            <a href="<?= e(lang_switch_url()) ?>" class="lang-btn lang-btn-top"><?= e(t('c.language')) ?></a>

            <h2><?= e(t('auth.sign_in')) ?></h2>
            <p class="lede"><?= e(t('auth.lede')) ?></p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php elseif ($flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <div class="field">
                <label for="username"><?= e(t('auth.username')) ?></label>
                <input type="text" id="username" name="username" autocomplete="username"
                       value="<?= e($_POST['username'] ?? '') ?>" autofocus required>
            </div>

            <div class="field">
                <label for="password"><?= e(t('auth.password')) ?></label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn"><?= e(t('auth.sign_in')) ?></button>
        </form>
    </section>

</div>

</body>
</html>
