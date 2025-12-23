<?php
session_start();
include "../config/koneksi.php"; 

$DB_TABLE = "pegawai";
$DB_USERS_TABLE = "users";

$is_connected = isset($koneksi) && $koneksi instanceof mysqli && $koneksi->connect_error === null;
$conn_error = '';
if (!$is_connected) {
    $error_msg = $koneksi->connect_error ?? 'Variable $koneksi tidak terdefinisi.';
    $conn_error = '<div class="alert alert-danger">
        <strong>ERROR KONEKSI:</strong> Gagal terhubung ke database. Error: '. htmlspecialchars($error_msg) .'
    </div>';
}

if (!isset($_SESSION['username']) || ($_SESSION['role'] !== 'manager')) {
    header("Location: dashboard.php"); 
    exit;
}
$user_role = $_SESSION['role'] ?? 'manager';
$current_user_id = $_SESSION['user_id'] ?? 1;
$nama_user = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'User Default';

$message = $conn_error;
if (isset($_SESSION['crud_message'])) {
    $message .= $_SESSION['crud_message'];
    unset($_SESSION['crud_message']);
}

$user_id_from_url = $_GET['id'] ?? null;

$data = [
    'user_id' => '', 
    'full_name' => '', 
    'username' => '', 
    'address' => '', 
    'phone_number' => '', 
    'role' => 'kasir',
    'status' => 'active',
    'password' => '' 
];
$form_mode = 'add';

if (isset($_GET['action']) && $_GET['action'] == 'delete' && $user_id_from_url && $is_connected) {
    $redirect_to_self = "Location: data_pegawai.php";
    if ($current_user_id == $user_id_from_url) {
        $_SESSION['crud_message'] = '<div class="alert alert-warning">Anda tidak boleh menghapus akun Anda sendiri!</div>';
    } else {
   
        $koneksi->begin_transaction();
        
        try {
           
            $stmt_users = $koneksi->prepare("DELETE FROM $DB_USERS_TABLE WHERE user_id = ?");
            if ($stmt_users) {
                $stmt_users->bind_param("i", $user_id_from_url);
                $stmt_users->execute();
                $stmt_users->close();
            }
            
          
            $stmt_pegawai = $koneksi->prepare("DELETE FROM $DB_TABLE WHERE user_id = ?");
            if ($stmt_pegawai) {
                $stmt_pegawai->bind_param("i", $user_id_from_url);
                if ($stmt_pegawai->execute()) {
                    $koneksi->commit();
                    $_SESSION['crud_message'] = '<div class="alert alert-success">Pegawai berhasil dihapus dari kedua tabel (users & pegawai).</div>';
                } else {
                    $koneksi->rollback();
                    $_SESSION['crud_message'] = '<div class="alert alert-danger">Gagal menghapus dari pegawai: '. htmlspecialchars($stmt_pegawai->error) .'</div>';
                }
                $stmt_pegawai->close();
            } else {
                $koneksi->rollback();
                $_SESSION['crud_message'] = '<div class="alert alert-danger">Prepared statement error (pegawai): '. htmlspecialchars($koneksi->error) .'</div>';
            }
        } catch (Exception $e) {
            $koneksi->rollback();
            $_SESSION['crud_message'] = '<div class="alert alert-danger">Error: '. htmlspecialchars($e->getMessage()) .'</div>';
        }
    }
    header($redirect_to_self);
    exit;
}

