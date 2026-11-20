<?php
require 'app/config.php';
require 'app/auth.php';

// Proteksi: Hanya Admin & Pengurus yang boleh akses
if (!in_array($_SESSION['role'], ['admin', 'pengurus'])) {
    die("Akses ditolak");
}

// 1. Tangkap Input Filter
$day   = isset($_GET['day']) ? $_GET['day'] : '';
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year  = isset($_GET['year']) ? $_GET['year'] : date('Y');
$filter_rt = isset($_GET['rt']) ? $_GET['rt'] : '';
$filter_event = isset($_GET['event_id']) ? $_GET['event_id'] : ''; 

// 2. Ambil Semua Daftar Kegiatan di Bulan Tersebut (Untuk Dropdown Filter)
$sqlAllEvents = "SELECT id, title, start_time FROM events WHERE MONTH(start_time) = ? AND YEAR(start_time) = ? ORDER BY start_time ASC";
$stmtAllEvents = $pdo->prepare($sqlAllEvents);
$stmtAllEvents->execute([$month, $year]);
$allEventsMonth = $stmtAllEvents->fetchAll();

// 3. Query Kegiatan yang Akan Ditampilkan di Tabel
$sqlEvent = "SELECT * FROM events WHERE MONTH(start_time) = ? AND YEAR(start_time) = ?";
$paramsEvent = [$month, $year];

if (!empty($day)) {
    $sqlEvent .= " AND DAY(start_time) = ?";
    $paramsEvent[] = $day;
}

if (!empty($filter_event)) {
    $sqlEvent .= " AND id = ?";
    $paramsEvent[] = $filter_event;
    $sqlEvent .= " ORDER BY start_time ASC";
} else {
    // DEFAULT: Ambil 3 kegiatan terakhir
    $sqlEvent .= " ORDER BY start_time DESC LIMIT 3";
}

$stmt = $pdo->prepare($sqlEvent);
$stmt->execute($paramsEvent);
$events = $stmt->fetchAll();

// Balikkan urutannya agar tampil sesuai kronologi
if (empty($filter_event)) {
    $events = array_reverse($events);
}
$totalEventSaatIni = count($events);

// 4. Ambil Member (Dengan Filter RT dan Paginasi Limit 20)
$limit = 20; 
$hal = isset($_GET['hal']) ? (int)$_GET['hal'] : 1;
if ($hal < 1) $hal = 1;
$offset = ($hal - 1) * $limit;

// -- Menghitung Total Member Untuk Paginasi
$sqlMembersCount = "SELECT COUNT(*) FROM users";
$paramsCount = [];
if (!empty($filter_rt)) {
    $sqlMembersCount .= " WHERE rt = :rt";
    $paramsCount[':rt'] = $filter_rt;
}
$stmtCount = $pdo->prepare($sqlMembersCount);
if (!empty($filter_rt)) $stmtCount->bindValue(':rt', $filter_rt);
$stmtCount->execute();
$total_records = $stmtCount->fetchColumn();

$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;

// -- Menarik Data Member Sesuai Paginasi
$sqlMembers = "SELECT * FROM users";
if (!empty($filter_rt)) {
    $sqlMembers .= " WHERE rt = :rt";
}
$sqlMembers .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";

$stmtMembers = $pdo->prepare($sqlMembers);
if (!empty($filter_rt)) $stmtMembers->bindValue(':rt', $filter_rt);
$stmtMembers->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmtMembers->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtMembers->execute();
$members = $stmtMembers->fetchAll();

// Parameter link untuk menjaga agar filter tidak tereset saat pindah halaman paginasi
$link_tambahan = "";
if (!empty($day)) $link_tambahan .= "&day=" . urlencode($day);
if (!empty($month)) $link_tambahan .= "&month=" . urlencode($month);
if (!empty($year)) $link_tambahan .= "&year=" . urlencode($year);
if (!empty($filter_rt)) $link_tambahan .= "&rt=" . urlencode($filter_rt);
if (!empty($filter_event)) $link_tambahan .= "&event_id=" . urlencode($filter_event);

// 5. Ambil Data Absensi Khusus Untuk Kegiatan yang Ditampilkan Saja
$attendanceMap = [];
if ($totalEventSaatIni > 0) {
    $eventIds = array_column($events, 'id');
    $inQuery = implode(',', array_fill(0, count($eventIds), '?'));
    
    $sqlAbsen = "
        SELECT user_id, event_id, type, created_at, latitude, longitude, selfie_path, notes 
        FROM attendances 
        WHERE event_id IN ($inQuery)
    ";
    $stmtAbsen = $pdo->prepare($sqlAbsen);
    $stmtAbsen->execute($eventIds);
    $rawAbsensi = $stmtAbsen->fetchAll();

    foreach ($rawAbsensi as $row) {
        $attendanceMap[$row['user_id']][$row['event_id']][$row['type']] = [
            'time' => date('H:i', strtotime($row['created_at'])),
            'latitude' => $row['latitude'],
            'longitude' => $row['longitude'],
            'selfie_path' => $row['selfie_path'],
            'notes' => $row['notes']
        ];
    }
}

