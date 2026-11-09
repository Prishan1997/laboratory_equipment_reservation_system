<?php
/**
 * Screen 04: User Dashboard, Reservation History & Notification Center
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

$pageTitle = 'My Bookings';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$pdo = getDBConnection();
$currentUser = getCurrentUser();
$userId = (int)$currentUser['user_id'];

// Handle Self-Service Cancellation Request (UC-03)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
    $token = $_POST['csrf_token'] ?? '';
    if (validateCSRFToken($token)) {
        $resId = (int)($_POST['reservation_id'] ?? 0);

        // Fetch reservation ensuring it belongs to current user
        $checkStmt = $pdo->prepare("SELECT * FROM reservations WHERE reservation_id = ? AND user_id = ?");
        $checkStmt->execute([$resId, $userId]);
        $res = $checkStmt->fetch();

        if ($res) {
            if (in_array($res['status'], ['pending', 'confirmed'], true)) {
                $cancelStmt = $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ?");
                $cancelStmt->execute([$resId]);

                createNotification(
                    $userId,
                    'Reservation Cancelled',
                    'Reservation #' . $resId . ' has been self-cancelled. The time window has been restored to the availability pool.',
                    'system'
                );

                setFlash('success', 'Reservation #' . $resId . ' was successfully cancelled.');
            } else {
                setFlash('warning', 'Only Pending or Confirmed reservations can be cancelled.');
            }
        }
    }
    header('Location: my_bookings.php');
    exit;
}

// Handle Mark All Notifications Read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifs_read') {
    $token = $_POST['csrf_token'] ?? '';
    if (validateCSRFToken($token)) {
        $updNotif = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $updNotif->execute([$userId]);
        setFlash('info', 'All notifications marked as read.');
    }
    header('Location: my_bookings.php');
    exit;
}

// Fetch user reservations
$resQuery = $pdo->prepare("
    SELECT r.*, e.equipment_name, e.model_number, e.lab_location, u.full_name AS reviewer_name
    FROM reservations r
    JOIN equipment e ON r.equipment_id = e.equipment_id
    LEFT JOIN users u ON r.reviewed_by = u.user_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
$resQuery->execute([$userId]);
$reservations = $resQuery->fetchAll();

// Fetch in-app notifications
$notifications = getRecentNotifications($userId, 10);
$unreadCount = getUnreadNotificationCount($userId);

// Calculate Stats
$totalCount = count($reservations);
$confirmedCount = count(array_filter($reservations, fn($r) => $r['status'] === 'confirmed'));
$pendingCount = count(array_filter($reservations, fn($r) => $r['status'] === 'pending'));
$completedCount = count(array_filter($reservations, fn($r) => in_array($r['status'], ['completed', 'checked_out'])));
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.6rem;">Researcher Dashboard &amp; Reservation History</h1>
        <p style="color:var(--text-muted); font-size:0.95rem;">
            Track your laboratory appointments, supervisory approval status, and in-app system notifications.
        </p>
    </div>
    <div>
        <a href="catalog.php" class="btn btn-primary btn-sm">+ New Reservation</a>
    </div>
</div>

<!-- Metrics Overview Cards -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
    <div class="card" style="padding:1.25rem; margin-bottom:0;">
        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Total Bookings</span>
        <h2 style="font-size:1.8rem; margin-top:0.25rem; color:#1e40af;"><?= $totalCount ?></h2>
    </div>
    <div class="card" style="padding:1.25rem; margin-bottom:0;">
        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Confirmed</span>
        <h2 style="font-size:1.8rem; margin-top:0.25rem; color:#16a34a;"><?= $confirmedCount ?></h2>
    </div>
    <div class="card" style="padding:1.25rem; margin-bottom:0;">
        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Pending Review</span>
        <h2 style="font-size:1.8rem; margin-top:0.25rem; color:#b45309;"><?= $pendingCount ?></h2>
    </div>
    <div class="card" style="padding:1.25rem; margin-bottom:0;">
        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Active / Completed</span>
        <h2 style="font-size:1.8rem; margin-top:0.25rem; color:#0284c7;"><?= $completedCount ?></h2>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; align-items: flex-start;">
    <!-- Left Column: Reservations Table -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Laboratory Equipment Reservations</h2>
            <span style="font-size:0.85rem; color:var(--text-muted);">Self-Service Management</span>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($reservations)): ?>
                <div style="padding: 2.5rem; text-align: center; color: var(--text-muted);">
                    <p>You have not placed any laboratory equipment reservations yet.</p>
                    <a href="catalog.php" class="btn btn-primary btn-sm" style="margin-top:0.75rem;">Browse Apparatus Catalog</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ref ID</th>
                                <th>Apparatus &amp; Lab</th>
                                <th>Scheduled Window</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $res): ?>
                                <tr>
                                    <td>
                                        <strong>#<?= (int)$res['reservation_id'] ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= sanitize($res['equipment_name']) ?></strong><br>
                                        <small style="color:var(--text-muted);"><?= sanitize($res['lab_location']) ?></small>
                                    </td>
                                    <td>
                                        <div><strong><?= formatDate($res['start_time']) ?></strong></div>
                                        <small style="color:var(--text-muted);">
                                            <?= formatTime($res['start_time']) ?> &ndash; <?= formatTime($res['end_time']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= renderStatusBadge($res['status']) ?>
                                        <?php if (!empty($res['rejection_reason'])): ?>
                                            <div style="font-size:0.75rem; color:#b91c1c; margin-top:0.25rem;">
                                                Reason: <?= sanitize($res['rejection_reason']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (in_array($res['status'], ['pending', 'confirmed'], true)): ?>
                                            <form action="my_bookings.php" method="POST" onsubmit="return confirm('Are you sure you want to cancel reservation #<?= $res['reservation_id'] ?>? This restores the slot to the general laboratory pool.');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="cancel_booking">
                                                <input type="hidden" name="reservation_id" value="<?= (int)$res['reservation_id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" style="padding:0.25rem 0.5rem;font-size:0.775rem;">
                                                    Cancel Slot
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span style="font-size:0.8rem; color:var(--text-muted);">&mdash;</span>
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

    <!-- Right Column: In-App Notification Center (Section 5.3) -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">In-App Notifications</h2>
            <?php if ($unreadCount > 0): ?>
                <form action="my_bookings.php" method="POST" style="margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="mark_notifs_read">
                    <button type="submit" class="btn btn-secondary btn-sm" style="font-size:0.75rem; padding:0.2rem 0.5rem;">
                        Mark Read
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 1rem;">
            <?php if (empty($notifications)): ?>
                <p style="font-size:0.875rem; color:var(--text-muted); text-align:center; padding:1.5rem 0;">
                    No recent notifications.
                </p>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:0.75rem;">
                    <?php foreach ($notifications as $n): ?>
                        <div style="padding:0.75rem; border-radius:6px; background:<?= $n['is_read'] ? '#f8fafc' : '#eff6ff' ?>; border-left:3px solid <?= $n['is_read'] ? '#cbd5e1' : '#2563eb' ?>;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.25rem;">
                                <strong style="font-size:0.85rem; color:#0f172a;"><?= sanitize($n['title']) ?></strong>
                                <span style="font-size:0.7rem; color:var(--text-muted);"><?= date('M d, H:i', strtotime($n['created_at'])) ?></span>
                            </div>
                            <p style="font-size:0.8rem; color:#334155; margin:0; line-height:1.35;">
                                <?= sanitize($n['message']) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
