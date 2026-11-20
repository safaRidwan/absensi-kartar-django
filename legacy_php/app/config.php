<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

$host = 'localhost';
$db   = 'db_karang_taruna';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

// Base URL (Sesuaikan dengan folder htdocs anda)
define('BASE_URL', 'http://localhost/absensi-kt/');
?>