<?php
require_once __DIR__ . '/app/bootstrap.php';

// Destroy customer session variables
unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);

flash('success', 'You have been logged out successfully.');
redirect('index.php');
