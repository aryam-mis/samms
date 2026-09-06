<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Admin', 'Manager']);

$pdo = db();

$reports = [
    'all_assets'   => 'rep.all_assets',
    'under_maint'  => 'rep.under_maint',
    'maint_log'    => 'rep.maint_log',
    'most_failing' => 'rep.most_failing',
    'req_count'    => 'rep.req_count',
    'fault_types'  => 'rep.fault_types',
    'it_perf'      => 'rep.it_perf',
];

$key = $_GET['r'] ?? 'all_assets';
if (!isset($reports[$key])) { $key = 'all_assets'; }

/** يبني (العناوين، الصفوف) للتقرير المطلوب */
function build_report(PDO $pdo, string $key): array
{
    switch ($key) {

        case 'under_maint':
            $rows = $pdo->query(
                "SELECT a.AssetNumber, a.DeviceName, a.DeviceNameEn, a.DeviceType,
                        a.Location, a.LocationEn, u.FullName, u.FullNameEn
                 FROM Assets a LEFT JOIN Users u ON u.UserID = a.AssignedUserID
                 WHERE a.Status = 'UnderMaintenance' ORDER BY a.AssetNumber"
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [$r['AssetNumber'], loc($r, 'DeviceName'), device_type_label($r['DeviceType']),
                          loc($r, 'Location') ?: '—', loc($r, 'FullName') ?: t('c.unassigned')];
            }
            return [[t('asset.number'), t('asset.name'), t('asset.type'), t('asset.location'), t('asset.assigned')], $out];

        case 'maint_log':
            $rows = $pdo->query(
                'SELECT m.MaintenanceID, a.AssetNumber, a.DeviceName, a.DeviceNameEn,
                        u.FullName, u.FullNameEn, m.MaintenanceDate, m.ActionTaken, m.ActionTakenEn
                 FROM Maintenance m
                 JOIN MaintenanceRequests r ON r.RequestID = m.RequestID
                 JOIN Assets a ON a.AssetID = r.AssetID
                 JOIN Users  u ON u.UserID  = m.ITSupportID
                 ORDER BY m.MaintenanceDate DESC'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['#' . $r['MaintenanceID'], $r['AssetNumber'], loc($r, 'DeviceName'),
                          loc($r, 'FullName'), fmt_date($r['MaintenanceDate']), loc($r, 'ActionTaken')];
            }
            return [[t('maint.number'), t('asset.number'), t('req.asset'), t('maint.tech'), t('maint.date'), t('maint.action')], $out];

        case 'most_failing':
            $rows = $pdo->query(
                "SELECT a.AssetNumber, a.DeviceName, a.DeviceNameEn, a.DeviceType,
                        COUNT(r.RequestID) AS Fails,
                        (SELECT r2.FaultType FROM MaintenanceRequests r2
                          WHERE r2.AssetID = a.AssetID AND r2.Status <> 'Cancelled'
                          GROUP BY r2.FaultType
                          ORDER BY COUNT(*) DESC, MAX(r2.RequestDate) DESC LIMIT 1) AS TopFault
                 FROM Assets a JOIN MaintenanceRequests r ON r.AssetID = a.AssetID
                 WHERE r.Status <> 'Cancelled'
                 GROUP BY a.AssetID ORDER BY Fails DESC, a.AssetNumber LIMIT 20"
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [$r['AssetNumber'], loc($r, 'DeviceName'), device_type_label($r['DeviceType']),
                          (int) $r['Fails'], fault_type_label($r['TopFault'])];
            }
            return [[t('asset.number'), t('asset.name'), t('asset.type'), t('rep.fail_count'), t('asset.top_fault')], $out];

        case 'req_count':
            $rows = $pdo->query(
                "SELECT DATE_FORMAT(RequestDate,'%Y-%m') ym, COUNT(*) c
                 FROM MaintenanceRequests GROUP BY ym ORDER BY ym DESC"
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) { $out[] = [$r['ym'], (int) $r['c']]; }
            return [[t('rep.month'), t('rep.count')], $out];

        case 'fault_types':
            $rows = $pdo->query(
                "SELECT r.FaultType, COUNT(*) c,
                        COUNT(DISTINCT r.AssetID) devices
                 FROM MaintenanceRequests r
                 WHERE r.Status <> 'Cancelled'
                 GROUP BY r.FaultType ORDER BY c DESC"
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [fault_type_label($r['FaultType']), (int) $r['c'], (int) $r['devices']];
            }
            return [[t('req.fault'), t('rep.count'), t('asset.title')], $out];

        case 'it_perf':
            // زمن الاستلام والإغلاق محسوبان من سجل تغيّر الحالة
            $rows = $pdo->query(
                "SELECT u.UserID, u.FullName, u.FullNameEn,
                        COUNT(DISTINCT m.RequestID) AS Handled,
                        (SELECT COUNT(*) FROM RequestStatusLog l
                          WHERE l.ChangedBy = u.UserID AND l.NewStatus = 'Completed') AS ClosedCount,
                        (SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, r2.RequestDate, l2.ChangedAt)) / 60, 1)
                           FROM RequestStatusLog l2
                           JOIN MaintenanceRequests r2 ON r2.RequestID = l2.RequestID
                          WHERE l2.ChangedBy = u.UserID AND l2.NewStatus = 'InProgress') AS AvgClaim,
                        (SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, r3.RequestDate, l3.ChangedAt)) / 60, 1)
                           FROM RequestStatusLog l3
                           JOIN MaintenanceRequests r3 ON r3.RequestID = l3.RequestID
                          WHERE l3.ChangedBy = u.UserID AND l3.NewStatus = 'Completed') AS AvgClose
                 FROM Users u
                 LEFT JOIN Maintenance m ON m.ITSupportID = u.UserID
                 WHERE u.Role IN ('ITSupport','Admin')
                 GROUP BY u.UserID ORDER BY Handled DESC"
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [loc($r, 'FullName'), (int) $r['Handled'], (int) $r['ClosedCount'],
                          $r['AvgClaim'] !== null ? $r['AvgClaim'] : '—',
                          $r['AvgClose'] !== null ? $r['AvgClose'] : '—'];
            }
            return [[t('maint.tech'), t('rep.handled'), t('rep.closed_count'), t('rep.avg_hours'), t('rep.avg_close')], $out];

        case 'all_assets':
        default:
            $rows = $pdo->query(
                'SELECT a.AssetNumber, a.DeviceName, a.DeviceNameEn, a.DeviceType, a.Brand, a.Model,
                        a.SerialNumber, a.Status, a.Location, a.LocationEn, u.FullName, u.FullNameEn
                 FROM Assets a LEFT JOIN Users u ON u.UserID = a.AssignedUserID
                 ORDER BY a.AssetNumber'
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [$r['AssetNumber'], loc($r, 'DeviceName'), device_type_label($r['DeviceType']),
                          $r['Brand'], $r['Model'], $r['SerialNumber'],
                          loc($r, 'FullName') ?: t('c.unassigned'), loc($r, 'Location') ?: '—',
                          asset_status_label($r['Status'])];
            }
            return [[t('asset.number'), t('asset.name'), t('asset.type'), t('asset.brand'), t('asset.model'),
                     t('asset.serial'), t('asset.assigned'), t('asset.location'), t('asset.status')], $out];
    }
}

