<?php
/**
 * Screen 03: Reservation Request & 24-Hour Slot Picker View
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

$pageTitle = 'Booking Engine';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$pdo = getDBConnection();
$currentUser = getCurrentUser();

$equipmentId = (int)($_GET['id'] ?? 0);
if ($equipmentId <= 0) {
    setFlash('error', 'Invalid equipment selected.');
    header('Location: catalog.php');
    exit;
}

// Fetch equipment record
$stmt = $pdo->prepare("SELECT * FROM equipment WHERE equipment_id = ?");
$stmt->execute([$equipmentId]);
$equipment = $stmt->fetch();

if (!$equipment) {
    setFlash('error', 'The requested apparatus does not exist.');
    header('Location: catalog.php');
    exit;
}

if ($equipment['status'] === 'decommissioned') {
    setFlash('error', 'This equipment has been permanently decommissioned.');
    header('Location: catalog.php');
    exit;
}

// Selected Date for 24-hour visual availability grid (default to today or specified date)
$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Fetch existing bookings for this equipment on the selected date
$dayStart = $selectedDate . ' 00:00:00';
$dayEnd   = $selectedDate . ' 23:59:59';

$resStmt = $pdo->prepare("
    SELECT reservation_id, start_time, end_time, status
    FROM reservations
    WHERE equipment_id = ?
      AND status IN ('pending', 'confirmed', 'checked_out')
      AND (start_time <= ? AND end_time >= ?)
");
$resStmt->execute([$equipmentId, $dayEnd, $dayStart]);
$existingBookings = $resStmt->fetchAll();

// Fetch maintenance blackouts on selected date
$maintStmt = $pdo->prepare("
    SELECT log_id, start_datetime, end_datetime, maintenance_type
    FROM maintenance_logs
    WHERE equipment_id = ?
      AND (start_datetime <= ? AND end_datetime >= ?)
");
$maintStmt->execute([$equipmentId, $dayEnd, $dayStart]);
$existingMaints = $maintStmt->fetchAll();

// Handle Form Submission
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $errorMessage = 'Security Token Error. Please resubmit the reservation.';
    } else {
        $startTimeRaw = trim((string)($_POST['start_time'] ?? ''));
        $endTimeRaw   = trim((string)($_POST['end_time'] ?? ''));
        $purpose      = trim((string)($_POST['purpose'] ?? ''));
        $certVerified = isset($_POST['cert_verified']) ? 1 : 0;

        // Validation
        if (empty($startTimeRaw) || empty($endTimeRaw) || empty($purpose)) {
            $errorMessage = 'All reservation fields (Time Window and Research Purpose) are mandatory.';
        } else {
            $startTs = strtotime($startTimeRaw);
            $endTs   = strtotime($endTimeRaw);

            if (!$startTs || !$endTs) {
                $errorMessage = 'Invalid datetime format submitted.';
            } elseif ($endTs <= $startTs) {
                $errorMessage = 'End time must be after the requested start time.';
            } elseif (($endTs - $startTs) > (4 * 3600)) {
                $errorMessage = 'Policy Violation: Maximum continuous reservation time is 4.0 hours per session.';
            } elseif ($equipment['status'] === 'maintenance') {
                $errorMessage = 'Cannot reserve: Equipment is declared under active maintenance.';
            } elseif (!$certVerified && !hasCertClearance($currentUser['cert_tier'], $equipment['req_cert_tier'])) {
                $errorMessage = 'Safety Certification Verification required for Tier ' . $equipment['req_cert_tier'] . ' apparatus.';
            } else {
                $startFormatted = date('Y-m-d H:i:s', $startTs);
                $endFormatted   = date('Y-m-d H:i:s', $endTs);

                // Transactional Collision Check (InnoDB ACID compliance)
                $pdo->beginTransaction();

                try {
                    // Check reservation collision
                    $collision = detectReservationCollision($pdo, $equipmentId, $startFormatted, $endFormatted);
                    $maintCollision = detectMaintenanceCollision($pdo, $equipmentId, $startFormatted, $endFormatted);

                    if ($collision) {
                        $pdo->rollBack();
                        $errorMessage = 'Booking Collision Detected: Another confirmed or pending reservation overlaps with your selected time window.';
                    } elseif ($maintCollision) {
                        $pdo->rollBack();
                        $errorMessage = 'Maintenance Conflict: The requested window intersects a scheduled calibration/repair blackout.';
                    } else {
                        // Determine initial status based on requires_approval
                        $initialStatus = ($equipment['requires_approval'] == 1) ? 'pending' : 'confirmed';

                        $insertRes = $pdo->prepare("
                            INSERT INTO reservations (
                                equipment_id, user_id, start_time, end_time, purpose, cert_verified, status, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $insertRes->execute([
                            $equipmentId,
                            $currentUser['user_id'],
                            $startFormatted,
                            $endFormatted,
                            $purpose,
                            $certVerified,
                            $initialStatus
                        ]);

                        $reservationId = (int)$pdo->lastInsertId();

                        // Commit transaction
                        $pdo->commit();

                        // Create In-App Notification
                        $notifMsg = sprintf(
                            'Your reservation #%d for %s from %s to %s has been registered with status: %s.',
                            $reservationId,
                            $equipment['equipment_name'],
                            formatDateTime($startFormatted),
                            formatDateTime($endFormatted),
                            strtoupper($initialStatus)
                        );
                        createNotification((int)$currentUser['user_id'], 'Reservation Submitted', $notifMsg, 'system');

                        setFlash('success', 'Reservation request #' . $reservationId . ' successfully submitted!');
                        header('Location: my_bookings.php');
                        exit;
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMessage = 'System Exception: ' . $e->getMessage();
                }
            }
        }
    }
}
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem;">
    <div>
        <span style="font-size:0.85rem; color:#2563eb; font-weight:700; text-transform:uppercase;">
            🔬 LERS • BOOKING ENGINE
        </span>
        <h1 style="font-size:1.6rem; margin-top:0.2rem;">
            <?= sanitize($equipment['equipment_name']) ?> 
            <span style="font-size:1rem; font-weight:400; color:var(--text-muted);">(<?= sanitize($equipment['lab_location']) ?>)</span>
        </h1>
    </div>
    <div>
        <a href="catalog.php" class="btn btn-secondary btn-sm">&larr; Back to Catalog</a>
    </div>
</div>

<?php if (!empty($errorMessage)): ?>
    <div class="alert alert-error" style="margin-bottom: 1.5rem;">
        <span class="alert-icon">✕</span>
        <span><?= sanitize($errorMessage) ?></span>
    </div>
<?php endif; ?>

<!-- Section 1: Availability Grid (Screen 03) -->
<section class="availability-grid-container">
    <div class="grid-date-header">
        <h2 style="font-size: 1.15rem; color: var(--primary-navy);">
            1. SELECT DATE &amp; 24-HOUR AVAILABILITY GRID
        </h2>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <a href="reserve.php?id=<?= $equipmentId ?>&date=<?= $prevDate ?>" class="btn btn-secondary btn-sm">&laquo; Prev</a>
            <input 
                type="date" 
                value="<?= $selectedDate ?>" 
                class="form-control" 
                style="width: 160px; padding: 0.35rem 0.5rem; font-size: 0.9rem;"
                onchange="window.location.href='reserve.php?id=<?= $equipmentId ?>&date=' + this.value;"
            >
            <a href="reserve.php?id=<?= $equipmentId ?>&date=<?= $nextDate ?>" class="btn btn-secondary btn-sm">Next &raquo;</a>
        </div>
    </div>

    <!-- 24-Hour Time Slot Cells -->
    <div class="slot-grid">
        <?php 
        // Operating hours: 08:00 to 19:00
        $hours = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19];
        foreach ($hours as $hour):
            $slotStartTs = strtotime(sprintf('%s %02d:00:00', $selectedDate, $hour));
            $slotEndTs   = strtotime(sprintf('%s %02d:00:00', $selectedDate, $hour + 1));
            $timeLabel   = date('h:i A', $slotStartTs);

            // Determine if slot overlaps with maintenance
            $isMaint = false;
            foreach ($existingMaints as $m) {
                $mStart = strtotime($m['start_datetime']);
                $mEnd   = strtotime($m['end_datetime']);
                if ($slotStartTs < $mEnd && $slotEndTs > $mStart) {
                    $isMaint = true;
                    break;
                }
            }

            // Determine if slot overlaps with booking
            $isBooked = false;
            if (!$isMaint) {
                foreach ($existingBookings as $b) {
                    $bStart = strtotime($b['start_time']);
                    $bEnd   = strtotime($b['end_time']);
                    if ($slotStartTs < $bEnd && $slotEndTs > $bStart) {
                        $isBooked = true;
                        break;
                    }
                }
            }

            $isClosed = ($hour >= 18); // Lab closes at 18:00

            if ($isMaint) {
                $cellClass = 'maintenance';
                $statusText = 'Maint';
            } elseif ($isBooked) {
                $cellClass = 'booked';
                $statusText = 'Booked';
            } elseif ($isClosed) {
                $cellClass = 'booked';
                $statusText = 'Closed';
            } else {
                $cellClass = 'free';
                $statusText = 'Free';
            }
        ?>
            <div 
                class="slot-cell <?= $cellClass ?>" 
                data-hour="<?= $hour ?>" 
                data-date="<?= $selectedDate ?>"
                title="<?= ($cellClass === 'free') ? 'Click to select ' . $timeLabel : $statusText ?>"
            >
                <span class="slot-time"><?= date('H:00', $slotStartTs) ?></span>
                <span class="slot-status"><?= $statusText ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="display: flex; gap: 1.5rem; margin-top: 1rem; font-size: 0.8rem; color: var(--text-muted);">
        <span style="display:flex;align-items:center;gap:0.35rem;"><span style="width:12px;height:12px;background:#86efac;border-radius:2px;display:inline-block;"></span> Free (Clickable)</span>
        <span style="display:flex;align-items:center;gap:0.35rem;"><span style="width:12px;height:12px;background:#cbd5e1;border-radius:2px;display:inline-block;"></span> Booked / Closed</span>
        <span style="display:flex;align-items:center;gap:0.35rem;"><span style="width:12px;height:12px;background:#fca5a5;border-radius:2px;display:inline-block;"></span> Scheduled Maintenance</span>
        <span style="display:flex;align-items:center;gap:0.35rem;"><span style="width:12px;height:12px;background:#93c5fd;border-radius:2px;display:inline-block;"></span> Active Selection</span>
    </div>
</section>

<!-- Section 2: Time Window & Research Justification (Screen 03) -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">2. TIME WINDOW &amp; RESEARCH JUSTIFICATION</h2>
        <span id="duration_calc" style="font-size:0.85rem; font-weight:700; color:#15803d;">Continuous 2.0h slot recommended</span>
    </div>
    <div class="card-body">
        <form action="reserve.php?id=<?= $equipmentId ?>&date=<?= $selectedDate ?>" method="POST">
            <?= csrfField() ?>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                <div class="form-group">
                    <label for="start_time" class="form-label">Start Time: *</label>
                    <input 
                        type="datetime-local" 
                        id="start_time" 
                        name="start_time" 
                        class="form-control" 
                        value="<?= $selectedDate ?>T10:00" 
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="end_time" class="form-label">End Time (Max 4.0 Hours): *</label>
                    <input 
                        type="datetime-local" 
                        id="end_time" 
                        name="end_time" 
                        class="form-control" 
                        value="<?= $selectedDate ?>T12:00" 
                        required
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="purpose" class="form-label">Research Purpose / Thesis Description: *</label>
                <textarea 
                    id="purpose" 
                    name="purpose" 
                    rows="3" 
                    class="form-control" 
                    placeholder="Provide research justification, sample composition, and grant/thesis chapter mapping..." 
                    required
                >Gold-nanoparticle thin film substrate characterization for MSc thesis chapter 4.</textarea>
            </div>

            <div style="margin: 1.25rem 0; padding: 0.85rem; background: #f8fafc; border: 1px solid var(--border-subtle); border-radius: 6px;">
                <label class="remember-checkbox" style="align-items: flex-start;">
                    <input type="checkbox" name="cert_verified" value="1" checked required style="margin-top: 3px;">
                    <span style="font-size: 0.875rem; color: #334155;">
                        I verify that I hold an active <strong><?= sanitize($equipment['req_cert_tier']) ?></strong> Safety Certificate for this device and adhere to University Laboratory Guidelines.
                    </span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <a href="catalog.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <span>Submit Reservation</span>
                </button>
            </div>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