if ($is_connected && $_SERVER["REQUEST_METHOD"] == "POST") {
    $redirect_to_self = "Location: data_pegawai.php";
    
    $post_id = $_POST['user_id'] ?? null;
    $nama = trim($_POST['full_name']);
    $user = trim($_POST['username']);
    $pass = $_POST['password'];
    $hp = trim($_POST['phone_number']);
    $alamat = trim($_POST['address']);
    $jabatan = trim($_POST['role']);
    $status = trim($_POST['status'] ?? 'active');
    $submit_type = $_POST['submit_type'] ?? 'save';
    

    if (!in_array($status, ['active', 'inactive'])) {
        $status = 'active';
    } 
    
    if (empty($nama) || empty($user) || empty($jabatan)) {
        $_SESSION['crud_message'] = '<div class="alert alert-danger">Nama, Username, dan Jabatan tidak boleh kosong.</div>';
        header($redirect_to_self);
        exit;
    }

    if ($submit_type == 'save' && empty($post_id)) {
        if (empty($pass)) {
            $_SESSION['crud_message'] = '<div class="alert alert-danger">Password wajib diisi saat menambah pegawai baru.</div>';
        } else {
            $hashed = password_hash($pass, PASSWORD_DEFAULT);
            
            $sql_insert_pegawai = "INSERT INTO $DB_TABLE (full_name,username,password,phone_number,address,role,status) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_pegawai = $koneksi->prepare($sql_insert_pegawai);

            if ($stmt_pegawai) {
                $stmt_pegawai->bind_param("sssssss", $nama, $user, $hashed, $hp, $alamat, $jabatan, $status);
                $success_pegawai = $stmt_pegawai->execute();
                $stmt_pegawai->close();

                if ($success_pegawai) {
                    
                    $new_user_id = $koneksi->insert_id;
                    
                    
                    $sql_insert_users = "INSERT INTO $DB_USERS_TABLE (user_id, username, password, full_name, role, status)
                                         VALUES (?, ?, ?, ?, ?, ?)
                                         ON DUPLICATE KEY UPDATE
                                             username = VALUES(username),
                                             password = VALUES(password),
                                             full_name = VALUES(full_name),
                                             role = VALUES(role),
                                             status = VALUES(status)";
                    $stmt_users = $koneksi->prepare($sql_insert_users);
                    
                    if ($stmt_users) {
                        $stmt_users->bind_param("isssss", $new_user_id, $user, $hashed, $nama, $jabatan, $status);
                        if ($stmt_users->execute()) {
                            $_SESSION['crud_message'] = '<div class="alert alert-success">Pegawai berhasil ditambahkan (Pegawai & User tersinkronisasi).</div>';
                        } else {
                           
                            $stmt_rollback = $koneksi->prepare("DELETE FROM $DB_TABLE WHERE user_id = ?");
                            if ($stmt_rollback) {
                                $stmt_rollback->bind_param("i", $new_user_id);
                                $stmt_rollback->execute();
                                $stmt_rollback->close();
                            }
                            $_SESSION['crud_message'] = '<div class="alert alert-danger">Gagal menyimpan data pengguna (users). Pegawai dihapus secara otomatis. Error: '. htmlspecialchars($stmt_users->error) .'</div>';
                        }
                        $stmt_users->close();
                    } else {
                      
                        $stmt_rollback = $koneksi->prepare("DELETE FROM $DB_TABLE WHERE user_id = ?");
                        if ($stmt_rollback) {
                            $stmt_rollback->bind_param("i", $new_user_id);
                            $stmt_rollback->execute();
                            $stmt_rollback->close();
                        }
                        $_SESSION['crud_message'] = '<div class="alert alert-danger">Prepared statement error (Users): '. htmlspecialchars($koneksi->error) .'</div>';
                    }

                } else {
                    $error_message = $stmt_pegawai->errno == 1062 ? "Username sudah digunakan." : $stmt_pegawai->error;
                    $_SESSION['crud_message'] = '<div class="alert alert-danger">Gagal menyimpan data pegawai. Error: '. htmlspecialchars($error_message) .'</div>';
                }
            } else {
                $_SESSION['crud_message'] = '<div class="alert alert-danger">Prepared statement error (Pegawai): '. htmlspecialchars($koneksi->error) .'</div>';
            }
        }
    } elseif ($submit_type == 'update' && !empty($post_id)) {
        
        $koneksi->begin_transaction();
        
        try {
           
            $param_types = "sssssi";
            $bind_params = [$nama, $hp, $alamat, $jabatan, $status];
            
            $sql_update_pegawai = "UPDATE $DB_TABLE SET full_name=?, phone_number=?, address=?, role=?, status=?";
            
            if (!empty($pass)) {
                $hashed = password_hash($pass, PASSWORD_DEFAULT);
                $sql_update_pegawai .= ", password=?";
                $param_types .= "s";
                $bind_params[] = $hashed;
            }
            
            $sql_update_pegawai .= " WHERE user_id=?";
            $bind_params[] = $post_id;

            $stmt_pegawai = $koneksi->prepare($sql_update_pegawai);
            if ($stmt_pegawai) {
                array_unshift($bind_params, $param_types);
                $refs = [];
                foreach($bind_params as $key => $value) $refs[$key] = &$bind_params[$key];
                call_user_func_array([$stmt_pegawai, 'bind_param'], $refs);
                
                $success_pegawai = $stmt_pegawai->execute();
                $stmt_pegawai->close();
                
                if ($success_pegawai) {
                  
                    $param_types_users = "sss";
                    $bind_params_users = [$nama, $jabatan, $status];
                    
                    $sql_update_users = "UPDATE $DB_USERS_TABLE SET full_name=?, role=?, status=?";
                    
                    if (!empty($pass)) {
                        $sql_update_users .= ", password=?";
                        $param_types_users .= "s";
                        $bind_params_users[] = $hashed;
                    }
                    
                    $sql_update_users .= " WHERE user_id=?";
                    $param_types_users .= "i";
                    $bind_params_users[] = $post_id;
                    
                    $stmt_users = $koneksi->prepare($sql_update_users);
                    if ($stmt_users) {
                        array_unshift($bind_params_users, $param_types_users);
                        $refs_users = [];
                        foreach($bind_params_users as $key => $value) $refs_users[$key] = &$bind_params_users[$key];
                        call_user_func_array([$stmt_users, 'bind_param'], $refs_users);
                        
                        if ($stmt_users->execute()) {
                            $koneksi->commit();
                            $_SESSION['crud_message'] = '<div class="alert alert-success">Data pegawai berhasil diubah (tersinkronisasi di users & pegawai).</div>';
                        } else {
                            $koneksi->rollback();
                            $_SESSION['crud_message'] = '<div class="alert alert-danger">Gagal mengubah data users: '. htmlspecialchars($stmt_users->error) .'</div>';
                        }
                        $stmt_users->close();
                    } else {
                        $koneksi->rollback();
                        $_SESSION['crud_message'] = '<div class="alert alert-danger">Prepared statement error (users): '. htmlspecialchars($koneksi->error) .'</div>';
                    }
                } else {
                    $koneksi->rollback();
                    $_SESSION['crud_message'] = '<div class="alert alert-danger">Gagal mengubah data pegawai: '. htmlspecialchars($koneksi->error) .'</div>';
                }
            } else {
                $koneksi->rollback();
                $_SESSION['crud_message'] = '<div class="alert alert-danger">Prepared statement error (pegawai): '. htmlspecialchars($koneksi->error) .'</div>';
            }
        } catch (Exception $e) {
            $koneksi->rollback();
            $_SESSION['crud_message'] = '<div class="alert alert-danger">Error: '. htmlspecialchars($e->getMessage()) .'</div>';
        }
    }
    
    header($redirect_to_self);
    exit;
} 

