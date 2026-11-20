
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Hanya cek apakah sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
// Hitung 12 jam dalam detik (12 jam * 60 menit * 60 detik)
$timeout_12jam = 43200; 

// 1. Set waktu login jika belum ada (biasanya saat pertama kali login sukses)
if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
}

// 2. Cek apakah selisih waktu sekarang dengan waktu login sudah lewat 12 jam
if (time() - $_SESSION['login_time'] > $timeout_12jam) {
    // Hapus semua data session
    session_unset();
    session_destroy();
    
    // Tendang ke halaman login dengan pesan khusus
    header("Location: login.php?msg=session_expired");
    exit;
}
?>