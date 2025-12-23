<?php
// FILE: report_sales_kasir.php (Laporan Penjualan Kasir - hanya transaksi milik kasir yang login)

session_start();
include "../config/koneksi.php";

date_default_timezone_set('Asia/Jakarta');

// --- KONEKSI & OTORISASI ---
$is_connected = isset($koneksi) && $koneksi instanceof mysqli && $koneksi->connect_error === null;
if (!$is_connected) {
    die("Error: Koneksi database gagal.");
}

if (!isset($_SESSION['username'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_role = $_SESSION['role'] ?? 'kasir';
$user_id = intval($_SESSION['user_id'] ?? 0);
$nama_user = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Kasir';

// Hanya kasir yang bisa akses halaman ini
if ($user_role !== 'kasir') {
    header("Location: report_sales.php");
    exit;
}

if ($user_id <= 0) {
    $_SESSION['trans_message'] = '<div class="alert alert-danger">User ID tidak valid. Silakan login ulang.</div>';
    header("Location: ../auth/login.php");
    exit;
}

// Helper
function formatRupiah($angka) {
    return 'Rp' . number_format($angka, 0, ',', '.');
}

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

function formatNoPenjualan($sale_id) {
    return 'TRJ' . str_pad($sale_id, 6, '0', STR_PAD_LEFT);
}

function formatIdPelanggan($pelanggan_id) {
    if ($pelanggan_id === null || $pelanggan_id === '' || $pelanggan_id === '0' || $pelanggan_id === 0) return '-';
    $pelanggan_id_int = intval($pelanggan_id);
    if ($pelanggan_id_int <= 0) return '-';
    return 'PL' . str_pad($pelanggan_id_int, 4, '0', STR_PAD_LEFT);
}

// Filter tanggal
$tanggal_dari = $_GET['tanggal_dari'] ?? date('Y-m-d');
$tanggal_sampai = $_GET['tanggal_sampai'] ?? date('Y-m-d');
$cetak = isset($_GET['cetak']) && $_GET['cetak'] == '1';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_dari) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_sampai)) {
    $tanggal_dari = date('Y-m-d');
    $tanggal_sampai = date('Y-m-d');
}
if (strtotime($tanggal_dari) > strtotime($tanggal_sampai)) {
    $tanggal_dari = date('Y-m-d');
    $tanggal_sampai = date('Y-m-d');
}

// Ambil data penjualan kasir ini saja
$sales_data = [];
$total_penjualan = 0;

$sql = "SELECT 
            sh.sale_id,
            sh.tanggal_transaksi,
            sh.pelanggan_id,
            sh.total_bayar,
            sh.diskon,
            sh.metode_bayar,
            COALESCE(pl.nama_pelanggan, 'Umum') as nama_pelanggan,
            (SELECT SUM(sd.subtotal) FROM sales_detail sd WHERE sd.sale_id = sh.sale_id) as subtotal_transaksi
        FROM sales_header sh
        LEFT JOIN pelanggan pl ON sh.pelanggan_id = pl.pelanggan_id
        WHERE sh.user_id = ?
          AND sh.tanggal_transaksi BETWEEN ? AND ?
        ORDER BY sh.sale_id DESC";

