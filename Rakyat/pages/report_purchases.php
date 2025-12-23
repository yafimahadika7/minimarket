<?php
// FILE: report_purchases.php (Laporan Data Pembelian)

session_start();
include "../config/koneksi.php";

date_default_timezone_set('Asia/Jakarta');

// --- KONEKSI & OTORISASI ---
$is_connected = isset($koneksi) && $koneksi instanceof mysqli && $koneksi->connect_error === null;

if (!$is_connected) {
    die("Error: Koneksi database gagal.");
}

// --- OTENTIKASI ---
if (!isset($_SESSION['username'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_role = $_SESSION['role'] ?? 'kasir';

// Hanya supervisor dan manager yang bisa akses laporan
if (!in_array($user_role, ['manager', 'supervisor'])) {
    $_SESSION['trans_message'] = '<div class="alert alert-warning">Anda tidak memiliki akses untuk melihat laporan pembelian.</div>';
    header("Location: dashboard.php");
    exit;
}

// Helper untuk format Rupiah
function formatRupiah($angka) {
    return 'Rp' . number_format($angka, 0, ',', '.');
}

// Helper untuk format tanggal Indonesia
function formatTanggalIndo($tanggal) {
    if (empty($tanggal)) return '-';
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    
    $timestamp = strtotime($tanggal);
    $hari_nama = $hari[date('w', $timestamp)];
    $tanggal_format = date('d', $timestamp) . ' ' . $bulan[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    
    return $hari_nama . ', ' . $tanggal_format;
}

// Ambil parameter filter tanggal
$tanggal_dari = $_GET['tanggal_dari'] ?? date('Y-m-d');
$tanggal_sampai = $_GET['tanggal_sampai'] ?? date('Y-m-d');

// Validasi format tanggal
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_dari) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_sampai)) {
    $tanggal_dari = date('Y-m-d');
    $tanggal_sampai = date('Y-m-d');
}

// Validasi bahwa tanggal_dari tidak lebih besar dari tanggal_sampai
if (strtotime($tanggal_dari) > strtotime($tanggal_sampai)) {
    $tanggal_dari = date('Y-m-d');
    $tanggal_sampai = date('Y-m-d');
}

// Query untuk mengambil data pembelian
$purchases_data = [];
$total_pembelian = 0;

if ($is_connected) {
    // Cek apakah tabel purchases_header sudah ada
    $table_check = $koneksi->query("SHOW TABLES LIKE 'purchases_header'");
    if ($table_check && $table_check->num_rows > 0) {
        $sql = "SELECT 
                    ph.purchase_id,
                    ph.tanggal_transaksi,
                    ph.user_id,
                    ph.supplier_id,
                    pd.product_id,
                    pd.qty,
                    pd.harga_beli,
                    pd.subtotal,
                    p.kode_barang,
                    COALESCE(u.full_name, pg.full_name, 'Unknown') as nama_pegawai,
                    s.nama_supplier
                FROM purchases_header ph
                INNER JOIN purchases_detail pd ON ph.purchase_id = pd.purchase_id
                INNER JOIN products p ON pd.product_id = p.product_id
                LEFT JOIN users u ON ph.user_id = u.user_id
                LEFT JOIN pegawai pg ON ph.user_id = pg.user_id
                LEFT JOIN supplier s ON ph.supplier_id = s.supplier_id
                WHERE ph.tanggal_transaksi BETWEEN ? AND ?
                ORDER BY ph.purchase_id ASC, pd.detail_id ASC";
        
        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ss", $tanggal_dari, $tanggal_sampai);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $purchases_data[] = [
                    'purchase_id' => $row['purchase_id'],
                    'tanggal' => $row['tanggal_transaksi'],
                    'user_id' => $row['user_id'],
                    'supplier_id' => $row['supplier_id'],
                    'kode_barang' => $row['kode_barang'],
                    'harga_beli' => $row['harga_beli'],
                    'qty' => $row['qty'],
                    'subtotal' => $row['subtotal'],
                    'nama_pegawai' => $row['nama_pegawai'],
                    'nama_supplier' => $row['nama_supplier']
                ];
                
                $total_pembelian += $row['subtotal'];
            }
            $stmt->close();
        }
    }
}

// Format nomor transaksi
function formatNoPembelian($purchase_id) {
    return 'TRB' . str_pad($purchase_id, 6, '0', STR_PAD_LEFT);
}

// Format ID Pegawai
function formatIdPegawai($user_id) {
    return 'P' . str_pad($user_id, 4, '0', STR_PAD_LEFT);
}

