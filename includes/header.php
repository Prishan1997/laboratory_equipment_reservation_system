<?php
/**
 * Shared Application Header & Navigation Bar
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$currentUser = getCurrentUser();
$unreadNotifs = $currentUser ? getUnreadNotificationCount((int)$currentUser['user_id']) : 0;
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? sanitize($pageTitle) . ' | LERS' : 'Laboratory Equipment Reservation System' ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if (isset($extraCss)): ?>
        <link rel="stylesheet" href="<?= sanitize($extraCss) ?>">
    <?php endif; ?>
</head>
<body>

<!-- Top Security & Meta Bar -->
<div class="top-notice-bar">
    <div class="status-badge">
        <span>🔒</span>
        <span>SSL/TLS Encrypted Academic Portal</span>
    </div>
    <div>
        <span>System Time: <?= date('Y-m-d H:i') ?></span>
        &nbsp;|&nbsp;
        <a href="#helpModal" onclick="alert('LERS Laboratory Helpdesk:\nSupport: lab-support@science.ac.lk\nOperational Hours: 08:00 - 18:00 Mon-Fri\nClearance Verification: Faculty Safety Office'); return false;">Help & Guidelines</a>
    </div>
</div>

<!-- Main Role-Aware Navigation Bar -->
<header class="main-navbar">
    <div class="brand-container">
        <a href="catalog.php" class="brand-title">
            <span>🔬 LERS</span>
            <span class="sub">| Academic Laboratory Portal</span>
        </a>
    </div>

    <?php if ($currentUser): ?>
    <nav>
        <ul class="nav-links">
            <li>
                <a href="catalog.php" class="<?= ($currentPage === 'catalog.php' || $currentPage === 'index.php') ? 'active' : '' ?>">
                    Equipment Directory
                </a>
            </li>
            <li>
                <a href="my_bookings.php" class="<?= ($currentPage === 'my_bookings.php') ? 'active' : '' ?>">
                    My Bookings
                    <?php if ($unreadNotifs > 0): ?>
                        <span class="badge badge-warning" style="font-size:0.65rem;margin-left:0.25rem;"><?= $unreadNotifs ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <?php if (hasRole(['supervisor', 'admin'])): ?>
            <li>
                <a href="approvals.php" class="<?= ($currentPage === 'approvals.php') ? 'active' : '' ?>">
                    Supervisor Queue
                </a>
            </li>
            <li>
                <a href="maintenance.php" class="<?= ($currentPage === 'maintenance.php') ? 'active' : '' ?>">
                    Maintenance Scheduler
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole('admin')): ?>
            <li>
                <a href="reports.php" class="<?= ($currentPage === 'reports.php') ? 'active' : '' ?>">
                    Usage & CSV Reports
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="user-session-meta">
        <div class="user-badge">
            <span>👤 <?= sanitize($currentUser['full_name']) ?></span>
            <span class="role-tag"><?= sanitize(ucfirst($currentUser['role'])) ?></span>
        </div>
        <a href="logout.php" class="btn-logout" title="End Session">Sign Out</a>
    </div>
    <?php else: ?>
    <div class="user-session-meta">
        <a href="login.php" class="btn btn-sm btn-secondary">Sign In</a>
        <a href="register.php" class="btn btn-sm btn-primary">Register</a>
    </div>
    <?php endif; ?>
</header>

<!-- Main Page Content Container -->
<main class="main-wrapper">
    <?= renderFlashes() ?>