if ($user_id_from_url && $is_connected) {
    $sql_get = "SELECT user_id,full_name,username,address,phone_number,role,status FROM $DB_TABLE WHERE user_id=?";
    $stmt = $koneksi->prepare($sql_get);

    if ($stmt) {
        $stmt->bind_param("i", $user_id_from_url);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows == 1) {
            $data = $res->fetch_assoc();
            $form_mode = 'edit';
        } else {
            $message .= '<div class="alert alert-danger">Data pegawai tidak ditemukan.</div>';
            $user_id_from_url = null;
        }
        $stmt->close();
    } else {
         $message .= '<div class="alert alert-danger">Error saat menyiapkan query: '. htmlspecialchars($koneksi->error) .'</div>';
    }
}

$pegawai_list = [];
if ($is_connected) {
    $sql = "SELECT user_id,username,password,full_name,role,address,phone_number,status 
            FROM $DB_TABLE 
            WHERE role IN ('kasir','supervisor','manager') 
            ORDER BY user_id DESC";
    $res = $koneksi->query($sql);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $pegawai_list[] = $row;
        }
        $res->free();
    } else {
        $message .= '<div class="alert alert-danger">Gagal memuat data pegawai: '. htmlspecialchars($koneksi->error) .'</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Pegawai | Minimarket App</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="../assets/css/modern-style.css">
<style>
#searchInput {
    border-radius: 12px;
    padding: 12px 16px;
    border: 2px solid var(--border-color);
    transition: all 0.3s;
}

