<?php

session_start();
include "../config/koneksi.php";

date_default_timezone_set('Asia/Jakarta');


$DB_HEADER = "purchases_header"; 
$DB_DETAIL = "purchases_detail"; 
$DB_PRODUCTS = "products"; 
$DB_SUPPLIER = "supplier"; 


$is_connected = isset($koneksi) && $koneksi instanceof mysqli && $koneksi->connect_error === null;
$conn_error = '';

if (!$is_connected) {
    $error_msg = $koneksi->connect_error ?? 'Variable $koneksi tidak terdefinisi.';
    $conn_error = '<div class="alert alert-danger"><strong>ERROR KONEKSI:</strong> Gagal terhubung ke database. Error: '. htmlspecialchars($error_msg) .'</div>';
}


if (!isset($_SESSION['username'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? 'kasir';
$nama_user = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Kasir';


if (empty($user_id)) {
    $_SESSION['trans_message'] = '<div class="alert alert-danger">User ID tidak valid. Silakan login ulang.</div>';
    header("Location: ../auth/login.php");
    exit;
}


if (!in_array($user_role, ['manager', 'supervisor'])) {
    $_SESSION['trans_message'] = '<div class="alert alert-warning">Anda tidak memiliki akses untuk transaksi pembelian.</div>';
    header("Location: dashboard.php");
    exit;
}


$pegawai_data = null;
if ($is_connected) {
    $stmt_pegawai = $koneksi->prepare("SELECT full_name FROM pegawai WHERE user_id = ? UNION SELECT full_name FROM users WHERE user_id = ? LIMIT 1");
    if ($stmt_pegawai) {
        $stmt_pegawai->bind_param("ii", $user_id, $user_id);
        $stmt_pegawai->execute();
        $result_pegawai = $stmt_pegawai->get_result();
        if ($result_pegawai->num_rows > 0) {
            $pegawai_data = $result_pegawai->fetch_assoc();
            $nama_user = $pegawai_data['full_name'] ?? $nama_user;
        }
        $stmt_pegawai->close();
    }
}


$next_transaction_no = 'TRB000001';
if ($is_connected) {

    $table_check = $koneksi->query("SHOW TABLES LIKE 'purchases_header'");
    if ($table_check && $table_check->num_rows > 0) {
        $stmt_max = $koneksi->query("SELECT MAX(purchase_id) as max_id FROM purchases_header");
        if ($stmt_max && $row = $stmt_max->fetch_assoc()) {
            $next_id = ($row['max_id'] ?? 0) + 1;
            $next_transaction_no = 'TRB' . str_pad($next_id, 6, '0', STR_PAD_LEFT);
        }
    }
}


$message = $conn_error;
if (isset($_SESSION['trans_message'])) {
    $message .= $_SESSION['trans_message'];
    unset($_SESSION['trans_message']);
}


function formatRupiah($angka) {
    return 'Rp' . number_format($angka, 0, ',', '.');
}


if ($is_connected && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'checkout') {
    $redirect_to_self = "Location: purchases_transaction.php";
    

    $table_check = $koneksi->query("SHOW TABLES LIKE 'purchases_header'");
    if (!$table_check || $table_check->num_rows == 0) {
        
        $installer_path = __DIR__ . '/../config/create_purchases_tables.sql';
        if (!file_exists($installer_path)) {
            throw new Exception("Tabel pembelian belum ada dan file installer tidak ditemukan: create_purchases_tables.sql. Silakan import database db_minimarket atau tambahkan file installer.");
        }
        $sql_file = file_get_contents($installer_path);
        if ($sql_file === false || trim($sql_file) === '') {
            throw new Exception("File installer create_purchases_tables.sql kosong atau gagal dibaca.");
        }
        if (!$koneksi->multi_query($sql_file)) {
            throw new Exception("Gagal menjalankan installer tabel pembelian: " . $koneksi->error);
        }
        while ($koneksi->next_result()) {;} 
    }
    
    
    if ($koneksi->autocommit(FALSE)) { 
        $koneksi->begin_transaction();
    }

    try {
       
        if (empty($_POST['items_json'])) {
            throw new Exception("Data keranjang belanja tidak valid.");
        }
        
        $items = json_decode($_POST['items_json'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($items) || !is_array($items)) {
            throw new Exception("Format data keranjang belanja tidak valid.");
        }
        

        foreach ($items as $item) {
            if (empty($item['id']) || empty($item['qty']) || empty($item['price'])) {
                throw new Exception("Data item tidak lengkap.");
            }
            if (intval($item['qty']) <= 0) {
                throw new Exception("Quantity harus lebih dari 0.");
            }
            if (floatval($item['price']) <= 0) {
                throw new Exception("Harga beli harus lebih dari 0.");
            }
        }
        
   
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        if ($supplier_id <= 0) {
            throw new Exception("Supplier harus dipilih.");
        }
        
        // Validasi supplier ada
        $stmt_supplier = $koneksi->prepare("SELECT supplier_id FROM $DB_SUPPLIER WHERE supplier_id = ?");
        $stmt_supplier->bind_param("i", $supplier_id);
        $stmt_supplier->execute();
        $result_supplier = $stmt_supplier->get_result();
        if ($result_supplier->num_rows === 0) {
            throw new Exception("Supplier tidak ditemukan.");
        }
        $stmt_supplier->close();
        
        $total_bayar = floatval($_POST['final_total'] ?? 0);
        $metode_bayar = trim($_POST['metode_bayar'] ?? 'Cash');
        
        if ($total_bayar <= 0) {
            throw new Exception("Total pembayaran harus lebih dari 0.");
        }
        
        // Validasi metode bayar
        $allowed_methods = ['Cash', 'Debit', 'Credit', 'Transfer'];
        if (!in_array($metode_bayar, $allowed_methods)) {
            $metode_bayar = 'Cash';
        }
        
        // 1. INSERT KE purchases_header
        $sql_header = "INSERT INTO $DB_HEADER (tanggal_transaksi, user_id, supplier_id, total_bayar, metode_bayar) 
                       VALUES (CURDATE(), ?, ?, ?, ?)";
        $stmt_header = $koneksi->prepare($sql_header);
        if (!$stmt_header) throw new Exception("Prepare header gagal: " . $koneksi->error);
        $stmt_header->bind_param("iids", $user_id, $supplier_id, $total_bayar, $metode_bayar);

        if (!$stmt_header->execute()) throw new Exception("Execute header gagal: " . $stmt_header->error);
        
        $purchase_id = $koneksi->insert_id;
        $stmt_header->close();

        // 2. LOOP & INSERT KE purchases_detail + UPDATE STOCK + UPDATE HARGA_BELI
        $sql_detail = "INSERT INTO $DB_DETAIL (purchase_id, product_id, qty, harga_beli, subtotal) VALUES (?, ?, ?, ?, ?)";
        $stmt_detail = $koneksi->prepare($sql_detail);
        if (!$stmt_detail) throw new Exception("Prepare detail gagal: " . $koneksi->error);

        // Update stock: TAMBAH stok (bukan kurangi seperti penjualan)
        $sql_update_stock = "UPDATE $DB_PRODUCTS SET stok = stok + ? WHERE product_id = ?"; 
        $stmt_stock = $koneksi->prepare($sql_update_stock);
        if (!$stmt_stock) throw new Exception("Prepare stock gagal: " . $koneksi->error);
        
        // Update harga_beli: Update harga beli produk sesuai harga beli terbaru
        $sql_update_harga = "UPDATE $DB_PRODUCTS SET harga_beli = ? WHERE product_id = ?";
        $stmt_harga = $koneksi->prepare($sql_update_harga);
        if (!$stmt_harga) throw new Exception("Prepare harga gagal: " . $koneksi->error);
        
        foreach ($items as $item) {
            $product_id = intval($item['id']);
            $qty = intval($item['qty']);
            $harga_beli = floatval($item['price']); // Harga beli dari form
            $subtotal = $qty * $harga_beli;
            
            // Validasi produk ada
            $stmt_check = $koneksi->prepare("SELECT product_id, nama_barang FROM $DB_PRODUCTS WHERE product_id = ?");
            $stmt_check->bind_param("i", $product_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check->num_rows === 0) {
                throw new Exception("Produk dengan ID {$product_id} tidak ditemukan.");
            }
            $stmt_check->close();
            
            // Update Stok (TAMBAH)
            $stmt_stock->bind_param("ii", $qty, $product_id);
            if (!$stmt_stock->execute()) {
                throw new Exception("Update stok gagal untuk product_id {$product_id}: " . $stmt_stock->error);
            }
            
            // Update Harga Beli (update dengan harga beli terbaru)
            $stmt_harga->bind_param("di", $harga_beli, $product_id);
            if (!$stmt_harga->execute()) {
                throw new Exception("Update harga beli gagal untuk product_id {$product_id}: " . $stmt_harga->error);
            }
            
            // Insert Detail
            $stmt_detail->bind_param("iiidd", $purchase_id, $product_id, $qty, $harga_beli, $subtotal);
            if (!$stmt_detail->execute()) {
                throw new Exception("Insert detail gagal untuk product_id {$product_id}: " . $stmt_detail->error);
            }
        }

        $stmt_detail->close();
        $stmt_stock->close();
        $stmt_harga->close();
        
        // COMMIT jika semua berhasil
        $koneksi->commit();
        $transaction_no = 'TRB' . str_pad($purchase_id, 6, '0', STR_PAD_LEFT);
        $_SESSION['trans_message'] = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>Transaksi Pembelian Berhasil!</strong><br>No. Transaksi: <strong>'.$transaction_no.'</strong><br>Total: <strong>'.formatRupiah($total_bayar).'</strong><br>Metode: <strong>'.$metode_bayar.'</strong></div>';
        
    } catch (Exception $e) {
        // ROLLBACK jika terjadi error
        if ($koneksi->in_transaction) {
            $koneksi->rollback();
        }
        $_SESSION['trans_message'] = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>Transaksi GAGAL!</strong><br>'. nl2br(htmlspecialchars($e->getMessage())) .'</div>';
    } catch (Error $e) {
        // Handle PHP errors
        if ($koneksi->in_transaction) {
            $koneksi->rollback();
        }
        $_SESSION['trans_message'] = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>Error Sistem!</strong><br>'. htmlspecialchars($e->getMessage()) .'</div>';
    }

    // Kembalikan ke autocommit=TRUE
    $koneksi->autocommit(TRUE);
    
    header($redirect_to_self);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pembelian | Minimarket App</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/modern-style.css">
<style>
/* Additional styles for transaction pages */
.btn-search {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 12px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
}

.btn-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
}

.btn-save {
    background: linear-gradient(135deg, var(--secondary-color), #059669);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 12px;
    cursor: pointer;
    margin-right: 10px;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
    transition: all 0.3s;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5);
}

.btn-cancel {
    background: linear-gradient(135deg, var(--danger-color), #dc2626);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 12px;
    cursor: pointer;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
    transition: all 0.3s;
}

.btn-cancel:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(239, 68, 68, 0.5);
}

.btn-add {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 12px;
    cursor: pointer;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
    transition: all 0.3s;
}

.btn-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
}

.cart-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow-md);
}

.cart-table th {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    padding: 16px;
    text-align: left;
    border: none;
    font-weight: 600;
    color: var(--text-primary);
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
}

.cart-table td {
    padding: 16px;
    border-bottom: 1px solid var(--border-color);
}

.cart-table tbody tr {
    transition: all 0.2s;
}

.cart-table tbody tr:hover {
    background: linear-gradient(90deg, rgba(99, 102, 241, 0.05), rgba(99, 102, 241, 0.02));
}

.payment-input {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid var(--border-color);
    border-radius: 12px;
    margin-bottom: 10px;
    transition: all 0.3s;
}

.payment-input:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    outline: none;
}

