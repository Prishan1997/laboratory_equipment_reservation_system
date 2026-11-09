<?php
/**
 * Screen 02: Equipment Catalog & Multi-Parameter Filter
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

$pageTitle = 'Equipment Catalog';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$pdo = getDBConnection();
$currentUser = getCurrentUser();

// Multi-Parameter Filter Inputs
$keyword  = trim((string)($_GET['keyword'] ?? ''));
$labRoom  = trim((string)($_GET['lab_location'] ?? ''));
$status   = trim((string)($_GET['status'] ?? ''));
$certTier = trim((string)($_GET['cert_tier'] ?? ''));

// Build Dynamic SQL Query
$sql = "SELECT * FROM equipment WHERE 1=1";
$params = [];

if ($keyword !== '') {
    $sql .= " AND (equipment_name LIKE :keyword OR description LIKE :keyword OR model_number LIKE :keyword)";
    $params[':keyword'] = '%' . $keyword . '%';
}

if ($labRoom !== '' && $labRoom !== 'all') {
    $sql .= " AND lab_location = :lab_location";
    $params[':lab_location'] = $labRoom;
}

if ($status !== '' && $status !== 'all') {
    $sql .= " AND status = :status";
    $params[':status'] = $status;
}

if ($certTier !== '' && $certTier !== 'all') {
    $sql .= " AND req_cert_tier = :cert_tier";
    $params[':cert_tier'] = $certTier;
}

$sql .= " ORDER BY status ASC, equipment_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$equipmentList = $stmt->fetchAll();

// Get unique lab locations for the dropdown
$labStmt = $pdo->query("SELECT DISTINCT lab_location FROM equipment ORDER BY lab_location ASC");
$allLabs = $labStmt->fetchAll(PDO::FETCH_COLUMN);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
    <div>
        <h1 style="font-size: 1.6rem;">Equipment Directory & Instrumentation Catalog</h1>
        <p style="color: var(--text-muted); font-size: 0.95rem;">
            Real-time operational apparatuses across Faculty of Science & Nanotechnology research suites.
        </p>
    </div>
    <div>
        <span class="user-badge" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">
            Your Clearance: <strong><?= sanitize($currentUser['cert_tier']) ?></strong>
        </span>
    </div>
</div>

<!-- Screen 02: Multi-Parameter Filter Bar -->
<section class="filter-bar">
    <form action="catalog.php" method="GET" class="filter-grid">
        <div>
            <label for="keyword" class="form-label">KEYWORD SEARCH</label>
            <input 
                type="text" 
                id="keyword" 
                name="keyword" 
                class="form-control" 
                placeholder="e.g. electron microscope, NMR, centrifuge" 
                value="<?= sanitize($keyword) ?>"
            >
        </div>

        <div>
            <label for="lab_location" class="form-label">LABORATORY</label>
            <select id="lab_location" name="lab_location" class="form-select">
                <option value="all">All Laboratory Rooms</option>
                <?php foreach ($allLabs as $lab): ?>
                    <option value="<?= sanitize($lab) ?>" <?= ($labRoom === $lab) ? 'selected' : '' ?>>
                        <?= sanitize($lab) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="status" class="form-label">STATUS</label>
            <select id="status" name="status" class="form-select">
                <option value="all">All Available</option>
                <option value="operational" <?= ($status === 'operational') ? 'selected' : '' ?>>Operational</option>
                <option value="maintenance" <?= ($status === 'maintenance') ? 'selected' : '' ?>>Under Maintenance</option>
                <option value="decommissioned" <?= ($status === 'decommissioned') ? 'selected' : '' ?>>Decommissioned</option>
            </select>
        </div>

        <div>
            <label for="cert_tier" class="form-label">CERT TIER</label>
            <select id="cert_tier" name="cert_tier" class="form-select">
                <option value="all">All Tiers</option>
                <option value="Tier 1" <?= ($certTier === 'Tier 1') ? 'selected' : '' ?>>Tier 1 (General)</option>
                <option value="Tier 2" <?= ($certTier === 'Tier 2') ? 'selected' : '' ?>>Tier 2 (Chemical/Bio)</option>
                <option value="Tier 3" <?= ($certTier === 'Tier 3') ? 'selected' : '' ?>>Tier 3 (Laser/Bio)</option>
            </select>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                Apply Filters
            </button>
            <?php if ($keyword !== '' || $labRoom !== '' || $status !== '' || $certTier !== ''): ?>
                <a href="catalog.php" class="btn btn-secondary" title="Reset Filters">✕</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<!-- Equipment Card Grid -->
<?php if (empty($equipmentList)): ?>
    <div class="card" style="padding: 3rem; text-align: center; color: var(--text-muted);">
        <h3>No Equipment Found</h3>
        <p>No laboratory apparatus matched your active search and clearance parameters.</p>
        <a href="catalog.php" class="btn btn-secondary btn-sm" style="margin-top: 1rem;">Reset Filter Parameters</a>
    </div>
<?php else: ?>
    <div class="equipment-grid">
        <?php foreach ($equipmentList as $item): 
            $isOperational = ($item['status'] === 'operational');
            $hasClearance = hasCertClearance($currentUser['cert_tier'], $item['req_cert_tier']);
        ?>
            <article class="equipment-card">
                <div class="equipment-card-head">
                    <div>
                        <h2 class="equipment-card-title"><?= sanitize($item['equipment_name']) ?></h2>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">Model: <?= sanitize($item['model_number']) ?></span>
                    </div>
                    <div>
                        <?= renderStatusBadge($item['status']) ?>
                    </div>
                </div>

                <div class="equipment-card-body">
                    <div class="meta-row">
                        <span>📍</span>
                        <span>Room: <strong><?= sanitize($item['lab_location']) ?></strong></span>
                    </div>
                    <div class="meta-row">
                        <span>🛡️</span>
                        <span>Req: <strong>Safety Cert <?= sanitize($item['req_cert_tier']) ?></strong></span>
                        <?php if (!$hasClearance): ?>
                            <span class="badge badge-warning" style="font-size:0.65rem;">Requires Higher Tier</span>
                        <?php endif; ?>
                    </div>

                    <p style="font-size: 0.875rem; color: #475569; margin-top: 0.75rem; line-height: 1.4;">
                        <?= sanitize($item['description']) ?>
                    </p>

                    <?php if (!empty($item['maintenance_note'])): ?>
                        <div class="maint-banner">
                            <strong>Maintenance Note:</strong> <?= sanitize($item['maintenance_note']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="equipment-card-footer">
                    <?php if ($isOperational): ?>
                        <a href="reserve.php?id=<?= (int)$item['equipment_id'] ?>" class="btn btn-primary btn-sm">
                            <span>📅</span>
                            <span>View Calendar & Reserve</span>
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary btn-sm disabled" title="Equipment is currently undergoing scheduled maintenance or servicing.">
                            <span>⊘</span>
                            <span>Temporarily Locked</span>
                        </button>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
