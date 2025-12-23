<?php
session_start();
include "../config/koneksi.php";

date_default_timezone_set('Asia/Jakarta');


if (!isset($_SESSION['username'])) {
   
    header("Location: ../auth/login.php");
    exit;
}


$user_role = $_SESSION['role'] ?? 'kasir';
$user_id = $_SESSION['user_id'] ?? null;

$nama_user = $_SESSION['nama_lengkap'] ?? $_SESSION['username'];

function formatRupiah($angka) {
    return 'Rp' . number_format($angka, 0, ',', '.');
}

$total_penjualan_hari_ini = 0;
$jumlah_transaksi_hari_ini = 0;
$barang_stok_kritis = 0;
$total_penjualan_bulan_ini = 0;
$total_pengadaan_bulan_ini = 0;
$laba_kotor_bulan_ini = 0;
$chart_labels = [];
$chart_sales = [];

if ($koneksi && !$koneksi->connect_error) {
    $today = date('Y-m-d');
    $first_day_month = date('Y-m-01');
    $last_day_month = date('Y-m-t');

    if ($user_role == 'kasir' && $user_id) {
        
        $stmt = $koneksi->prepare("SELECT COALESCE(SUM(total_bayar), 0) as total, COUNT(*) as jumlah 
                                   FROM sales_header 
                                   WHERE tanggal_transaksi = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $today, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $total_penjualan_hari_ini = floatval($row['total']);
                $jumlah_transaksi_hari_ini = intval($row['jumlah']);
            }
            $stmt->close();
        }
    }
    

    if (in_array($user_role, ['manager', 'supervisor'])) {
       
        $stmt = $koneksi->query("SELECT COUNT(*) as jumlah FROM products WHERE stok <= 10");
        if ($stmt && $row = $stmt->fetch_assoc()) {
            $barang_stok_kritis = intval($row['jumlah']);
        }
        

        $stmt = $koneksi->prepare("SELECT COALESCE(SUM(total_bayar), 0) as total 
                                   FROM sales_header 
                                   WHERE tanggal_transaksi BETWEEN ? AND ?");
        if ($stmt) {
            $stmt->bind_param("ss", $first_day_month, $last_day_month);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $total_penjualan_bulan_ini = floatval($row['total']);
            }
            $stmt->close();
        }
        

        $stmt = $koneksi->prepare("SELECT COALESCE(SUM(total_bayar), 0) as total 
                                   FROM purchases_header 
                                   WHERE tanggal_transaksi BETWEEN ? AND ?");
        if ($stmt) {
            $stmt->bind_param("ss", $first_day_month, $last_day_month);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $total_pengadaan_bulan_ini = floatval($row['total']);
            }
            $stmt->close();
        }

        if ($user_role == 'manager') {
          
            $stmt = $koneksi->prepare("
                SELECT 
                    COALESCE(SUM(sh.total_bayar), 0) as total_penjualan,
                    COALESCE(SUM(sd.qty * p.harga_beli), 0) as total_harga_beli
                FROM sales_header sh
                LEFT JOIN sales_detail sd ON sh.sale_id = sd.sale_id
                LEFT JOIN products p ON sd.product_id = p.product_id
                WHERE sh.tanggal_transaksi BETWEEN ? AND ?
            ");
            if ($stmt) {
                $stmt->bind_param("ss", $first_day_month, $last_day_month);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $total_penjualan = floatval($row['total_penjualan']);
                    $total_harga_beli = floatval($row['total_harga_beli']);
                    $laba_kotor_bulan_ini = $total_penjualan - $total_harga_beli;
                }
                $stmt->close();
            }
        }
    }
    

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $chart_labels[] = date('d M', strtotime("-$i days"));
        
        $stmt = $koneksi->prepare("SELECT COALESCE(SUM(total_bayar), 0) as total 
                                   FROM sales_header 
                                   WHERE tanggal_transaksi = ?");
        if ($stmt) {
            $stmt->bind_param("s", $date);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $chart_sales[] = floatval($row['total']);
            $stmt->close();
        } else {
            $chart_sales[] = 0;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Sistem Minimarket</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/modern-style.css">
    
    <style>
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            padding: 30px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .stat-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        
        .stat-card.bg-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .stat-card.bg-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        
        .stat-card.bg-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        
        .stat-card.bg-info {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }
        
        .stat-card.bg-light {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            color: #1e293b;
        }
        
        .stat-card .card-title {
            font-size: 0.9rem;
            font-weight: 600;
            opacity: 0.9;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-card .fs-3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .stat-card .btn {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            font-weight: 600;
        }
        
        .stat-card.bg-light .btn {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            border: none;
        }
        
        .stat-card .btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .stat-card.bg-light .btn:hover {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
        }
        
        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .chart-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
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
                <a class="nav-link active-page" href="dashboard.php">
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

            <?php if ($user_role == 'kasir'): ?>
            <li class="nav-item mt-3">
                <strong class="text-secondary small">LAPORAN</strong>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="report_sales_kasir.php"><i class="fas fa-chart-bar"></i> Laporan Penjualan Saya</a>
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
    
    <div id="main-content" class="fade-in">
        
        <div class="dashboard-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold gradient-text mb-2"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</h2>
                    <p class="text-muted mb-0">Selamat datang kembali, <b class="text-primary"><?= htmlspecialchars($nama_user); ?></b></p>
                </div>
                <div class="text-end">
                    <p class="text-muted mb-0"><i class="fas fa-calendar-alt me-2"></i><?= date('d F Y') ?></p>
                    <p class="text-muted mb-0">
                        <i class="fas fa-clock me-2"></i><span id="liveClock"><?= date('H:i') ?></span>
                    </p>
                </div>
            </div>
        </div>

        <?php if ($user_role == 'kasir'): ?>
            <div class="alert alert-info mb-4">
                <i class="bi bi-person-badge me-2"></i> Anda login sebagai <b>Kasir</b>. Silakan fokus pada transaksi penjualan.
            </div>

            <div class="row g-4">

                <div class="col-md-6">
                    <div class="stat-card bg-success">
                        <div class="card-body position-relative">
                            <h5 class="card-title">Total Penjualan Hari Ini</h5>
                            <p class="fs-3 fw-bold"><?= formatRupiah($total_penjualan_hari_ini) ?></p>
                            <a href="sales_transaction.php" class="btn btn-sm">Mulai Transaksi Baru <i class="fas fa-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="stat-card bg-primary">
                        <div class="card-body position-relative">
                            <h5 class="card-title">Jumlah Transaksi Saya</h5>
                            <p class="fs-3 fw-bold"><?= $jumlah_transaksi_hari_ini ?> Transaksi</p>
                            <a href="report_sales_kasir.php" class="btn btn-sm">Lihat Riwayat <i class="fas fa-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>

            </div>

        <?php elseif (in_array($user_role, ['manager', 'supervisor'])): ?>

            <div class="alert alert-warning mb-4">
                <i class="bi bi-shield-check me-2"></i> Anda login sebagai 
                <b><?= strtoupper($user_role); ?></b>. Anda dapat mengelola stok & pengadaan.
            </div>

            <div class="row g-4">

                <div class="col-md-4">
                    <div class="stat-card bg-danger">
                        <div class="card-body position-relative">
                            <h5 class="card-title">Barang Stok Kritis</h5>
                            <p class="fs-3 fw-bold"><?= $barang_stok_kritis ?> Produk</p>
                            <a href="products.php?status=critical" class="btn btn-sm">Lihat Produk <i class="fas fa-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-card bg-info">
                        <div class="card-body position-relative">
                            <h5 class="card-title">Total Penjualan Bulan Ini</h5>
                            <p class="fs-3 fw-bold"><?= formatRupiah($total_penjualan_bulan_ini) ?></p>
                            <a href="report_sales.php" class="btn btn-sm">Detail Laporan <i class="fas fa-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="stat-card bg-light">
                        <div class="card-body position-relative">
                            <h5 class="card-title">Total Pengadaan</h5>
                            <p class="fs-3 fw-bold"><?= formatRupiah($total_pengadaan_bulan_ini) ?></p>
                            <a href="purchases_transaction.php" class="btn btn-sm">Buat Order Baru <i class="fas fa-arrow-right ms-2"></i></a>
                        </div>
                    </div>
                </div>

            </div>

            <?php if ($user_role == 'manager'): ?>
            <div class="row g-4 mt-2">
                <div class="col-md-6">
                    <div class="stat-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                        <div class="card-body position-relative">
                            <h5 class="card-title">Laba Kotor Bulan Ini</h5>
                            <p class="fs-3 fw-bold"><?= formatRupiah($laba_kotor_bulan_ini) ?></p>
                            <small class="opacity-75">Total Penjualan - Total Harga Beli</small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <?php endif; ?>

        <div class="chart-container mt-5">
            <h4 class="fw-bold mb-4 gradient-text">
                <i class="fas fa-chart-line me-2"></i> Grafik Penjualan 7 Hari Terakhir
            </h4>
            <div style="height: 250px; position: relative;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        
        (function () {
            const el = document.getElementById('liveClock');
            if (!el) return;
            const tick = () => {
                const now = new Date();
                el.textContent = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
            };
            tick();
            setInterval(tick, 1000);
        })();

  
        const chartLabels = <?= json_encode($chart_labels) ?>;
        const chartSales = <?= json_encode($chart_sales) ?>;
        
    
        const ctx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Total Penjualan (Rp)',
                    data: chartSales,
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                aspectRatio: 2.5,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('id-ID', {
                                        style: 'currency',
                                        currency: 'IDR',
                                        minimumFractionDigits: 0
                                    }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('id-ID', {
                                    style: 'currency',
                                    currency: 'IDR',
                                    minimumFractionDigits: 0,
                                    maximumFractionDigits: 0
                                }).format(value);
                            }
                        }
                    }
                }
            }
        });
    </script>
    
    </body>
</html>
<?php 
if($koneksi && $koneksi instanceof mysqli) $koneksi->close(); 
?>