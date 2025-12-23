<?php
session_start();
include "../config/koneksi.php"; 


$DB_TABLE = "products"; 
$ID_COLUMN = "product_id"; 
$ID_PREFIX = "BRG"; 

$is_connected = isset($koneksi) && $koneksi instanceof mysqli && $koneksi->connect_error === null;
$conn_error = '';
if (!$is_connected) {
    $error_msg = $koneksi->connect_error ?? 'Variable $koneksi tidak terdefinisi.';
    $conn_error = '<div class="alert alert-danger">
        <strong>ERROR KONEKSI:</strong> Gagal terhubung ke database. Error: '. htmlspecialchars($error_msg) .'
    </div>';
}

if (!isset($_SESSION['username']) || !in_array($_SESSION['role'], ['manager', 'supervisor'])) {
    header("Location: dashboard.php"); 
    exit;
}

$user_role = $_SESSION['role'] ?? 'supervisor';


$message = $conn_error;
if (isset($_SESSION['crud_message'])) {
    $message .= $_SESSION['crud_message'];
    unset($_SESSION['crud_message']);
}


$id_from_url = $_GET['id'] ?? null; 

$data = [
    'product_id' => '', 
    'kode_barang' => '', 
    'nama_barang' => '', 
    'supplier_id' => '',
    'harga_beli' => '',
    'harga_jual' => '',
    'stok' => 0,
    'satuan' => '',
    'kategori' => '',
    'tanggal_ed' => '' 
];
$form_mode = 'add'; 


$supplier_options = [];
if ($is_connected) {
    $sql_supplier = "SELECT supplier_id, nama_supplier FROM supplier ORDER BY nama_supplier ASC";
    $res_supplier = $koneksi->query($sql_supplier);
    if ($res_supplier) {
        while ($row = $res_supplier->fetch_assoc()) {
            $supplier_options[] = $row;
        }
        $res_supplier->free();
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete' && $id_from_url && $is_connected) {
    $redirect_to_self = "Location: products.php";

    $stmt = $koneksi->prepare("DELETE FROM $DB_TABLE WHERE $ID_COLUMN = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id_from_url);
        if ($stmt->execute()) {
            $_SESSION['crud_message'] = '<div class="alert alert-success">Barang berhasil dihapus.</div>';
        } else {
            $error_msg = $stmt->errno == 1451 ? "Barang tidak dapat dihapus karena sudah tercatat dalam transaksi." : $stmt->error;
            $_SESSION['crud_message'] = '<div class="alert alert-danger">Gagal menghapus: '. htmlspecialchars($error_msg) .'</div>';
        }
        $stmt->close();
    } else {
        $_SESSION['crud_message'] = '<div class="alert alert-danger">Prepared statement error: '. htmlspecialchars($koneksi->error) .'</div>';
    }
    
    header($redirect_to_self);
    exit;
}


if ($is_connected && $_SERVER["REQUEST_METHOD"] == "POST") {
    $redirect_to_self = "Location: products.php";
    
    $post_id = $_POST['product_id'] ?? null;
    $kode = trim($_POST['kode_barang']);
    $nama = trim($_POST['nama_barang']);
    $supplier = $_POST['supplier_id'];
    $beli = floatval($_POST['harga_beli']);
    $jual = floatval($_POST['harga_jual']);
    $stok = intval($_POST['stok'] ?? 0); 
    $satuan = trim($_POST['satuan']);
    $kategori = trim($_POST['kategori']);
    $tanggal_ed = $_POST['tanggal_ed']; 
    $submit_type = $_POST['submit_type'] ?? 'save'; 
    
    if (empty($kode) || empty($nama) || empty($supplier) || $beli <= 0 || $jual <= 0) {
        $_SESSION['crud_message'] = '<div class="alert alert-danger">Kode Barang, Nama, Supplier, Harga Beli, dan Harga Jual wajib diisi/lebih dari nol.</div>';
        header($redirect_to_self);
        exit;
    }

    
    if ($submit_type == 'save' && empty($post_id)) {
       
        $sql_insert = "INSERT INTO $DB_TABLE (kode_barang, nama_barang, supplier_id, harga_beli, harga_jual, stok, satuan, kategori, tanggal_ed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $koneksi->prepare($sql_insert);

        if ($stmt) {
            $stmt->bind_param("ssiiddsss", $kode, $nama, $supplier, $beli, $jual, $stok, $satuan, $kategori, $tanggal_ed);
            if ($stmt->execute()) {
                $_SESSION['crud_message'] = '<div class="alert alert-success">Barang berhasil ditambahkan.</div>';
            } else {
                $error_msg = $stmt->errno == 1062 ? "Kode Barang sudah ada." : $stmt->error;
                $_SESSION['crud_message'] = '<div class="alert alert-danger">Gagal menyimpan data: '. htmlspecialchars($error_msg) .'</div>';
            }
            $stmt->close();
        } else {
            $_SESSION['crud_message'] = '<div class="alert alert-danger">Prepared statement error: '. htmlspecialchars($koneksi->error) .'</div>';
        }
        

    } elseif ($submit_type == 'update' && !empty($post_id)) {
        
      
        $sql_update = "UPDATE $DB_TABLE SET nama_barang=?, supplier_id=?, harga_beli=?, harga_jual=?, satuan=?, kategori=?, tanggal_ed=? WHERE $ID_COLUMN=?";
        $stmt = $koneksi->prepare($sql_update);
        
        if ($stmt) {
            $stmt->bind_param("sidissii", $nama, $supplier, $beli, $jual, $satuan, $kategori, $tanggal_ed, $post_id);
            
            if ($stmt->execute()) {
                $_SESSION['crud_message'] = '<div class="alert alert-success">Data barang berhasil diubah.</div>';
            } else {
                $_SESSION['crud_message'] = '<div class="alert alert-danger">Gagal mengubah data: '. htmlspecialchars($stmt->error) .'</div>';
            }
            $stmt->close();
        } else {
            $_SESSION['crud_message'] = '<div class="alert alert-danger">Prepared statement error: '. htmlspecialchars($koneksi->error) .'</div>';
        }
    }
    
    header($redirect_to_self);
    exit;
} 


if ($id_from_url && $is_connected) {
 
    $sql_get = "SELECT $ID_COLUMN, kode_barang, nama_barang, supplier_id, harga_beli, harga_jual, stok, satuan, kategori, tanggal_ed FROM $DB_TABLE WHERE $ID_COLUMN=?";
    $stmt = $koneksi->prepare($sql_get);

    if ($stmt) {
        $stmt->bind_param("i", $id_from_url);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows == 1) {
            $data = $res->fetch_assoc();
            $form_mode = 'edit';
        } else {
            $message .= '<div class="alert alert-danger">Data barang tidak ditemukan.</div>';
            $id_from_url = null;
        }
        $stmt->close();
    } else {
        $message .= '<div class="alert alert-danger">Error saat menyiapkan query: '. htmlspecialchars($koneksi->error) .'</div>';
    }
}


