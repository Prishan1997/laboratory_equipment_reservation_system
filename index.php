<?php
/**
 * Main Application Entry Point
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: catalog.php');
} else {
    header('Location: login.php');
}
exit;
