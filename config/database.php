<?php
/**
 * SAMMS - إعدادات الاتصال بقاعدة البيانات
 *
 * هذا هو الملف الوحيد الذي يحتاج تعديلاً عند نقل النظام
 * من الجهاز المحلي (WAMP) إلى استضافة حقيقية.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'samms');
define('DB_USER', 'root');
define('DB_PASS', '');          // فارغة في WAMP محلياً فقط
define('DB_CHARSET', 'utf8mb4');

/**
 * يرجّع اتصال PDO واحد ويعيد استخدامه في نفس الطلب.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            exit('تعذّر الاتصال بقاعدة البيانات.');
        }
    }

    return $pdo;
}