#searchInput:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    outline: none;
}

.table-hover tbody tr:hover {
    background: linear-gradient(90deg, rgba(99, 102, 241, 0.05), rgba(99, 102, 241, 0.02)) !important;
    cursor: pointer;
    transform: scale(1.01);
}

th {
    user-select: none;
}
</style>
</head>
<body>
<nav id="sidebar">
    <h4 class="text-center mb-4 border-bottom pb-2 text-white">
        <i class="fas fa-store me-2"></i> Minimarket App
    </h4>
    <p class="text-center badge bg-primary mx-auto d-block w-50"><?= strtoupper($user_role); ?></p>
    
    <ul class="nav flex-column p-2">
        <li class="nav-item">
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>

        <?php if (in_array($user_role, ['manager', 'supervisor'])): ?>
        <li class="nav-item mt-3">
            <strong class="text-secondary small">MASTER DATA</strong>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="products.php"><i class="fas fa-box"></i> Data Barang</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="suppliers.php"><i class="fas fa-truck"></i> Data Supplier</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="data_pelanggan.php"><i class="fas fa-users"></i> Data Pelanggan</a>
        </li>
        <?php endif; ?>
        
        <?php if ($user_role == 'manager'): ?>
        <li class="nav-item">
            <a class="nav-link active-page" href="data_pegawai.php"><i class="fas fa-user-tie"></i> Data Pegawai</a>
        </li>
        <?php endif; ?>

        <li class="nav-item mt-3">
            <strong class="text-secondary small">TRANSAKSI</strong>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="sales_transaction.php"><i class="fas fa-cash-register"></i> Penjualan (POS)</a>
        </li>
        <?php if (in_array($user_role, ['manager', 'supervisor'])): ?>
        <li class="nav-item">
            <a class="nav-link" href="purchases_transaction.php"><i class="fas fa-shopping-cart"></i> Pembelian</a>
        </li>
        <?php endif; ?>

        <?php if (in_array($user_role, ['manager', 'supervisor'])): ?>
        <li class="nav-item mt-3">
            <strong class="text-secondary small">LAPORAN</strong>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="report_sales.php"><i class="fas fa-chart-bar"></i> Data Penjualan</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="report_purchases.php"><i class="fas fa-file-invoice-dollar"></i> Data Pembelian</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="report_products.php"><i class="fas fa-cubes"></i> Laporan Stok Barang</a>
        </li>
        <?php endif; ?>

        <li class="nav-item mt-5">
            <a class="nav-link text-danger" href="../auth/logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </li>
    </ul>