/* Search Results */
#productResults, #supplierResults {
    max-height: 250px; 
    overflow-y: auto;
    width: 100%; 
    position: absolute; 
    z-index: 1050; 
    top: 100%; 
    left: 0;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    background:white;
    border:1px solid #ddd;
}
.product-item, .supplier-item {
    cursor: pointer;
    padding:10px;
    border-bottom:1px solid #eee;
}
.product-item:hover, .supplier-item:hover {
    background-color:#f8f9fa;
}
.search-wrapper{position:relative;}

/* Modal Styling */
.modal-content {
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}
.modal-header {
    border-bottom: 2px solid rgba(255,255,255,0.2);
}
.modal-body {
    padding: 15px;
}
.modal-footer {
    border-top: 1px solid #dee2e6;
}

/* Search Bar in Modal */
.modal-search-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
    padding: 10px;
    background-color: #f8f9fa;
    border-radius: 5px;
}
.modal-search-bar input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.modal-search-bar .btn-search-modal {
    background-color: #007bff;
    color: white;
    border: none;
    padding: 8px 20px;
    border-radius: 4px;
    cursor: pointer;
}
.modal-search-bar .btn-search-modal:hover {
    background-color: #0056b3;
}
.modal-search-bar .btn-new-item {
    background-color: #28a745;
    color: white;
    border: none;
    padding: 8px 20px;
    border-radius: 4px;
    cursor: pointer;
}
.modal-search-bar .btn-new-item:hover {
    background-color: #218838;
}

