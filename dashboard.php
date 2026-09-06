<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$pageTitle = t('nav.dashboard');
require_once __DIR__ . '/includes/header.php';

$pdo    = db();
$userId = current_user_id();

/** [الحالة => العدد] */
function count_by_status(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['Status']] = (int) $row['c'];
    }
    return $out;
}

$kpis = [];
$rows = [];
$tableTitle = '';
$intro = '';
$showCharts = false;

if ($role === 'Employee') {

    $intro = t('dash.intro_emp');

    $st = $pdo->prepare('SELECT COUNT(*) FROM Assets WHERE AssignedUserID = :id');
    $st->execute([':id' => $userId]);
    $myAssets = (int) $st->fetchColumn();

    $req = count_by_status(
        $pdo,
        'SELECT Status, COUNT(*) c FROM MaintenanceRequests WHERE EmployeeID = :id GROUP BY Status',
        [':id' => $userId]
    );

    // كل مؤشر يحمل رابطاً يفتح الصفحة المقابلة مصفّاة على القيمة نفسها
    $kpis = [
        [t('dash.my_assets'),    $myAssets,                                     'is-ok',   'my_assets.php'],
        [t('dash.open_req'),     ($req['New'] ?? 0) + ($req['InProgress'] ?? 0), 'is-warn', 'requests.php?status=New'],
        [t('dash.done_req'),     $req['Completed'] ?? 0,                         'is-ok',   'requests.php?status=Completed'],
        [t('dash.my_total_req'), array_sum($req),                                'is-new',  'requests.php'],
    ];

    $tableTitle = t('dash.my_latest');
    $stmt = $pdo->prepare(
        'SELECT r.RequestID, r.ProblemDescription, r.ProblemDescriptionEn, r.Priority,
                r.Status, r.RequestDate, a.AssetNumber, a.DeviceName, a.DeviceNameEn
         FROM MaintenanceRequests r
         JOIN Assets a ON a.AssetID = r.AssetID
         WHERE r.EmployeeID = :id
         ORDER BY r.RequestDate DESC LIMIT 8'
    );
    $stmt->execute([':id' => $userId]);
    $rows = $stmt->fetchAll();

} elseif ($role === 'ITSupport') {

    $intro = t('dash.intro_it');
    $req   = count_by_status($pdo, 'SELECT Status, COUNT(*) c FROM MaintenanceRequests GROUP BY Status');

    $st = $pdo->prepare('SELECT COUNT(DISTINCT RequestID) FROM Maintenance WHERE ITSupportID = :id');
    $st->execute([':id' => $userId]);
    $handled = (int) $st->fetchColumn();

    $kpis = [
        [t('dash.new_req'),     $req['New'] ?? 0,        'is-new',  'requests.php?status=New'],
        [t('dash.in_progress'), $req['InProgress'] ?? 0, 'is-warn', 'requests.php?status=InProgress'],
        [t('dash.completed'),   $req['Completed'] ?? 0,  'is-ok',   'requests.php?status=Completed'],
        [t('dash.handled'),     $handled,                'is-ok',   'maintenance.php'],
    ];

    $tableTitle = t('dash.open_list');
    $rows = $pdo->query(
        "SELECT r.RequestID, r.ProblemDescription, r.ProblemDescriptionEn, r.Priority,
                r.Status, r.RequestDate, a.AssetNumber, a.DeviceName, a.DeviceNameEn,
                u.FullName AS Requester, u.FullNameEn AS RequesterEn
         FROM MaintenanceRequests r
         JOIN Assets a ON a.AssetID = r.AssetID
         JOIN Users  u ON u.UserID  = r.EmployeeID
         WHERE r.Status IN ('New','InProgress')
         ORDER BY FIELD(r.Priority,'High','Medium','Low'), r.RequestDate ASC
         LIMIT 8"
    )->fetchAll();

} else { // Admin / Manager

    $intro      = t('dash.intro_mgr');
    $showCharts = true;

    $assets = count_by_status($pdo, 'SELECT Status, COUNT(*) c FROM Assets GROUP BY Status');
    $req    = count_by_status($pdo, 'SELECT Status, COUNT(*) c FROM MaintenanceRequests GROUP BY Status');

    $kpis = [
        [t('dash.total_assets'),   array_sum($assets),                             'is-new',   'assets.php'],
        [t('dash.active'),         $assets['Active'] ?? 0,                         'is-ok',    'assets.php?status=Active'],
        [t('dash.under_maint'),    $assets['UnderMaintenance'] ?? 0,               'is-warn',  'assets.php?status=UnderMaintenance'],
        [t('dash.out_of_service'), $assets['OutOfService'] ?? 0,                   'is-bad',   'assets.php?status=OutOfService'],
        [t('dash.retired'),        $assets['Retired'] ?? 0,                        'is-muted', 'assets.php?status=Retired'],
        [t('dash.open_req'),       ($req['New'] ?? 0) + ($req['InProgress'] ?? 0),  'is-warn',  'requests.php?status=New'],
        [t('dash.completed'),      $req['Completed'] ?? 0,                          'is-ok',    'requests.php?status=Completed'],
    ];

    $byType = $pdo->query(
        'SELECT DeviceType, COUNT(*) c FROM Assets GROUP BY DeviceType ORDER BY c DESC'
    )->fetchAll();

    $byMonth = $pdo->query(
        "SELECT DATE_FORMAT(RequestDate,'%Y-%m') ym, COUNT(*) c
         FROM MaintenanceRequests GROUP BY ym ORDER BY ym ASC LIMIT 12"
    )->fetchAll();

    $tableTitle = t('dash.latest');
    $rows = $pdo->query(
        'SELECT r.RequestID, r.ProblemDescription, r.ProblemDescriptionEn, r.Priority,
                r.Status, r.RequestDate, a.AssetNumber, a.DeviceName, a.DeviceNameEn,
                u.FullName AS Requester, u.FullNameEn AS RequesterEn
         FROM MaintenanceRequests r
         JOIN Assets a ON a.AssetID = r.AssetID
         JOIN Users  u ON u.UserID  = r.EmployeeID
         ORDER BY r.RequestDate DESC LIMIT 8'
    )->fetchAll();
}
?>

