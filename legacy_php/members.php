<?php
require 'app/config.php';
require 'app/auth.php';

// Proteksi: Admin dan Pengurus
if (!in_array($_SESSION['role'], ['admin', 'pengurus'])) {
    header("Location: member.php");
    exit;
}

$current_role = $_SESSION['role'];
$current_user_id = $_SESSION['user_id'];

// --- TANGKAP PARAMETER URL (PAGINASI & FILTER) ---
$hal = isset($_GET['hal']) ? (int)$_GET['hal'] : 1;
if ($hal < 1) $hal = 1;
$filter_rt = isset($_GET['rt']) ? $_GET['rt'] : '';

// Parameter tambahan untuk disisipkan saat redirect
$url_params = "&hal=" . $hal;
if (!empty($filter_rt)) {
    $url_params .= "&rt=" . urlencode($filter_rt);
}
$link_tambahan = !empty($filter_rt) ? "&rt=" . urlencode($filter_rt) : "";

// --- LOGIC 1: TAMBAH USER ---
if (isset($_POST['add_user'])) {
    $name = $_POST['name'];
    $username = preg_replace("/[^a-zA-Z0-9_]/", "", $_POST['username']); 
    $password = md5($_POST['password']); 
    $role = $_POST['role']; 
    $rt = !empty($_POST['rt']) ? $_POST['rt'] : NULL;
    
    if ($current_role === 'pengurus' && $role === 'admin') {
        $error = "Pengurus tidak memiliki izin menambah level Admin.";
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->rowCount() > 0) {
            $error = "Gagal: Username '$username' sudah digunakan!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (name, username, password_hash, role, rt) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $username, $password, $role, $rt])) {
                header("Location: members.php?msg=added" . $url_params); exit;
            }
        }
    }
}

// --- LOGIC 2: EDIT USER (DENGAN CEK DUPLIKASI USERNAME) ---
if (isset($_POST['edit_user'])) {
    $id = $_POST['user_id'];
    $name = $_POST['name'];
    $username = preg_replace("/[^a-zA-Z0-9_]/", "", $_POST['username']);
    $role = $_POST['role'];
    $rt = !empty($_POST['rt']) ? $_POST['rt'] : NULL;
    $password = $_POST['password'];

    $stmtTarget = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmtTarget->execute([$id]);
    $target = $stmtTarget->fetch();

    if ($current_role === 'pengurus' && $target['role'] === 'admin') {
        $error = "Pengurus dilarang mengubah data Admin.";
    } else {
        // Cek apakah username sudah dipakai oleh user LAIN
        $checkUser = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $checkUser->execute([$username, $id]);
        
        if ($checkUser->rowCount() > 0) {
            $error = "Gagal Update: Username '$username' sudah digunakan oleh pengguna lain!";
        } else {
            if (!empty($password)) {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, rt = ?, password_hash = MD5(?) WHERE id = ?");
                $stmt->execute([$name, $username, $role, $rt, $password, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, role = ?, rt = ? WHERE id = ?");
                $stmt->execute([$name, $username, $role, $rt, $id]);
            }
            header("Location: members.php?msg=updated" . $url_params); exit;
        }
    }
}

// --- LOGIC 3: HAPUS USER ---
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmtCheck = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmtCheck->execute([$id]);
    $target = $stmtCheck->fetch();

    if ($current_role === 'admin' && $id != $current_user_id) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        header("Location: members.php?msg=deleted" . $url_params); exit;
    } elseif ($current_role === 'pengurus' && in_array($target['role'], ['pengurus', 'member']) && $id != $current_user_id) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        header("Location: members.php?msg=deleted" . $url_params); exit;
    } else {
        $error = "Akses ditolak atau Anda mencoba menghapus Admin.";
    }
}

// --- LOGIC 4: QUERY & PAGINATION ---
$limit = 10; 
$offset = ($hal - 1) * $limit;
$where_sql = "";
$params = [];

if (!empty($filter_rt)) {
    $where_sql = "WHERE rt = :rt";
    $params[':rt'] = $filter_rt;
}

$count_query = "SELECT COUNT(*) FROM users $where_sql";
$total_stmt = $pdo->prepare($count_query);
if (!empty($filter_rt)) $total_stmt->bindValue(':rt', $filter_rt);
$total_stmt->execute();
$total_records = $total_stmt->fetchColumn();

$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;

$data_query = "SELECT * FROM users $where_sql ORDER BY FIELD(role, 'admin', 'pengurus', 'member'), name ASC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($data_query);
if (!empty($filter_rt)) $stmt->bindValue(':rt', $filter_rt);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

