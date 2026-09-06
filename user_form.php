<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Admin']);

$pdo    = db();
$id     = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];

$u = [
    'EmployeeNo' => '', 'FullName' => '', 'FullNameEn' => '', 'Username' => '',
    'Email' => '', 'Department' => '', 'DepartmentEn' => '', 'Role' => 'Employee', 'IsActive' => 1,
];

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM Users WHERE UserID = :id');
    $stmt->execute([':id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        set_flash('error', t('c.not_found'));
        redirect('users.php');
    }
    $u = array_merge($u, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('auth.err_session');
    }

    foreach (['EmployeeNo', 'FullName', 'FullNameEn', 'Username', 'Email', 'Department', 'DepartmentEn', 'Role'] as $k) {
        $u[$k] = trim($_POST[$k] ?? '');
    }
    $u['IsActive'] = isset($_POST['IsActive']) ? 1 : 0;
    $password      = $_POST['Password'] ?? '';

    if ($u['EmployeeNo'] === '') { $errors[] = t('user.empno')    . ' — ' . t('auth.err_empty'); }
    if ($u['FullName'] === '')   { $errors[] = t('user.name')     . ' — ' . t('auth.err_empty'); }
    if ($u['Username'] === '')   { $errors[] = t('user.username') . ' — ' . t('auth.err_empty'); }
    if (!filter_var($u['Email'], FILTER_VALIDATE_EMAIL)) { $errors[] = t('user.email') . ' — ' . t('auth.err_empty'); }
    if (!in_array($u['Role'], roles(), true)) { $u['Role'] = 'Employee'; }

    if (!$isEdit || $password !== '') {
        if (strlen($password) < 8) { $errors[] = t('user.err_password'); }
    }

    foreach ([['Username', 'user.err_username'], ['Email', 'user.err_email'], ['EmployeeNo', 'user.err_empno']] as [$col, $msg]) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM Users WHERE $col = :v AND UserID <> :id");
        $dup->execute([':v' => $u[$col], ':id' => $id]);
        if ((int) $dup->fetchColumn() > 0) { $errors[] = t($msg); }
    }

    if (!$errors) {
        $data = [
            ':no'     => $u['EmployeeNo'],
            ':name'   => $u['FullName'],
            ':nameEn' => $u['FullNameEn'] !== '' ? $u['FullNameEn'] : null,
            ':user'   => $u['Username'],
            ':mail'   => $u['Email'],
            ':dept'   => $u['Department'] !== '' ? $u['Department'] : null,
            ':deptEn' => $u['DepartmentEn'] !== '' ? $u['DepartmentEn'] : null,
            ':role'   => $u['Role'],
            ':act'    => $u['IsActive'],
        ];

        if ($isEdit) {
            $data[':id'] = $id;
            $sql = 'UPDATE Users SET EmployeeNo=:no, FullName=:name, FullNameEn=:nameEn, Username=:user,
                        Email=:mail, Department=:dept, DepartmentEn=:deptEn, Role=:role, IsActive=:act';
            if ($password !== '') {
                $sql .= ', PasswordHash=:pw';
                $data[':pw'] = password_hash($password, PASSWORD_BCRYPT);
            }
            $sql .= ' WHERE UserID=:id';
            $pdo->prepare($sql)->execute($data);
        } else {
            $data[':pw'] = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare(
                'INSERT INTO Users (EmployeeNo, FullName, FullNameEn, Username, PasswordHash,
                                    Email, Department, DepartmentEn, Role, IsActive)
                 VALUES (:no,:name,:nameEn,:user,:pw,:mail,:dept,:deptEn,:role,:act)'
            )->execute($data);
        }

        set_flash('success', t('c.saved'));
        redirect('users.php');
    }
}

$pageTitle = $isEdit ? t('user.edit') : t('user.add');
require_once __DIR__ . '/includes/header.php';
?>

<?= back_link('users.php') ?>

<div class="page-head">
    <h1><?= e($pageTitle) ?></h1>
    <p><a href="users.php">&larr; <?= e(t('user.title')) ?></a></p>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="panel form-panel">
    <?= csrf_field() ?>

    <div class="form-grid">
        <div class="field">
            <label for="EmployeeNo"><?= e(t('user.empno')) ?> *</label>
            <input type="text" id="EmployeeNo" name="EmployeeNo" dir="ltr" value="<?= e($u['EmployeeNo']) ?>" required>
        </div>
        <div class="field">
            <label for="Username"><?= e(t('user.username')) ?> *</label>
            <input type="text" id="Username" name="Username" dir="ltr" value="<?= e($u['Username']) ?>" required>
        </div>

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
        <div class="field">
            <label for="Role"><?= e(t('user.role')) ?></label>
            <select id="Role" name="Role">
                <?php foreach (roles() as $rl): ?>
                    <option value="<?= e($rl) ?>" <?= $u['Role'] === $rl ? 'selected' : '' ?>><?= e(role_label($rl)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="Department"><?= e(t('user.dept')) ?></label>
            <input type="text" id="Department" name="Department" value="<?= e($u['Department']) ?>">
        </div>
        <div class="field">
            <label for="DepartmentEn"><?= e(t('user.dept')) ?> — <?= e(t('c.english_optional')) ?></label>
            <input type="text" id="DepartmentEn" name="DepartmentEn" dir="ltr" value="<?= e($u['DepartmentEn']) ?>">
        </div>

        <div class="field wide">
            <label for="Password"><?= e($isEdit ? t('user.password_keep') : t('user.password_new') . ' *') ?></label>
            <input type="password" id="Password" name="Password" dir="ltr" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
        </div>
    </div>

    <label class="check">
        <input type="checkbox" name="IsActive" value="1" <?= $u['IsActive'] ? 'checked' : '' ?>>
        <span><?= e(t('user.active')) ?></span>
    </label>

    <div class="form-actions">
        <button type="submit" class="btn btn-inline"><?= e(t('c.save')) ?></button>
        <a href="users.php" class="btn btn-inline btn-ghost"><?= e(t('c.cancel')) ?></a>
    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
