<?php
/**
 * SAMMS - رأس الصفحة المشترك / Shared layout header
 * عرّفي $pageTitle قبل تضمينه.
 */

require_once __DIR__ . '/auth.php';
require_login();

$user     = current_user();
$role     = $user['role'];
$navItems = nav_items($role);
$current  = basename($_SERVER['PHP_SELF']);
$flash    = get_flash();
$title    = $pageTitle ?? t('app.name');
?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(dir_attr()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — SAMMS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="shell">

    <aside class="sidebar">
        <div class="brand">
            <?= logo_svg(26) ?>
            <span class="brand-name">SAMMS</span>
            <span class="brand-sub"><?= e(t('app.brand_sub')) ?></span>
        </div>

        <ul class="nav">
            <?php foreach ($navItems as $file => $item): ?>
                <li>
                    <a href="<?= e($file) ?>" class="<?= $current === $file ? 'active' : '' ?>">
                        <?= e(t($item['key'])) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar-foot">
            <span class="who-name"><?= e(current_first_name()) ?></span>
            <span class="who-role role-badge role-<?= e(strtolower($role)) ?>"><?= e(role_label($role)) ?></span>
            <div class="foot-actions">
                <a href="<?= e(lang_switch_url()) ?>" class="lang-btn"><?= e(t('c.language')) ?></a>
                <a href="logout.php" class="signout"><?= e(t('auth.sign_out')) ?></a>
            </div>
        </div>
    </aside>

    <main class="main">
        <div class="session-bar">
            <span class="session-role role-badge role-<?= e(strtolower($role)) ?>">
                <?= e(role_label($role)) ?>
            </span>
            <span class="session-user"><?= e(current_first_name()) ?></span>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
