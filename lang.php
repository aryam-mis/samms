<?php
/**
 * SAMMS - تبديل اللغة
 *
 * يقبل فقط أسماء صفحات موجودة داخل المشروع، لمنع إعادة التوجيه لموقع خارجي.
 */

require_once __DIR__ . '/includes/auth.php';

set_locale($_GET['set'] ?? '');

$back  = $_GET['back'] ?? 'dashboard.php';
$parts = explode('?', $back, 2);
$page  = basename($parts[0]);
$query = $parts[1] ?? '';

// لا نقبل إلا ملف .php موجود فعلاً في جذر المشروع
$isSafe = preg_match('/^[A-Za-z0-9_\-]+\.php$/', $page)
       && is_file(__DIR__ . '/' . $page);

if (!$isSafe) {
    $page  = is_logged_in() ? 'dashboard.php' : 'login.php';
    $query = '';
}

redirect($page . ($query !== '' ? '?' . $query : ''));
