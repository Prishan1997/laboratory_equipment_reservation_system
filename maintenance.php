<?php
/**
 * Screen 06: Maintenance Blackout Scheduler (UC-09 & FR-5.1)
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

$pageTitle = 'Maintenance Scheduler';
require_once __DIR__ . '/includes/header.php';
requireRole(['supervisor', 'admin']);

$pdo = getDBConnection();
$currentUser = getCurrentUser();
$technicianId = (int)$currentUser['user_id'];

$errorMessage = '';
$successMessage = '';

// Handle Maintenance Logging & Blackout Declaration (UC-09)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $errorMessage = 'Security validation failed. Please try again.';
    } else {
        $equipmentId   = (int)($_POST['equipment_id'] ?? 0);
        $startDatetime = trim((string)($_POST['start_datetime'] ?? ''));
        $endDatetime   = trim((string)($_POST['end_datetime'] ?? ''));
        $maintType     = trim((string)($_POST['maintenance_type'] ?? 'routine_calibration'));
        $notes         = trim((string)($_POST['servicing_notes'] ?? ''));

        if ($equipmentId <= 0 || empty($startDatetime) || empty($endDatetime) || empty($notes)) {
            $errorMessage = 'Please complete all required blackout and servicing fields.';
        } else {
            $startTs = strtotime($startDatetime);
            $endTs   = strtotime($endDatetime);

            if (!$startTs || !$endTs || $endTs <= $startTs) {
                $errorMessage = 'End datetime must be later than the declared maintenance start datetime.';
            } else {
                $startFormatted = date('Y-m-d H:i:s', $startTs);
                $endFormatted   = date('Y-m-d H:i:s', $endTs);

                // Transactional Blackout Commitment & Auto-Cancellation (UC-09)
                $pdo->beginTransaction();

                try {
                    // 1. Insert into maintenance_logs
                    $logStmt = $pdo->prepare("
                        INSERT INTO maintenance_logs (
                            equipment_id, technician_id, start_datetime, end_datetime, maintenance_type, servicing_notes, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $logStmt->execute([
                        $equipmentId,
                        $technicianId,
                        $startFormatted,
                        $endFormatted,
                        $maintType,
                        $notes
                    ]);

                    // 2. Query for conflicting active bookings (pending, confirmed)
                    $conflictQuery = $pdo->prepare("
                        SELECT reservation_id, user_id, start_time, end_time
                        FROM reservations
                        WHERE equipment_id = ?
                          AND status IN ('pending', 'confirmed')
                          AND (start_time < ? AND end_time > ?)
                    ");
                    $conflictQuery->execute([$equipmentId, $endFormatted, $startFormatted]);
                    $conflictedBookings = $conflictQuery->fetchAll();

                    // 3. Auto-cancel conflicting reservations and notify researchers
                    $cancelledCount = 0;
                    if (!empty($conflictedBookings)) {
                        $cancelStmt = $pdo->prepare("
                            UPDATE reservations 
                            SET status = 'cancelled', rejection_reason = 'Auto-cancelled due to emergency maintenance/calibration blackout.' 
                            WHERE reservation_id = ?
                        ");

                        foreach ($conflictedBookings as $cb) {
                            $cancelStmt->execute([$cb['reservation_id']]);
                            $cancelledCount++;

                            createNotification(
                                (int)$cb['user_id'],
                                'Reservation Cancelled - Maintenance Blackout',
                                sprintf(
                                    'Your reservation #%d was automatically cancelled due to scheduled %s servicing (%s to %s).',
                                    $cb['reservation_id'],
                                    str_replace('_', ' ', $maintType),
                                    formatDateTime($startFormatted),
                                    formatDateTime($endFormatted)
                                ),
                                'blackout_cancelled'
                            );
                        }
                    }

                    // 4. Update equipment operational state to 'maintenance'
                    $updEq = $pdo->prepare("
                        UPDATE equipment 
                        SET status = 'maintenance', 
                            maintenance_note = ? 
                        WHERE equipment_id = ?
                    ");
                    $updEq->execute([
                        sprintf('%s: %s (Until %s)', ucwords(str_replace('_', ' ', $maintType)), $notes, formatDate($endFormatted)),
                        $equipmentId
                    ]);

                    $pdo->commit();

                    setFlash(
                        'success', 
                        sprintf('Maintenance blackout logged successfully! %d overlapping reservation(s) were automatically cancelled and researchers notified.', $cancelledCount)
                    );
                    header('Location: maintenance.php');
                    exit;

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMessage = 'Database Transaction Error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch all equipment for selector
$equipList = $pdo->query("SELECT equipment_id, equipment_name, lab_location, status FROM equipment ORDER BY equipment_name ASC")->fetchAll();

// Fetch recent maintenance logs
$logsStmt = $pdo->query("
    SELECT m.*, e.equipment_name, e.lab_location, u.full_name AS technician_name
    FROM maintenance_logs m
    JOIN equipment e ON m.equipment_id = e.equipment_id
    JOIN users u ON m.technician_id = u.user_id
    ORDER BY m.start_datetime DESC
    LIMIT 15
");
$recentLogs = $logsStmt->fetchAll();
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.6rem;">Preventative Maintenance &amp; Servicing Blackouts</h1>
        <p style="color:var(--text-muted); font-size:0.95rem;">
            Schedule calibrations or declare emergency repair blackouts with automated conflict cancellation (UC-09).
        </p>
    </div>
</div>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-error" style="margin-bottom: 1.5rem;">
        <span class="alert-icon">✕</span>
        <span><?= sanitize($errorMessage) ?></span>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1.3fr; gap: 1.5rem; align-items: flex-start;">
    <!-- Maintenance Logging Form (UC-09) -->
    <section class="card">
        <div class="card-header" style="background:#fef2f2; border-color:#fecaca;">
            <h2 class="card-title" style="color:#991b1b;">🛠️ Declare Maintenance Window</h2>
        </div>
        <div class="card-body">
            <form action="maintenance.php" method="POST">
                <?= csrfField() ?>

                <div class="form-group">
                    <label for="equipment_id" class="form-label">Target Apparatus: *</label>
                    <select id="equipment_id" name="equipment_id" class="form-select" required>
                        <option value="">-- Select Equipment --</option>
                        <?php foreach ($equipList as $eq): ?>
                            <option value="<?= (int)$eq['equipment_id'] ?>">
                                <?= sanitize($eq['equipment_name']) ?> (<?= sanitize($eq['lab_location']) ?>) - [<?= strtoupper($eq['status']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="start_datetime" class="form-label">Blackout Start: *</label>
                        <input type="datetime-local" id="start_datetime" name="start_datetime" class="form-control" required value="<?= date('Y-m-d') ?>T08:00">
                    </div>

                    <div class="form-group">
                        <label for="end_datetime" class="form-label">Expected Completion: *</label>
                        <input type="datetime-local" id="end_datetime" name="end_datetime" class="form-control" required value="<?= date('Y-m-d', strtotime('+2 days')) ?>T18:00">
                    </div>
                </div>

                <div class="form-group">
                    <label for="maintenance_type" class="form-label">Maintenance Classification: *</label>
                    <select id="maintenance_type" name="maintenance_type" class="form-select">
                        <option value="routine_calibration">Routine Calibration &amp; Optical Alignment</option>
                        <option value="preventative_service">Preventative Service &amp; Cryo Replenishment</option>
                        <option value="emergency_repair">Emergency Hardware Breakdown Repair</option>
                        <option value="consumable_replacement">Consumables / Filament Replacement</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="servicing_notes" class="form-label">Servicing Details &amp; Fault Description: *</label>
                    <textarea id="servicing_notes" name="servicing_notes" rows="3" class="form-control" placeholder="Describe diagnosis, replaced parts, or calibration standards..." required></textarea>
                </div>

                <div style="background:#fef2f2; border:1px solid #fecaca; padding:0.75rem; border-radius:6px; margin-bottom:1.25rem; font-size:0.8rem; color:#991b1b;">
                    <strong>Notice:</strong> Committing this blackout will automatically cancel any conflicting student reservations and immediately notify the affected researchers.
                </div>

                <button type="submit" class="btn btn-danger" style="width:100%;">
                    <span>🔒</span>
                    <span>Commit Maintenance Blackout</span>
                </button>
            </form>
        </div>
    </section>

    <!-- Recent Servicing Logs -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Maintenance &amp; Servicing Log History</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($recentLogs)): ?>
                <p style="padding:2rem; text-align:center; color:var(--text-muted);">No recorded maintenance blackouts found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Apparatus</th>
                                <th>Category</th>
                                <th>Service Window</th>
                                <th>Technician</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td>
                                        <strong><?= sanitize($log['equipment_name']) ?></strong><br>
                                        <small style="color:var(--text-muted);"><?= sanitize($log['servicing_notes']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-warning" style="font-size:0.65rem;">
                                            <?= ucwords(str_replace('_', ' ', $log['maintenance_type'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small>
                                            <?= formatDate($log['start_datetime']) ?> &ndash; <?= formatDate($log['end_datetime']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small><?= sanitize($log['technician_name']) ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
