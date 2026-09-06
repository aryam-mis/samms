<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT a.*, u.FullName AS Owner, u.FullNameEn AS OwnerEn,
            u.Department, u.DepartmentEn, u.EmployeeNo
     FROM Assets a LEFT JOIN Users u ON u.UserID = a.AssignedUserID
     WHERE a.AssetID = :id'
);
$stmt->execute([':id' => $id]);
$a = $stmt->fetch();

if (!$a) {
    set_flash('error', t('c.not_found'));
    redirect(has_role(['Employee']) ? 'my_assets.php' : 'assets.php');
}

// الموظف لا يرى إلا أجهزته
if (has_role(['Employee']) && (int) $a['AssignedUserID'] !== current_user_id()) {
    set_flash('error', t('auth.no_permission'));
    redirect('my_assets.php');
}

// استبعاد أو حذف الجهاز (مدير النظام فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['delete', 'retire'], true)) {
    require_role(['Admin']);
    if (verify_csrf($_POST['csrf_token'] ?? null)) {

        if ($_POST['action'] === 'retire') {
            $pdo->prepare('UPDATE Assets SET Status = "Retired" WHERE AssetID = :id')->execute([':id' => $id]);
            set_flash('success', t('asset.retired_ok'));
            redirect('asset_view.php?id=' . $id);
        }

        // الحذف مسموح فقط لجهاز لا سجل له — والقاعدة ترفضه أصلاً بـ RESTRICT
        try {
            $pdo->prepare('DELETE FROM Assets WHERE AssetID = :id')->execute([':id' => $id]);
            set_flash('success', t('c.deleted'));
            redirect('assets.php');
        } catch (PDOException $ex) {
            set_flash('error', t('asset.cannot_delete'));
            redirect('asset_view.php?id=' . $id);
        }
    }
}