[$headers, $rows] = build_report($pdo, $key);

handle_export('report_' . $key, $headers, $rows, t($reports[$key]));

$pageTitle = t('rep.title');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1><?= e(t('rep.title')) ?></h1>
    <p><?= e(t('rep.sub')) ?></p>
</div>

<nav class="tabs">
    <?php foreach ($reports as $rk => $label): ?>
        <a href="reports.php?r=<?= e($rk) ?>" class="<?= $key === $rk ? 'active' : '' ?>"><?= e(t($label)) ?></a>
    <?php endforeach; ?>
</nav>

<section class="panel">
    <div class="panel-head">
        <h2><?= e(t($reports[$key])) ?> — <?= e(sprintf(t('c.total_rows'), count($rows))) ?></h2>
        <span class="export-group">
            <a href="<?= e(url_with(['export' => 'xlsx'])) ?>" class="btn btn-inline"><?= e(t('c.export_excel')) ?></a>
            <a href="<?= e(url_with(['export' => 'csv'])) ?>" class="btn btn-inline btn-ghost"><?= e(t('c.export_csv')) ?></a>
        </span>
    </div>

    <?php if (!$rows): ?>
        <p class="empty"><?= e(t('rep.empty')) ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($row as $i => $cell): ?>
                            <td class="<?= $i === 0 ? 'ident' : '' ?>"><?= e((string) $cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
