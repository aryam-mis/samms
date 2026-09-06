<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Employee']);

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT a.*,
            (SELECT COUNT(*) FROM MaintenanceRequests r
              WHERE r.AssetID = a.AssetID AND r.Status IN ('New','InProgress')) AS OpenReq,
            (SELECT MAX(m.MaintenanceDate) FROM Maintenance m
              JOIN MaintenanceRequests r2 ON r2.RequestID = m.RequestID
              WHERE r2.AssetID = a.AssetID) AS LastMaint
     FROM Assets a
     WHERE a.AssignedUserID = :id
     ORDER BY a.AssetNumber"
);
$stmt->execute([':id' => current_user_id()]);
$assets = $stmt->fetchAll();

$pageTitle = t('myassets.title');
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head with-action">
    <div>
        <h1><?= e(t('myassets.title')) ?></h1>
        <p><?= e(t('myassets.sub')) ?></p>
    </div>
    <?php if ($assets): ?>
        <a href="request_new.php" class="btn btn-inline">+ <?= e(t('req.new')) ?></a>
    <?php endif; ?>
</div>

<?php if (!$assets): ?>
    <section class="panel"><p class="empty"><?= e(t('myassets.none')) ?></p></section>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($assets as $a): ?>
            <article class="asset-card">
                <div class="asset-card-top">
                    <span class="ident"><?= e($a['AssetNumber']) ?></span>
                    <?= badge($a['Status'], asset_status_label($a['Status'])) ?>
                </div>
                <h3><a href="asset_view.php?id=<?= (int) $a['AssetID'] ?>"><?= e(loc($a, 'DeviceName')) ?></a></h3>
                <p class="asset-card-meta">
                    <?= e(device_type_label($a['DeviceType'])) ?> · <?= e($a['Brand'] . ' ' . $a['Model']) ?>
                </p>
                <dl class="mini-facts">
                    <div><dt><?= e(t('asset.last_maint')) ?></dt><dd><?= e(fmt_date($a['LastMaint'])) ?></dd></div>
                    <div><dt><?= e(t('asset.open_req')) ?></dt><dd><?= (int) $a['OpenReq'] ?></dd></div>
                </dl>
                <a href="request_new.php?asset=<?= (int) $a['AssetID'] ?>" class="btn btn-inline btn-ghost full">
                    <?= e(t('req.new')) ?>
                </a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
