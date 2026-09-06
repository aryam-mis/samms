<?php
/**
 * SAMMS - تسجيل الدخول والصلاحيات / Auth & role-based access
 */

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

require_once __DIR__ . '/functions.php';

/** محاولة تسجيل الدخول — ترجّع رسالة خطأ أو null عند النجاح */
function attempt_login(string $username, string $password): ?string
{
    $stmt = db()->prepare(
        'SELECT UserID, FullName, FullNameEn, Username, PasswordHash, Role,
                Department, DepartmentEn, IsActive
         FROM Users WHERE Username = :u LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    // رسالة واحدة للحالتين حتى لا نكشف أي أسماء مستخدمين موجودة
    if (!$user || !password_verify($password, $user['PasswordHash'])) {
        return t('auth.err_bad');
    }

    if ((int) $user['IsActive'] !== 1) {
        return t('auth.err_disabled');
    }

    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'           => (int) $user['UserID'],
        'name'         => $user['FullName'],
        'nameEn'       => $user['FullNameEn'],
        'username'     => $user['Username'],
        'role'         => $user['Role'],
        'department'   => $user['Department'],
        'departmentEn' => $user['DepartmentEn'],
    ];

    return null;
}

function logout(): void
{
    $locale  = $_SESSION['locale'] ?? null;   // نحتفظ باللغة بعد الخروج
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();

    session_start();
    if ($locale) {
        $_SESSION['locale'] = $locale;
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_role(): ?string
{
    return $_SESSION['user']['role'] ?? null;
}

function current_user_id(): int
{
    return (int) ($_SESSION['user']['id'] ?? 0);
}

/** اسم المستخدم الحالي باللغة المعروضة */
function current_user_name(): string
{
    $u = current_user();
    if (!$u) {
        return '';
    }
    if (locale() === 'en' && !empty($u['nameEn'])) {
        return $u['nameEn'];
    }
    return $u['name'];
}

/**
 * الاسم الأول فقط — يُستخدم في الترويسة والترحيب حيث تكفي الإشارة
 * للشخص دون اسم العائلة.
 */
function first_name(string $full): string
{
    $full = trim(preg_replace('/\s+/u', ' ', $full));
    if ($full === '') {
        return '';
    }
    $parts = explode(' ', $full);
    return $parts[0];
}

function current_first_name(): string
{
    return first_name(current_user_name());
}

function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('error', t('auth.need_login'));
        redirect('login.php');
    }
}

function require_role(array $roles): void
{
    require_login();
    if (!in_array(current_role(), $roles, true)) {
        set_flash('error', t('auth.no_permission'));
        redirect('dashboard.php');
    }
}

function has_role(array $roles): bool
{
    return in_array(current_role(), $roles, true);
}

/** روابط القائمة الجانبية حسب الدور */
function nav_items(string $role): array
{
    $all = [
        'dashboard.php'   => ['key' => 'nav.dashboard',   'roles' => ['Admin', 'Manager', 'ITSupport', 'Employee']],
        'assets.php'      => ['key' => 'nav.assets',      'roles' => ['Admin', 'Manager', 'ITSupport']],
        'my_assets.php'   => ['key' => 'nav.my_assets',   'roles' => ['Employee']],
        'requests.php'    => ['key' => 'nav.requests',    'roles' => ['Admin', 'Manager', 'ITSupport', 'Employee']],
        'maintenance.php' => ['key' => 'nav.maintenance', 'roles' => ['Admin', 'Manager', 'ITSupport']],
        'reports.php'     => ['key' => 'nav.reports',     'roles' => ['Admin', 'Manager']],
        'import.php'      => ['key' => 'nav.import',      'roles' => ['Admin']],
        'users.php'       => ['key' => 'nav.users',       'roles' => ['Admin']],
        'profile.php'     => ['key' => 'nav.profile',     'roles' => ['Admin', 'Manager', 'ITSupport', 'Employee']],
    ];

    $items = [];
    foreach ($all as $file => $item) {
        if (in_array($role, $item['roles'], true)) {
            $items[$file] = $item;
        }
    }
    return $items;
}
