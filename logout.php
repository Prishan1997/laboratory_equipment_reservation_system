<?php
/**
 * Logout Handler
 * Laboratory Equipment Reservation System (LERS)
 * Author: Prishan Samarasinghe (MSc IT)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

logoutUser();

session_start();
$_SESSION['flash_info'] = 'You have safely signed out of your institutional session.';
header('Location: login.php');
exit;
