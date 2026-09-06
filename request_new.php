<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['Employee']);

$pdo    = db();
$me     = current_user_id();
$errors = [];

$stmt = $pdo->prepare(
    "SELECT AssetID, AssetNumber, DeviceName, DeviceNameEn
     FROM Assets WHERE AssignedUserID = :id AND Status <> 'OutOfService'
     ORDER BY AssetNumber"
);
$stmt->execute([':id' => $me]);
$myAssets = $stmt->fetchAll();

$assetId  = (int) ($_POST['AssetID'] ?? $_GET['asset'] ?? 0);
$problem  = trim($_POST['ProblemDescription'] ?? '');
$problemEn= trim($_POST['ProblemDescriptionEn'] ?? '');
$priority = $_POST['Priority'] ?? 'Medium';
$fault    = $_POST['FaultType'] ?? 'Other';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = t('auth.err_session');
    }

    $owned = false;
    foreach ($myAssets as $a) {
        if ((int) $a['AssetID'] === $assetId) { $owned = true; break; }
    }
    if (!$owned)                                  { $errors[] = t('req.err_asset'); }
    if ($problem === '')                          { $errors[] = t('req.err_problem'); }
    if (!in_array($priority, priorities(), true))   { $priority = 'Medium'; }
    if (!in_array($fault, fault_types(), true))     { $fault = 'Other'; }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO MaintenanceRequests
                    (AssetID, EmployeeID, ProblemDescription, ProblemDescriptionEn, FaultType, Priority, Status, RequestDate)
                 VALUES (:a, :e, :p, :pe, :ft, :pr, "New", NOW())'
            );
            $ins->execute([
                ':a'  => $assetId,
                ':e'  => $me,
                ':p'  => $problem,
                ':pe' => $problemEn !== '' ? $problemEn : null,
                ':ft' => $fault,
                ':pr' => $priority,
            ]);
            $newId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO RequestStatusLog (RequestID, OldStatus, NewStatus, ChangedBy, ChangedAt)
                 VALUES (:r, NULL, "New", :u, NOW())'
            )->execute([':r' => $newId, ':u' => $me]);

            $pdo->commit();
            set_flash('success', t('req.created'));
            redirect('request_view.php?id=' . $newId);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = t('c.not_found');
        }
    }
}

$pageTitle = t('req.new');
require_once __DIR__ . '/includes/header.php';
?>

<?= back_link('my_assets.php') ?>

<div class="page-head">
    <h1><?= e(t('req.new')) ?></h1>
    <p><a href="requests.php">&larr; <?= e(t('req.title')) ?></a></p>
</div>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$myAssets): ?>
    <section class="panel"><p class="empty"><?= e(t('req.no_assets')) ?></p></section>
<?php else: ?>
    <form method="post" class="panel form-panel narrow">
        <?= csrf_field() ?>

        <div class="field">
            <label for="AssetID"><?= e(t('req.asset')) ?> *</label>
            <select id="AssetID" name="AssetID" required>
                <option value=""><?= e(t('req.err_asset')) ?></option>
                <?php foreach ($myAssets as $a): ?>
                    <option value="<?= (int) $a['AssetID'] ?>" <?= $assetId === (int) $a['AssetID'] ? 'selected' : '' ?>>
                        <?= e($a['AssetNumber'] . ' — ' . loc($a, 'DeviceName')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="FaultType"><?= e(t('req.fault')) ?></label>
            <select id="FaultType" name="FaultType">
                <?php foreach (fault_types() as $ft): ?>
                    <option value="<?= e($ft) ?>" <?= $fault === $ft ? 'selected' : '' ?>><?= e(fault_type_label($ft)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="Priority"><?= e(t('req.priority')) ?></label>
            <select id="Priority" name="Priority">
                <?php foreach (priorities() as $p): ?>
                    <option value="<?= e($p) ?>" <?= $priority === $p ? 'selected' : '' ?>><?= e(priority_label($p)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="ProblemDescription"><?= e(t('req.problem')) ?> *</label>
            <textarea id="ProblemDescription" name="ProblemDescription" rows="5" required><?= e($problem) ?></textarea>
        </div>

        <div class="field">
            <label for="ProblemDescriptionEn"><?= e(t('req.problem')) ?> — <?= e(t('c.english_optional')) ?></label>
            <textarea id="ProblemDescriptionEn" name="ProblemDescriptionEn" rows="3" dir="ltr"><?= e($problemEn) ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-inline"><?= e(t('c.save')) ?></button>
            <a href="requests.php" class="btn btn-inline btn-ghost"><?= e(t('c.cancel')) ?></a>
        </div>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