include 'includes/header.php'; 
?>

<style>
    .card { border-radius: 15px !important; border: none; overflow: hidden; }
    .btn { border-radius: 10px !important; }
    .rounded-pill-custom { border-radius: 50px !important; }
    .form-control, .form-select, .input-group-text { border-radius: 10px !important; }
    .modal-content { border-radius: 20px !important; border: none; overflow: hidden; }
    .table-responsive { border-radius: 0 0 15px 15px; }
    .badge-role { font-size: 0.65rem !important; padding: 0.3em 0.6em !important; font-weight: 600; border-radius: 6px !important; }
    .badge-rt { font-size: 0.7rem; background-color: #f1f3f5; color: #495057; border: 1px solid #dee2e6; border-radius: 6px; padding: 0.2rem 0.5rem; }
    .btn-xs { padding: 0.35rem 0.6rem !important; font-size: 0.8rem !important; border-radius: 8px !important; border: none !important; transition: all 0.2s; }
    .btn-xs:hover { transform: translateY(-1px); filter: brightness(90%); }
    .btn-group-modern { display: inline-flex; gap: 6px; }
    @media (max-width: 576px) { .card-header { padding: 1.2rem !important; } .table td { padding: 0.75rem 0.5rem !important; } }
</style>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <h5 class="mb-0 fw-bold text-primary"><i class='bx bx-group me-1'></i> Data Pengguna</h5>
        
        <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center">
            <form method="GET" action="members.php" class="d-flex gap-2">
                <select name="rt" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua RT</option>
                    <option value="05" <?= ($filter_rt == '05') ? 'selected' : '' ?>>RT 05</option>
                    <option value="06" <?= ($filter_rt == '06') ? 'selected' : '' ?>>RT 06</option>
                    <option value="07" <?= ($filter_rt == '07') ? 'selected' : '' ?>>RT 07</option>
                    <option value="08" <?= ($filter_rt == '08') ? 'selected' : '' ?>>RT 08</option>
                    <option value="09" <?= ($filter_rt == '09') ? 'selected' : '' ?>>RT 09</option>
                </select>
                <?php if(!empty($filter_rt)): ?>
                    <a href="members.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center"><i class='bx bx-x'></i></a>
                <?php endif; ?>
            </form>

            <button type="button" class="btn btn-info btn-sm px-3 shadow-sm rounded-pill-custom" data-bs-toggle="modal" data-bs-target="#modalAddUser">
                <i class="bx bx-plus"></i> Tambah
            </button>
        </div>
    </div>

    <?php if(isset($error)): ?><div class="alert alert-danger mx-3 my-2 rounded-3 p-2 small auto-dismiss"><?= $error ?></div><?php endif; ?>
    <?php if(isset($_GET['msg'])): ?><div class="alert alert-success mx-3 my-2 rounded-3 p-2 small auto-dismiss">Berhasil Disimpan!</div><?php endif; ?>

    <div class="table-responsive text-nowrap border-bottom mt-2">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th class="px-4" style="font-size: 0.85rem;">Informasi User</th>
                    <th class="text-center px-3" style="width: 120px; font-size: 0.85rem;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if(count($users) > 0): ?>
                    <?php foreach($users as $user): ?>
                    <tr>
                        <td class="px-4 align-middle">
                            <div class="fw-bold text-dark mb-1" style="font-size: 0.9rem;">
                                <?= htmlspecialchars($user['name']) ?>
                                <?php if(!empty($user['rt'])): ?>
                                    <span class="badge-rt ms-1">RT <?= htmlspecialchars($user['rt']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php $badge = ($user['role'] == 'admin') ? 'danger' : (($user['role'] == 'pengurus') ? 'primary' : 'info'); ?>
                            <span class="badge badge-role bg-label-<?= $badge ?>"><?= strtoupper($user['role']) ?></span>
                        </td>
                        <td class="text-center px-3 align-middle">
                            <?php 
                            $targetIsAdmin = ($user['role'] === 'admin');
                            $isSelf = ($user['id'] == $current_user_id);

                            if ($current_role === 'admin' || ($current_role === 'pengurus' && !$targetIsAdmin)): ?>
                                <div class="btn-group-modern">
                                    <button type="button" class="btn btn-sm btn-outline-warning shadow-sm" 
                                            onclick="openEditModal('<?= $user['id'] ?>', '<?= htmlspecialchars($user['name']) ?>', '<?= htmlspecialchars($user['username']) ?>', '<?= $user['role'] ?>', '<?= $user['rt'] ?>')">
                                        <i class="bx bx-edit-alt"></i>
                                    </button>
                                    <?php if (!$isSelf): ?>
                                        <a href="members.php?delete=<?= $user['id'] ?><?= $url_params ?>" class="btn btn-sm btn-danger shadow-sm" onclick="return confirm('Hapus user ini?')">
                                            <i class="bx bx-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted" style="font-size: 0.7rem;"><i class='bx bx-lock-alt'></i> Terkunci</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="2" class="text-center py-4 text-muted">Tidak ada data pengguna untuk kriteria ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer bg-white py-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center">
            <span class="text-muted small mb-2 mb-md-0">Total: <strong><?= $total_records ?></strong> User (Halaman <?= $hal ?> dari <?= $total_pages ?>)</span>
            
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

<div class="modal fade" id="modalAddUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content shadow-lg">
            <form method="POST">
                <div class="modal-header bg-primary text-white p-3 border-0">
                    <h5 class="modal-title text-white">Tambah User</h5>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-3"><input type="text" name="name" class="form-control" placeholder="Nama Lengkap" required></div>
                    <div class="mb-3"><input type="text" name="username" class="form-control" placeholder="Username" required></div>
                    <div class="mb-3"><input type="password" name="password" class="form-control" placeholder="Password" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <select name="role" class="form-select">
                                <option value="member">ANGGOTA</option>
                                <option value="pengurus">PENGURUS</option>
                                <?php if($current_role === 'admin'): ?>
                                    <option value="admin">ADMIN</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-5">
                            <select name="rt" class="form-select">
                                <option value="">- RT -</option>
                                <option value="05">05</option>
                                <option value="06">06</option>
                                <option value="07">07</option>
                                <option value="08">08</option>
                                <option value="09">09</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="submit" name="add_user" class="btn btn-primary w-100 fw-bold rounded-pill-custom py-2">SIMPAN DATA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditUser" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content shadow-lg border-0">
            <form method="POST">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header bg-warning p-3 border-0">
                    <h5 class="modal-title text-white">Edit User</h5>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-3"><input type="text" name="name" id="edit_name" class="form-control" required></div>
                    <div class="mb-3"><input type="text" name="username" id="edit_username" class="form-control" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <select name="role" id="edit_role" class="form-select" <?= ($current_role === 'pengurus') ? 'disabled' : '' ?>>
                                <option value="member">ANGGOTA</option>
                                <option value="pengurus">PENGURUS</option>
                                <option value="admin">ADMIN</option>
                            </select>
                            <?php if($current_role === 'pengurus'): ?>
                                <input type="hidden" name="role" id="edit_role_hidden">
                            <?php endif; ?>
                        </div>
                        <div class="col-5">
                            <select name="rt" id="edit_rt" class="form-select">
                                <option value="">- RT -</option>
                                <option value="05">05</option>
                                <option value="06">06</option>
                                <option value="07">07</option>
                                <option value="08">08</option>
                                <option value="09">09</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-0">
                        <div class="input-group">
                            <input type="password" name="password" id="edit_password" class="form-control" placeholder="Password Baru">
                            <span class="input-group-text cursor-pointer" onclick="toggleEditPass()"><i id="eyeIcon" class="bx bx-hide"></i></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-3 pt-0">
                    <button type="submit" name="edit_user" class="btn btn-warning w-100 fw-bold rounded-pill-custom py-2">SIMPAN PERUBAHAN</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/vendor/libs/jquery/jquery.js"></script>
<script>
$(document).ready(function() {
    setTimeout(function() { $(".auto-dismiss").fadeOut('slow'); }, 4000); // Pesan error dibikin sedikit lebih lama (4 detik) agar mudah terbaca
});

// Parameter JavaScript ini ditambah 'rt'
function openEditModal(id, name, username, role, rt) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_role').value = role;
    
    if(document.getElementById('edit_role_hidden')) {
        document.getElementById('edit_role_hidden').value = role;
    }
    
    // Set value RT di form edit
    document.getElementById('edit_rt').value = rt ? rt : '';
    
    document.getElementById('edit_password').value = ""; 
    new bootstrap.Modal(document.getElementById('modalEditUser')).show();
}

function toggleEditPass() {
    const p = document.getElementById('edit_password');
    const i = document.getElementById('eyeIcon');
    if (p.type === 'password') { p.type = 'text'; i.classList.replace('bx-hide', 'bx-show'); }
    else { p.type = 'password'; i.classList.replace('bx-show', 'bx-hide'); }
}

if (window.history.replaceState) {
    const url = new URL(window.location.href);
    url.searchParams.delete('msg');
    window.history.replaceState({path: url.href}, '', url.href);
}
</script>

<?php include 'includes/footer.php'; ?>