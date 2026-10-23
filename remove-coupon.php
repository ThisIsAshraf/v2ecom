<?php
require_once __DIR__ . '/app/bootstrap.php';

// Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('checkout.php');
}

verify_csrf();

// Clear active coupon session
unset($_SESSION['coupon_code']);
flash('success', 'Coupon removed from order.');

redirect('checkout.php');
