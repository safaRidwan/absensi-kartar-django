<?php
require 'app/config.php';
require 'app/auth.php';
require 'app/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user_id = $_SESSION['user_id'];
$msg_profile = "";

if (isset($_POST['update_profile'])) {
    $name = $_POST['name'];
    $username = preg_replace("/[^a-zA-Z0-9_]/", "", $_POST['username']); 
    $pass = $_POST['password'];

    $check = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check->execute([$username, $user_id]);
    
    if ($check->rowCount() > 0) {
        $msg_profile = "<div class='alert alert-danger p-2 small auto-dismiss'>Username sudah digunakan oleh pengguna lain!</div>";
    } else {
        if (!empty($pass)) {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, password_hash = MD5(?) WHERE id = ?");
            $stmt->execute([$name, $username, $pass, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ? WHERE id = ?");
            $stmt->execute([$name, $username, $user_id]);
        }
        $_SESSION['name'] = $name;
        $msg_profile = "<div class='alert alert-success p-2 small auto-dismiss'>Profil berhasil disimpan!</div>";
    }
}

$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$me = $stmtUser->fetch();

$stmt = $pdo->query("SELECT * FROM events WHERE is_active = 1 ORDER BY start_time DESC LIMIT 1");
$event = $stmt->fetch();

$statusAbsensi = 'belum'; 
$eventExpired = false;
$eventNotStarted = false;
$btnLabel = 'KIRIM ABSEN';

if ($event) {
    $now = date('Y-m-d H:i:s');
    
    $stmtIn = $pdo->prepare("SELECT id FROM attendances WHERE user_id = ? AND event_id = ? AND type = 'in'");
    $stmtIn->execute([$user_id, $event['id']]);
    $dataIn = $stmtIn->fetch();

    $stmtOut = $pdo->prepare("SELECT id FROM attendances WHERE user_id = ? AND event_id = ? AND type = 'out'");
    $stmtOut->execute([$user_id, $event['id']]);
    $dataOut = $stmtOut->fetch();

    $stmtIzin = $pdo->prepare("SELECT id FROM attendances WHERE user_id = ? AND event_id = ? AND type = 'izin'");
    $stmtIzin->execute([$user_id, $event['id']]);
    $dataIzin = $stmtIzin->fetch();

    if ($dataIzin) {
        $statusAbsensi = 'selesai';
    } elseif (!$dataIn) {
        $statusAbsensi = 'siap_masuk';
        $btnLabel = 'KIRIM ABSEN MASUK';
    } elseif ($dataIn && !$dataOut) {
        $statusAbsensi = 'siap_pulang';
        $btnLabel = 'KIRIM ABSEN PULANG';
    } else {
        $statusAbsensi = 'selesai';
    }

    if (strtotime($now) < strtotime($event['start_time'])) {
        $eventNotStarted = true;
    } elseif ($statusAbsensi !== 'selesai' && strtotime($now) > strtotime($event['end_time'])) {
        $eventExpired = true;
    }
}

// --- LOGIC 4: AMBIL RIWAYAT (DENGAN PAGINASI) ---
$stmtHistory = $pdo->prepare("
    SELECT a.*, e.title, e.start_time 
    FROM attendances a 
    JOIN events e ON a.event_id = e.id 
    WHERE a.user_id = ? 
    ORDER BY e.start_time DESC, a.created_at ASC
");
$stmtHistory->execute([$user_id]);
$rawHistory = $stmtHistory->fetchAll();

$groupedHistoryMap = [];
foreach ($rawHistory as $row) {
    $evtId = $row['event_id'];
    if (!isset($groupedHistoryMap[$evtId])) {
        $groupedHistoryMap[$evtId] = ['title' => $row['title'], 'date' => $row['start_time'], 'in' => '-', 'out' => '-', 'status' => 'Hadir'];
    }
    if ($row['type'] == 'in') $groupedHistoryMap[$evtId]['in'] = date('H:i', strtotime($row['created_at']));
    elseif ($row['type'] == 'out') $groupedHistoryMap[$evtId]['out'] = date('H:i', strtotime($row['created_at']));
    elseif ($row['type'] == 'izin') {
        $groupedHistoryMap[$evtId]['in'] = 'IZIN';
        $groupedHistoryMap[$evtId]['out'] = 'IZIN';
        $groupedHistoryMap[$evtId]['status'] = 'Izin: '.$row['notes'];
    }
}

// Mengubah array asosiatif menjadi index biasa agar mudah dipotong
$groupedHistory = array_values($groupedHistoryMap);

// Paginasi Array History
$limit_history = 10;
$hal = isset($_GET['hal']) ? (int)$_GET['hal'] : 1;
if ($hal < 1) $hal = 1;

$total_history = count($groupedHistory);
$total_pages = ceil($total_history / $limit_history);
if ($total_pages < 1) $total_pages = 1;

$offset = ($hal - 1) * $limit_history;
// Memotong array sesuai halaman saat ini
$pagedHistory = array_slice($groupedHistory, $offset, $limit_history);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Anggota Kartar - Mobile</title>
    <link rel="stylesheet" href="assets/vendor/css/core.css" />
    <link rel="stylesheet" href="assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="assets/vendor/fonts/boxicons.css" />
    <link rel="icon" type="image/x-icon" href="assets/img/logo_kartar2.png" />
    <style>
        body { background-color: #f8f9fa; font-family: 'Public Sans', sans-serif; padding-bottom: 20px; }
        .nav-pills { background: #ffffff; border: 1px solid #e7e7ff; padding: 5px; border-radius: 15px !important; }
        .nav-pills .nav-link { padding: 10px 5px; font-size: 0.7rem; font-weight: 600; color: #a1acb8; border-radius: 12px !important; }
        .nav-pills .nav-link i { display: block; font-size: 1.3rem; margin-bottom: 3px; }
        .nav-pills .nav-link.active { background-color: #696cff !important; box-shadow: 0 2px 10px rgba(105, 108, 255, 0.4); color: #fff !important; }
        #camera-container, #camera-container-izin { width: 100%; max-width: 400px; margin: 0 auto; background: #000; border-radius: 20px; overflow: hidden; display: none; position: relative; aspect-ratio: 3/4; }
        #video-preview, #video-preview-izin { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
        .btn-capture { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); width: 70px; height: 70px; border: 5px solid rgba(255,255,255,0.5); background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; z-index: 20; }
        .btn-capture i { color: #696cff; font-size: 2rem; }
        #img-result, #img-result-izin { width: 100%; max-width: 300px; border-radius: 15px; display: none; margin: 15px auto; border: 4px solid #fff; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .card { border-radius: 20px; border: none; }
        .event-header { background: linear-gradient(135deg, #696cff 0%, #3f42af 100%); color: white; padding: 20px; border-radius: 20px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="container-xxl container-p-y">
    <div class="d-flex justify-content-between align-items-center mb-4 px-2">
        <div class="d-flex align-items-center">
            <img src="assets/img/logo_kartar2.png" alt="Logo" class="me-3" style="width: 50px;">
            <div>
                <span class="text-muted d-block small">Holaa...</span>
                <h5 class="fw-bold m-0"><?= htmlspecialchars($me['name']) ?></h5>
            </div>
        </div>
        <a href="logout.php" class="btn btn-icon btn-danger rounded-circle shadow-sm"><i class="bx bx-power-off"></i></a>
    </div>

    <ul class="nav nav-pills nav-fill mb-4 shadow-sm" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#navs-absen"><i class="bx bx-scan"></i>Absen</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#navs-izin"><i class="bx bx-envelope"></i>Izin</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#navs-history"><i class="bx bx-time-five"></i>Riwayat</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#navs-profile"><i class="bx bx-user-circle"></i>Profil</button></li>
    </ul>

    <div class="tab-content p-0 bg-transparent shadow-none">
        
        <div class="tab-pane fade show active" id="navs-absen" role="tabpanel">
            <?php if (!$event): ?>
                <div class="card text-center p-5 shadow-sm"><h6>Tidak ada kegiatan aktif.</h6></div>
            <?php elseif ($eventNotStarted): ?>
                <div class="card text-center p-5 shadow-sm">
                    <div class="mb-3"><i class="bx bx-time-five text-warning" style="font-size: 4rem;"></i></div>
                    <h5 class="text-warning fw-bold">Kegiatan Belum Dimulai</h5>
                    <p class="text-muted mb-0">Absensi dibuka pada <br><strong><?= date('d M Y, H:i', strtotime($event['start_time'])) ?> WIB</strong></p>
                </div>
            <?php elseif ($eventExpired): ?>
                <div class="card bg-label-danger text-center p-5 shadow-sm"><h5 class="text-danger fw-bold">Waktu Habis</h5></div>
            <?php elseif ($statusAbsensi == 'selesai'): ?>
                <div class="card bg-label-success text-center p-5 shadow-sm">
                    <div class="mb-3"><i class="bx bxs-check-circle text-success" style="font-size: 4rem;"></i></div>
                    <h5 class="text-success fw-bold">Kegiatan Selesai!</h5>
                    <p class="text-muted mb-0"><?= $dataIzin ? 'Anda sudah melakukan Izin.' : 'Terima kasih telah berpartisipasi.' ?></p>
                </div>
            <?php else: ?>
                <div class="event-header shadow">
                    <h5 class="mb-0 fw-bold text-white"><?= htmlspecialchars($event['title']) ?></h5>
                    <small class="opacity-75"><?= date('H:i', strtotime($event['start_time'])) ?> - <?= date('H:i', strtotime($event['end_time'])) ?> WIB</small>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-body p-4 text-center">
                        <form action="process_attendance.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                            <input type="hidden" class="lat-input" name="latitude">
                            <input type="hidden" class="lon-input" name="longitude">
                            <input type="hidden" name="type" value="<?= ($statusAbsensi == 'siap_masuk') ? 'in' : 'out' ?>">
                            <input type="file" name="selfie" class="real-file-input" style="display: none;">

                            <?php if ($statusAbsensi == 'siap_masuk'): ?>
                                <div id="camera-container">
                                    <video id="video-preview" autoplay playsinline></video>
                                    <button type="button" class="btn-capture" onclick="takePicture('normal')"><i class="bx bx-camera"></i></button>
                                </div>
                                <canvas id="canvas-result" style="display:none;"></canvas>
                                <img id="img-result">
                                
                                <button type="button" id="btnStartCamera" class="btn btn-outline-primary w-100 rounded-pill mt-2" onclick="startCamera('normal')">
                                    <i class='bx bx-camera'></i> Selfie Masuk
                                </button>
                            <?php else: ?>
                                <div class="alert bg-label-info mb-2 p-3 text-start d-flex align-items-center rounded-3">
                                    <i class='bx bx-info-circle fs-3 text-info me-3'></i>
                                    <small class="text-dark fw-bold">Pencatatan Pulang hanya mendeteksi GPS. Tidak perlu mengambil foto selfie.</small>
                                </div>
                            <?php endif; ?>

                            <div class="bg-light rounded-3 p-3 my-3 border">
                                <div class="status-lokasi small fw-bold text-warning mb-2">Menghubungkan GPS...</div>
                            </div>

                            <button type="submit" id="btnSubmit" class="btn w-100 btn-lg shadow <?= ($statusAbsensi == 'siap_masuk') ? 'btn-primary' : 'btn-warning' ?>" disabled><?= $btnLabel ?></button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="navs-izin" role="tabpanel">
            <?php if (!$event || $statusAbsensi == 'selesai' || $eventExpired || $eventNotStarted || $dataIn): ?>
                <div class="card text-center p-5 shadow-sm">
                    <div class="mb-3"><i class="bx bx-lock-alt text-muted" style="font-size: 3rem;"></i></div>
                    <h6 class="text-muted">
                        <?php 
                            if ($dataIn) echo "Anda sudah absen masuk, izin ditutup.";
                            elseif ($dataIzin) echo "Anda sudah mengirimkan Izin.";
                            elseif ($eventExpired) echo "Waktu absensi berakhir.";
                            elseif ($eventNotStarted) echo "Kegiatan belum dimulai.";
                            else echo "Fitur izin ditutup.";
                        ?>
                    </h6>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3">Form Izin Anggota</h5>
                        <form action="process_attendance.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="event_id" value="<?= $event['id'] ?>">
                            <input type="hidden" class="lat-input" name="latitude">
                            <input type="hidden" class="lon-input" name="longitude">
                            <input type="hidden" name="type" value="izin">
                            <input type="file" name="selfie" class="real-file-input" id="izin-file-input" style="display: none;">

                            <div class="mb-3">
                                <label class="form-label small fw-bold">ALASAN TIDAK HADIR</label>
                                <textarea name="notes" class="form-control" placeholder="Contoh: Sakit" required></textarea>
                            </div>

                            <div class="text-center mb-3">
                                <div id="camera-container-izin" style="display:none;">
                                    <video id="video-preview-izin" autoplay playsinline></video>
                                    <button type="button" class="btn-capture" onclick="takePicture('izin')"><i class="bx bx-camera"></i></button>
                                </div>
                                <img id="img-result-izin">
                                <button type="button" id="btnStartIzin" class="btn btn-sm btn-outline-secondary w-100 rounded-pill" onclick="startCamera('izin')">Ambil Foto Bukti</button>
                            </div>

                            <div class="bg-light p-2 mb-3 border rounded text-center">
                                <div class="status-lokasi small fw-bold text-warning">Menunggu Lokasi...</div>
                            </div>

                            <button type="submit" id="btnSubmitIzin" class="btn btn-danger btn-lg w-100 shadow" disabled>KIRIM IZIN</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="navs-history" role="tabpanel">
            <div class="card shadow-sm p-2 mb-3">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Kegiatan</th><th class="text-center">DATANG</th><th class="text-center">PULANG</th></tr></thead>
                    <tbody>
                        <?php if (count($pagedHistory) > 0): ?>
                            <?php foreach($pagedHistory as $h): ?>
                            <tr>
                                <td><div class="fw-bold text-dark"><?= htmlspecialchars($h['title']) ?></div><small><?= $h['status'] ?></small></td>
                                <td class="text-center"><span class="badge bg-label-primary"><?= $h['in'] ?></span></td>
                                <td class="text-center"><span class="badge bg-label-warning"><?= $h['out'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">Belum ada riwayat absensi.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginasi Riwayat -->
            <?php if($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center mb-0">
                        <li class="page-item <?= ($hal <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?hal=<?= $hal - 1 ?>#navs-history">Previous</a>
                        </li>
                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($hal == $i) ? 'active' : '' ?>">
                                <a class="page-link" href="?hal=<?= $i ?>#navs-history"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($hal >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="?hal=<?= $hal + 1 ?>#navs-history">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade" id="navs-profile" role="tabpanel">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <?php if(!empty($msg_profile)) echo $msg_profile; ?>
                    <form method="POST">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="mb-3"><label class="form-label small fw-bold">NAMA LENGKAP</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($me['name']) ?>" required></div>
                        <div class="mb-3"><label class="form-label small fw-bold">USERNAME</label><input type="text" name="username" class="form-control" value="<?= htmlspecialchars($me['username']) ?>" required></div>
                        <div class="mb-4"><label class="form-label small fw-bold">GANTI PASSWORD</label><input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak ganti"></div>
                        <button type="submit" class="btn btn-primary btn-lg w-100 shadow">Simpan Perubahan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/vendor/libs/jquery/jquery.js"></script>
<script src="assets/vendor/js/bootstrap.js"></script>

<script>
    let hasLocation = false;
    let hasPhotoNormal = false;
    let hasPhotoIzin = false;
    let videoStream = null;
    
    // Kirim status checkout ke JavaScript
    const isCheckoutMode = <?= ($statusAbsensi == 'siap_pulang') ? 'true' : 'false' ?>;

    function validateForms() {
        if(document.getElementById('btnSubmit')) {
            if(isCheckoutMode) {
                // Jika sedang absen pulang, syaratnya hanya GPS (tidak butuh foto)
                document.getElementById('btnSubmit').disabled = !hasLocation;
            } else {
                // Jika absen masuk, butuh GPS & Foto
                document.getElementById('btnSubmit').disabled = !(hasLocation && hasPhotoNormal);
            }
        }
        if(document.getElementById('btnSubmitIzin')) {
            document.getElementById('btnSubmitIzin').disabled = !(hasLocation && hasPhotoIzin);
        }
    }

    function getLocation() {
        const statusDivs = document.querySelectorAll('.status-lokasi');
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition((pos) => {
                document.querySelectorAll('.lat-input').forEach(i => i.value = pos.coords.latitude);
                document.querySelectorAll('.lon-input').forEach(i => i.value = pos.coords.longitude);
                statusDivs.forEach(d => {
                    d.innerHTML = "<i class='bx bxs-check-circle'></i> Lokasi Terkunci";
                    d.className = "status-lokasi small fw-bold text-success mb-2";
                });
                hasLocation = true;
                validateForms();
            }, (err) => {
                statusDivs.forEach(d => d.innerText = "Gagal GPS. Refresh halaman!");
            }, {enableHighAccuracy: true});
        }
    }
    window.onload = getLocation;

    async function startCamera(mode) {
        const container = mode === 'normal' ? document.getElementById('camera-container') : document.getElementById('camera-container-izin');
        const video = mode === 'normal' ? document.getElementById('video-preview') : document.getElementById('video-preview-izin');
        container.style.display = 'block';
        try {
            if(videoStream) videoStream.getTracks().forEach(t => t.stop());
            videoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } });
            video.srcObject = videoStream;
        } catch (err) { alert("Izin kamera ditolak!"); }
    }

    function takePicture(mode) {
        const video = mode === 'normal' ? document.getElementById('video-preview') : document.getElementById('video-preview-izin');
        const canvas = document.getElementById('canvas-result');
        const img = mode === 'normal' ? document.getElementById('img-result') : document.getElementById('img-result-izin');
        const container = mode === 'normal' ? document.getElementById('camera-container') : document.getElementById('camera-container-izin');
        const input = mode === 'normal' ? document.querySelector('.real-file-input') : document.getElementById('izin-file-input');

        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.translate(canvas.width, 0); ctx.scale(-1, 1);
        ctx.drawImage(video, 0, 0);
        
        if(videoStream) videoStream.getTracks().forEach(t => t.stop());
        container.style.display = 'none';
        img.src = canvas.toDataURL('image/jpeg');
        img.style.display = 'block';

        canvas.toBlob(blob => {
            const file = new File([blob], "selfie.jpg", { type: "image/jpeg" });
            const dt = new DataTransfer(); dt.items.add(file);
            input.files = dt.files;
            if(mode === 'normal') hasPhotoNormal = true; else hasPhotoIzin = true;
            validateForms();
        }, 'image/jpeg');
    }

    // Mengontrol Tab yang aktif secara otomatis saat memuat ulang halaman
    window.addEventListener('DOMContentLoaded', (event) => {
        <?php if(!empty($msg_profile)): ?>
            // Otomatis buka tab profil setelah disimpan
            const profileTabTrigger = document.querySelector('button[data-bs-target="#navs-profile"]');
            if(profileTabTrigger) { new bootstrap.Tab(profileTabTrigger).show(); }
        <?php elseif(isset($_GET['hal']) || strpos($_SERVER['REQUEST_URI'], '#navs-history') !== false): ?>
            // Otomatis buka tab riwayat jika sedang melakukan paginasi
            const historyTabTrigger = document.querySelector('button[data-bs-target="#navs-history"]');
            if(historyTabTrigger) { new bootstrap.Tab(historyTabTrigger).show(); }
        <?php endif; ?>
    });
</script>
</body>
</html>