// إحصائيات الجهاز — جوهر المشكلة التي بُني النظام لحلها
$st = $pdo->prepare('SELECT COUNT(*) FROM Maintenance m
                     JOIN MaintenanceRequests r ON r.RequestID = m.RequestID
                     WHERE r.AssetID = :id');
$st->execute([':id' => $id]);
$timesServiced = (int) $st->fetchColumn();

$st = $pdo->prepare('SELECT MAX(m.MaintenanceDate) FROM Maintenance m
                     JOIN MaintenanceRequests r ON r.RequestID = m.RequestID
                     WHERE r.AssetID = :id');
$st->execute([':id' => $id]);
$lastMaint = $st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM MaintenanceRequests
                     WHERE AssetID = :id AND Status IN ('New','InProgress')");
$st->execute([':id' => $id]);
$openReq = (int) $st->fetchColumn();

// توزيع الأعطال حسب النوع — يجيب عن: أي عطل يتكرر على هذا الجهاز؟
$st = $pdo->prepare(
    "SELECT FaultType, COUNT(*) c,
            MAX(RequestDate) AS LastSeen
     FROM MaintenanceRequests
     WHERE AssetID = :id AND Status <> 'Cancelled'
     GROUP BY FaultType ORDER BY c DESC, LastSeen DESC"
);
$st->execute([':id' => $id]);
$faultBreakdown = $st->fetchAll();
$topFault = $faultBreakdown[0] ?? null;

// سجل الطلبات وما نُفّذ فيها
$st = $pdo->prepare(
    'SELECT r.RequestID, r.ProblemDescription, r.ProblemDescriptionEn, r.FaultType, r.Priority, r.Status,
            r.RequestDate, r.ClosedAt, u.FullName AS Requester, u.FullNameEn AS RequesterEn
     FROM MaintenanceRequests r
     JOIN Users u ON u.UserID = r.EmployeeID
     WHERE r.AssetID = :id ORDER BY r.RequestDate DESC'
);
$st->execute([':id' => $id]);
$requests = $st->fetchAll();

$st = $pdo->prepare('SELECT COUNT(*) FROM MaintenanceRequests WHERE AssetID = :id');
$st->execute([':id' => $id]);
$linkedRequests = (int) $st->fetchColumn();

$warrantyExpired = $a['WarrantyExpiryDate'] && strtotime($a['WarrantyExpiryDate']) < time();

$pageTitle = t('asset.details');
require_once __DIR__ . '/includes/header.php';
?>

<?= back_link('assets.php') ?>

<div class="page-head with-action">
    <div>
        <h1><?= e(loc($a, 'DeviceName')) ?></h1>
        <p>
            <span class="ident"><?= e($a['AssetNumber']) ?></span> ·
            <?= e(device_type_label($a['DeviceType'])) ?> ·
            <?= badge($a['Status'], asset_status_label($a['Status'])) ?>
        </p>
    </div>
    <?php if (has_role(['Admin'])): ?>
        <div class="head-actions">
            <a href="asset_form.php?id=<?= (int) $a['AssetID'] ?>" class="btn btn-inline"><?= e(t('c.edit')) ?></a>

            <?php if ($a['Status'] !== 'Retired'): ?>
                <form method="post" onsubmit="return confirm('<?= e(t('asset.confirm_retire')) ?>');" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="retire">
                    <button type="submit" class="btn btn-inline btn-ghost"><?= e(t('asset.retire')) ?></button>
                </form>
            <?php endif; ?>

            <?php if ($linkedRequests === 0): ?>
                <form method="post" onsubmit="return confirm('<?= e(t('c.confirm_delete')) ?>');" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-inline btn-danger"><?= e(t('c.delete')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="kpi-grid">
    <div class="kpi is-new">
        <span class="kpi-value"><?= $timesServiced ?></span>
        <span class="kpi-label"><?= e(t('asset.times')) ?></span>
    </div>
    <div class="kpi is-ok">
        <span class="kpi-value small"><?= e(fmt_date($lastMaint)) ?></span>
        <span class="kpi-label"><?= e(t('asset.last_maint')) ?></span>
    </div>
    <div class="kpi <?= $openReq ? 'is-warn' : 'is-ok' ?>">
        <span class="kpi-value"><?= $openReq ?></span>
        <span class="kpi-label"><?= e(t('asset.open_req')) ?></span>
    </div>
    <div class="kpi <?= $warrantyExpired ? 'is-bad' : 'is-ok' ?>">
        <span class="kpi-value small"><?= e(fmt_date($a['WarrantyExpiryDate'])) ?></span>
        <span class="kpi-label"><?= e($warrantyExpired ? t('asset.warranty_ended') : t('asset.warranty')) ?></span>
    </div>
    <div class="kpi <?= $topFault && (int) $topFault['c'] > 1 ? 'is-bad' : 'is-muted' ?>">
        <span class="kpi-value small">
            <?= e($topFault ? fault_type_label($topFault['FaultType']) . ' ×' . (int) $topFault['c'] : '—') ?>
        </span>
        <span class="kpi-label"><?= e(t('asset.top_fault')) ?></span>
    </div>
</div>

<section class="panel">
    <div class="panel-head"><h2><?= e(t('asset.details')) ?></h2></div>
    <dl class="detail-list">
        <div><dt><?= e(t('asset.brand')) ?></dt><dd><?= e($a['Brand']) ?></dd></div>
        <div><dt><?= e(t('asset.model')) ?></dt><dd><?= e($a['Model']) ?></dd></div>
        <div><dt><?= e(t('asset.serial')) ?></dt><dd><span class="ident"><?= e($a['SerialNumber']) ?></span></dd></div>
        <div><dt><?= e(t('asset.assigned')) ?></dt><dd><?= e(loc($a, 'Owner') ?: t('c.unassigned')) ?></dd></div>
        <div><dt><?= e(t('user.dept')) ?></dt><dd><?= e(loc($a, 'Department') ?: '—') ?></dd></div>
        <div><dt><?= e(t('asset.location')) ?></dt><dd><?= e(loc($a, 'Location') ?: '—') ?></dd></div>
        <div><dt><?= e(t('asset.handover')) ?></dt><dd><?= e(fmt_date($a['AssignedDate'])) ?></dd></div>
        <div><dt><?= e(t('asset.purchase')) ?></dt><dd><?= e(fmt_date($a['PurchaseDate'])) ?></dd></div>
        <div><dt><?= e(t('asset.warranty')) ?></dt><dd><?= e(fmt_date($a['WarrantyExpiryDate'])) ?></dd></div>
    </dl>
</section>

<section class="panel">
    <div class="panel-head"><h2><?= e(t('asset.faults_by_type')) ?></h2></div>
    <?php if (!$faultBreakdown): ?>
        <p class="empty"><?= e(t('asset.no_faults')) ?></p>
    <?php else: ?>
        <div class="chart-body">
            <?php $maxF = max(array_map(fn($r) => (int) $r['c'], $faultBreakdown)); ?>
            <?php foreach ($faultBreakdown as $fb): ?>
                <div class="bar-row">
                    <span class="bar-label"><?= e(fault_type_label($fb['FaultType'])) ?></span>
                    <span class="bar-track">
                        <span class="bar-fill <?= (int) $fb['c'] > 1 ? 'bar-alert' : '' ?>"
                              style="width: <?= round($fb['c'] / $maxF * 100) ?>%"></span>
                    </span>
                    <span class="bar-value"><?= (int) $fb['c'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head"><h2><?= e(t('asset.history')) ?></h2></div>

    <?php if (!$requests): ?>
        <p class="empty"><?= e(t('asset.no_history')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th><?= e(t('req.number')) ?></th>
                        <th><?= e(t('req.problem')) ?></th>
                        <th><?= e(t('req.fault')) ?></th>
                        <th><?= e(t('req.requester')) ?></th>
                        <th><?= e(t('req.priority')) ?></th>
                        <th><?= e(t('req.status')) ?></th>
                        <th><?= e(t('req.date')) ?></th>
                        <th><?= e(t('req.closed')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><a href="request_view.php?id=<?= (int) $r['RequestID'] ?>" class="ident">#<?= (int) $r['RequestID'] ?></a></td>
                        <td><span class="truncate" title="<?= e(loc($r, 'ProblemDescription')) ?>"><?= e(loc($r, 'ProblemDescription')) ?></span></td>
                        <td class="cell-muted"><?= e(fault_type_label($r['FaultType'])) ?></td>
                        <td class="cell-muted"><?= e(loc($r, 'Requester')) ?></td>
                        <td><?= badge($r['Priority'], priority_label($r['Priority'])) ?></td>
                        <td><?= badge($r['Status'], request_status_label($r['Status'])) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($r['RequestDate'])) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($r['ClosedAt'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