<div class="page-head">
    <h1><?= e(t('dash.greeting', current_user_name())) ?></h1>
    <p><?= e($intro) ?></p>
</div>

<div class="kpi-grid">
    <?php foreach ($kpis as [$label, $value, $tone, $link]): ?>
        <a href="<?= e($link) ?>" class="kpi <?= e($tone) ?>"
           title="<?= e(t('dash.drill_hint')) ?>">
            <span class="kpi-value"><?= (int) $value ?></span>
            <span class="kpi-label"><?= e($label) ?></span>
            <span class="kpi-go" aria-hidden="true">&#8250;</span>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($showCharts): ?>
<div class="chart-row">

    <section class="panel">
        <div class="panel-head"><h2><?= e(t('dash.by_type')) ?></h2></div>
        <div class="chart-body">
            <?php $maxT = max(array_map(fn($r) => (int) $r['c'], $byType ?: [['c' => 1]])); ?>
            <?php foreach ($byType as $r): ?>
                <a class="bar-row" href="assets.php?type=<?= e(urlencode($r['DeviceType'])) ?>"
                   title="<?= e(device_type_label($r['DeviceType'])) ?> — <?= (int) $r['c'] ?>">
                    <span class="bar-label"><?= e(device_type_label($r['DeviceType'])) ?></span>
                    <span class="bar-track">
                        <span class="bar-fill" style="width: <?= $maxT ? round($r['c'] / $maxT * 100) : 0 ?>%"></span>
                    </span>
                    <span class="bar-value"><?= (int) $r['c'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2><?= e(t('dash.by_month')) ?></h2></div>
        <div class="chart-body">
            <?php $maxM = max(array_map(fn($r) => (int) $r['c'], $byMonth ?: [['c' => 1]])); ?>
            <div class="col-chart">
                <?php foreach ($byMonth as $r): ?>
                    <a class="col-item" href="requests.php?month=<?= e(urlencode($r['ym'])) ?>"
                       title="<?= e($r['ym']) ?> — <?= (int) $r['c'] ?>">
                        <span class="col-bar" style="height: <?= $maxM ? max(6, round($r['c'] / $maxM * 100)) : 6 ?>%"></span>
                        <span class="col-cap"><?= (int) $r['c'] ?></span>
                        <span class="col-label ident"><?= e(substr($r['ym'], 5)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

</div>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <h2><?= e($tableTitle) ?></h2>
        <a href="requests.php" class="btn btn-inline btn-ghost"><?= e(t('nav.requests')) ?></a>
    </div>

    <?php if (!$rows): ?>
        <p class="empty"><?= e(t('dash.no_requests')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th><?= e(t('asset.number')) ?></th>
                        <th><?= e(t('req.asset')) ?></th>
                        <?php if ($role !== 'Employee'): ?><th><?= e(t('req.requester')) ?></th><?php endif; ?>
                        <th><?= e(t('req.problem')) ?></th>
                        <th><?= e(t('req.priority')) ?></th>
                        <th><?= e(t('req.status')) ?></th>
                        <th><?= e(t('c.date')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><span class="ident"><?= e($r['AssetNumber']) ?></span></td>
                        <td class="cell-strong">
                            <a href="request_view.php?id=<?= (int) $r['RequestID'] ?>"><?= e(loc($r, 'DeviceName')) ?></a>
                        </td>
                        <?php if ($role !== 'Employee'): ?>
                            <td class="cell-muted"><?= e(loc($r, 'Requester')) ?></td>
                        <?php endif; ?>
                        <td><span class="truncate" title="<?= e(loc($r, 'ProblemDescription')) ?>"><?= e(loc($r, 'ProblemDescription')) ?></span></td>
                        <td><?= badge($r['Priority'], priority_label($r['Priority'])) ?></td>
                        <td><?= badge($r['Status'], request_status_label($r['Status'])) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($r['RequestDate'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
