<?php
// FILE: report_products.php (Laporan Stok Barang)

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
    $_SESSION['trans_message'] = '<div class="alert alert-warning">Anda tidak memiliki akses untuk melihat laporan stok barang.</div>';
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

// Ambil parameter filter
$filter_kategori = $_GET['kategori'] ?? '';
$filter_stok = $_GET['stok'] ?? 'all'; // all, low, out

// Query untuk mengambil data produk
$products_data = [];
$total_nilai_stok = 0;

if ($is_connected) {
    $sql = "SELECT 
                p.product_id,
                p.kode_barang,
                p.nama_barang,
                p.harga_beli,
                p.harga_jual,
                p.stok,
                p.satuan,
                p.kategori,
                p.tanggal_ed,
                s.nama_supplier
            FROM products p
            LEFT JOIN supplier s ON p.supplier_id = s.supplier_id
            WHERE 1=1";
    
    $params = [];
    $types = '';
    
    // Filter kategori
    if (!empty($filter_kategori)) {
        $sql .= " AND p.kategori = ?";
        $params[] = $filter_kategori;
        $types .= 's';
    }
    
    // Filter stok
    if ($filter_stok === 'low') {
        $sql .= " AND p.stok <= 10 AND p.stok > 0";
    } elseif ($filter_stok === 'out') {
        $sql .= " AND p.stok = 0";
    }
    
    $sql .= " ORDER BY p.kode_barang ASC";
    
    $stmt = $koneksi->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $nilai_stok = $row['stok'] * $row['harga_beli'];
            $products_data[] = [
                'product_id' => $row['product_id'],
                'kode_barang' => $row['kode_barang'],
                'nama_barang' => $row['nama_barang'],
                'harga_beli' => $row['harga_beli'],
                'harga_jual' => $row['harga_jual'],
                'stok' => $row['stok'],
                'satuan' => $row['satuan'],
                'kategori' => $row['kategori'],
                'tanggal_ed' => $row['tanggal_ed'],
                'nama_supplier' => $row['nama_supplier'],
                'nilai_stok' => $nilai_stok
            ];
            
            $total_nilai_stok += $nilai_stok;
        }
        $stmt->close();
    }
    
    // Ambil daftar kategori untuk filter
    $kategori_list = [];
    $stmt_kategori = $koneksi->query("SELECT DISTINCT kategori FROM products WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori ASC");
    if ($stmt_kategori) {
        while ($row = $stmt_kategori->fetch_assoc()) {
            $kategori_list[] = $row['kategori'];
        }
    }
}

// Format tanggal ED
function formatTanggalED($tanggal) {
    if (empty($tanggal) || $tanggal == '0000-00-00') return '-';
    return date('d M Y', strtotime($tanggal));
}

