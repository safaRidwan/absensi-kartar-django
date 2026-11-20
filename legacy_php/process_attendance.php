<?php
require 'app/config.php';
require 'app/auth.php';
require 'app/functions.php';

// --- FUNGSI MENGHITUNG JARAK (HAVERSINE FORMULA) ---
function hitungJarak($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; 
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) + 
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
         
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c; 
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Akses ditolak");
}

$user_id  = $_SESSION['user_id'];
$event_id = $_POST['event_id'];
$type     = $_POST['type']; 
$notes    = isset($_POST['notes']) ? $_POST['notes'] : null;

$lat = !empty($_POST['latitude']) ? $_POST['latitude'] : null;
$lon = !empty($_POST['longitude']) ? $_POST['longitude'] : null;

if ($lat === null || $lon === null) {
    die("Gagal: Koordinat lokasi tidak terdeteksi. Silakan aktifkan GPS dan refresh halaman.");
}

// --- LOGIKA PEMBLOKIRAN JARAK ---
if ($type !== 'izin') {
    $stmtEvent = $pdo->prepare("SELECT lat, lon, tolerance_meters FROM events WHERE id = ?");
    $stmtEvent->execute([$event_id]);
    $eventData = $stmtEvent->fetch();

    if ($eventData) {
        $jarakUser = hitungJarak($eventData['lat'], $eventData['lon'], $lat, $lon);
        if ($jarakUser > $eventData['tolerance_meters']) {
            $pesan = "Gagal Absen! Anda berada " . round($jarakUser) . " meter dari lokasi. (Batas maksimal: " . $eventData['tolerance_meters'] . " meter)";
            echo "<script>alert('$pesan'); window.location.href='member.php';</script>";
            exit(); 
        }
    } else {
        die("Gagal: Data kegiatan tidak ditemukan.");
    }
}

// --- LOGIKA UPLOAD FOTO (Dilewati jika Absen Pulang) ---
$fileName = null;

if ($type !== 'out') { // Jika bukan absen pulang, WAJIB ada foto
    if (!isset($_FILES['selfie']) || $_FILES['selfie']['error'] !== UPLOAD_ERR_OK) {
        die("Foto bukti wajib diunggah.");
    }

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileExtension = pathinfo($_FILES['selfie']['name'], PATHINFO_EXTENSION);
    $fileName = 'selfie_' . $user_id . '_' . time() . '.' . $fileExtension;
    $uploadPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES['selfie']['tmp_name'], $uploadPath)) {
        die("Gagal mengunggah foto ke server. Cek izin folder uploads.");
    }
}

// --- SIMPAN KE DATABASE ---
try {
    $sql = "INSERT INTO attendances (user_id, event_id, type, latitude, longitude, selfie_path, status, created_at, notes) 
            VALUES (?, ?, ?, ?, ?, ?, 'valid', NOW(), ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $event_id, $type, $lat, $lon, $fileName, $notes]);

    header("Location: member.php?status=success");
    exit();
} catch (PDOException $e) {
    if ($fileName && file_exists($uploadPath)) unlink($uploadPath);
    die("Gagal menyimpan ke database: " . $e->getMessage());
}