include 'includes/header.php';
?>

<style>
    .card { border-radius: 15px !important; border: none; overflow: hidden; }
    .table thead th { background-color: #f8f9fa; text-transform: none; font-size: 0.65rem; letter-spacing: 0.5px; vertical-align: middle; border: 1px solid #dee2e6; }
    .sticky-col { position: sticky; left: 0; background-color: #ffffff !important; z-index: 2; border-right: 2px solid #f1f1f1 !important; }
    .user-name-cell { min-width: 150px; }
    .absen-box { padding: 4px; border-radius: 6px; }
    .event-title-wrap { display: block; font-size: 0.75rem; font-weight: 800; min-width: 100px; color: #333; }
    .bg-rekap { background-color: #f0f4f8 !important; }
    .badge-izin { font-size: 0.6rem; }
    .badge-rt { font-size: 0.65rem; background-color: #e9ecef; color: #495057; border-radius: 4px; padding: 0.2em 0.5em; }
    .action-link { font-size: 0.75rem; margin: 0 2px; color: #696cff; transition: 0.2s; }
    .action-link:hover { color: #3f42af; }
</style>

<div class="card shadow-sm mb-4">
    <div class="card-header p-3 border-0">
        <h5 class="mb-3 fw-bold text-primary"><i class="bx bx-check-double me-1"></i> Verifikasi Absensi</h5>
        <form method="GET" class="row g-2 align-items-center bg-light p-3 report-filter mx-0 shadow-sm" style="border-radius: 10px;">
            
            <div class="col-auto">
                <select name="day" class="form-select form-select-sm border-0 shadow-sm" onchange="this.form.submit()">
                    <option value="">-- Tgl --</option>
                    <?php for($d=1; $d<=31; $d++): ?>
                        <option value="<?= $d ?>" <?= ($day == $d) ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="month" class="form-select form-select-sm border-0 shadow-sm" onchange="this.form.submit()">
                    <?php 
                    $months = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
                    foreach($months as $mNum => $mName): ?>
                        <option value="<?= $mNum ?>" <?= ($mNum == $month) ? 'selected' : '' ?>><?= $mName ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <select name="year" class="form-select form-select-sm border-0 shadow-sm" onchange="this.form.submit()">
                    <?php for($y=2024; $y<=date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= ($y == $year) ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="col-auto">
                <select name="rt" class="form-select form-select-sm border-0 shadow-sm" onchange="this.form.submit()">
                    <option value="">-- Semua RT --</option>
                    <option value="05" <?= ($filter_rt == '05') ? 'selected' : '' ?>>RT 05</option>
                    <option value="06" <?= ($filter_rt == '06') ? 'selected' : '' ?>>RT 06</option>
                    <option value="07" <?= ($filter_rt == '07') ? 'selected' : '' ?>>RT 07</option>
                    <option value="08" <?= ($filter_rt == '08') ? 'selected' : '' ?>>RT 08</option>
                    <option value="09" <?= ($filter_rt == '09') ? 'selected' : '' ?>>RT 09</option>
                </select>
            </div>

            <div class="col-auto">
                <select name="event_id" class="form-select form-select-sm border-0 shadow-sm text-primary fw-bold" onchange="this.form.submit()" style="max-width: 250px;">
                    <option value="">-- Default (3 Kegiatan Terakhir) --</option>
                    <?php foreach($allEventsMonth as $evM): ?>
                        <option value="<?= $evM['id'] ?>" <?= ($filter_event == $evM['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($evM['title']) ?> (<?= date('d/m', strtotime($evM['start_time'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto ms-md-auto">
                <a href="report.php" class="btn btn-sm btn-outline-secondary w-100 px-3 shadow-sm rounded-pill">
                    <i class="bx bx-reset"></i> Reset Filter
                </a>
            </div>
        </form>
    </div>

    <div class="table-responsive p-0">
        <table class="table table-bordered table-sm text-center align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" class="sticky-col user-name-cell">Nama Anggota</th>
                    <?php if($totalEventSaatIni > 0): ?>
                        <th colspan="<?= $totalEventSaatIni ?>" class="text-center">Kegiatan</th>
                    <?php else: ?>
                        <th rowspan="2">Tidak Ada Kegiatan</th>
                    <?php endif; ?>
                    <th colspan="3" class="bg-rekap">REKAP</th>
                </tr>
                <tr>
                    <?php foreach($events as $ev): ?>
                        <th class="p-2" style="min-width: 140px;">
                            <span class="event-title-wrap text-primary"><?= htmlspecialchars($ev['title']) ?></span>
                            <small class="text-muted" style="font-size: 0.6rem;"><?= date('d M Y', strtotime($ev['start_time'])) ?></small>
                        </th>
                    <?php endforeach; ?>
                    <th class="text-success fw-bold bg-rekap" title="Hadir">H</th>
                    <th class="text-warning fw-bold bg-rekap" title="Izin">I</th>
                    <th class="text-danger fw-bold bg-rekap" title="Alpa">A</th>
                </tr>
            </thead>
            <tbody>
                <?php if(count($members) > 0): ?>
                    <?php foreach($members as $member): 
                        $totalH = 0; $totalI = 0;
                    ?>
                    <tr>
                        <td class="text-start fw-bold sticky-col user-name-cell bg-white small">
                            <?= htmlspecialchars($member['name']) ?>
                            <?php if(!empty($member['rt'])): ?>
                                <span class="badge-rt ms-1 d-inline-block">RT <?= htmlspecialchars($member['rt']) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php foreach($events as $ev): ?>
                            <td class="p-1">
                                <?php 
                                    $dataUser = isset($attendanceMap[$member['id']][$ev['id']]) ? $attendanceMap[$member['id']][$ev['id']] : null;
                                    
                                    if (isset($dataUser['izin'])): 
                                        $totalI++;
                                        $infoIzin = $dataUser['izin'];
                                ?>
                                    <div class="badge bg-label-warning p-1 mb-1 w-100">IZIN</div>
                                    <div class="d-flex justify-content-center">
                                        <a href="uploads/<?= $infoIzin['selfie_path'] ?>" target="_blank" class="action-link" title="Lihat Bukti"><i class="bx bx-image-alt"></i></a>
                                        <a href="https://www.google.com/maps?q=<?= $infoIzin['latitude'] ?>,<?= $infoIzin['longitude'] ?>" target="_blank" class="action-link" title="Lokasi"><i class="bx bx-map"></i></a>
                                    </div>
                                    <div class="small mt-1 text-muted" style="font-size: 0.55rem; line-height: 1;"><?= htmlspecialchars($infoIzin['notes']) ?></div>

                                <?php elseif (isset($dataUser['in'])): 
                                        $totalH++;
                                        $infoIn = $dataUser['in'];
                                        $infoOut = isset($dataUser['out']) ? $dataUser['out'] : null;
                                ?>
                                    <div class="absen-box mb-1">
                                        <div class="text-success fw-bold" style="font-size: 0.65rem;">DATANG: <?= $infoIn['time'] ?></div>
                                        <div class="text-primary fw-bold" style="font-size: 0.65rem;">PULANG: <?= $infoOut ? $infoOut['time'] : '--:--' ?></div>
                                    </div>
                                    <div class="d-flex justify-content-center">
                                        <a href="uploads/<?= $infoIn['selfie_path'] ?>" target="_blank" class="action-link" title="Foto Masuk"><i class="bx bx-camera"></i></a>
                                        <a href="https://www.google.com/maps?q=<?= $infoIn['latitude'] ?>,<?= $infoIn['longitude'] ?>" target="_blank" class="action-link" title="Lokasi Masuk"><i class="bx bx-map-pin"></i></a>
                                        
                                        <?php if($infoOut): ?>
                                            <span class="text-muted mx-1">|</span>
                                            <?php if(!empty($infoOut['selfie_path'])): ?>
                                                <a href="uploads/<?= $infoOut['selfie_path'] ?>" target="_blank" class="action-link" title="Foto Pulang"><i class="bx bxs-camera"></i></a>
                                            <?php endif; ?>
                                            <a href="https://www.google.com/maps?q=<?= $infoOut['latitude'] ?>,<?= $infoOut['longitude'] ?>" target="_blank" class="action-link" title="Lokasi Pulang"><i class="bx bxs-map-pin"></i></a>
                                        <?php endif; ?>
                                    </div>
                                    
                                <?php else: ?>
                                    <span class="text-light">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        
                        <td class="fw-bold text-success bg-light border-start"><?= $totalH ?></td>
                        <td class="fw-bold text-warning bg-light"><?= $totalI ?></td>
                        <td class="fw-bold text-danger bg-light"><?= ($totalEventSaatIni - ($totalH + $totalI)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= $totalEventSaatIni > 0 ? $totalEventSaatIni + 4 : 5 ?>" class="text-center py-4 text-muted">
                            Tidak ada data anggota untuk filter ini.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-white py-3 border-top">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
            <span class="text-muted small mb-2 mb-md-0">Total: <strong><?= $total_records ?></strong> Anggota (Halaman <?= $hal ?> dari <?= $total_pages ?>)</span>
            
            <nav aria-label="Page navigation">
                <ul class="pagination mb-0">
                    <li class="page-item <?= ($hal <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?hal=<?= $hal - 1 ?><?= $link_tambahan ?>" <?= ($hal <= 1) ? 'tabindex="-1" aria-disabled="true"' : '' ?>>Previous</a>
                    </li>
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= ($hal == $i) ? 'active' : '' ?>">
                            <a class="page-link" href="?hal=<?= $i ?><?= $link_tambahan ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($hal >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?hal=<?= $hal + 1 ?><?= $link_tambahan ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>