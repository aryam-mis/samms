<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Admin']);

$pdo = db();

// حذف مستخدم
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (verify_csrf($_POST['csrf_token'] ?? null)) {
        $del = (int) ($_POST['id'] ?? 0);
        if ($del === current_user_id()) {
            set_flash('error', t('user.err_self'));
        } else {
            try {
                $pdo->prepare('DELETE FROM Users WHERE UserID = :id')->execute([':id' => $del]);
                set_flash('success', t('c.deleted'));
            } catch (PDOException $ex) {
                set_flash('error', t('user.has_data'));   // مرتبط بسجلات
            }
        }
    }
    redirect('users.php');
}

$users = $pdo->query(
    'SELECT UserID, EmployeeNo, FullName, FullNameEn, Username, Email,
            Department, DepartmentEn, Role, IsActive
     FROM Users ORDER BY Role, FullName'
)->fetchAll();

$pageTitle = t('user.title');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head with-action">
    <div>
        <h1><?= e(t('user.title')) ?></h1>
        <p><?= e(t('user.sub')) ?></p>
    </div>
    <a href="user_form.php" class="btn btn-inline">+ <?= e(t('user.add')) ?></a>
</div>

<section class="panel">
    <div class="panel-head"><h2><?= e(sprintf(t('c.total_rows'), count($users))) ?></h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead>
                <tr>
                    <th><?= e(t('user.empno')) ?></th>
                    <th><?= e(t('user.name')) ?></th>
                    <th><?= e(t('user.username')) ?></th>
                    <th><?= e(t('user.email')) ?></th>
                    <th><?= e(t('user.dept')) ?></th>
                    <th><?= e(t('user.role')) ?></th>
                    <th><?= e(t('asset.status')) ?></th>
                    <th><?= e(t('c.actions')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><span class="ident"><?= e($u['EmployeeNo']) ?></span></td>
                    <td class="cell-strong"><?= e(loc($u, 'FullName')) ?></td>
                    <td><span class="ident"><?= e($u['Username']) ?></span></td>
                    <td class="cell-muted"><span class="ident"><?= e($u['Email']) ?></span></td>
                    <td class="cell-muted"><?= e(loc($u, 'Department') ?: '—') ?></td>
                    <td><?= e(role_label($u['Role'])) ?></td>
                    <td>
                        <span class="badge <?= $u['IsActive'] ? 'is-ok' : 'is-muted' ?>">
                            <?= e($u['IsActive'] ? t('user.active') : t('user.inactive')) ?>
                        </span>
                    </td>
                    <td class="row-actions">
                        <a href="user_form.php?id=<?= (int) $u['UserID'] ?>"><?= e(t('c.edit')) ?></a>
                        <?php if ((int) $u['UserID'] !== current_user_id()): ?>
                            <form method="post" style="display:inline"
                                  onsubmit="return confirm('<?= e(t('c.confirm_delete')) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $u['UserID'] ?>">
                                <button type="submit" class="link-danger"><?= e(t('c.delete')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