$stmt = $koneksi->prepare($sql);
if ($stmt) {
    $stmt->bind_param("iss", $user_id, $tanggal_dari, $tanggal_sampai);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sales_data[] = [
            'sale_id' => $row['sale_id'],
            'tanggal' => $row['tanggal_transaksi'],
            'pelanggan_id' => $row['pelanggan_id'],
            'nama_pelanggan' => $row['nama_pelanggan'] ?? 'Umum',
            'subtotal' => $row['subtotal_transaksi'] ?? 0,
            'diskon' => $row['diskon'],
            'total_bayar' => $row['total_bayar'],
            'metode_bayar' => $row['metode_bayar']
        ];
        $total_penjualan += floatval($row['total_bayar'] ?? 0);
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan Penjualan Saya | Minimarket App</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/modern-style.css">
<style>
.header-green{background-color:#28a745;color:white;padding:15px 30px;margin-bottom:20px;position:relative;}
.header-green .header-title{font-size:24px;font-weight:bold;text-align:center;}
.header-green .header-subtitle{font-size:12px;opacity:0.9;position:absolute;left:30px;top:15px;}
.header-green .header-date{font-size:14px;position:absolute;right:30px;top:15px;}
.form-container{background:white;padding:20px;margin:0 20px 20px 20px;border-radius:5px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}
.report-table{width:100%;border-collapse:collapse;background:white;font-size:14px;}
.report-table th{background:linear-gradient(135deg, #28a745 0%, #20c997 100%);color:white;padding:12px;text-align:left;border:1px solid #1e7e34;font-weight:bold;}
.report-table td{padding:10px;border:1px solid #ddd;}
.report-table tbody tr:nth-child(even){background-color:#f0f8f4;}
.no-print {display: table-cell;}
.btn-back-fixed{
  position: fixed;
  right: 20px;
  bottom: 20px;
  z-index: 2000;
  border-radius: 12px;
  padding: 10px 16px;
}
@media print {
  /* print hanya area data */
  body { background: white !important; padding: 0 !important; margin: 0 !important; }
  #sidebar, .btn-back, .btn-search, .btn-print, .header-green { display:none !important; }
  .no-print { display:none !important; }
  #main-content { margin-left:0 !important; padding:0 !important; }
  .signature-section { display:flex !important; }
  .print-report-header { display:block !important; }
  /* sembunyikan semua konten, tampilkan hanya print-area */
  #main-content > * { display:none !important; }
  #main-content .print-area { display:block !important; }
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
            <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        </li>
        <li class="nav-item mt-3">
            <strong class="text-secondary small">TRANSAKSI</strong>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="sales_transaction.php"><i class="fas fa-cash-register"></i> Penjualan (POS)</a>
        </li>
        <li class="nav-item mt-3">
            <strong class="text-secondary small">LAPORAN</strong>
        </li>
        <li class="nav-item">
            <a class="nav-link active-page" href="report_sales_kasir.php"><i class="fas fa-chart-bar"></i> Laporan Penjualan Saya</a>
        </li>
        <li class="nav-item mt-5">
            <a class="nav-link text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </li>
    </ul>
</nav>

<div id="main-content" class="fade-in">
    <div class="header-green">
        <div class="header-subtitle">Kasir: <b><?= htmlspecialchars($nama_user) ?></b></div>
        <div class="header-title">Laporan Penjualan Saya</div>
        <div class="header-date"><?= date('d/m/Y H:i') ?></div>
    </div>

    <div class="form-container">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold">Tanggal Dari</label>
                <input type="date" class="form-control" name="tanggal_dari" value="<?= htmlspecialchars($tanggal_dari) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Tanggal Sampai</label>
                <input type="date" class="form-control" name="tanggal_sampai" value="<?= htmlspecialchars($tanggal_sampai) ?>">
            </div>
            <div class="col-md-6 text-end">
                <button type="submit" class="btn btn-search"><i class="fas fa-search me-1"></i> Tampilkan</button>
                <a class="btn btn-print" href="report_sales_kasir.php?tanggal_dari=<?= urlencode($tanggal_dari) ?>&tanggal_sampai=<?= urlencode($tanggal_sampai) ?>&cetak=1">
                    <i class="fas fa-print me-1"></i> Cetak
                </a>
            </div>
        </form>
    </div>

    <!-- AREA YANG DICETAK (hanya data) -->
    <div class="print-area">
        <!-- Header khusus untuk hasil cetak -->
        <div class="form-container print-report-header" style="display:none; margin: 0 0 15px 0; border-radius:0; box-shadow:none; border-bottom:3px solid #28a745;">
            <div style="text-align:center; padding:8px 0;">
                <div style="font-size:18px; font-weight:800; color:#28a745;">LAPORAN PENJUALAN</div>
                <div style="font-size:12px; color:#333;">Kasir: <b><?= htmlspecialchars($nama_user) ?></b></div>
                <div style="font-size:12px; color:#333;">Periode: <b><?= htmlspecialchars($tanggal_dari) ?></b> s/d <b><?= htmlspecialchars($tanggal_sampai) ?></b></div>
            </div>
        </div>

        <div class="form-container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <div class="text-muted">Periode: <b><?= htmlspecialchars($tanggal_dari) ?></b> s/d <b><?= htmlspecialchars($tanggal_sampai) ?></b></div>
                <div class="text-muted">Total (dibayar): <b class="text-success"><?= formatRupiah($total_penjualan) ?></b></div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>No. Transaksi</th>
                        <th>Tanggal</th>
                        <th>ID Pelanggan</th>
                        <th>Nama Pelanggan</th>
                        <th class="text-end">Subtotal</th>
                        <th class="text-end">Diskon</th>
                        <th class="text-end">Total Bayar</th>
                        <th>Metode</th>
                        <th class="no-print">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sales_data)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:25px;">Tidak ada transaksi pada periode ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($sales_data as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars(formatNoPenjualan($row['sale_id'])) ?></td>
                            <td><?= htmlspecialchars(formatTanggalIndo($row['tanggal'])) ?></td>
                            <td><?= htmlspecialchars(formatIdPelanggan($row['pelanggan_id'])) ?></td>
                            <td><?= htmlspecialchars($row['nama_pelanggan'] ?? 'Umum') ?></td>
                            <td class="text-end"><?= formatRupiah($row['subtotal']) ?></td>
                            <td class="text-end"><?= formatRupiah($row['diskon']) ?></td>
                            <td class="text-end fw-bold"><?= formatRupiah($row['total_bayar']) ?></td>
                            <td><?= htmlspecialchars($row['metode_bayar']) ?></td>
                            <td class="no-print">
                                <a class="btn btn-sm btn-outline-primary" href="print_nota.php?id=<?= intval($row['sale_id']) ?>" target="_blank">
                                    <i class="fas fa-receipt me-1"></i> Nota
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        </div>

        <div class="form-container signature-section" style="display:none; justify-content:space-between; gap:40px; margin-top:25px;">
        <div style="flex:1; text-align:center;">
            <div>Kasir</div>
            <div style="margin-top:70px; border-top:1px solid #333; padding-top:8px; font-weight:bold;">
                ( <?= htmlspecialchars($nama_user) ?> )
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

    <a class="btn btn-secondary btn-back btn-back-fixed" href="dashboard.php">
        <i class="fas fa-arrow-left me-2"></i> Kembali
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($cetak): ?>
<script>
  // Auto print saat tombol "Cetak" ditekan (cetak=1)
  window.addEventListener('load', () => {
    window.print();
  });
</script>
<?php endif; ?>
</body>
</html>


