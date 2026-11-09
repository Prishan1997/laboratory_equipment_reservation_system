<?php
/**
 * Screen 05: Supervisor Queue, Pending Approvals & Physical Check-Out/Return Lifecycle
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

$pageTitle = 'Supervisor Approval Queue';
require_once __DIR__ . '/includes/header.php';
requireRole(['supervisor', 'admin']);

$pdo = getDBConnection();
$currentUser = getCurrentUser();
$supervisorId = (int)$currentUser['user_id'];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (validateCSRFToken($token)) {
        $action = trim((string)($_POST['action'] ?? ''));
        $reservationId = (int)($_POST['reservation_id'] ?? 0);

        // Fetch reservation with user details
        $resStmt = $pdo->prepare("
            SELECT r.*, e.equipment_name, u.full_name, u.email
            FROM reservations r
            JOIN equipment e ON r.equipment_id = e.equipment_id
            JOIN users u ON r.user_id = u.user_id
            WHERE r.reservation_id = ?
        ");
        $resStmt->execute([$reservationId]);
        $targetRes = $resStmt->fetch();

        if ($targetRes) {
            // 1. APPROVE
            if ($action === 'approve') {
                $upd = $pdo->prepare("
                    UPDATE reservations 
                    SET status = 'confirmed', reviewed_by = ? 
                    WHERE reservation_id = ?
                ");
                $upd->execute([$supervisorId, $reservationId]);

                createNotification(
                    (int)$targetRes['user_id'],
                    'Reservation Approved',
                    sprintf('Your reservation #%d for %s has been confirmed by %s.', $reservationId, $targetRes['equipment_name'], $currentUser['full_name']),
                    'booking_confirmed'
                );

                setFlash('success', 'Reservation #' . $reservationId . ' confirmed successfully.');
            }
            // 2. REJECT
            elseif ($action === 'reject') {
                $reason = trim((string)($_POST['rejection_reason'] ?? ''));
                if (empty($reason)) {
                    $reason = 'Does not meet departmental safety or justification criteria.';
                }

                $upd = $pdo->prepare("
                    UPDATE reservations 
                    SET status = 'rejected', reviewed_by = ?, rejection_reason = ? 
                    WHERE reservation_id = ?
                ");
                $upd->execute([$supervisorId, $reason, $reservationId]);

                createNotification(
                    (int)$targetRes['user_id'],
                    'Reservation Rejected',
                    sprintf('Reservation #%d for %s was rejected: %s', $reservationId, $targetRes['equipment_name'], $reason),
                    'booking_rejected'
                );

                setFlash('warning', 'Reservation #' . $reservationId . ' rejected.');
            }
            // 3. PHYSICAL CHECK-OUT
            elseif ($action === 'checkout') {
                $upd = $pdo->prepare("
                    UPDATE reservations 
                    SET status = 'checked_out', checked_out_at = NOW() 
                    WHERE reservation_id = ?
                ");
                $upd->execute([$reservationId]);

                createNotification(
                    (int)$targetRes['user_id'],
                    'Equipment Possessed (Checked-Out)',
                    sprintf('Physical possession of %s was officially registered at %s.', $targetRes['equipment_name'], date('H:i')),
                    'system'
                );

                setFlash('info', 'Apparatus physical check-out logged for reservation #' . $reservationId);
            }
            // 4. PHYSICAL RETURN & CONDITION LOG
            elseif ($action === 'return') {
                $condition = trim((string)($_POST['return_condition'] ?? 'operational'));
                $consumables = trim((string)($_POST['consumable_notes'] ?? ''));

                $upd = $pdo->prepare("
                    UPDATE reservations 
                    SET status = 'completed', 
                        returned_at = NOW(), 
                        return_condition = ?, 
                        consumable_notes = ? 
                    WHERE reservation_id = ?
                ");
                $upd->execute([$condition, $consumables, $reservationId]);

                // If defective, update equipment status to maintenance
                if ($condition === 'defective') {
                    $pdo->prepare("
                        UPDATE equipment 
                        SET status = 'maintenance', 
                            maintenance_note = 'Defect reported during return from Res #' || ? 
                        WHERE equipment_id = ?
                    ")->execute([$reservationId, $targetRes['equipment_id']]);
                }

                createNotification(
                    (int)$targetRes['user_id'],
                    'Equipment Successfully Returned',
                    sprintf('Physical return of %s concluded. Assessed Condition: %s.', $targetRes['equipment_name'], strtoupper($condition)),
                    'system'
                );

                setFlash('success', 'Equipment return processed and condition report committed.');
            }
        }
    }
    header('Location: approvals.php');
    exit;
}

// Fetch Pending Reservations (UC-08)
$pendingStmt = $pdo->query("
    SELECT r.*, e.equipment_name, e.model_number, e.lab_location, e.req_cert_tier,
           u.full_name, u.id_number, u.email, u.cert_tier AS user_tier, u.department
    FROM reservations r
    JOIN equipment e ON r.equipment_id = e.equipment_id
    JOIN users u ON r.user_id = u.user_id
    WHERE r.status = 'pending'
    ORDER BY r.created_at ASC
");
$pendingReservations = $pendingStmt->fetchAll();

// Fetch Active / Confirmed Bookings for Check-out & Return Lifecycle (Section 5.2)
$activeStmt = $pdo->query("
    SELECT r.*, e.equipment_name, e.lab_location,
           u.full_name, u.id_number, u.email
    FROM reservations r
    JOIN equipment e ON r.equipment_id = e.equipment_id
    JOIN users u ON r.user_id = u.user_id
    WHERE r.status IN ('confirmed', 'checked_out')
    ORDER BY r.start_time ASC
");
$activeReservations = $activeStmt->fetchAll();
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.6rem;">Supervisor Approvals &amp; Physical Custody Tracking</h1>
        <p style="color:var(--text-muted); font-size:0.95rem;">
            Review student reservation requests (UC-08) and oversee physical equipment check-out &amp; return condition logs (Section 5.2).
        </p>
    </div>
</div>

<!-- Section 1: Pending Approvals Queue (UC-08) -->
<section class="card" style="margin-bottom: 2rem;">
    <div class="card-header" style="background:#fffbeb; border-color:#fde68a;">
        <h2 class="card-title" style="color:#b45309;">
            ⏳ Pending Supervisory Approvals Queue (<?= count($pendingReservations) ?>)
        </h2>
        <span style="font-size:0.85rem; color:#78350f;">Requires Safety Clearance Verification</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($pendingReservations)): ?>
            <div style="padding: 2.5rem; text-align: center; color: var(--text-muted);">
                <p>✓ All student and researcher reservation requests have been processed.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Target Apparatus</th>
                            <th>Requested Window</th>
                            <th>Research Justification</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingReservations as $p): ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($p['full_name']) ?></strong><br>
                                    <small style="color:var(--text-muted);">ID: <?= sanitize($p['id_number']) ?></small><br>
                                    <span class="badge badge-info" style="font-size:0.65rem;">User: <?= sanitize($p['user_tier']) ?></span>
                                </td>
                                <td>
                                    <strong><?= sanitize($p['equipment_name']) ?></strong><br>
                                    <small style="color:var(--text-muted);"><?= sanitize($p['lab_location']) ?></small><br>
                                    <span class="badge badge-warning" style="font-size:0.65rem;">Req: <?= sanitize($p['req_cert_tier']) ?></span>
                                </td>
                                <td>
                                    <strong><?= formatDate($p['start_time']) ?></strong><br>
                                    <small style="color:var(--text-muted);">
                                        <?= formatTime($p['start_time']) ?> &ndash; <?= formatTime($p['end_time']) ?>
                                    </small>
                                </td>
                                <td style="max-width: 280px; font-size: 0.85rem; color: #334155;">
                                    <?= sanitize($p['purpose']) ?>
                                </td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:0.4rem;">
                                        <!-- Approve Form -->
                                        <form action="approvals.php" method="POST" style="margin:0;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="reservation_id" value="<?= (int)$p['reservation_id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm" style="width:100%;">
                                                ✓ Approve
                                            </button>
                                        </form>

                                        <!-- Reject Form with Reason -->
                                        <button 
                                            type="button" 
                                            class="btn btn-danger btn-sm" 
                                            onclick="const reason = prompt('Please enter the mandatory rejection rationale:'); if (reason) { document.getElementById('reject_reason_<?= $p['reservation_id'] ?>').value = reason; document.getElementById('reject_form_<?= $p['reservation_id'] ?>').submit(); }"
                                        >
                                            ✕ Reject
                                        </button>
                                        <form id="reject_form_<?= $p['reservation_id'] ?>" action="approvals.php" method="POST" style="display:none;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="reservation_id" value="<?= (int)$p['reservation_id'] ?>">
                                            <input type="hidden" id="reject_reason_<?= $p['reservation_id'] ?>" name="rejection_reason" value="">
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Section 2: Physical Check-Out & Return Management (Section 5.2) -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">📦 Physical Equipment Check-Out &amp; Return Hand-off</h2>
        <span style="font-size:0.85rem; color:var(--text-muted);">Audit Trail for Custody &amp; Condition</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($activeReservations)): ?>
            <div style="padding: 2.5rem; text-align: center; color: var(--text-muted);">
                <p>No confirmed reservations awaiting check-out or active custody hand-offs.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Res #</th>
                            <th>Researcher</th>
                            <th>Instrument &amp; Location</th>
                            <th>Scheduled Slot</th>
                            <th>Current State</th>
                            <th>Physical Hand-off Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeReservations as $act): ?>
                            <tr>
                                <td><strong>#<?= (int)$act['reservation_id'] ?></strong></td>
                                <td>
                                    <strong><?= sanitize($act['full_name']) ?></strong><br>
                                    <small style="color:var(--text-muted);"><?= sanitize($act['id_number']) ?></small>
                                </td>
                                <td>
                                    <strong><?= sanitize($act['equipment_name']) ?></strong><br>
                                    <small style="color:var(--text-muted);"><?= sanitize($act['lab_location']) ?></small>
                                </td>
                                <td>
                                    <?= formatDate($act['start_time']) ?><br>
                                    <small style="color:var(--text-muted);"><?= formatTime($act['start_time']) ?> &ndash; <?= formatTime($act['end_time']) ?></small>
                                </td>
                                <td><?= renderStatusBadge($act['status']) ?></td>
                                <td>
                                    <?php if ($act['status'] === 'confirmed'): ?>
                                        <!-- Log Check-Out -->
                                        <form action="approvals.php" method="POST" onsubmit="return confirm('Confirm physical apparatus hand-off to researcher?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="checkout">
                                            <input type="hidden" name="reservation_id" value="<?= (int)$act['reservation_id'] ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                🔑 Log Physical Check-Out
                                            </button>
                                        </form>
                                    <?php elseif ($act['status'] === 'checked_out'): ?>
                                        <!-- Log Return & Condition -->
                                        <form action="approvals.php" method="POST" style="display:flex; gap:0.4rem; align-items:center;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="return">
                                            <input type="hidden" name="reservation_id" value="<?= (int)$act['reservation_id'] ?>">
                                            <select name="return_condition" class="form-select" style="padding:0.25rem 0.5rem; font-size:0.8rem; width:auto;">
                                                <option value="operational">Operational</option>
                                                <option value="requires_cleaning">Cleaning Req.</option>
                                                <option value="minor_wear">Minor Wear</option>
                                                <option value="defective">Defective / Damaged</option>
                                            </select>
                                            <button type="submit" class="btn btn-success btn-sm">
                                                📥 Process Return
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
