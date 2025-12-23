<?php


session_start();
include "../config/koneksi.php";

date_default_timezone_set('Asia/Jakarta');


if (!isset($_SESSION['username'])) {
    header("Location: ../auth/login.php");
    exit;
}


$sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($sale_id <= 0) {
    die("Error: ID Transaksi tidak valid.");
}

$DB_HEADER = "sales_header";
$DB_DETAIL = "sales_detail";
$DB_PRODUCTS = "products";
$DB_PELANGGAN = "pelanggan";

$sql = "SELECT sh.sale_id, sh.tanggal_transaksi, sh.user_id, sh.pelanggan_id, sh.total_bayar, sh.diskon, sh.metode_bayar,
        COALESCE(u.full_name, pg.full_name, 'Unknown') as nama_kasir,
        COALESCE(pl.nama_pelanggan, 'Umum') as nama_pelanggan
        FROM $DB_HEADER sh
        LEFT JOIN users u ON sh.user_id = u.user_id
        LEFT JOIN pegawai pg ON sh.user_id = pg.user_id
        LEFT JOIN $DB_PELANGGAN pl ON sh.pelanggan_id = pl.pelanggan_id
        WHERE sh.sale_id = ?";

$stmt = $koneksi->prepare($sql);
if (!$stmt) {
    die("Error: " . $koneksi->error);
}

$stmt->bind_param("i", $sale_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Error: Transaksi tidak ditemukan.");
}

$transaksi = $result->fetch_assoc();
$stmt->close();


$sql_detail = "SELECT sd.*, p.kode_barang, p.nama_barang 
               FROM $DB_DETAIL sd
               INNER JOIN $DB_PRODUCTS p ON sd.product_id = p.product_id
               WHERE sd.sale_id = ?
               ORDER BY sd.detail_id ASC";

$stmt_detail = $koneksi->prepare($sql_detail);
$stmt_detail->bind_param("i", $sale_id);
$stmt_detail->execute();
$result_detail = $stmt_detail->get_result();
$items = [];
while ($row = $result_detail->fetch_assoc()) {
    $items[] = $row;
}
$stmt_detail->close();


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

$nama_toko = "MINIMARKET RAKYAT";
$alamat_toko = "Perum. Puri Pesona Blok A Ruko No. 2 (Toko Indra) Rt/Rw 004/009, Kel. Bojong Pondok Terong, Kec. Cipayung, Kota Depok, Kode Pos 16444";


$no_penjualan = 'TRJ' . str_pad($sale_id, 6, '0', STR_PAD_LEFT);
$tanggal = formatTanggalIndo($transaksi['tanggal_transaksi']);
$tanggal_singkat = date('d/m/Y', strtotime($transaksi['tanggal_transaksi']));
$total_belanja = floatval($transaksi['total_bayar']) + floatval($transaksi['diskon']);
$diskon = floatval($transaksi['diskon']);
$total_bayar = floatval($transaksi['total_bayar']);


$jumlah_bayar = $total_bayar;
$kembalian = 0;
if (isset($_SESSION['nota_data'][$sale_id])) {
    $nota_data = $_SESSION['nota_data'][$sale_id];
    $jumlah_bayar = floatval($nota_data['paid_amount'] ?? $total_bayar);
    $kembalian = floatval($nota_data['change_amount'] ?? 0);
   
    unset($_SESSION['nota_data'][$sale_id]);
}
$nama_kasir = $transaksi['nama_kasir'] ?? 'Kasir';


