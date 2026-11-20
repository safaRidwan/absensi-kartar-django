<?php
require 'app/config.php';
require 'app/auth.php';
require 'app/functions.php';

// Cek Admin
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'pengurus') {
    die("Akses ditolak.");
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: index.php");
    exit;
}

// 1. Ambil Data Event Lama
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$id]);
$event = $stmt->fetch();

if (!$event) die("Event tidak ditemukan.");

// 2. Proses Update Data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Token Error");
    }

    $title = $_POST['title'];
    $start = str_replace('T', ' ', $_POST['start_time']);
    $end   = str_replace('T', ' ', $_POST['end_time']);
    $lat   = $_POST['lat'];
    $lon   = $_POST['lon'];
    $tol   = $_POST['tolerance'];
    $active = isset($_POST['is_active']) ? 1 : 0;

    $sql = "UPDATE events SET title=?, start_time=?, end_time=?, lat=?, lon=?, tolerance_meters=?, is_active=? WHERE id=?";
    $stmt = $pdo->prepare($sql);
    
    if($stmt->execute([$title, $start, $end, $lat, $lon, $tol, $active, $id])) {
        header("Location: index.php?msg=updated");
        exit;
    } else {
        $error = "Gagal mengupdate event.";
    }
}

$startVal = date('Y-m-d\TH:i', strtotime($event['start_time']));
$endVal   = date('Y-m-d\TH:i', strtotime($event['end_time']));

include 'includes/header.php';
?>

<style>
    /* Custom Styles for Better Mobile UX */
    .card { border-radius: 15px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .card-header { background: #fff; border-bottom: 1px solid #f0f0f0; padding: 1.2rem; border-radius: 15px 15px 0 0 !important; }
    .form-label { font-weight: 600; color: #566a7f; font-size: 0.85rem; text-transform: uppercase; }
    .form-control, .form-select { border-radius: 10px; padding: 0.6rem 1rem; border: 1px solid #d9dee3; }
    .form-control:focus { box-shadow: 0 0 0 0.2rem rgba(105, 108, 255, 0.1); }
    
    @media (max-width: 576px) {
        .card-header { flex-direction: column; text-align: center; gap: 10px; }
        .btn-responsive { width: 100%; margin-bottom: 10px; }
        .footer-buttons { flex-direction: column-reverse; display: flex; gap: 10px; }
        .footer-buttons .btn { width: 100%; margin: 0 !important; }
    }
</style>

<div class="container-xxl flex-grow-1 container-p-y">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-primary"><i class='bx bx-edit-alt me-1'></i> Edit Kegiatan</h5>
            <a href="index.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                <i class='bx bx-arrow-back me-1'></i> Kembali
            </a>
        </div>
        <div class="card-body mt-3">
            <?php if(isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div class="mb-4">
                    <label class="form-label">Nama Kegiatan</label>
                    <div class="input-group input-group-merge">
                        <span class="input-group-text"><i class="bx bx-bookmark"></i></span>
                        <input type="text" class="form-control" name="title" value="<?= htmlspecialchars($event['title']) ?>" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="form-label">Waktu Mulai</label>
                        <input type="datetime-local" class="form-control" name="start_time" value="<?= $startVal ?>" required>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label">Waktu Selesai</label>
                        <input type="datetime-local" class="form-control" name="end_time" value="<?= $endVal ?>" required>
                    </div>
                </div>

                <hr class="my-4">

                <div class="row">
                    <div class="col-md-6 mb-4">
                        <label class="form-label">Latitude</label>
                        <div class="input-group input-group-merge">
                            <span class="input-group-text"><i class="bx bx-map-pin"></i></span>
                            <input type="text" class="form-control" name="lat" value="<?= $event['lat'] ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label">Longitude</label>
                        <div class="input-group input-group-merge">
                            <span class="input-group-text"><i class="bx bx-map-alt"></i></span>
                            <input type="text" class="form-control" name="lon" value="<?= $event['lon'] ?>" required>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Radius Toleransi (Meter)</label>
                    <div class="input-group input-group-merge">
                        <span class="input-group-text"><i class="bx bx-radar"></i></span>
                        <input type="number" class="form-control" name="tolerance" value="<?= $event['tolerance_meters'] ?>" required>
                    </div>
                    <div class="form-text small">Jarak maksimal anggota dari titik pusat lokasi.</div>
                </div>

                <div class="mb-4">
                    <div class="card bg-light border-0 p-3">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= $event['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="isActive">Status Kegiatan Aktif</label>
                        </div>
                        <small class="text-muted d-block mt-1">Jika aktif, kegiatan akan muncul di menu absen anggota.</small>
                    </div>
                </div>

                <div class="footer-buttons pt-3">
                    <button type="submit" class="btn btn-primary shadow">
                        <i class='bx bx-save me-1'></i> Simpan Perubahan
                    </button>
                    
                    <a href="event_delete.php?id=<?= $id ?>" class="btn btn-outline-danger" onclick="return confirm('Yakin hapus kegiatan ini? Semua data absen terkait akan hilang!')">
                        <i class='bx bx-trash me-1'></i> Hapus Kegiatan
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>