$products_list = [];
if ($is_connected) {
    
    $sql = "SELECT p.$ID_COLUMN, p.kode_barang, p.nama_barang, p.harga_beli, p.harga_jual, p.stok, p.satuan, p.kategori, p.tanggal_ed, s.nama_supplier 
            FROM $DB_TABLE p
            LEFT JOIN supplier s ON p.supplier_id = s.supplier_id
            ORDER BY p.$ID_COLUMN DESC";
    $res = $koneksi->query($sql);

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $products_list[] = $row;
        }
        $res->free();
    } else {
        $message .= '<div class="alert alert-danger">Gagal memuat data barang: '. htmlspecialchars($koneksi->error) .'</div>';
    }
}


function formatRupiah($angka) {
    return 'Rp' . number_format($angka, 0, ',', '.');
}


function getEdStatus($ed_date) {
    if (empty($ed_date)) return ['status' => 'N/A', 'class' => 'secondary'];
    
    $today = new DateTime();
    $ed = new DateTime($ed_date);
    $diff = $today->diff($ed);
    
    if ($diff->invert == 1) {
        return ['status' => 'Expired', 'class' => 'dark'];
    } elseif ($diff->days <= 30) { 
        return ['status' => 'Warning', 'class' => 'danger'];
    } elseif ($diff->days <= 90) { 
        return ['status' => 'Waspada', 'class' => 'warning'];
    } else {
        return ['status' => 'Aman', 'class' => 'success'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Barang | Minimarket App</title>
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
            <a class="nav-link active-page" href="products.php"><i class="fas fa-box"></i> Data Barang</a>
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
            <a class="nav-link" href="data_pegawai.php"><i class="fas fa-user-tie"></i> Data Pegawai</a>
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
<h2 class="fw-bold text-dark"><i class="fas fa-box me-2"></i> Data Barang</h2>
<span class="text-muted">Manajemen Data Stok Produk (Role: <b><?= strtoupper($user_role); ?></b>)</span>
</div>
<?= $message ?>
<div class="row">
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas <?= $form_mode=='edit'?'fa-edit':'fa-plus'; ?>"></i> <?= $form_mode=='edit'?'Ubah':'Input'; ?> Data Barang</h5>
            </div>
            <div class="card-body">
                <form id="productForm" method="POST" action="products.php">
                    <input type="hidden" name="product_id" value="<?= htmlspecialchars($data['product_id']) ?>">
                    <input type="hidden" name="submit_type" value="<?= $form_mode=='edit'?'update':'save'; ?>">
                    
                    <div class="mb-2">
                        <label class="form-label">ID Barang</label>
                        <input type="text" class="form-control" value="<?= $data['product_id'] ? $ID_PREFIX.str_pad($data['product_id'],4,'0',STR_PAD_LEFT) : '(Auto)'; ?>" readonly>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Kode Barang (SKU)</label>
                        <input type="text" class="form-control" name="kode_barang" required value="<?= htmlspecialchars($data['kode_barang']) ?>" <?= ($form_mode=='edit'?'readonly':'') ?> title="<?= ($form_mode=='edit'?'Kode barang tidak bisa diubah':'') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Nama Barang</label>
                        <input type="text" class="form-control" name="nama_barang" required value="<?= htmlspecialchars($data['nama_barang']) ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Supplier</label>
                        <select class="form-select" name="supplier_id" required>
                            <option value="">-- Pilih Supplier --</option>
                            <?php foreach($supplier_options as $s): ?>
                                <option value="<?= $s['supplier_id'] ?>" <?= ($data['supplier_id']==$s['supplier_id']?'selected':'') ?>>
                                    <?= htmlspecialchars($s['nama_supplier']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Harga Beli (Modal)</label>
                            <input type="number" class="form-control" name="harga_beli" required min="1" step="any" value="<?= htmlspecialchars($data['harga_beli']) ?>">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Harga Jual</label>
                            <input type="number" class="form-control" name="harga_jual" required min="1" step="any" value="<?= htmlspecialchars($data['harga_jual']) ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Satuan</label>
                            <input type="text" class="form-control" name="satuan" value="<?= htmlspecialchars($data['satuan']) ?>" placeholder="Pcs, Box, Kg">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label">Kategori</label>
                            <input type="text" class="form-control" name="kategori" value="<?= htmlspecialchars($data['kategori']) ?>" placeholder="Makanan, Minuman, dll">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Tanggal Kedaluwarsa (ED)</label>
                        <input type="date" class="form-control" name="tanggal_ed" value="<?= htmlspecialchars($data['tanggal_ed']) ?>">
                    </div>
                    
                    <?php if ($form_mode == 'edit'): ?>
                    <div class="mb-3">
                        <label class="form-label">Stok Saat Ini</label>
                        <input type="text" class="form-control fw-bold" value="<?= $data['stok'] ?>" readonly title="Stok hanya dapat diubah melalui Transaksi Pembelian/Penjualan/Penyesuaian">
                        <input type="hidden" name="stok" value="<?= $data['stok'] ?>"> 
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between gap-2">
                        <button type="submit" class="btn btn-success flex-fill"><i class="fas <?= $form_mode=='edit'?'fa-edit':'fa-save'; ?>"></i> <?= $form_mode=='edit'?'Ubah Data':'Simpan'; ?></button>
                        <?php if($form_mode=='edit'): ?>
                            <a href="products.php?action=delete&id=<?= $data['product_id'] ?>" 
                               class="btn btn-danger flex-fill" 
                               onclick="return confirm('Yakin hapus barang <?= htmlspecialchars($data['nama_barang']) ?>? Tindakan ini tidak dapat dibatalkan.')">
                                <i class="fas fa-trash"></i> Hapus
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php if($form_mode=='edit'): ?><a href="products.php" class="btn btn-secondary w-100 mt-2"><i class="fas fa-plus"></i> Form Baru</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between bg-dark text-white">
                <h5 class="mb-0">Daftar Stok Barang</h5>
                <div class="d-flex">
                    <input type="text" id="searchInput" class="form-control form-control-sm me-2" placeholder="Cari Barang...">
                    <button class="btn btn-light btn-sm" onclick="document.getElementById('searchInput').focus();"><i class="fas fa-search"></i> Cari</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 align-middle" id="productTable">
                        <thead class="table-light">
                        <tr>
                            <th onclick="sortTable(0)" style="cursor:pointer;">ID <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(1)" style="cursor:pointer;">Kode <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(2)" style="cursor:pointer;">Nama Barang <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(3)" style="cursor:pointer;">Stok <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(4)" style="cursor:pointer;">Hrg Jual <i class="fas fa-sort"></i></th>
                            <th onclick="sortTable(5)" style="cursor:pointer;">ED <i class="fas fa-sort"></i></th> <th>Supplier</th>
                        </tr>
                        </thead>
                        <tbody id="productBody">
                        <?php if($is_connected && !empty($products_list)):
                        foreach($products_list as $p):
                            $stok_alert = $p['stok'] < 10 ? 'bg-danger text-white' : ($p['stok'] < 50 ? 'bg-warning text-dark' : 'bg-success text-white');
                            $ed_status = getEdStatus($p['tanggal_ed']);
                            $ed_badge_class = 'bg-' . $ed_status['class'];
                        ?>
                        <tr onclick="window.location.href='products.php?id=<?= $p['product_id'] ?>'" 
                            style="cursor:pointer; <?= ($id_from_url==$p['product_id'])?'background-color:#e0f7fa;font-weight:bold;':''; ?>">
                            
                            <td><?= $ID_PREFIX.str_pad($p['product_id'],4,'0',STR_PAD_LEFT) ?></td>
                            <td><?= htmlspecialchars($p['kode_barang']) ?></td>
                            <td><?= htmlspecialchars($p['nama_barang']) ?><br><small class="text-muted">Kategori: <?= htmlspecialchars($p['kategori']) ?></small></td>
                            <td><span class="badge <?= $stok_alert ?>"><?= $p['stok'] ?> <?= htmlspecialchars($p['satuan']) ?></span></td>
                            <td><?= formatRupiah($p['harga_jual']) ?></td>
                            
                            <td>
                                <span class="badge <?= $ed_badge_class ?>">
                                    <?= empty($p['tanggal_ed']) ? 'N/A' : date('d/m/Y', strtotime($p['tanggal_ed'])) ?>
                                </span>
                            </td>
                            
                            <td><?= htmlspecialchars($p['nama_supplier'] ?? 'N/A') ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center"><?= $is_connected?'Tidak ada data barang.':'Gagal menampilkan data karena koneksi database bermasalah.' ?></td></tr>
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
const tableBody=document.getElementById("productBody"),searchInput=document.getElementById("searchInput");

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

function sortTable(colIndex){
    let rows=Array.from(tableBody.querySelectorAll("tr"));
    let visibleRows=rows.filter(row=>row.style.display!=="none");
    
    visibleRows.sort((a,b)=>{
        let x=a.cells[colIndex].innerText.toLowerCase().replace(/[rp.,]/g, ''),
            y=b.cells[colIndex].innerText.toLowerCase().replace(/[rp.,]/g, '');
        
    
        if(colIndex===0 || colIndex===3 || colIndex===4){
            const val_a=parseInt(x.replace(/brg/g, '') || 0),
                  val_b=parseInt(y.replace(/brg/g, '') || 0);
            return sortDirection?val_a-val_b:val_b-val_a;
        }
        
       
        if(colIndex===5){
          
            const parseDate = (dateStr) => {
                if(dateStr === 'n/a' || dateStr === 'expired') return sortDirection ? '99999999' : '00000000'; // Pindahkan N/A ke belakang
                const parts = dateStr.split('/');
                return parts.length === 3 ? `${parts[2]}${parts[1]}${parts[0]}` : dateStr;
            };
            const date_a = parseDate(a.cells[colIndex].querySelector('.badge').innerText.toLowerCase().trim());
            const date_b = parseDate(b.cells[colIndex].querySelector('.badge').innerText.toLowerCase().trim());

            return sortDirection ? date_a.localeCompare(date_b) : date_b.localeCompare(date_a);
        }

        
        return sortDirection?x.localeCompare(y):y.localeCompare(x);
    });
    
    sortDirection=!sortDirection;
    tableBody.innerHTML='';
    visibleRows.forEach(row=>tableBody.appendChild(row));
    rows.filter(row=>row.style.display==="none").forEach(row=>tableBody.appendChild(row));
    paginate()
}

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
    

    let startPage = Math.max(1, currentPage - 1);
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

    const table = document.getElementById('productTable');
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
    
 d
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
});
</script>
</body>
</html>
<?php if($is_connected && $koneksi instanceof mysqli) $koneksi->close(); ?>