/* Table in Modal */
.modal-table-container {
    max-height: 450px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.modal-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    margin: 0;
}
.modal-table thead {
    position: sticky;
    top: 0;
    z-index: 10;
    background-color: #f8f9fa;
}
.modal-table th {
    background-color: #f8f9fa;
    padding: 12px 10px;
    text-align: left;
    border: 1px solid #ddd;
    font-weight: bold;
    font-size: 13px;
    color: #333;
}
.modal-table td {
    padding: 10px;
    border: 1px solid #ddd;
    font-size: 13px;
}
.modal-table tbody tr {
    cursor: pointer;
    transition: background-color 0.2s;
}
.modal-table tbody tr:hover {
    background-color: #e3f2fd !important;
}
.modal-table tbody tr.selected {
    background-color: #bbdefb !important;
}
.modal-table tbody tr:nth-child(even) {
    background-color: #f9f9f9;
}
.modal-table tbody tr:nth-child(even):hover {
    background-color: #e3f2fd !important;
}
.text-end {
    text-align: right;
}
.text-center {
    text-align: center;
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
            <a class="nav-link active-page" href="purchases_transaction.php"><i class="fas fa-shopping-cart"></i> Pembelian</a>
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
    <!-- Header Hijau -->
    <div class="header-green">
        <div class="header-subtitle"><i class="fas fa-file-invoice me-2"></i>FORM TRANSAKSI PEMBELIAN</div>
        <div class="header-title">Transaksi Pembelian</div>
    </div>

    <?= $message ?>

    <!-- Informasi Transaksi -->
    <div class="form-container">
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label>Nama Pegawai:</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($nama_user) ?>" readonly>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>No Transaksi:</label>
                    <input type="text" class="form-control" id="transactionNo" value="<?= htmlspecialchars($next_transaction_no) ?>" readonly>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    <label>Tanggal:</label>
                    <input type="text" class="form-control" value="<?= date('d M Y') ?>" readonly>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Input Supplier dan Barang -->
    <div class="form-container">
        <div class="row">
            <!-- Bagian Kiri: Supplier -->
            <div class="col-md-6">
                <div class="form-group">
                    <label>ID Supplier</label>
                    <div class="search-wrapper">
                        <div class="input-group">
                            <input type="text" class="form-control" id="supplierIdInput" placeholder="Masukkan ID Supplier" autocomplete="off">
                            <button type="button" class="btn btn-search" onclick="openSupplierSearchModal()">
                                <i class="fas fa-search"></i> Cari
                            </button>
                        </div>
                        <div id="supplierResults"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Nama Supplier</label>
                    <input type="text" class="form-control" id="supplierNameInput" placeholder="Nama Supplier" readonly>
                </div>
            </div>

            <!-- Bagian Kanan: Barang -->
            <div class="col-md-6">
                <div class="form-group">
                    <label>Kode Barang</label>
                    <div class="search-wrapper">
                        <div class="input-group">
                            <input type="text" class="form-control" id="productCodeInput" placeholder="Masukkan Kode Barang" autocomplete="off">
                            <button type="button" class="btn btn-search" onclick="openProductSearchModal()">
                                <i class="fas fa-search"></i> Cari
                            </button>
                        </div>
                        <div id="productResults"></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nama Barang</label>
                            <input type="text" class="form-control" id="productNameInput" readonly>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Stok</label>
                            <input type="text" class="form-control" id="productStockInput" readonly>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Harga Beli (Lama)</label>
                            <input type="text" class="form-control text-end" id="productHargaBeliLamaInput" readonly>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Harga Beli (Baru)</label>
                            <input type="number" class="form-control text-end" id="productHargaBeliInput" placeholder="Masukkan harga beli baru" min="0" step="100">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Qty</label>
                            <input type="number" class="form-control" id="productQtyInput" value="1" min="1">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Sub Total</label>
                            <input type="text" class="form-control text-end" id="productSubTotalInput" readonly>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="button" class="btn btn-add w-100" onclick="addItemToCart()">
                                <i class="fas fa-plus"></i> Tambah Pesanan
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabel Cart -->
    <div class="form-container">
        <table class="cart-table">
            <thead>
                <tr>
                    <th>ID Supplier</th>
                    <th>Nama Supplier</th>
                    <th>Kode Barang</th>
                    <th>Nama Barang</th>
                    <th>Harga Beli</th>
                    <th>QTY</th>
                    <th>Sub Total</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="cartBody">
                <tr>
                    <td colspan="8" class="text-center text-muted" style="padding:50px;">Keranjang kosong. Tambahkan barang.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Bagian Pembayaran -->
    <div class="row">
        <div class="col-md-8"></div>
        <div class="col-md-4">
            <div class="payment-section">
                <form id="checkoutForm" method="POST" action="purchases_transaction.php">
                    <input type="hidden" name="action" value="checkout">
                    <input type="hidden" name="final_total" id="finalTotalInput" value="0">
                    <input type="hidden" name="items_json" id="itemsJsonInput" value="[]">
                    <input type="hidden" name="supplier_id" id="supplierIdHidden" value="">
                    <input type="hidden" name="metode_bayar" id="metodeBayar" value="Cash">

                    <div class="form-group">
                        <label>Total</label>
                        <input type="text" class="form-control payment-input text-end" id="grandTotalDisplay" value="<?= formatRupiah(0) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Metode Pembayaran</label>
                        <select class="form-control payment-input" id="metodeBayarSelect">
                            <option value="Cash">Cash</option>
                            <option value="Debit">Debit</option>
                            <option value="Credit">Credit</option>
                            <option value="Transfer">Transfer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Bayar</label>
                        <input type="number" class="form-control payment-input text-end" id="paidAmount" value="0" min="0">
                    </div>

                    <div class="form-group">
                        <label>Sisa</label>
                        <input type="text" class="form-control payment-input text-end" id="changeDisplay" value="<?= formatRupiah(0) ?>" readonly>
                    </div>

                    <div class="text-end mt-3">
                        <button type="button" class="btn btn-cancel" onclick="clearCart()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                        <button type="submit" class="btn btn-save" id="btnCheckout" disabled>
                            <i class="fas fa-save"></i> Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Pencarian Barang -->
<div class="modal fade" id="productSearchModal" tabindex="-1" aria-labelledby="productSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="productSearchModalLabel">
                    <i class="fas fa-box me-2"></i>Popup Barang
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="modal-search-bar">
                    <input type="text" class="form-control" id="productSearchInput" placeholder="Masukkan kode atau nama barang..." autocomplete="off">
                    <button type="button" class="btn-search-modal" onclick="searchProductInModal($('#productSearchInput').val())">
                        <i class="fas fa-search me-1"></i> Cari
                    </button>
                    <button type="button" class="btn-new-item" onclick="window.open('products.php', '_blank')">
                        <i class="fas fa-plus me-1"></i> Barang Baru
                    </button>
                </div>
                <div class="modal-table-container">
                    <table class="modal-table">
                        <thead>
                            <tr>
                                <th>Kode Barang</th>
                                <th>Nama Barang</th>
                                <th class="text-end">Harga Beli</th>
                                <th class="text-end">Harga Jual</th>
                                <th class="text-center">Stok</th>
                            </tr>
                        </thead>
                        <tbody id="productSearchResults">
                            <tr>
                                <td colspan="5" class="text-center text-muted" style="padding: 50px;">
                                    Masukkan kode atau nama barang untuk mencari...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Pencarian Supplier -->
<div class="modal fade" id="supplierSearchModal" tabindex="-1" aria-labelledby="supplierSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="supplierSearchModalLabel">
                    <i class="fas fa-truck me-2"></i>Popup Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="modal-search-bar">
                    <input type="text" class="form-control" id="supplierSearchInput" placeholder="Masukkan ID atau nama supplier..." autocomplete="off">
                    <button type="button" class="btn-search-modal" onclick="searchSupplierInModal($('#supplierSearchInput').val())">
                        <i class="fas fa-search me-1"></i> Cari
                    </button>
                    <button type="button" class="btn-new-item" onclick="window.open('suppliers.php', '_blank')">
                        <i class="fas fa-plus me-1"></i> Supplier Baru
                    </button>
                </div>
                <div class="modal-table-container">
                    <table class="modal-table">
                        <thead>
                            <tr>
                                <th>ID Supplier</th>
                                <th>Nama Supplier</th>
                                <th>No. Telepon</th>
                                <th>Kontak</th>
                            </tr>
                        </thead>
                        <tbody id="supplierSearchResults">
                            <tr>
                                <td colspan="4" class="text-center text-muted" style="padding: 50px;">
                                    Masukkan ID atau nama supplier untuk mencari...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    let cart = []; 
    
    // --- FUNGSI FORMATTING DAN UTILITY ---
    function formatRupiah(number) {
        if (isNaN(number)) return 'Rp0';
        return 'Rp' + new Intl.NumberFormat('id-ID').format(number);
    }

    function calculateTotal() {
        let subtotal = cart.reduce((sum, item) => sum + (item.qty * item.price), 0);
        let finalTotal = subtotal;
        if (finalTotal < 0) finalTotal = 0;

        $('#grandTotalDisplay').val(formatRupiah(finalTotal));
        $('#finalTotalInput').val(finalTotal);

        return finalTotal;
    }

    function updateChange() {
        const finalTotal = calculateTotal();
        const paid = parseFloat($('#paidAmount').val()) || 0;
        const change = paid - finalTotal;

        $('#changeDisplay').val(formatRupiah(change > 0 ? change : 0));
        
        // Aktifkan tombol checkout jika: ada item, supplier dipilih, dan total > 0
        const supplierId = $('#supplierIdHidden').val();
        const isReady = cart.length > 0 && supplierId && finalTotal > 0;
        $('#btnCheckout').prop('disabled', !isReady);
    }

    // --- FUNGSI KERANJANG ---
    function renderCart() {
        const cartBody = $('#cartBody');
        cartBody.empty();

        // Ambil data supplier
        const supplierId = $('#supplierIdHidden').val() || '';
        const supplierName = $('#supplierNameInput').val() || '';

        if (cart.length === 0) {
            cartBody.append('<tr><td colspan="8" class="text-center text-muted" style="padding:50px;">Keranjang kosong. Tambahkan barang.</td></tr>');
            calculateTotal();
            updateChange();
            return;
        }

        cart.forEach((item, index) => {
            const subtotal = item.qty * item.price;
            const row = `
                <tr class="item-row">
                    <td>${supplierId || '-'}</td>
                    <td>${supplierName || '-'}</td>
                    <td>${item.code}</td>
                    <td>${item.name}</td>
                    <td class="text-end">${formatRupiah(item.price)}</td>
                    <td class="text-center">
                        <input type="number" data-id="${item.id}" value="${item.qty}" min="1" class="form-control form-control-sm text-center input-qty" style="width: 70px; display: inline-block;">
                    </td>
                    <td class="text-end text-primary fw-bold">${formatRupiah(subtotal)}</td>
                    <td><button type="button" class="btn btn-danger btn-sm" onclick="removeItem(${item.id})"><i class="fas fa-times"></i></button></td>
                </tr>
            `;
            cartBody.append(row);
        });

        $('#itemsJsonInput').val(JSON.stringify(cart));
        calculateTotal();
        updateChange();
    }

    function addItem(product, qty = 1, hargaBeli = null) {
        // Validasi input
        if (!product || !product.id && !product.product_id) {
            alert('Data produk tidak valid.');
            return;
        }
        
        const productId = parseInt(product.id || product.product_id);
        const productName = product.nama_barang || product.name || 'Unknown';
        
        if (isNaN(productId) || productId <= 0) {
            alert('ID produk tidak valid.');
            return;
        }
        
        qty = parseInt(qty) || 1;
        if (qty <= 0) {
            alert('Quantity harus lebih dari 0.');
            return;
        }
        
        // Gunakan harga_beli dari parameter atau dari product
        const hargaBeliFinal = hargaBeli || parseFloat(product.harga_beli || product.price || 0);
        if (hargaBeliFinal <= 0) {
            alert('Harga beli harus lebih dari 0.');
            return;
        }
        
        const existingItemIndex = cart.findIndex(item => item.id === productId);

        if (existingItemIndex !== -1) {
            const existingItem = cart[existingItemIndex];
            // Update qty dan harga beli jika berbeda
            existingItem.qty = existingItem.qty + qty;
            existingItem.price = hargaBeliFinal; // Update dengan harga beli terbaru
        } else {
            // Tambahkan item baru
            cart.push({
                id: productId,
                code: product.kode_barang || product.code || '',
                name: productName,
                price: hargaBeliFinal,
                stock: parseInt(product.stok || product.stock || 0),
                qty: qty
            });
        }
        renderCart();
    }

    // Fungsi untuk menambahkan item dari form (format baru)
    function addItemToCart() {
        const productCode = $('#productCodeInput').val().trim();
        if (!productCode) {
            alert('Masukkan kode barang terlebih dahulu.');
            return;
        }

        const productName = $('#productNameInput').val();
        if (!productName) {
            alert('Pilih barang terlebih dahulu dengan mencari kode barang.');
            return;
        }

        const qty = parseInt($('#productQtyInput').val()) || 1;
        const hargaBeli = parseFloat($('#productHargaBeliInput').val()) || 0;
        const productId = $('#productCodeInput').data('product-id');

        if (!productId) {
            alert('Data produk tidak valid. Silakan cari ulang.');
            return;
        }

        if (hargaBeli <= 0) {
            alert('Harga beli harus lebih dari 0.');
            $('#productHargaBeliInput').focus();
            return;
        }

        if (qty <= 0) {
            alert('Quantity harus lebih dari 0.');
            $('#productQtyInput').focus();
            return;
        }

        // Tambahkan ke cart
        addItem({
            id: productId,
            product_id: productId,
            kode_barang: productCode,
            nama_barang: productName,
            harga_beli: hargaBeli,
            stok: parseInt($('#productStockInput').val()) || 0
        }, qty, hargaBeli);

        // Reset form
        clearProductForm();
        $('#productCodeInput').focus();
    }

    // Fungsi untuk membuka modal pencarian supplier
    function openSupplierSearchModal() {
        const supplierId = $('#supplierIdInput').val().trim();
        const modal = new bootstrap.Modal(document.getElementById('supplierSearchModal'));
        $('#supplierSearchInput').val(supplierId);
        modal.show();
        
        const tbody = $('#supplierSearchResults');
        
        // Jika ada ID, langsung cari
        if (supplierId) {
            searchSupplierInModal(supplierId);
        } else {
            tbody.html('<tr><td colspan="4" class="text-center text-muted" style="padding: 50px;">Masukkan ID atau nama supplier untuk mencari...</td></tr>');
        }
    }
    
    // Fungsi untuk mencari supplier di modal
    function searchSupplierInModal(query) {
        if (!query || query.trim() === '') {
            const tbody = $('#supplierSearchResults');
            tbody.html('<tr><td colspan="4" class="text-center text-muted" style="padding: 50px;">Masukkan ID atau nama supplier untuk mencari...</td></tr>');
            return;
        }
        
        const tbody = $('#supplierSearchResults');
        tbody.html('<tr><td colspan="4" class="text-center" style="padding: 50px;"><i class="fas fa-spinner fa-spin"></i> Mencari...</td></tr>');
        
        // Cek apakah query adalah angka (ID) atau teks (nama)
        const isNumeric = /^\d+$/.test(query);
        const action = isNumeric ? 'search_supplier_by_id' : 'search_supplier_live';
        const param = isNumeric ? { action: action, id: query } : { action: action, q: query };
        
        $.ajax({
            url: 'ajax_handler.php',
            method: 'GET',
            data: param,
            dataType: 'json',
            success: function(response) {
                tbody.empty();
                
                if (isNumeric) {
                    // Single result untuk ID
                    if (response.id) {
                        const row = `
                            <tr class="supplier-select-item" 
                                data-id="${response.id}" 
                                data-name="${response.name}"
                                onclick="selectSupplier(${response.id}, '${response.name.replace(/'/g, "\\'")}')">
                                <td>${response.id}</td>
                                <td>${response.name}</td>
                                <td>${response.phone || '-'}</td>
                                <td>${response.contact || '-'}</td>
                            </tr>
                        `;
                        tbody.html(row);
                    } else {
                        tbody.html('<tr><td colspan="4" class="text-center text-danger" style="padding: 50px;">Supplier tidak ditemukan.</td></tr>');
                    }
                } else {
                    // Multiple results untuk nama
                    if (response.length > 0) {
                        response.forEach(s => {
                            const row = `
                                <tr class="supplier-select-item" 
                                    data-id="${s.id}" 
                                    data-name="${s.name}"
                                    onclick="selectSupplier(${s.id}, '${s.name.replace(/'/g, "\\'")}')">
                                    <td>${s.id}</td>
                                    <td>${s.name}</td>
                                    <td>${s.phone || '-'}</td>
                                    <td>${s.contact || '-'}</td>
                                </tr>
                            `;
                            tbody.append(row);
                        });
                    } else {
                        tbody.html('<tr><td colspan="4" class="text-center text-danger" style="padding: 50px;">Supplier tidak ditemukan.</td></tr>');
                    }
                }
            },
            error: function() {
                tbody.html('<tr><td colspan="4" class="text-center text-danger" style="padding: 50px;">Error saat mencari supplier.</td></tr>');
            }
        });
    }
    
    // Fungsi untuk memilih supplier dari modal
    function selectSupplier(id, name) {
        $('#supplierIdHidden').val(id);
        $('#supplierNameInput').val(name);
        $('#supplierIdInput').val(id);
        bootstrap.Modal.getInstance(document.getElementById('supplierSearchModal')).hide();
        updateChange(); // Update tombol checkout
        $('#productCodeInput').focus();
    }

    // Fungsi untuk membuka modal pencarian produk
    function openProductSearchModal() {
        const productCode = $('#productCodeInput').val().trim();
        const modal = new bootstrap.Modal(document.getElementById('productSearchModal'));
        $('#productSearchInput').val(productCode);
        modal.show();
        
        const tbody = $('#productSearchResults');
        
        // Jika ada kode, langsung cari
        if (productCode) {
            searchProductInModal(productCode);
        } else {
            tbody.html('<tr><td colspan="5" class="text-center text-muted" style="padding: 50px;">Masukkan kode atau nama barang untuk mencari...</td></tr>');
        }
    }
    
    // Fungsi untuk mencari produk di modal
    function searchProductInModal(query) {
        const tbody = $('#productSearchResults');
        
        if (!query || query.trim() === '') {
            tbody.html('<tr><td colspan="5" class="text-center text-muted" style="padding: 50px;">Masukkan kode atau nama barang untuk mencari...</td></tr>');
            return;
        }
        
        tbody.html('<tr><td colspan="5" class="text-center" style="padding: 50px;"><i class="fas fa-spinner fa-spin"></i> Mencari...</td></tr>');
        
        // Cari berdasarkan kode dan nama secara bersamaan
        let codeSearch = $.ajax({
            url: 'ajax_handler.php',
            method: 'GET',
            data: { action: 'search_product_code', code: query },
            dataType: 'json'
        });
        
        let nameSearch = $.ajax({
            url: 'ajax_handler.php',
            method: 'GET',
            data: { action: 'search_product_live', q: query },
            dataType: 'json'
        });
        
        // Tunggu kedua hasil
        $.when(codeSearch, nameSearch).done(function(codeResult, nameResult) {
            tbody.empty();
            
            const product = codeResult[0];
            const products = nameResult[0];
            
            // Gabungkan hasil (prioritaskan hasil kode jika ada)
            let allProducts = [];
            let productMap = new Map();
            
            // Tambahkan hasil dari pencarian kode
            if (product && product.id) {
                productMap.set(product.id, {
                    product_id: product.id,
                    kode_barang: product.kode_barang,
                    nama_barang: product.nama_barang,
                    harga_beli: product.harga_beli || 0,
                    harga_jual: product.harga_jual,
                    stok: product.stok
                });
            }
            
            // Tambahkan hasil dari pencarian nama
            if (products && Array.isArray(products)) {
                products.forEach(p => {
                    if (!productMap.has(p.product_id)) {
                        productMap.set(p.product_id, p);
                    }
                });
            }
            
            // Konversi map ke array
            allProducts = Array.from(productMap.values());
            
            if (allProducts.length > 0) {
                allProducts.forEach(p => {
                    const stokClass = p.stok <= 0 ? 'text-danger' : (p.stok < 10 ? 'text-warning' : 'text-success');
                    const row = `
                        <tr class="product-select-item" 
                            data-product='${JSON.stringify(p).replace(/'/g, "&#39;")}'
                            onclick="selectProductFromTable(${p.product_id}, '${p.kode_barang.replace(/'/g, "\\'")}', '${p.nama_barang.replace(/'/g, "\\'")}', ${p.harga_beli || 0}, ${p.stok})">
                            <td>${p.kode_barang}</td>
                            <td>${p.nama_barang}</td>
                            <td class="text-end">${formatRupiah(p.harga_beli || 0)}</td>
                            <td class="text-end">${formatRupiah(p.harga_jual)}</td>
                            <td class="text-center ${stokClass}"><strong>${p.stok}</strong></td>
                        </tr>
                    `;
                    tbody.append(row);
                });
            } else {
                tbody.html('<tr><td colspan="5" class="text-center text-danger" style="padding: 50px;">Barang tidak ditemukan.</td></tr>');
            }
        }).fail(function() {
            tbody.html('<tr><td colspan="5" class="text-center text-danger" style="padding: 50px;">Error saat mencari produk.</td></tr>');
        });
    }
    
    // Fungsi untuk memilih produk dari tabel
    function selectProductFromTable(id, code, name, hargaBeli, stok) {
        // Set data produk ke form
        $('#productCodeInput').data('product-id', id);
        $('#productCodeInput').val(code);
        $('#productNameInput').val(name);
        $('#productStockInput').val(stok);
        $('#productHargaBeliLamaInput').val(formatRupiah(hargaBeli || 0));
        $('#productHargaBeliInput').val(hargaBeli || 0);
        $('#productQtyInput').val(1);
        updateSubTotal();
        
        // Tutup modal
        bootstrap.Modal.getInstance(document.getElementById('productSearchModal')).hide();
        $('#productHargaBeliInput').focus();
    }

    // Fungsi untuk update subtotal
    function updateSubTotal() {
        const qty = parseInt($('#productQtyInput').val()) || 1;
        const hargaBeli = parseFloat($('#productHargaBeliInput').val()) || 0;
        const subtotal = qty * hargaBeli;
        $('#productSubTotalInput').val(formatRupiah(subtotal));
    }

    // Fungsi untuk clear form produk
    function clearProductForm() {
        $('#productCodeInput').val('').data('product-id', '');
        $('#productNameInput').val('');
        $('#productStockInput').val('');
        $('#productHargaBeliLamaInput').val('');
        $('#productHargaBeliInput').val(0);
        $('#productQtyInput').val(1);
        $('#productSubTotalInput').val(formatRupiah(0));
    }

    function removeItem(id) {
        cart = cart.filter(item => item.id !== id);
        renderCart();
    }

    function clearCart() {
        if (confirm("Yakin membatalkan seluruh transaksi?")) {
            cart = [];
            $('#paidAmount').val(0);
            $('#supplierIdInput').val('');
            $('#supplierNameInput').val('');
            $('#supplierIdHidden').val('');
            clearProductForm();
            renderCart();
            $('#supplierIdInput').focus();
        }
    }
    
    // --- EVENT HANDLERS ---
    
    // 1. Input QTY di Keranjang
    $(document).on('change blur', '.input-qty', function() {
        const id = parseInt($(this).data('id'));
        let newQty = parseInt($(this).val());
        const item = cart.find(i => i.id === id);
        
        if (!item) {
            $(this).val(1);
            return;
        }

        if (isNaN(newQty) || newQty < 1) {
            newQty = 1;
            $(this).val(1);
        }

        item.qty = newQty;
        renderCart();
    });

    // 2. Update subtotal saat qty atau harga beli berubah di form produk
    $('#productQtyInput, #productHargaBeliInput').on('keyup change', function() {
        updateSubTotal();
    });

    // 3. Kalkulasi Ulang saat Bayar berubah
    $('#paidAmount').on('keyup change', function() {
        if (parseFloat($(this).val()) < 0 || isNaN(parseFloat($(this).val()))) {
             $(this).val(0);
        }
        updateChange();
    });
    
    // Update metode bayar saat berubah
    $('#metodeBayarSelect').on('change', function() {
        $('#metodeBayar').val($(this).val());
    });

    // 4. Enter key untuk search produk
    $('#productCodeInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            openProductSearchModal();
        }
    });

    // 5. Enter key untuk search supplier
    $('#supplierIdInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            openSupplierSearchModal();
        }
    });
    
    // Event handler untuk pencarian di modal produk
    $('#productSearchInput').on('keyup', function(e) {
        if (e.which === 13) {
            // Enter key
            searchProductInModal($(this).val().trim());
        } else {
            const query = $(this).val().trim();
            if (query.length >= 2) {
                clearTimeout(window.productSearchTimeout);
                window.productSearchTimeout = setTimeout(function() {
                    searchProductInModal(query);
                }, 500);
            } else if (query.length === 0) {
                $('#productSearchResults').html('<tr><td colspan="5" class="text-center text-muted" style="padding: 50px;">Masukkan kode atau nama barang untuk mencari...</td></tr>');
            }
        }
    });
    
    // Event handler untuk pencarian di modal supplier
    $('#supplierSearchInput').on('keyup', function(e) {
        if (e.which === 13) {
            // Enter key
            searchSupplierInModal($(this).val().trim());
        } else {
            const query = $(this).val().trim();
            if (query.length >= 1) {
                clearTimeout(window.supplierSearchTimeout);
                window.supplierSearchTimeout = setTimeout(function() {
                    searchSupplierInModal(query);
                }, 500);
            } else if (query.length === 0) {
                $('#supplierSearchResults').html('<tr><td colspan="4" class="text-center text-muted" style="padding: 50px;">Masukkan ID atau nama supplier untuk mencari...</td></tr>');
            }
        }
    });

    // 6. Submit Form dengan validasi lengkap
    $('#checkoutForm').on('submit', function(e) {
        e.preventDefault();
        
        // Validasi cart
        if (cart.length === 0) {
            alert('Keranjang belanja kosong. Tidak bisa checkout.');
            return false;
        }
        
        // Validasi supplier
        const supplierId = $('#supplierIdHidden').val();
        if (!supplierId) {
            alert('Supplier harus dipilih terlebih dahulu.');
            $('#supplierIdInput').focus();
            return false;
        }
        
        // Validasi setiap item di cart
        let cartErrors = [];
        cart.forEach((item, index) => {
            if (!item.id || !item.qty || !item.price) {
                cartErrors.push(`Item #${index + 1}: Data tidak lengkap`);
            }
            if (item.qty <= 0) {
                cartErrors.push(`Item #${index + 1} (${item.name}): Quantity harus lebih dari 0`);
            }
            if (item.price <= 0) {
                cartErrors.push(`Item #${index + 1} (${item.name}): Harga beli tidak valid`);
            }
        });
        
        if (cartErrors.length > 0) {
            alert('Validasi cart gagal:\n' + cartErrors.join('\n'));
            renderCart();
            return false;
        }
        
        const finalTotal = parseFloat($('#finalTotalInput').val()) || 0;
        
        if (finalTotal <= 0) {
            alert('Total pembayaran harus lebih dari 0.');
            return false;
        }
        
        // Update metode bayar sebelum submit
        $('#metodeBayar').val($('#metodeBayarSelect').val());
        
        // Konfirmasi sebelum menyimpan
        const metodeBayar = $('#metodeBayarSelect').val();
        const confirmMsg = `Konfirmasi Transaksi Pembelian:\n\n` +
                          `Supplier: ${$('#supplierNameInput').val()}\n` +
                          `Total: ${formatRupiah(finalTotal)}\n` +
                          `Metode: ${metodeBayar}\n\n` +
                          `Lanjutkan transaksi?`;
        
        if (!confirm(confirmMsg)) {
            return false;
        }
        
        // Disable tombol checkout untuk mencegah double submit
        $('#btnCheckout').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Memproses...');
        
        // Submit form
        this.submit();
    });

    // Inisialisasi
    $(document).ready(function() {
        renderCart();
        $('#paidAmount').val(0);
        $('#metodeBayar').val('Cash');
        $('#metodeBayarSelect').val('Cash');
        $('#supplierIdInput').focus();
        updateSubTotal();
    });
</script>
</body>
</html>
<?php 
if($is_connected && $koneksi instanceof mysqli) $koneksi->close(); 
?>
          