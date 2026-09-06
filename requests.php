<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo   = db();
$isEmp = has_role(['Employee']);

$q        = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$fault    = $_GET['fault'] ?? '';
$month    = $_GET['month'] ?? '';   // صيغة YYYY-MM — يصل من الرسم الشهري في لوحة المؤشرات

$where  = [];
$params = [];

if ($isEmp) {                       // الموظف يرى طلباته فقط
    $where[] = 'r.EmployeeID = :me';
    $params[':me'] = current_user_id();
}
if ($q !== '') {
    $where[] = like_any(
        ['a.AssetNumber', 'a.DeviceName', 'a.DeviceNameEn',
         'r.ProblemDescription', 'r.ProblemDescriptionEn'],
        $q, $params
    );
}
if (in_array($status, request_statuses(), true)) {
    $where[] = 'r.Status = :st';
    $params[':st'] = $status;
}
if (in_array($priority, priorities(), true)) {
    $where[] = 'r.Priority = :pr';
    $params[':pr'] = $priority;
}
if (in_array($fault, fault_types(), true)) {
    $where[] = 'r.FaultType = :ft';
    $params[':ft'] = $fault;
}
if (preg_match('/^\d{4}-\d{2}$/', $month)) {
    $where[] = "DATE_FORMAT(r.RequestDate, '%Y-%m') = :mo";
    $params[':mo'] = $month;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$baseSql  = "FROM MaintenanceRequests r
             JOIN Assets a ON a.AssetID = r.AssetID
             JOIN Users  u ON u.UserID  = r.EmployeeID
             $whereSql";

$selectSql = "SELECT r.RequestID, r.ProblemDescription, r.ProblemDescriptionEn, r.FaultType, r.Priority,
                     r.Status, r.RequestDate, r.ClosedAt,
                     a.AssetID, a.AssetNumber, a.DeviceName, a.DeviceNameEn,
                     u.FullName AS Requester, u.FullNameEn AS RequesterEn
              $baseSql";

if (($_GET['export'] ?? '') !== '') {
    // التصدير يحمل أعمدة تحليلية إضافية لا تظهر في الجدول:
    // نوع الجهاز والموقع وقسم مقدّم الطلب والفني المسؤول وزمنَي الاستلام والإغلاق.
    $exportSql = "SELECT r.RequestID, r.FaultType, r.Priority, r.Status,
                         r.RequestDate, r.ClosedAt,
                         r.ProblemDescription, r.ProblemDescriptionEn,
                         a.AssetNumber, a.DeviceName, a.DeviceNameEn,
                         a.DeviceType, a.Brand, a.Location, a.LocationEn,
                         u.FullName AS Requester, u.FullNameEn AS RequesterEn,
                         u.Department, u.DepartmentEn,
                         (SELECT tu.FullName
                            FROM RequestStatusLog sl
                            JOIN Users tu ON tu.UserID = sl.ChangedBy
                           WHERE sl.RequestID = r.RequestID AND sl.NewStatus = 'InProgress'
                        ORDER BY sl.ChangedAt LIMIT 1)                       AS TechName,
                         (SELECT tu.FullNameEn
                            FROM RequestStatusLog sl
                            JOIN Users tu ON tu.UserID = sl.ChangedBy
                           WHERE sl.RequestID = r.RequestID AND sl.NewStatus = 'InProgress'
                        ORDER BY sl.ChangedAt LIMIT 1)                       AS TechNameEn,
                         (SELECT TIMESTAMPDIFF(MINUTE, r.RequestDate, sl.ChangedAt)
                            FROM RequestStatusLog sl
                           WHERE sl.RequestID = r.RequestID AND sl.NewStatus = 'InProgress'
                        ORDER BY sl.ChangedAt LIMIT 1)                       AS ClaimMinutes,
                         CASE WHEN r.Status = 'Completed' AND r.ClosedAt IS NOT NULL
                              THEN TIMESTAMPDIFF(MINUTE, r.RequestDate, r.ClosedAt)
                         END                                                 AS CloseMinutes,
                         (SELECT COUNT(*) FROM Maintenance m
                           WHERE m.RequestID = r.RequestID)                  AS ActionCount
                  $baseSql";
    $stmt = $pdo->prepare($exportSql . ' ORDER BY r.RequestDate DESC');
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            $r['RequestID'],
            $r['AssetNumber'],
            loc($r, 'DeviceName'),
            device_type_label($r['DeviceType']),
            $r['Brand'],
            loc($r, 'Location'),
            loc($r, 'Requester'),
            loc($r, 'Department'),
            $r['TechName'] === null ? '' : (locale() === 'en' && $r['TechNameEn'] ? $r['TechNameEn'] : $r['TechName']),
            loc($r, 'ProblemDescription'),
            fault_type_label($r['FaultType']),
            priority_label($r['Priority']),
            request_status_label($r['Status']),
            $r['RequestDate'],
            $r['ClosedAt'],
            $r['ClaimMinutes'] === null ? '' : round($r['ClaimMinutes'] / 60, 2),
            $r['CloseMinutes'] === null ? '' : round($r['CloseMinutes'] / 60, 2),
            (int) $r['ActionCount'],
        ];
    }
    handle_export('maintenance_requests', [
        t('req.number'), t('asset.number'), t('req.asset'), t('asset.type'), t('asset.brand'),
        t('asset.location'), t('req.requester'), t('user.department'), t('exp.technician'),
        t('req.problem'), t('req.fault'), t('req.priority'), t('req.status'),
        t('req.date'), t('req.closed'),
        t('exp.hours_claim'), t('exp.hours_close'), t('exp.action_count'),
    ], $out, t('req.title'));
}