</nav>
<div id="main-content">
<div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
<h2 class="fw-bold text-dark"><i class="fas fa-user-tie me-2"></i> Data Pegawai</h2>
<span class="text-muted">Manajemen Pengguna (Role: <b><?= strtoupper($user_role); ?></b>)</span>
</div>
<?= $message ?>
<div class="row">
<div class="col-md-4">
<div class="card mb-4">
<div class="card-header bg-primary text-white">
<h5 class="mb-0"><i class="fas <?= $form_mode=='edit'?'fa-edit':'fa-plus'; ?>"></i> <?= $form_mode=='edit'?'Ubah':'Input'; ?> Data Pegawai</h5>
</div>
<div class="card-body">
<form id="pegawaiForm" method="POST" action="data_pegawai.php">
<input type="hidden" name="user_id" value="<?= htmlspecialchars($data['user_id']) ?>">
<input type="hidden" name="submit_type" value="<?= $form_mode=='edit'?'update':'save'; ?>">
<div class="mb-2"><label class="form-label">ID Pegawai</label><input type="text" class="form-control" value="<?= $data['user_id']?'P'.str_pad($data['user_id'],4,'0',STR_PAD_LEFT):'(Auto)'; ?>" readonly></div>
<div class="mb-2"><label class="form-label">Nama Pegawai</label><input type="text" class="form-control" name="full_name" required value="<?= htmlspecialchars($data['full_name']) ?>"></div>
<div class="mb-2"><label class="form-label">Username</label><input type="text" class="form-control" name="username" required value="<?= htmlspecialchars($data['username']) ?>" <?= ($form_mode=='edit'?'readonly':'') ?> title="<?= ($form_mode=='edit'?'Username tidak bisa diubah':'') ?>"></div>
<div class="mb-2"><label class="form-label">Jabatan (Role)</label><div class="d-flex gap-3">
<?php foreach(['kasir','supervisor','manager'] as $role_option): $checked=($data['role']==$role_option)?'checked':''; ?>
<div class="form-check"><input class="form-check-input" type="radio" name="role" value="<?= $role_option; ?>" <?= $checked; ?> required><label class="form-check-label"><?= ucfirst($role_option); ?></label></div>
<?php endforeach; ?>
</div></div>
<div class="mb-2"><label class="form-label">No. HP</label><input type="text" class="form-control" name="phone_number" value="<?= htmlspecialchars($data['phone_number']) ?>"></div>
<div class="mb-2"><label class="form-label">Alamat</label><textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($data['address']) ?></textarea></div>
<div class="mb-2"><label class="form-label">Status</label>
    <div class="d-flex gap-3">
        <div class="form-check">
            <input class="form-check-input" type="radio" name="status" value="active" <?= ($data['status'] ?? 'active') == 'active' ? 'checked' : '' ?> required>
            <label class="form-check-label text-success">
                <i class="fas fa-check-circle"></i> Aktif
            </label>
        </div>
        <div class="form-check">
            <input class="form-check-input" type="radio" name="status" value="inactive" <?= ($data['status'] ?? 'active') == 'inactive' ? 'checked' : '' ?> required>
            <label class="form-check-label text-danger">
                <i class="fas fa-times-circle"></i> Tidak Aktif
            </label>
        </div>
    </div>
