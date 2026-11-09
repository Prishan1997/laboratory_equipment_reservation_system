<?php
/**
 * Core Helper Functions & Notification Engine
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Set a session flash message.
 */
function setFlash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_' . $type] = $message;
}

/**
 * Retrieve and clear flash messages.
 */
function getFlashes(): array
{
    $flashes = [];
    $types = ['success', 'error', 'warning', 'info'];

    foreach ($types as $type) {
        $key = 'flash_' . $type;
        if (!empty($_SESSION[$key])) {
            $flashes[$type] = $_SESSION[$key];
            unset($_SESSION[$key]);
        }
    }

    return $flashes;
}

/**
 * Render flash messages as styled HTML banners.
 */
function renderFlashes(): string
{
    $flashes = getFlashes();
    if (empty($flashes)) {
        return '';
    }

    $html = '<div class="flash-messages-container" style="margin-bottom: 1.5rem;">';
    foreach ($flashes as $type => $message) {
        $colorClass = 'alert-' . $type;
        $icon = match($type) {
            'success' => '✓',
            'error'   => '✕',
            'warning' => '⚠',
            default   => 'ℹ'
        };
        $html .= sprintf(
            '<div class="alert %s" role="alert"><span class="alert-icon">%s</span> <span>%s</span></div>',
            htmlspecialchars($colorClass, ENT_QUOTES, 'UTF-8'),
            $icon,
            htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        );
    }
    $html .= '</div>';
    return $html;
}

/**
 * Create an in-app notification entry for a user.
 */
function createNotification(int $userId, string $title, string $message, string $type = 'system'): bool
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    return $stmt->execute([$userId, $title, $message, $type]);
}

/**
 * Get count of unread notifications for a user.
 */
function getUnreadNotificationCount(int $userId): int
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Get recent notifications for a user.
 */
function getRecentNotifications(int $userId, int $limit = 5): array
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT notification_id, title, message, type, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Check if user safety certification tier satisfies the equipment required tier.
 * Tiers: 'Tier 1' < 'Tier 2' < 'Tier 3'
 */
function hasCertClearance(string $userTier, string $requiredTier): bool
{
    $rank = [
        'Tier 1' => 1,
        'Tier 2' => 2,
        'Tier 3' => 3
    ];

    $uRank = $rank[$userTier] ?? 1;
    $rRank = $rank[$requiredTier] ?? 1;

    return $uRank >= $rRank;
}

/**
 * Format datetime for academic interface.
 */
function formatDateTime(?string $datetime): string
{
    if (!$datetime) return 'N/A';
    return date('M d, Y - h:i A', strtotime($datetime));
}

function formatDate(?string $datetime): string
{
    if (!$datetime) return 'N/A';
    return date('M d, Y', strtotime($datetime));
}

function formatTime(?string $datetime): string
{
    if (!$datetime) return 'N/A';
    return date('h:i A', strtotime($datetime));
}

/**
 * Render visual status badge HTML.
 */
function renderStatusBadge(string $status): string
{
    $normalized = strtolower(trim($status));
    $label = ucwords(str_replace('_', ' ', $normalized));

    $badgeClass = match($normalized) {
        'operational', 'confirmed', 'completed' => 'badge-success',
        'pending'                               => 'badge-warning',
        'maintenance', 'rejected', 'cancelled'  => 'badge-danger',
        'checked_out'                           => 'badge-info',
        'decommissioned'                        => 'badge-dark',
        default                                 => 'badge-secondary'
    };

    return sprintf(
        '<span class="badge %s">%s</span>',
        htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
}

/**
 * Human-readable role label.
 */
function getRoleLabel(string $role): string
{
    return match($role) {
        'admin'      => 'System Administrator',
        'supervisor' => 'Lab Supervisor / Tech',
        'student'    => 'Student / Researcher',
        default      => ucfirst($role)
    };
}

/**
 * Server-side collision detection for reservations.
 * Enforces NFR-1 using composite indexes on (equipment_id, start_time, end_time, status).
 *
 * Overlap condition:
 * (ExistingStart < NewEnd) AND (ExistingEnd > NewStart)
 */
function detectReservationCollision(PDO $pdo, int $equipmentId, string $startDateTime, string $endDateTime, ?int $excludeReservationId = null): bool
{
    $sql = "
        SELECT COUNT(*)
        FROM reservations
        WHERE equipment_id = :equipment_id
          AND status IN ('pending', 'confirmed', 'checked_out')
          AND (start_time < :end_time AND end_time > :start_time)
    ";

    $params = [
        ':equipment_id' => $equipmentId,
        ':start_time'   => $startDateTime,
        ':end_time'     => $endDateTime
    ];

    if ($excludeReservationId !== null) {
        $sql .= " AND reservation_id != :exclude_id";
        $params[':exclude_id'] = $excludeReservationId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check if the requested window intersects a scheduled maintenance blackout.
 */
function detectMaintenanceCollision(PDO $pdo, int $equipmentId, string $startDateTime, string $endDateTime): ?array
{
    $stmt = $pdo->prepare("
        SELECT log_id, start_datetime, end_datetime, maintenance_type, servicing_notes
        FROM maintenance_logs
        WHERE equipment_id = :equipment_id
          AND (start_datetime < :end_time AND end_datetime > :start_time)
        LIMIT 1
    ");
    $stmt->execute([
        ':equipment_id' => $equipmentId,
        ':start_time'   => $startDateTime,
        ':end_time'     => $endDateTime
    ]);

    $result = $stmt->fetch();
    return $result ?: null;
}
