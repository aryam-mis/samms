<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo    = db();
$me     = current_user_id();
$errors = [];

$st = $pdo->prepare('SELECT * FROM Users WHERE UserID = :id');
$st->execute([':id' => $me]);
$u = $st->fetch();

if (!$u) {
    logout();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('auth.err_session');
    }

    // ---- تحديث البيانات ----
    elseif ($action === 'details') {
        $name   = trim($_POST['FullName'] ?? '');
        $nameEn = trim($_POST['FullNameEn'] ?? '');
        $email  = trim($_POST['Email'] ?? '');

        if ($name === '')  { $errors[] = t('user.name') . ' — ' . t('auth.err_empty'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = t('user.email') . ' — ' . t('auth.err_empty'); }

        $dup = $pdo->prepare('SELECT COUNT(*) FROM Users WHERE Email = :e AND UserID <> :id');
        $dup->execute([':e' => $email, ':id' => $me]);
        if ((int) $dup->fetchColumn() > 0) { $errors[] = t('user.err_email'); }

        if (!$errors) {
            $pdo->prepare('UPDATE Users SET FullName=:n, FullNameEn=:ne, Email=:e WHERE UserID=:id')
                ->execute([':n' => $name, ':ne' => $nameEn !== '' ? $nameEn : null, ':e' => $email, ':id' => $me]);

            $_SESSION['user']['name']   = $name;
            $_SESSION['user']['nameEn'] = $nameEn !== '' ? $nameEn : null;

            set_flash('success', t('profile.updated'));
            redirect('profile.php');
        }
    }

    // ---- تغيير كلمة المرور ----
    elseif ($action === 'password') {
        $current = $_POST['CurrentPassword'] ?? '';
        $new     = $_POST['NewPassword'] ?? '';
        $confirm = $_POST['ConfirmPassword'] ?? '';

        if (!password_verify($current, $u['PasswordHash'])) { $errors[] = t('profile.err_current'); }
        if (strlen($new) < 8)     { $errors[] = t('user.err_password'); }
        if ($new !== $confirm)    { $errors[] = t('profile.err_match'); }
        if ($new !== '' && $new === $current) { $errors[] = t('profile.err_same'); }

        if (!$errors) {
            $pdo->prepare('UPDATE Users SET PasswordHash = :p WHERE UserID = :id')
                ->execute([':p' => password_hash($new, PASSWORD_BCRYPT), ':id' => $me]);

            session_regenerate_id(true);   // إبطال الجلسة القديمة بعد تغيير كلمة المرور
            set_flash('success', t('profile.pw_changed'));
            redirect('profile.php');
        }
    }

    $st->execute([':id' => $me]);
    $u = $st->fetch();
}

$pageTitle = t('profile.title');
require_once __DIR__ . '/includes/header.php';
?>

<?= back_link('dashboard.php') ?>

<div class="page-head">
    <h1><?= e(t('profile.title')) ?></h1>
    <p><?= e(t('profile.sub')) ?></p>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head"><h2><?= e(t('profile.account')) ?></h2></div>
    <dl class="detail-list">
        <div><dt><?= e(t('user.empno')) ?></dt><dd><span class="ident"><?= e($u['EmployeeNo']) ?></span></dd></div>
        <div><dt><?= e(t('user.username')) ?></dt><dd><span class="ident"><?= e($u['Username']) ?></span></dd></div>
        <div><dt><?= e(t('user.role')) ?></dt><dd><?= e(role_label($u['Role'])) ?></dd></div>
        <div><dt><?= e(t('user.dept')) ?></dt><dd><?= e(loc($u, 'Department') ?: '—') ?></dd></div>
        <div><dt><?= e(t('profile.joined')) ?></dt><dd><?= e(fmt_date($u['CreatedAt'])) ?></dd></div>
    </dl>
</section>

<div class="chart-row">

    <section class="panel form-panel">
        <div class="panel-head"><h2><?= e(t('c.edit')) ?></h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="details">

            <div class="field">
                <label for="FullName"><?= e(t('user.name')) ?> *</label>
                <input type="text" id="FullName" name="FullName" value="<?= e($u['FullName']) ?>" required>
            </div>
            <div class="field">
                <label for="FullNameEn"><?= e(t('user.name')) ?> — <?= e(t('c.english_optional')) ?></label>
                <input type="text" id="FullNameEn" name="FullNameEn" dir="ltr" value="<?= e($u['FullNameEn']) ?>">
            </div>
            <div class="field">
                <label for="Email"><?= e(t('user.email')) ?> *</label>
                <input type="email" id="Email" name="Email" dir="ltr" value="<?= e($u['Email']) ?>" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-inline"><?= e(t('c.save')) ?></button>
            </div>
        </form>
    </section>

    <section class="panel form-panel">
        <div class="panel-head"><h2><?= e(t('profile.change_pw')) ?></h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">

            <div class="field">
                <label for="CurrentPassword"><?= e(t('profile.current_pw')) ?></label>
                <input type="password" id="CurrentPassword" name="CurrentPassword" dir="ltr"
                       autocomplete="current-password" required>
            </div>
            <div class="field">
                <label for="NewPassword"><?= e(t('profile.new_pw')) ?></label>
                <input type="password" id="NewPassword" name="NewPassword" dir="ltr"
                       autocomplete="new-password" minlength="8" required>
            </div>
            <div class="field">
                <label for="ConfirmPassword"><?= e(t('profile.confirm_pw')) ?></label>
                <input type="password" id="ConfirmPassword" name="ConfirmPassword" dir="ltr"
                       autocomplete="new-password" minlength="8" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-inline"><?= e(t('c.save')) ?></button>
            </div>
        </form>
    </section>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