</div>
<div class="mb-3"><label class="form-label">Password <?= ($form_mode=='edit'?'<span class="text-muted small">(Kosongkan jika tidak diubah)</span>':'*') ?></label><input type="password" class="form-control" name="password" <?= ($form_mode=='add'?'required':'') ?>></div>
<div class="d-flex justify-content-between gap-2">
<button type="submit" class="btn btn-success flex-fill"><i class="fas <?= $form_mode=='edit'?'fa-edit':'fa-save'; ?>"></i> <?= $form_mode=='edit'?'Ubah Data':'Simpan'; ?></button>
<?php if($form_mode=='edit'):
$is_current_user=($current_user_id==$data['user_id']);
$delete_class=$is_current_user?'disabled':'';
$delete_title=$is_current_user?'Tidak dapat menghapus akun Anda sendiri.':'';
$delete_onclick=$is_current_user?'return false;':"return confirm('Yakin hapus pegawai ".htmlspecialchars($data['full_name'])."? Tindakan ini tidak dapat dibatalkan.')";
?>
<a href="data_pegawai.php?action=delete&id=<?= $data['user_id'] ?>" class="btn btn-danger flex-fill <?= $delete_class ?>" title="<?= $delete_title ?>" onclick="<?= $delete_onclick ?>"><i class="fas fa-trash"></i> Hapus</a>
<?php endif; ?>
</div>
<?php if($form_mode=='edit'): ?><a href="data_pegawai.php" class="btn btn-secondary w-100 mt-2"><i class="fas fa-plus"></i> Form Baru</a><?php endif; ?>
</form>
</div>
</div>
</div>
<div class="col-md-8">
<div class="card">
<div class="card-header d-flex justify-content-between bg-dark text-white">
<h5 class="mb-0">Daftar Pegawai</h5>
<div class="d-flex"><input type="text" id="searchInput" class="form-control form-control-sm me-2" placeholder="Cari Pegawai..."><button class="btn btn-light btn-sm" onclick="document.getElementById('searchInput').focus();"><i class="fas fa-search"></i> Cari</button></div>
</div>
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-hover table-striped mb-0 align-middle" id="pegawaiTable">
<thead class="table-light">
<tr><th onclick="sortTable(0)" style="cursor:pointer;">ID <i class="fas fa-sort"></i></th><th onclick="sortTable(1)" style="cursor:pointer;">Nama Pegawai <i class="fas fa-sort"></i></th><th onclick="sortTable(2)" style="cursor:pointer;">Jabatan <i class="fas fa-sort"></i></th><th>Status</th><th>Alamat</th><th>No. HP</th></tr>
</thead>
<tbody id="pegawaiBody">
<?php if($is_connected && !empty($pegawai_list)):
foreach($pegawai_list as $p):
$badge_color='secondary'; if($p['role']=='manager') $badge_color='primary'; elseif($p['role']=='supervisor') $badge_color='info'; elseif($p['role']=='kasir') $badge_color='success';
?>
<tr onclick="window.location.href='data_pegawai.php?id=<?= $p['user_id'] ?>'" style="cursor:pointer; <?= ($user_id_from_url==$p['user_id'])?'background-color:#fce8e8;font-weight:bold;':''; ?> <?= (($p['status'] ?? 'active') == 'inactive') ? 'opacity:0.6;' : ''; ?>">
<td>P<?= str_pad($p['user_id'],4,'0',STR_PAD_LEFT) ?></td>
<td><?= htmlspecialchars($p['full_name']) ?></td>
<td><span class="badge bg-<?= $badge_color ?>"><?= strtoupper($p['role']) ?></span></td>
<td>
    <?php if(($p['status'] ?? 'active') == 'active'): ?>
        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Aktif</span>
    <?php else: ?>
        <span class="badge bg-danger"><i class="fas fa-times-circle"></i> Tidak Aktif</span>
    <?php endif; ?>