$perPage = 15;
$countStmt = $pdo->prepare("SELECT COUNT(*) $baseSql");
$countStmt->execute($params);
$total  = (int) $countStmt->fetchColumn();
$pages  = max(1, (int) ceil($total / $perPage));
$page   = min(current_page(), $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    $selectSql . " ORDER BY FIELD(r.Status,'New','InProgress','Completed','Cancelled'),
                            FIELD(r.Priority,'High','Medium','Low'),
                            r.RequestDate DESC
                   LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = t('req.title');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head with-action">
    <div>
        <h1><?= e(t('req.title')) ?></h1>
        <p><?= e(t('req.sub')) ?></p>
    </div>
    <?php if ($isEmp): ?>
        <a href="request_new.php" class="btn btn-inline">+ <?= e(t('req.new')) ?></a>
    <?php endif; ?>
</div>

<form class="filters" method="get" action="requests.php">
    <div class="field">
        <label for="q"><?= e(t('c.search')) ?></label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('c.search_ph')) ?>">
    </div>
    <div class="field">
        <label for="status"><?= e(t('req.status')) ?></label>
        <select id="status" name="status">
            <option value=""><?= e(t('c.all')) ?></option>
            <?php foreach (request_statuses() as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(request_status_label($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="fault"><?= e(t('req.fault')) ?></label>
        <select id="fault" name="fault">
            <option value=""><?= e(t('c.all')) ?></option>
            <?php foreach (fault_types() as $ft): ?>
                <option value="<?= e($ft) ?>" <?= $fault === $ft ? 'selected' : '' ?>><?= e(fault_type_label($ft)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="priority"><?= e(t('req.priority')) ?></label>
        <select id="priority" name="priority">
            <option value=""><?= e(t('c.all')) ?></option>
            <?php foreach (priorities() as $p): ?>
                <option value="<?= e($p) ?>" <?= $priority === $p ? 'selected' : '' ?>><?= e(priority_label($p)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-inline"><?= e(t('c.filter')) ?></button>
        <a href="requests.php" class="btn btn-inline btn-ghost"><?= e(t('c.reset')) ?></a>
    </div>
</form>

<section class="panel">
    <div class="panel-head">
        <h2><?= e(sprintf(t('c.total_rows'), $total)) ?></h2>
        <span class="export-group">
            <a href="<?= e(url_with(['export' => 'xlsx'])) ?>" class="btn btn-inline"><?= e(t('c.export_excel')) ?></a>
            <a href="<?= e(url_with(['export' => 'csv'])) ?>" class="btn btn-inline btn-ghost"><?= e(t('c.export_csv')) ?></a>
        </span>
    </div>

    <?php if (!$requests): ?>
        <p class="empty"><?= e(t('c.no_results')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th><?= e(t('req.number')) ?></th>
                        <th><?= e(t('req.asset')) ?></th>
                        <?php if (!$isEmp): ?><th><?= e(t('req.requester')) ?></th><?php endif; ?>
                        <th><?= e(t('req.problem')) ?></th>
                        <th><?= e(t('req.fault')) ?></th>
                        <th><?= e(t('req.priority')) ?></th>
                        <th><?= e(t('req.status')) ?></th>
                        <th><?= e(t('req.date')) ?></th>
                        <th><?= e(t('c.actions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><span class="ident">#<?= (int) $r['RequestID'] ?></span></td>
                        <td class="cell-strong">
                            <?= e(loc($r, 'DeviceName')) ?>
                            <span class="cell-muted block ident"><?= e($r['AssetNumber']) ?></span>
                        </td>
                        <?php if (!$isEmp): ?><td class="cell-muted"><?= e(loc($r, 'Requester')) ?></td><?php endif; ?>
                        <td><span class="truncate" title="<?= e(loc($r, 'ProblemDescription')) ?>"><?= e(loc($r, 'ProblemDescription')) ?></span></td>
                        <td class="cell-muted"><?= e(fault_type_label($r['FaultType'])) ?></td>
                        <td><?= badge($r['Priority'], priority_label($r['Priority'])) ?></td>
                        <td><?= badge($r['Status'], request_status_label($r['Status'])) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($r['RequestDate'])) ?></td>
                        <td class="row-actions"><a href="request_view.php?id=<?= (int) $r['RequestID'] ?>"><?= e(t('c.view')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="pager">
                <a class="btn btn-inline btn-ghost <?= $page <= 1 ? 'disabled' : '' ?>"
                   href="<?= e(url_with(['p' => max(1, $page - 1)])) ?>"><?= e(t('c.prev')) ?></a>
                <span class="pager-info"><?= e(sprintf(t('c.page_of'), $page, $pages)) ?></span>
                <a class="btn btn-inline btn-ghost <?= $page >= $pages ? 'disabled' : '' ?>"
                   href="<?= e(url_with(['p' => min($pages, $page + 1)])) ?>"><?= e(t('c.next')) ?></a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
