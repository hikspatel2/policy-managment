<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 */
// Default index page - redirects to login or dashboard
session_start();

if (isset($_SESSION['user_id'])) {
    // User is logged in, redirect to dashboard
    header("Location: dashboard.php");
} else {
    // User is not logged in, redirect to login
    header("Location: login.php");
}
exit();
?>