</td>
<td><?= htmlspecialchars($p['address']) ?></td>
<td><?= htmlspecialchars($p['phone_number']) ?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="6" class="text-center"><?= $is_connected?'Tidak ada data pegawai.':'Gagal menampilkan data karena koneksi database bermasalah.' ?></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<div class="card-footer d-flex justify-content-center pt-3 pb-0">
<nav><ul class="pagination" id="pagination"></ul></nav>
</div>
</div>
</div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let sortDirection=true,currentPage=1,rowsPerPage=10;
const tableBody=document.getElementById("pegawaiBody"),searchInput=document.getElementById("searchInput");
if(searchInput){
    searchInput.addEventListener("keyup",function(){
        let filter=this.value.toLowerCase();
        let rows=Array.from(tableBody.querySelectorAll("tr"));
        rows.forEach(row=>{
            let text=row.textContent.toLowerCase();
            row.style.display=text.includes(filter)?"":"none";
        });
     
        currentPage = 1;
        paginate();
    });
}
function sortTable(colIndex){let rows=Array.from(tableBody.querySelectorAll("tr"));let visibleRows=rows.filter(row=>row.style.display!=="none");visibleRows.sort((a,b)=>{let x=a.cells[colIndex].innerText.toLowerCase(),y=b.cells[colIndex].innerText.toLowerCase();if(colIndex===0){const id_a=parseInt(x.replace('p','')),id_b=parseInt(y.replace('p',''));return sortDirection?id_a-id_b:id_b-id_a}return sortDirection?x.localeCompare(y):y.localeCompare(x)});sortDirection=!sortDirection;tableBody.innerHTML='';visibleRows.forEach(row=>tableBody.appendChild(row));rows.filter(row=>row.style.display==="none").forEach(row=>tableBody.appendChild(row));paginate()}
function paginate(){
    let allRows=Array.from(tableBody.querySelectorAll("tr")),
        visibleRows=allRows.filter(row=>row.style.display!=="none"),
        totalRows=visibleRows.length,
        totalPages=Math.ceil(totalRows/rowsPerPage);
    
    if(currentPage>totalPages&&totalPages>0)currentPage=totalPages;
    else if(totalPages===0)currentPage=1;
    
    visibleRows.forEach(row=>row.style.display="none");
    let start=(currentPage-1)*rowsPerPage,
        end=start+rowsPerPage;
    
    for(let i=start;i<end&&i<totalRows;i++)visibleRows[i].style.display="";
    
    let pagination=document.getElementById("pagination");
    pagination.innerHTML="";
    

    if(totalPages > 0) {
        pagination.innerHTML += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
            <button class="page-link" ${currentPage === 1 ? 'disabled' : ''} onclick="gotoPage(${currentPage - 1})" type="button">
                <i class="fas fa-chevron-left"></i>
            </button>
        </li>`;
    }
    

    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, currentPage + 2);
    
    if(startPage > 1) {
        pagination.innerHTML += `<li class="page-item"><button class="page-link" onclick="gotoPage(1)" type="button">1</button></li>`;
        if(startPage > 2) {
            pagination.innerHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    for(let i=startPage;i<=endPage;i++){
        pagination.innerHTML+=`<li class="page-item ${i===currentPage?'active':''}">
            <button class="page-link" onclick="gotoPage(${i})" type="button">${i}</button>
        </li>`;
    }
    
    if(endPage < totalPages) {
        if(endPage < totalPages - 1) {
            pagination.innerHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        pagination.innerHTML += `<li class="page-item"><button class="page-link" onclick="gotoPage(${totalPages})" type="button">${totalPages}</button></li>`;
    }
    
   
    if(totalPages > 0) {
        pagination.innerHTML += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
            <button class="page-link" ${currentPage === totalPages ? 'disabled' : ''} onclick="gotoPage(${currentPage + 1})" type="button">
                <i class="fas fa-chevron-right"></i>
            </button>
        </li>`;
    }
}
function gotoPage(page){
    if(page < 1) return;
    const totalRows = Array.from(tableBody.querySelectorAll("tr")).filter(row=>row.style.display!=="none").length;
    const totalPages = Math.ceil(totalRows/rowsPerPage);
    if(page > totalPages && totalPages > 0) return;
    
    currentPage = page;
    paginate();

    const table = document.querySelector('.table');
    if(table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
}


document.addEventListener('click', function(e) {
    const pageLink = e.target.closest('.page-link');
    if(!pageLink) return;
    
    const pageItem = pageLink.closest('.page-item');
    

    if(pageItem && pageItem.classList.contains('disabled')) {
        e.preventDefault();
        e.stopPropagation();
        return false;
    }
    

    if(pageLink.disabled) {
        e.preventDefault();
        e.stopPropagation();
        return false;
    }
    
   
    const onclick = pageLink.getAttribute('onclick');
    if(onclick) {
        const match = onclick.match(/gotoPage\((\d+)\)/);
        if(match) {
            e.preventDefault();
            e.stopPropagation();
            const pageNum = parseInt(match[1]);
            gotoPage(pageNum);
            return false;
        }
    }
    
 
    const pageText = pageLink.textContent.trim();
    if(pageText && !isNaN(pageText) && pageText !== '...') {
        e.preventDefault();
        e.stopPropagation();
        gotoPage(parseInt(pageText));
        return false;
    }
}, true); 

document.addEventListener('DOMContentLoaded',function(){
    paginate();
    const btnHapus=document.getElementById('btnHapus');
    if(btnHapus&&btnHapus.classList.contains('disabled')){
        btnHapus.title='Tidak dapat menghapus akun Anda sendiri.';
        btnHapus.addEventListener('click',function(e){e.preventDefault()});
    }
});
</script>
</body>
</html>
<?php if($is_connected && $koneksi instanceof mysqli) $koneksi->close(); ?>