// Format ID Supplier
function formatIdSupplier($supplier_id) {
    return 'SP' . str_pad($supplier_id, 4, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan Data Pembelian | Minimarket App</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/modern-style.css">
<style>
/* Header Minimarket Rakyat */
.print-header {
    text-align: center;
    margin-bottom: 15px;
    padding: 10px;
    display: none;
}

.print-header .nama-toko {
    font-size: 18px;
    font-weight: bold;
    color: #28a745;
    margin-bottom: 3px;
}

.print-header .alamat-toko {
    font-size: 10px;
    color: #666;
}

/* Class untuk menyembunyikan elemen saat print */
.no-print {
    display: table-cell;
}

/* Header Hijau */
.header-green{background-color:#28a745;color:white;padding:15px 30px;margin-bottom:20px;position:relative;}
.header-green .header-title{font-size:24px;font-weight:bold;text-align:center;}
.header-green .header-subtitle{font-size:12px;opacity:0.9;position:absolute;left:30px;top:15px;}
.header-green .header-date{font-size:14px;position:absolute;right:30px;top:15px;}

/* Form Container */
.form-container{background:white;padding:20px;margin:0 20px 20px 20px;border-radius:5px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}

/* Input Fields */
.form-group{margin-bottom:15px;}
.form-group label{font-weight:bold;margin-bottom:5px;display:block;}
.form-control{border:1px solid #ddd;padding:8px;border-radius:4px;}
.btn-search{background-color:#007bff;color:white;border:none;padding:8px 15px;border-radius:4px;cursor:pointer;margin-right:10px;}
.btn-print{background-color:#28a745;color:white;border:none;padding:8px 15px;border-radius:4px;cursor:pointer;}

/* Table */
.report-table{width:100%;border-collapse:collapse;background:white;font-size:14px;}
.report-table th{background:linear-gradient(135deg, #28a745 0%, #20c997 100%);color:white;padding:12px;text-align:left;border:1px solid #1e7e34;font-weight:bold;}
.report-table td{padding:10px;border:1px solid #ddd;}
.report-table tbody tr:hover{background-color:#e8f5e9;}
.report-table tbody tr:nth-child(even){background-color:#f0f8f4;}

/* Buttons */
.btn-back{background-color:#6c757d;color:white;border:none;padding:10px 20px;border-radius:4px;cursor:pointer;position:fixed;bottom:20px;right:20px;}

/* Print Styles */
@media print {
    /* Pastikan warna tercetak */
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    
    body { background: white !important; padding: 0 !important; margin: 0 !important; }
    /* Sembunyikan elemen yang tidak perlu */
    #sidebar, .btn-back, .btn-search, .btn-print, .filter-container, .header-green {
        display: none !important;
    }
    
    /* Sembunyikan kolom Aksi */
    .no-print {
        display: none !important;
    }
    
    #main-content {
        margin-left: 0 !important;
        padding: 10px !important;
    }
    
    /* Tampilkan header Minimarket Rakyat sederhana saat print */
    .print-header {
        display: block !important;
        background: transparent !important;
        padding: 5px 0 !important;
        margin-bottom: 10px !important;
        border-bottom: 2px solid #28a745 !important;
    }
    
    .print-header .nama-toko {
        color: #28a745 !important;
        font-size: 16px !important;
    }
    
    .print-header .alamat-toko {
        color: #666 !important;
        font-size: 9px !important;
    }

    .print-report-title {
        display: block !important;
    }

    .signature-section {
        display: flex !important;
    }

    /* print hanya area data */
    #main-content > * { display:none !important; }
    #main-content .print-area { display:block !important; }
    
    /* Styling tabel */
    .report-table {
        page-break-inside: auto;
        font-size: 11px !important;
    }
    
    .report-table th {
        background: #28a745 !important;
        color: white !important;
        padding: 8px 6px !important;
        font-size: 10px !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .report-table td {
        padding: 6px !important;
        font-size: 10px !important;
    }
    
    .report-table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
    
    .report-table tbody tr:nth-child(even) {
        background-color: #f0f8f4 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Styling untuk total */
    .table-info {
        background-color: #d4edda !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Container form */
    .form-container {
        margin: 0 !important;
        padding: 10px 0 !important;
        box-shadow: none !important;
    }
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
            <a class="nav-link active-page" href="report_purchases.php"><i class="fas fa-file-invoice-dollar"></i> Data Pembelian</a>
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
    <!-- Header Print Minimarket Rakyat (Tampil saat print) -->
    <div class="print-header">
        <div class="nama-toko">MINIMARKET RAKYAT</div>
        <div class="alamat-toko">Perum. Puri Pesona Blok A Ruko No. 2 (Toko Indra) Rt/Rw 004/009, Kel. Bojong Pondok Terong, Kec. Cipayung, Kota Depok, Kode Pos 16444</div>
    </div>
    
    <!-- Header Hijau -->
    <div class="header-green">
        <div class="header-subtitle"><i class="fas fa-file-invoice me-2"></i>FORM DATA PEMBELIAN</div>
        <div class="header-title">Laporan Data Pembelian</div>
        <div class="header-date"><?= formatTanggalIndo(date('Y-m-d')) ?></div>
    </div>

    <!-- Filter Periode -->
    <div class="form-container filter-container">
        <form method="GET" action="report_purchases.php" id="filterForm">
            <div class="row align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-bold">Periode</label>
                </div>
                <div class="col-md-3">
                    <div class="form-group mb-0">
                        <input type="date" class="form-control" name="tanggal_dari" value="<?= htmlspecialchars($tanggal_dari) ?>" required>
                    </div>
                </div>
                <div class="col-md-1 text-center">
                    <label class="form-label mb-0">sampai</label>
                </div>
                <div class="col-md-3">
                    <div class="form-group mb-0">
                        <input type="date" class="form-control" name="tanggal_sampai" value="<?= htmlspecialchars($tanggal_sampai) ?>" required>
                    </div>
                </div>
                <div class="col-md-3 text-end">
                    <button type="submit" class="btn btn-search">
                        <i class="fas fa-search"></i> Cari
                    </button>
                    <button type="button" class="btn btn-print" onclick="window.print()">
                        <i class="fas fa-print"></i> Cetak
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Tabel Laporan -->
    <div class="print-area">
    <div class="form-container">
        <div class="table-responsive">
            <!-- Judul khusus untuk hasil cetak (berwarna) -->
            <div class="print-report-title" style="display:none; text-align:center; margin: 0 0 12px 0; padding: 6px 0; border-bottom:2px solid #28a745;">
                <div style="font-size:18px; font-weight:800; color:#28a745;">LAPORAN PEMBELIAN</div>
                <div style="font-size:12px; color:#333;">Periode: <b><?= htmlspecialchars($tanggal_dari) ?></b> s/d <b><?= htmlspecialchars($tanggal_sampai) ?></b></div>
            </div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>No Pembelian</th>
                        <th>Tanggal</th>
                        <th>ID Pegawai</th>
                        <th>ID Supplier</th>
                        <th>Kode Barang</th>
                        <th class="text-end">Harga Beli</th>
                        <th class="text-center">QTY</th>
                        <th class="text-end">Sub Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($purchases_data)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted" style="padding:50px;">
                                Tidak ada data pembelian untuk periode yang dipilih.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchases_data as $row): ?>
                            <tr>
                                <td><?= formatNoPembelian($row['purchase_id']) ?></td>
                                <td><?= date('Y-m-d', strtotime($row['tanggal'])) ?></td>
                                <td><?= formatIdPegawai($row['user_id']) ?></td>
                                <td><?= formatIdSupplier($row['supplier_id']) ?></td>
                                <td><?= htmlspecialchars($row['kode_barang']) ?></td>
                                <td class="text-end"><?= formatRupiah($row['harga_beli']) ?></td>
                                <td class="text-center"><?= $row['qty'] ?></td>
                                <td class="text-end fw-bold"><?= formatRupiah($row['subtotal']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-info">
                            <td colspan="7" class="text-end fw-bold">TOTAL PEMBELIAN:</td>
                            <td class="text-end fw-bold"><?= formatRupiah($total_pembelian) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tombol Kembali -->
    <button type="button" class="btn btn-back" onclick="window.location.href='dashboard.php'">
        <i class="fas fa-arrow-left"></i> Kembali
    </button>

    <div class="form-container signature-section" style="display:none; justify-content:space-between; gap:40px; margin:20px 20px 0 20px;">
        <div style="flex:1; text-align:center;">
            <div><?= htmlspecialchars(ucfirst($user_role)) ?></div>
            <div style="margin-top:70px; border-top:1px solid #333; padding-top:8px; font-weight:bold;">
                ( <?= htmlspecialchars($_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? $user_role) ?> )
            </div>
        </div>
        <div style="flex:1; text-align:center;">
            <div>Supervisor</div>
            <div style="margin-top:70px; border-top:1px solid #333; padding-top:8px; font-weight:bold;">
                ( .................... )
            </div>
        </div>
    </div>
</div> <!-- /print-area -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Validasi tanggal
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        const tanggalDari = document.querySelector('input[name="tanggal_dari"]').value;
        const tanggalSampai = document.querySelector('input[name="tanggal_sampai"]').value;
        
        if (tanggalDari > tanggalSampai) {
            e.preventDefault();
            alert('Tanggal "Dari" tidak boleh lebih besar dari tanggal "Sampai".');
            return false;
        }
    });
</script>
</body>
</html>
<?php 
if($is_connected && $koneksi instanceof mysqli) $koneksi->close(); 
?>
