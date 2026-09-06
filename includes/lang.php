<?php
/**
 * SAMMS - محرّك اللغة / Localisation engine
 *
 * ترتيب تحديد اللغة: الجلسة ← الكوكي ← العربية افتراضياً
 */

const SUPPORTED_LOCALES = ['ar', 'en'];
const DEFAULT_LOCALE    = 'ar';
const LOCALE_COOKIE     = 'samms_lang';

/** اللغة الحالية */
function locale(): string
{
    static $locale = null;

    if ($locale === null) {
        if (!empty($_SESSION['locale']) && in_array($_SESSION['locale'], SUPPORTED_LOCALES, true)) {
            $locale = $_SESSION['locale'];
        } elseif (!empty($_COOKIE[LOCALE_COOKIE]) && in_array($_COOKIE[LOCALE_COOKIE], SUPPORTED_LOCALES, true)) {
            $locale = $_COOKIE[LOCALE_COOKIE];
            $_SESSION['locale'] = $locale;
        } else {
            $locale = DEFAULT_LOCALE;
        }
    }

    return $locale;
}

/** تثبيت لغة جديدة في الجلسة والكوكي */
function set_locale(string $new): void
{
    if (!in_array($new, SUPPORTED_LOCALES, true)) {
        return;
    }
    $_SESSION['locale'] = $new;
    setcookie(LOCALE_COOKIE, $new, [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** اللغة الأخرى — تُستخدم في زر التبديل */
function other_locale(): string
{
    return locale() === 'ar' ? 'en' : 'ar';
}

function is_rtl(): bool
{
    return locale() === 'ar';
}

function dir_attr(): string
{
    return is_rtl() ? 'rtl' : 'ltr';
}

/**
 * ترجمة مفتاح. أي وسائط إضافية تُمرَّر إلى sprintf.
 * إذا لم يوجد المفتاح، يُعاد المفتاح نفسه ليسهل اكتشاف النقص.
 */
function t(string $key, ...$args): string
{
    static $strings = [];

    $loc = locale();

    if (!isset($strings[$loc])) {
        $file = __DIR__ . '/../lang/' . $loc . '.php';
        $strings[$loc] = is_file($file) ? require $file : [];
    }

    $text = $strings[$loc][$key] ?? null;

    // احتياطي: العربية ثم المفتاح نفسه
    if ($text === null) {
        if (!isset($strings[DEFAULT_LOCALE])) {
            $fallbackFile = __DIR__ . '/../lang/' . DEFAULT_LOCALE . '.php';
            $strings[DEFAULT_LOCALE] = is_file($fallbackFile) ? require $fallbackFile : [];
        }
        $text = $strings[DEFAULT_LOCALE][$key] ?? $key;
    }

    return $args ? vsprintf($text, $args) : $text;
}

/**
 * يختار العمود المناسب للغة الحالية.
 * loc($row, 'DeviceName') يُرجع DeviceNameEn في الإنجليزية إن وُجد،
 * وإلا يرجع العربي — حتى لا تظهر خانة فارغة للبيانات المُدخلة حديثاً.
 */
function loc(array $row, string $column): string
{
    if (locale() === 'en') {
        $en = $row[$column . 'En'] ?? null;
        if ($en !== null && trim((string) $en) !== '') {
            return (string) $en;
        }
    }
    return (string) ($row[$column] ?? '');
}

/** رابط تبديل اللغة مع العودة للصفحة الحالية بكل معاملاتها */
function lang_switch_url(): string
{
    $back = basename($_SERVER['PHP_SELF']);
    $qs   = $_GET;
    unset($qs['set']);
    $query = $qs ? '?' . http_build_query($qs) : '';

    return 'lang.php?set=' . other_locale()
         . '&back=' . rawurlencode($back . $query);
}
