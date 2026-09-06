<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);
$me  = current_user_id();

function load_request(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        'SELECT r.*, a.AssetID, a.AssetNumber, a.DeviceName, a.DeviceNameEn, a.DeviceType,
                a.Status AS AssetStatus, a.Location, a.LocationEn,
                u.FullName AS Requester, u.FullNameEn AS RequesterEn,
                u.Department, u.DepartmentEn
         FROM MaintenanceRequests r
         JOIN Assets a ON a.AssetID = r.AssetID
         JOIN Users  u ON u.UserID  = r.EmployeeID
         WHERE r.RequestID = :id'
    );
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

$r = load_request($pdo, $id);
if (!$r) {
    set_flash('error', t('c.not_found'));
    redirect('requests.php');
}

$isOwner = (int) $r['EmployeeID'] === $me;
$isTech  = has_role(['ITSupport', 'Admin']);

// الموظف لا يفتح إلا طلباته
if (has_role(['Employee']) && !$isOwner) {
    set_flash('error', t('auth.no_permission'));
    redirect('requests.php');
}

$errors = [];

/** يسجّل تغيّر الحالة */
function log_status(PDO $pdo, int $reqId, ?string $old, string $new, int $by): void
{
    $pdo->prepare(
        'INSERT INTO RequestStatusLog (RequestID, OldStatus, NewStatus, ChangedBy, ChangedAt)
         VALUES (:r,:o,:n,:u,NOW())'
    )->execute([':r' => $reqId, ':o' => $old, ':n' => $new, ':u' => $by]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('auth.err_session');
    } else {
        try {
            // ---- استلام الطلب ----
            if ($action === 'claim' && $isTech) {
                if ($r['Status'] !== 'New') {
                    $errors[] = t('req.err_state');
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE MaintenanceRequests SET Status="InProgress" WHERE RequestID=:id')
                        ->execute([':id' => $id]);
                    $pdo->prepare('UPDATE Assets SET Status="UnderMaintenance" WHERE AssetID=:a')
                        ->execute([':a' => $r['AssetID']]);
                    log_status($pdo, $id, 'New', 'InProgress', $me);
                    $pdo->commit();
                    set_flash('success', t('req.claimed'));
                    redirect('request_view.php?id=' . $id);
                }
            }

            // ---- تسجيل عملية صيانة ----
            elseif ($action === 'record' && $isTech) {
                $act    = trim($_POST['ActionTaken'] ?? '');
                $actEn  = trim($_POST['ActionTakenEn'] ?? '');
                $parts  = trim($_POST['PartsReplaced'] ?? '');
                $notes  = trim($_POST['Notes'] ?? '');

                if (!in_array($r['Status'], ['New', 'InProgress'], true)) {
                    $errors[] = t('req.err_state');
                } elseif ($act === '') {
                    $errors[] = t('maint.err_action');
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        'INSERT INTO Maintenance
                            (RequestID, ITSupportID, MaintenanceDate, ActionTaken, ActionTakenEn, PartsReplaced, Notes)
                         VALUES (:r,:u,NOW(),:a,:ae,:p,:n)'
                    )->execute([
                        ':r' => $id, ':u' => $me, ':a' => $act,
                        ':ae' => $actEn !== '' ? $actEn : null,
                        ':p' => $parts !== '' ? $parts : null,
                        ':n' => $notes !== '' ? $notes : null,
                    ]);

                    if ($r['Status'] === 'New') {   // تسجيل عملية يعني أن العمل بدأ
                        $pdo->prepare('UPDATE MaintenanceRequests SET Status="InProgress" WHERE RequestID=:id')
                            ->execute([':id' => $id]);
                        $pdo->prepare('UPDATE Assets SET Status="UnderMaintenance" WHERE AssetID=:a')
                            ->execute([':a' => $r['AssetID']]);
                        log_status($pdo, $id, 'New', 'InProgress', $me);
                    }
                    $pdo->commit();
                    set_flash('success', t('req.record_added'));
                    redirect('request_view.php?id=' . $id);
                }
            }

            // ---- إغلاق الطلب ----
            elseif ($action === 'close' && $isTech) {
                if ($r['Status'] !== 'InProgress') {
                    $errors[] = t('req.err_state');
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE MaintenanceRequests SET Status="Completed", ClosedAt=NOW() WHERE RequestID=:id')
                        ->execute([':id' => $id]);
                    $pdo->prepare('UPDATE Maintenance SET CompletionDate=NOW()
                                   WHERE RequestID=:r AND CompletionDate IS NULL')
                        ->execute([':r' => $id]);
                    $pdo->prepare('UPDATE Assets SET Status="Active" WHERE AssetID=:a')
                        ->execute([':a' => $r['AssetID']]);
                    log_status($pdo, $id, 'InProgress', 'Completed', $me);
                    $pdo->commit();
                    set_flash('success', t('req.closed_ok'));
                    redirect('request_view.php?id=' . $id);
                }
            }

            // ---- إلغاء الطلب (صاحبه أو مدير النظام) ----
            elseif ($action === 'cancel' && ($isOwner || has_role(['Admin']))) {
                if ($r['Status'] !== 'New') {
                    $errors[] = t('req.err_state');
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE MaintenanceRequests SET Status="Cancelled", ClosedAt=NOW() WHERE RequestID=:id')
                        ->execute([':id' => $id]);
                    log_status($pdo, $id, 'New', 'Cancelled', $me);
                    $pdo->commit();
                    set_flash('success', t('req.cancelled_ok'));
                    redirect('request_view.php?id=' . $id);
                }
            } else {
                $errors[] = t('auth.no_permission');
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = t('req.err_state');
        }
    }

    $r = load_request($pdo, $id);   // إعادة التحميل بعد أي محاولة
}

// المسار والعمليات
$st = $pdo->prepare(
    'SELECT l.*, u.FullName, u.FullNameEn FROM RequestStatusLog l
     JOIN Users u ON u.UserID = l.ChangedBy
     WHERE l.RequestID = :id ORDER BY l.ChangedAt ASC, l.LogID ASC'
);
$st->execute([':id' => $id]);
$timeline = $st->fetchAll();

$st = $pdo->prepare(
    'SELECT m.*, u.FullName, u.FullNameEn FROM Maintenance m
     JOIN Users u ON u.UserID = m.ITSupportID
     WHERE m.RequestID = :id ORDER BY m.MaintenanceDate ASC'
);
$st->execute([':id' => $id]);
$records = $st->fetchAll();

$pageTitle = t('req.details');
require_once __DIR__ . '/includes/header.php';
?>

<?= back_link('requests.php') ?>

<div class="page-head with-action">
    <div>
        <h1><span class="ident">#<?= (int) $r['RequestID'] ?></span> <?= e(loc($r, 'DeviceName')) ?></h1>
        <p>
            <span class="ident"><?= e($r['AssetNumber']) ?></span> ·
            <?= badge($r['Status'], request_status_label($r['Status'])) ?>
            <?= badge($r['Priority'], priority_label($r['Priority'])) ?>
        </p>
    </div>
    <div class="head-actions">
        <?php if ($isTech && $r['Status'] === 'New'): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="claim">
                <button class="btn btn-inline"><?= e(t('req.claim')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($isTech && $r['Status'] === 'InProgress'): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="close">
                <button class="btn btn-inline"><?= e(t('req.close')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (($isOwner || has_role(['Admin'])) && $r['Status'] === 'New'): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <button class="btn btn-inline btn-ghost"><?= e(t('req.cancel_req')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head"><h2><?= e(t('req.details')) ?></h2></div>
    <dl class="detail-list">
        <div><dt><?= e(t('req.asset')) ?></dt>
            <dd><a href="asset_view.php?id=<?= (int) $r['AssetID'] ?>"><?= e(loc($r, 'DeviceName')) ?></a></dd></div>
        <div><dt><?= e(t('asset.type')) ?></dt><dd><?= e(device_type_label($r['DeviceType'])) ?></dd></div>
        <div><dt><?= e(t('req.fault')) ?></dt><dd><?= e(fault_type_label($r['FaultType'])) ?></dd></div>
        <div><dt><?= e(t('req.requester')) ?></dt><dd><?= e(loc($r, 'Requester')) ?></dd></div>
        <div><dt><?= e(t('user.dept')) ?></dt><dd><?= e(loc($r, 'Department') ?: '—') ?></dd></div>
        <div><dt><?= e(t('asset.location')) ?></dt><dd><?= e(loc($r, 'Location') ?: '—') ?></dd></div>
        <div><dt><?= e(t('req.date')) ?></dt><dd><?= e(fmt_datetime($r['RequestDate'])) ?></dd></div>
        <div><dt><?= e(t('req.closed')) ?></dt><dd><?= e(fmt_datetime($r['ClosedAt'])) ?></dd></div>
        <div class="wide"><dt><?= e(t('req.problem')) ?></dt><dd><?= e(loc($r, 'ProblemDescription')) ?></dd></div>
    </dl>
</section>

<section class="panel">
    <div class="panel-head"><h2><?= e(t('req.timeline')) ?></h2></div>
    <ol class="timeline">
        <?php foreach ($timeline as $l): ?>
            <li class="<?= e(status_class($l['NewStatus'])) ?>">
                <span class="tl-status"><?= e(request_status_label($l['NewStatus'])) ?></span>
                <span class="tl-meta">
                    <?= e(fmt_datetime($l['ChangedAt'])) ?> · <?= e(t('req.changed_by')) ?> <?= e(loc($l, 'FullName')) ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="panel">
    <div class="panel-head"><h2><?= e(t('req.records')) ?></h2></div>

    <?php if (!$records): ?>
        <p class="empty"><?= e(t('req.no_records')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th><?= e(t('maint.number')) ?></th>
                        <th><?= e(t('maint.tech')) ?></th>
                        <th><?= e(t('maint.date')) ?></th>
                        <th><?= e(t('maint.action')) ?></th>
                        <th><?= e(t('maint.parts')) ?></th>
                        <th><?= e(t('maint.notes')) ?></th>
                        <th><?= e(t('maint.completion')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $m): ?>
                    <tr>
                        <td><span class="ident">#<?= (int) $m['MaintenanceID'] ?></span></td>
                        <td class="cell-muted"><?= e(loc($m, 'FullName')) ?></td>
                        <td class="cell-muted"><?= e(fmt_datetime($m['MaintenanceDate'])) ?></td>
                        <td><?= e(loc($m, 'ActionTaken')) ?></td>
                        <td class="cell-muted"><?= e(loc($m, 'PartsReplaced') ?: '—') ?></td>
                        <td class="cell-muted"><?= e(loc($m, 'Notes') ?: '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($m['CompletionDate'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($isTech && in_array($r['Status'], ['New', 'InProgress'], true)): ?>
<section class="panel form-panel">
    <div class="panel-head"><h2><?= e(t('req.add_record')) ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="record">

        <div class="field">
            <label for="ActionTaken"><?= e(t('maint.action')) ?> *</label>
            <textarea id="ActionTaken" name="ActionTaken" rows="3" required></textarea>
        </div>
        <div class="field">
            <label for="ActionTakenEn"><?= e(t('maint.action')) ?> — <?= e(t('c.english_optional')) ?></label>
            <textarea id="ActionTakenEn" name="ActionTakenEn" rows="2" dir="ltr"></textarea>
        </div>
        <div class="form-grid">
            <div class="field">
                <label for="PartsReplaced"><?= e(t('maint.parts')) ?></label>
                <input type="text" id="PartsReplaced" name="PartsReplaced">
            </div>
            <div class="field">
                <label for="Notes"><?= e(t('maint.notes')) ?></label>
                <input type="text" id="Notes" name="Notes">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-inline"><?= e(t('c.save')) ?></button>
        </div>
    </form>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