$nama_supervisor = '';
$stmt_spv = $koneksi->prepare("
    SELECT COALESCE(pg.full_name, u.full_name, '') AS full_name
    FROM users u
    LEFT JOIN pegawai pg ON pg.user_id = u.user_id
    WHERE u.role = 'supervisor' AND (u.status = 'active' OR u.status IS NULL)
    ORDER BY u.user_id ASC
    LIMIT 1
");
if ($stmt_spv) {
    $stmt_spv->execute();
    $res_spv = $stmt_spv->get_result();
    if ($res_spv && $res_spv->num_rows > 0) {
        $row_spv = $res_spv->fetch_assoc();
        $nama_supervisor = trim($row_spv['full_name'] ?? '');
    }
    $stmt_spv->close();
}

$pelanggan_id_raw = $transaksi['pelanggan_id'] ?? null;


$pelanggan_id_int = ($pelanggan_id_raw !== null && $pelanggan_id_raw !== '') ? intval($pelanggan_id_raw) : 0;


if ($pelanggan_id_int > 0) {
    
    $id_pelanggan = 'PL' . str_pad($pelanggan_id_int, 4, '0', STR_PAD_LEFT);
    
   
    $stmt_pelanggan = $koneksi->prepare("SELECT nama_pelanggan FROM $DB_PELANGGAN WHERE pelanggan_id = ?");
    if ($stmt_pelanggan) {
        $stmt_pelanggan->bind_param("i", $pelanggan_id_int);
        $stmt_pelanggan->execute();
        $result_pelanggan = $stmt_pelanggan->get_result();
        if ($result_pelanggan->num_rows > 0) {
            $pelanggan_data = $result_pelanggan->fetch_assoc();
            $nama_pelanggan = $pelanggan_data['nama_pelanggan'] ?? 'Umum';
        } else {
        
            $nama_pelanggan = $transaksi['nama_pelanggan'] ?? 'Umum';
        }
        $stmt_pelanggan->close();
    } else {
 
        $nama_pelanggan = $transaksi['nama_pelanggan'] ?? 'Umum';
    }
} else {

    $id_pelanggan = '-';
    $nama_pelanggan = 'Umum';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nota Penjualan - <?= htmlspecialchars($no_penjualan) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .nota-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .nota-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .nama-toko {
            color: #28a745;
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .alamat-toko {
            font-size: 12px;
            color: #333;
            line-height: 1.6;
        }
        
        .judul-nota {
            text-align: center;
            margin: 20px 0;
            padding: 10px 0;
            border-top: 3px solid #28a745;
            border-bottom: 3px solid #28a745;
            background: linear-gradient(90deg, rgba(40,167,69,0.1) 0%, rgba(40,167,69,0.2) 50%, rgba(40,167,69,0.1) 100%);
        }
        
        .judul-nota h2 {
            font-size: 22px;
            font-weight: bold;
            color: #28a745;
        }
        
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .info-left, .info-right {
            width: 48%;
        }
        
        .info-row {
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: bold;
            display: inline-block;
            width: 120px;
        }
        
        .table-container {
            margin: 20px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        table th {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 12px 10px;
            text-align: left;
            border: 1px solid #1e7e34;
            font-weight: bold;
        }
        
        table td {
            padding: 10px;
            border: 1px solid #ddd;
        }
        
        table tbody tr:nth-child(even) {
            background-color: #f0f8f4;
        }
        
        table tbody tr:hover {
            background-color: #e8f5e9;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .summary-section {
            margin-top: 20px;
            border-top: 3px solid #28a745;
            padding-top: 15px;
            background: linear-gradient(90deg, rgba(40,167,69,0.05) 0%, rgba(40,167,69,0.1) 50%, rgba(40,167,69,0.05) 100%);
            padding: 15px;
            border-radius: 5px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 15px;
            padding: 5px 0;
        }
        
        .summary-label {
            font-weight: bold;
            color: #333;
        }
        
        .summary-value {
            font-weight: bold;
            color: #28a745;
            font-size: 16px;
        }
        
        .footer-section {
            margin-top: 30px;
            text-align: right;
            font-size: 12px;
            color: #666;
        }
        
        .kasir-section {
            text-align: center;
            margin-top: 40px;
            font-size: 14px;
        }
        
        .kasir-label {
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .kasir-name {
            margin-top: 30px;
        }

        .signature-grid{
            display:flex;
            justify-content:space-between;
            gap:40px;
            margin-top:30px;
        }
        .signature-box{
            flex:1;
            text-align:center;
        }
        .signature-line{
            margin-top:60px;
            border-top:1px solid #333;
            padding-top:8px;
            font-weight:bold;
        }
        
        .button-container {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
        }
        
        .btn-print {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            margin-right: 10px;
        }
        
        .btn-print:hover {
            background-color: #0056b3;
        }
        
        .btn-back {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 12px 30px;
            font-size: 16px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-back:hover {
            background-color: #5a6268;
        }
        
        @media print {
            
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            body {
                background: white;
                padding: 0;
            }
            
            .button-container {
                display: none;
            }

           
            body * {
                visibility: hidden !important;
            }
            .print-area, .print-area * {
                visibility: visible !important;
            }
            .print-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            
            .nota-container {
                box-shadow: none;
                padding: 20px;
            }
            
           
            .nama-toko {
                color: #28a745 !important;
                -webkit-text-fill-color: #28a745 !important;
                background: none !important;
            }


            table th {
                background: #28a745 !important;
                color: white !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            

            .judul-nota {
                border-top-color: #28a745 !important;
                border-bottom-color: #28a745 !important;
            }
            
            .summary-section {
                border-top-color: #28a745 !important;
            }
            
            .summary-value {
                color: #28a745 !important;
            }
            
            .judul-nota h2 {
                color: #28a745 !important;
            }
        }
    </style>
</head>
<body>
    <div class="button-container">
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print
        </button>
        <a href="sales_transaction.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
    
    <div class="print-area">
    <div class="nota-container">
       
        <div class="nota-header">
            <div class="nama-toko"><?= htmlspecialchars($nama_toko) ?></div>
            <div class="alamat-toko"><?= htmlspecialchars($alamat_toko) ?></div>
        </div>
        
     
        <div class="judul-nota">
            <h2>Nota Penjualan</h2>
        </div>
        
        
        <div class="info-section">
            <div class="info-left">
                <div class="info-row">
                    <span class="info-label">Tanggal</span>
                    <span>: <?= htmlspecialchars($tanggal_singkat) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">No penjualan</span>
                    <span>: <?= htmlspecialchars($no_penjualan) ?></span>
                </div>
            </div>
            <div class="info-right">
                <div class="info-row">
                    <span class="info-label">ID Pelanggan</span>
                    <span>: <?= htmlspecialchars($id_pelanggan) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Nama Pelanggan</span>
                    <span>: <?= htmlspecialchars($nama_pelanggan) ?></span>
                </div>
            </div>
        </div>
        

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Barang</th>
                        <th>Nama Barang</th>
                        <th class="text-right">Harga</th>
                        <th class="text-center">Qty</th>
                        <th class="text-right">Sub Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($items as $item): 
                        $subtotal = floatval($item['qty']) * floatval($item['harga_satuan']);
                    ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= htmlspecialchars($item['kode_barang']) ?></td>
                        <td><?= htmlspecialchars($item['nama_barang']) ?></td>
                        <td class="text-right"><?= formatRupiah($item['harga_satuan']) ?></td>
                        <td class="text-center"><?= $item['qty'] ?></td>
                        <td class="text-right"><?= formatRupiah($subtotal) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
      
        <div class="summary-section">
            <div class="summary-row">
                <span class="summary-label">Total Belanja</span>
                <span class="summary-value"><?= formatRupiah($total_belanja) ?></span>
            </div>
            <?php if ($diskon > 0): ?>
            <div class="summary-row">
                <span class="summary-label">Diskon</span>
                <span class="summary-value">- <?= formatRupiah($diskon) ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row">
                <span class="summary-label">Jumlah Bayar</span>
                <span class="summary-value"><?= formatRupiah($jumlah_bayar) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">Jumlah Kembalian</span>
                <span class="summary-value"><?= formatRupiah($kembalian) ?></span>
            </div>
        </div>
        
        
        <div class="footer-section">
            <div>Depok, <?= htmlspecialchars($tanggal) ?></div>
        </div>
        
       
        <div class="signature-grid">
            <div class="signature-box">
                <div class="kasir-label">Kasir</div>
                <div class="signature-line">( <?= htmlspecialchars($nama_kasir) ?> )</div>
            </div>
            <div class="signature-box">
                <div class="kasir-label">Supervisor</div>
                <div class="signature-line">( <?= htmlspecialchars($nama_supervisor !== '' ? $nama_supervisor : '....................') ?> )</div>
            </div>
        </div>
    </div>
    </div>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</body>
</html>

