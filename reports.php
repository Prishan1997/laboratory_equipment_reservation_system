<?php
/**
 * Screen 07: Administrative Utilization Reporting & RFC 4180 CSV Export (FR-6.1)
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['admin', 'supervisor']);

$pdo = getDBConnection();

$startDate = trim((string)($_GET['start_date'] ?? date('Y-m-01')));
$endDate   = trim((string)($_GET['end_date'] ?? date('Y-m-t')));
$equipFilter = (int)($_GET['equipment_id'] ?? 0);

// Check if CSV export is requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvSql = "
        SELECT 
            r.reservation_id,
            e.equipment_name,
            e.model_number,
            e.lab_location,
            u.full_name AS researcher_name,
            u.id_number AS researcher_id,
            u.department,
            r.start_time,
            r.end_time,
            ROUND(TIMESTAMPDIFF(MINUTE, r.start_time, r.end_time) / 60.0, 2) AS booked_hours,
            r.status,
            r.return_condition,
            rev.full_name AS approved_by,
            r.created_at
        FROM reservations r
        JOIN equipment e ON r.equipment_id = e.equipment_id
        JOIN users u ON r.user_id = u.user_id
        LEFT JOIN users rev ON r.reviewed_by = rev.user_id
        WHERE DATE(r.start_time) >= :start_date AND DATE(r.start_time) <= :end_date
    ";
    $csvParams = [
        ':start_date' => $startDate,
        ':end_date'   => $endDate
    ];

    if ($equipFilter > 0) {
        $csvSql .= " AND r.equipment_id = :equip_id";
        $csvParams[':equip_id'] = $equipFilter;
    }

    $csvSql .= " ORDER BY r.start_time DESC";

    $stmt = $pdo->prepare($csvSql);
    $stmt->execute($csvParams);
    $rows = $stmt->fetchAll();

    // Stream RFC 4180 CSV Headers
    $filename = sprintf('lers_utilization_report_%s_to_%s.csv', $startDate, $endDate);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel compatibility
    fputs($output, "\xEF\xBB\xBF");

    // CSV Headers
    fputcsv($output, [
        'Reservation Ref ID',
        'Equipment Name',
        'Model Number',
        'Laboratory Room',
        'Researcher Name',
        'Student/Staff ID',
        'Academic Department',
        'Slot Start Time',
        'Slot End Time',
        'Booked Duration (Hours)',
        'Reservation Status',
        'Physical Return Condition',
        'Reviewing Supervisor',
        'Submission Timestamp'
    ]);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['reservation_id'],
            $row['equipment_name'],
            $row['model_number'],
            $row['lab_location'],
            $row['researcher_name'],
            $row['researcher_id'],
            $row['department'],
            $row['start_time'],
            $row['end_time'],
            $row['booked_hours'],
            strtoupper($row['status']),
            $row['return_condition'] ? ucwords(str_replace('_', ' ', $row['return_condition'])) : 'N/A',
            $row['approved_by'] ?? 'Auto/Pending',
            $row['created_at']
        ]);
    }

    fclose($output);
    exit;
}

$pageTitle = 'Utilization & CSV Reports';
require_once __DIR__ . '/includes/header.php';

// Aggregate Analytics Query
$statsSql = "
    SELECT 
        COUNT(*) AS total_reservations,
        SUM(CASE WHEN status IN ('confirmed', 'completed', 'checked_out') THEN 1 ELSE 0 END) AS valid_bookings,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_sessions,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_sessions,
        COALESCE(SUM(CASE WHEN status IN ('confirmed', 'completed', 'checked_out') THEN TIMESTAMPDIFF(MINUTE, start_time, end_time) ELSE 0 END) / 60.0, 0) AS total_hours
    FROM reservations
    WHERE DATE(start_time) >= :start_date AND DATE(start_time) <= :end_date
";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
$stats = $statsStmt->fetch();

// Utilization by Apparatus
$equipUtilSql = "
    SELECT 
        e.equipment_id,
        e.equipment_name,
        e.lab_location,
        e.status AS current_status,
        COUNT(r.reservation_id) AS total_requests,
        COALESCE(SUM(CASE WHEN r.status IN ('confirmed', 'completed', 'checked_out') THEN TIMESTAMPDIFF(MINUTE, r.start_time, r.end_time) ELSE 0 END) / 60.0, 0) AS operational_hours
    FROM equipment e
    LEFT JOIN reservations r ON e.equipment_id = r.equipment_id AND DATE(r.start_time) >= :start_date AND DATE(r.start_time) <= :end_date
    GROUP BY e.equipment_id, e.equipment_name, e.lab_location, e.status
    ORDER BY operational_hours DESC
";
$utilStmt = $pdo->prepare($equipUtilSql);
$utilStmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
$equipStats = $utilStmt->fetchAll();

// Apparatus List for Filter
$allEquip = $pdo->query("SELECT equipment_id, equipment_name FROM equipment ORDER BY equipment_name ASC")->fetchAll();
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.6rem;">Operational Utilization &amp; Audit Reports</h1>
        <p style="color:var(--text-muted); font-size:0.95rem;">
            Aggregate instrumentation usage analytics and stream RFC-4180 CSV exports for institutional audit (FR-6.1).
        </p>
    </div>
    <div>
        <a href="reports.php?start_date=<?= urlencode($startDate) ?>&end_date=<?= urlencode($endDate) ?>&equipment_id=<?= $equipFilter ?>&export=csv" class="btn btn-success">
            <span>📥</span>
            <span>Export RFC-4180 CSV</span>
        </a>
    </div>
</div>

<!-- Filter Bar -->
<section class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <form action="reports.php" method="GET" style="display:grid; grid-template-columns: 1fr 1fr 1.5fr auto; gap: 1rem; align-items: flex-end;">
            <div>
                <label for="start_date" class="form-label">Report Range Start:</label>
                <input type="date" id="start_date" name="start_date" class="form-control" value="<?= sanitize($startDate) ?>" required>
            </div>
            <div>
                <label for="end_date" class="form-label">Report Range End:</label>
                <input type="date" id="end_date" name="end_date" class="form-control" value="<?= sanitize($endDate) ?>" required>
            </div>
            <div>
                <label for="equipment_id" class="form-label">Filter Apparatus:</label>
                <select id="equipment_id" name="equipment_id" class="form-select">
                    <option value="0">All Apparatuses</option>
                    <?php foreach ($allEquip as $eq): ?>
                        <option value="<?= (int)$eq['equipment_id'] ?>" <?= ($equipFilter === (int)$eq['equipment_id']) ? 'selected' : '' ?>>
                            <?= sanitize($eq['equipment_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Run Query</button>
            </div>
        </form>
    </div>
</section>

<!-- Summary Metric Cards -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
    <div class="card" style="padding:1.25rem; margin-bottom:0;">
        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Total Booked Hours</span>
        <h2 style="font-size:1.8rem; margin-top:0.25rem; color:#1e40af;"><?= number_format((float)$stats['total_hours'], 1) ?> hrs</h2>
    </div>
    <div class="card" style="padding:1.25rem; margin-bottom:0;">
        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Valid Bookings</span>
        <h2 style="font-size:1.8rem; margin-top:0.25rem; color:#16a34a;"><?= (int)$stats['valid_bookings'] ?></h2>
    </div>
    <div class="card" style="padding:1.25rem; margin-bottom:0;">
        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Completed Sessions</span>
        <h2 style="font-size:1.8rem; margin-top:0.25rem; color:#0284c7;"><?= (int)$stats['completed_sessions'] ?></h2>
    </div>
    <div class="card" style="padding:1.25rem; margin-bottom:0;">
        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Cancellations</span>
        <h2 style="font-size:1.8rem; margin-top:0.25rem; color:#dc2626;"><?= (int)$stats['cancelled_sessions'] ?></h2>
    </div>
</div>

<!-- Equipment Utilization Table -->
<section class="card">
    <div class="card-header">
        <h2 class="card-title">Apparatus Utilization Breakdown (<?= formatDate($startDate) ?> &ndash; <?= formatDate($endDate) ?>)</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Equipment Apparatus</th>
                        <th>Laboratory Location</th>
                        <th>Current Status</th>
                        <th>Total Requests</th>
                        <th>Accumulated Usage Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equipStats as $eq): ?>
                        <tr>
                            <td><strong><?= sanitize($eq['equipment_name']) ?></strong></td>
                            <td><?= sanitize($eq['lab_location']) ?></td>
                            <td><?= renderStatusBadge($eq['current_status']) ?></td>
                            <td><?= (int)$eq['total_requests'] ?></td>
                            <td>
                                <strong><?= number_format((float)$eq['operational_hours'], 1) ?> hrs</strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