// Cek stok kritis
function getStokStatus($stok) {
    if ($stok == 0) {
        return '<span class="badge bg-danger">Habis</span>';
    } elseif ($stok <= 10) {
        return '<span class="badge bg-warning">Kritis</span>';
    } else {
        return '<span class="badge bg-success">Aman</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan Stok Barang | Minimarket App</title>
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

    /* Print clean: hanya data laporan */
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

    /* sembunyikan semua konten, tampilkan hanya print-area */
    #main-content > * { display:none !important; }
    #main-content .print-area { display:block !important; }
    .print-report-title { display:block !important; }
    
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
            <a class="nav-link" href="report_purchases.php"><i class="fas fa-file-invoice-dollar"></i> Data Pembelian</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active-page" href="report_products.php"><i class="fas fa-cubes"></i> Laporan Stok Barang</a>
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
        <div class="header-subtitle"><i class="fas fa-cubes me-2"></i>FORM LAPORAN STOK</div>
        <div class="header-title">Laporan Stok Barang</div>
        <div class="header-date"><?= formatTanggalIndo(date('Y-m-d')) ?></div>
    </div>

    <!-- Filter -->
    <div class="form-container filter-container">
        <form method="GET" action="report_products.php" id="filterForm">
            <div class="row align-items-end">
                <div class="col-md-2">
                    <label class="form-label fw-bold">Kategori</label>
                </div>
                <div class="col-md-3">
                    <div class="form-group mb-0">
                        <select class="form-control" name="kategori">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($kategori_list as $kat): ?>
                                <option value="<?= htmlspecialchars($kat) ?>" <?= $filter_kategori == $kat ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($kat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status Stok</label>
                </div>
                <div class="col-md-3">
                    <div class="form-group mb-0">
                        <select class="form-control" name="stok">
                            <option value="all" <?= $filter_stok == 'all' ? 'selected' : '' ?>>Semua Stok</option>
                            <option value="low" <?= $filter_stok == 'low' ? 'selected' : '' ?>>Stok Kritis (≤10)</option>
                            <option value="out" <?= $filter_stok == 'out' ? 'selected' : '' ?>>Stok Habis (0)</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-2 text-end">
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

    <!-- Tabel Laporan (AREA YANG DICETAK) -->
    <div class="print-area">
        <!-- Judul khusus untuk hasil cetak (berwarna) -->
        <div class="print-report-title" style="display:none; text-align:center; margin: 0 0 12px 0; padding: 6px 0; border-bottom:2px solid #28a745;">
            <div style="font-size:18px; font-weight:800; color:#28a745;">LAPORAN STOK BARANG</div>
            <div style="font-size:12px; color:#333;">
                Filter: Kategori <b><?= htmlspecialchars($filter_kategori !== '' ? $filter_kategori : 'Semua') ?></b>,
                Status <b><?= htmlspecialchars($filter_stok === 'low' ? 'Stok Kritis (≤10)' : ($filter_stok === 'out' ? 'Stok Habis (0)' : 'Semua Stok')) ?></b>
            </div>
        </div>

        <div class="form-container">
            <div class="table-responsive">
                <table class="report-table">
                <thead>
                    <tr>
                        <th>Kode Barang</th>
                        <th>Nama Barang</th>
                        <th>Kategori</th>
                        <th>Supplier</th>
                        <th class="text-end">Harga Beli</th>
                        <th class="text-end">Harga Jual</th>
                        <th class="text-center">Stok</th>
                        <th>Satuan</th>
                        <th>Status</th>
                        <th>Tanggal ED</th>
                        <th class="text-end">Nilai Stok</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products_data)): ?>
                        <tr>
                            <td colspan="11" class="text-center text-muted" style="padding:50px;">
                                Tidak ada data barang untuk filter yang dipilih.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products_data as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['kode_barang']) ?></td>
                                <td><?= htmlspecialchars($row['nama_barang']) ?></td>
                                <td><?= htmlspecialchars($row['kategori'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($row['nama_supplier'] ?? '-') ?></td>
                                <td class="text-end"><?= formatRupiah($row['harga_beli']) ?></td>
                                <td class="text-end"><?= formatRupiah($row['harga_jual']) ?></td>
                                <td class="text-center fw-bold <?= $row['stok'] == 0 ? 'text-danger' : ($row['stok'] <= 10 ? 'text-warning' : '') ?>">
                                    <?= $row['stok'] ?>
                                </td>
                                <td><?= htmlspecialchars($row['satuan'] ?? '-') ?></td>
                                <td><?= getStokStatus($row['stok']) ?></td>
                                <td><?= formatTanggalED($row['tanggal_ed']) ?></td>
                                <td class="text-end fw-bold"><?= formatRupiah($row['nilai_stok']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-info">
                            <td colspan="10" class="text-end fw-bold">TOTAL NILAI STOK:</td>
                            <td class="text-end fw-bold"><?= formatRupiah($total_nilai_stok) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Tombol Kembali -->
    <button type="button" class="btn btn-back" onclick="window.location.href='dashboard.php'">
        <i class="fas fa-arrow-left"></i> Kembali
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php 
if($is_connected && $koneksi instanceof mysqli) $koneksi->close(); 
?>
