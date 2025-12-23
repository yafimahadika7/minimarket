<?php

session_start();
include "../config/koneksi.php"; 

date_default_timezone_set('Asia/Jakarta');


$DB_HEADER = "sales_header"; 
$DB_DETAIL = "sales_detail"; 
$DB_PRODUCTS = "products"; 
$DB_PELANGGAN = "pelanggan"; 

// --- KONEKSI & OTORISASI ---
$is_connected = isset($koneksi) && $koneksi instanceof mysqli && $koneksi->connect_error === null;
$conn_error = '';

if (!$is_connected) {
    $error_msg = $koneksi->connect_error ?? 'Variable $koneksi tidak terdefinisi.';
    $conn_error = '<div class="alert alert-danger"><strong>ERROR KONEKSI:</strong> Gagal terhubung ke database. Error: '. htmlspecialchars($error_msg) .'</div>';
}

// --- OTENTIKASI ---
if (!isset($_SESSION['username'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? 'kasir';
$nama_user = $_SESSION['nama_lengkap'] ?? $_SESSION['username'] ?? 'Kasir';

// Pastikan user_id valid
if (empty($user_id)) {
    $_SESSION['trans_message'] = '<div class="alert alert-danger">User ID tidak valid. Silakan login ulang.</div>';
    header("Location: ../auth/login.php");
    exit;
}

// Ambil data pegawai dari database
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

// --- MODE EDIT TRANSAKSI (Hanya untuk Manager & Supervisor) ---
$edit_mode = false;
$edit_sale_id = null;
$edit_transaction_data = null;
$edit_items_data = [];

if (isset($_GET['edit']) && in_array($user_role, ['manager', 'supervisor'])) {
    $edit_sale_id = intval($_GET['edit']);
    if ($edit_sale_id > 0 && $is_connected) {
        // Load data transaksi header
        $stmt_header = $koneksi->prepare("SELECT sh.*, 
                                         COALESCE(u.full_name, pg.full_name, 'Unknown') as nama_pegawai,
                                         COALESCE(pl.nama_pelanggan, 'Umum') as nama_pelanggan
                                         FROM $DB_HEADER sh
                                         LEFT JOIN users u ON sh.user_id = u.user_id
                                         LEFT JOIN pegawai pg ON sh.user_id = pg.user_id
                                         LEFT JOIN pelanggan pl ON sh.pelanggan_id = pl.pelanggan_id
                                         WHERE sh.sale_id = ?");
        if ($stmt_header) {
            $stmt_header->bind_param("i", $edit_sale_id);
            $stmt_header->execute();
            $result_header = $stmt_header->get_result();
            if ($result_header->num_rows > 0) {
                $edit_transaction_data = $result_header->fetch_assoc();
                $edit_mode = true;
                
                // Load data detail transaksi
                $stmt_detail = $koneksi->prepare("SELECT sd.*, p.kode_barang, p.nama_barang, p.harga_jual, p.harga_beli, p.stok 
                                                  FROM $DB_DETAIL sd
                                                  INNER JOIN $DB_PRODUCTS p ON sd.product_id = p.product_id
                                                  WHERE sd.sale_id = ?");
                if ($stmt_detail) {
                    $stmt_detail->bind_param("i", $edit_sale_id);
                    $stmt_detail->execute();
                    $result_detail = $stmt_detail->get_result();
                    while ($row = $result_detail->fetch_assoc()) {
                        $edit_items_data[] = [
                            'id' => intval($row['product_id']),
                            'code' => $row['kode_barang'],
                            'name' => $row['nama_barang'],
                            'price' => floatval($row['harga_satuan']),
                            'price_beli' => floatval($row['harga_beli']),
                            'qty' => intval($row['qty']),
                            'stock' => intval($row['stok'])
                        ];
                    }
                    $stmt_detail->close();
                }
            }
            $stmt_header->close();
        }
    }
}

// Generate nomor transaksi (TRJ + 6 digit)
$next_transaction_no = 'TRJ000001';
if ($is_connected && !$edit_mode) {
    $stmt_max = $koneksi->query("SELECT MAX(sale_id) as max_id FROM sales_header");
    if ($stmt_max && $row = $stmt_max->fetch_assoc()) {
        $next_id = ($row['max_id'] ?? 0) + 1;
        $next_transaction_no = 'TRJ' . str_pad($next_id, 6, '0', STR_PAD_LEFT);
    }
} elseif ($edit_mode && $edit_transaction_data) {
    $next_transaction_no = 'TRJ' . str_pad($edit_sale_id, 6, '0', STR_PAD_LEFT);
}

// --- PESAN TRANSAKSI ---
$message = $conn_error;
if (isset($_SESSION['trans_message'])) {
    $message .= $_SESSION['trans_message'];
    unset($_SESSION['trans_message']);
}

// Helper untuk format Rupiah
function formatRupiah($angka) {
    // Fungsi ini sama dengan yang di JS (format Intl), digunakan untuk tampilan PHP
    return 'Rp' . number_format($angka, 0, ',', '.');
}

// --- LOGIKA PEMROSESAN TRANSAKSI (POST) ---
if ($is_connected && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'checkout') {
    $redirect_to_self = "Location: sales_transaction.php";
    
    // Pastikan koneksi support multi-query dan transaksi
    if ($koneksi->autocommit(FALSE)) { 
        $koneksi->begin_transaction();
    }

    try {
        // Validasi input
        if (empty($_POST['items_json'])) {
            throw new Exception("Data keranjang belanja tidak valid.");
        }
        
        $items = json_decode($_POST['items_json'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($items) || !is_array($items)) {
            throw new Exception("Format data keranjang belanja tidak valid.");
        }
        
        // Validasi setiap item
        foreach ($items as $item) {
            if (empty($item['id']) || empty($item['qty']) || empty($item['price'])) {
                throw new Exception("Data item tidak lengkap.");
            }
            if (intval($item['qty']) <= 0) {
                throw new Exception("Quantity harus lebih dari 0.");
            }
            if (floatval($item['price']) <= 0) {
                throw new Exception("Harga harus lebih dari 0.");
            }
        }
        
        // PERHATIAN: Pelanggan ID dari JS adalah string kosong "" jika Umum. Kita konversi ke NULL.
        $pelanggan_id = empty($_POST['pelanggan_id']) || $_POST['pelanggan_id'] === '' ? NULL : intval($_POST['pelanggan_id']);
        $total_bayar = floatval($_POST['final_total'] ?? 0);
        $diskon = floatval($_POST['discount'] ?? 0);
        $metode_bayar = trim($_POST['metode_bayar'] ?? 'Cash');
        $paid_amount = floatval($_POST['paid_amount'] ?? $total_bayar);
        $change_amount = floatval($_POST['change_amount'] ?? 0);
        
        if ($total_bayar <= 0) {
            throw new Exception("Total pembayaran harus lebih dari 0.");
        }
        
        if ($diskon < 0) {
            throw new Exception("Diskon tidak boleh negatif.");
        }
        
        // Validasi metode bayar
        $allowed_methods = ['Cash', 'Debit', 'Credit', 'QRIS', 'Transfer'];
        if (!in_array($metode_bayar, $allowed_methods)) {
            $metode_bayar = 'Cash';
        }
        
        // 1. INSERT KE sales_header (Dengan penanganan NULL untuk pelanggan_id)
        if ($pelanggan_id === NULL) {
             $sql_header_final = "INSERT INTO $DB_HEADER (tanggal_transaksi, user_id, pelanggan_id, total_bayar, diskon, metode_bayar) 
                                 VALUES (CURDATE(), ?, NULL, ?, ?, ?)";
             $stmt_header = $koneksi->prepare($sql_header_final);
             if (!$stmt_header) throw new Exception("Prepare header gagal: " . $koneksi->error);
             // user_id (int), total_bayar (double), diskon (double), metode_bayar (string)
             $stmt_header->bind_param("idds", $user_id, $total_bayar, $diskon, $metode_bayar); 
        } else {
             $sql_base = "INSERT INTO $DB_HEADER (tanggal_transaksi, user_id, pelanggan_id, total_bayar, diskon, metode_bayar) 
                       VALUES (CURDATE(), ?, ?, ?, ?, ?)";
             $stmt_header = $koneksi->prepare($sql_base);
             if (!$stmt_header) throw new Exception("Prepare header gagal: " . $koneksi->error);
             // user_id (int), pelanggan_id (int), total_bayar (double), diskon (double), metode_bayar (string)
             $stmt_header->bind_param("iidds", $user_id, $pelanggan_id, $total_bayar, $diskon, $metode_bayar); 
        }

        if (!$stmt_header->execute()) throw new Exception("Execute header gagal: " . $stmt_header->error);
        
        $sale_id = $koneksi->insert_id;
        $stmt_header->close();

        // 2. VALIDASI STOK SEBELUM INSERT (Pre-check semua stok)
        $stock_errors = [];
        foreach ($items as $item) {
            $product_id = intval($item['id']);
            $qty = intval($item['qty']);
            
            // Cek stok tersedia
            $stmt_check = $koneksi->prepare("SELECT stok, nama_barang FROM $DB_PRODUCTS WHERE product_id = ?");
            if ($stmt_check) {
                $stmt_check->bind_param("i", $product_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                
                if ($result_check->num_rows === 0) {
                    $stock_errors[] = "Barang dengan ID {$product_id} tidak ditemukan.";
                } else {
                    $product_data = $result_check->fetch_assoc();
                    if ($product_data['stok'] < $qty) {
                        $stock_errors[] = "Stok " . htmlspecialchars($product_data['nama_barang']) . " tidak mencukupi. Tersedia: {$product_data['stok']}, Dibutuhkan: {$qty}";
                    }
                }
                $stmt_check->close();
            }
        }
        
        if (!empty($stock_errors)) {
            throw new Exception("Validasi stok gagal:\n" . implode("\n", $stock_errors));
        }
        
        // 3. LOOP & INSERT KE sales_detail + UPDATE STOCK
        $sql_detail = "INSERT INTO $DB_DETAIL (sale_id, product_id, qty, harga_satuan, subtotal) VALUES (?, ?, ?, ?, ?)";
        $stmt_detail = $koneksi->prepare($sql_detail);
        if (!$stmt_detail) throw new Exception("Prepare detail gagal: " . $koneksi->error);

        // Atomic update stock: memastikan stok tidak kurang dari yang dibeli
        $sql_update_stock = "UPDATE $DB_PRODUCTS SET stok = stok - ? WHERE product_id = ? AND stok >= ?"; 
        $stmt_stock = $koneksi->prepare($sql_update_stock);
        if (!$stmt_stock) throw new Exception("Prepare stock gagal: " . $koneksi->error);
        
        foreach ($items as $item) {
            $product_id = intval($item['id']);
            $qty = intval($item['qty']);
            $harga = floatval($item['price']);
            $subtotal = $qty * $harga;
            
            // Update Stok (dengan validasi atomic)
            $stmt_stock->bind_param("iii", $qty, $product_id, $qty);
            if (!$stmt_stock->execute()) {
                throw new Exception("Update stok gagal untuk product_id {$product_id}: " . $stmt_stock->error);
            }
            
            if ($koneksi->affected_rows === 0) {
                 // Gagal update stok, artinya stok tidak mencukupi (stok < qty)
                 $stmt_check = $koneksi->prepare("SELECT stok, nama_barang FROM $DB_PRODUCTS WHERE product_id = ?");
                 $stmt_check->bind_param("i", $product_id);
                 $stmt_check->execute();
                 $result_check = $stmt_check->get_result();
                 $stock_data = $result_check->fetch_assoc() ?? ['nama_barang' => 'Unknown', 'stok' => 0];
                 $stmt_check->close();
                 throw new Exception("Stok barang " . htmlspecialchars($stock_data['nama_barang']) . " tidak mencukupi. Sisa stok: " . $stock_data['stok']);
            }
            
            // Insert Detail
            $stmt_detail->bind_param("iiidd", $sale_id, $product_id, $qty, $harga, $subtotal);
            if (!$stmt_detail->execute()) {
                throw new Exception("Insert detail gagal untuk product_id {$product_id}: " . $stmt_detail->error);
            }
        }

        $stmt_detail->close();
        $stmt_stock->close();
        
        // COMMIT jika semua berhasil
        $koneksi->commit();
        $transaction_no = 'TRJ' . str_pad($sale_id, 6, '0', STR_PAD_LEFT);
        $_SESSION['trans_message'] = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>Transaksi Berhasil!</strong><br>No. Transaksi: <strong>'.$transaction_no.'</strong><br>Total: <strong>'.formatRupiah($total_bayar).'</strong><br>Metode: <strong>'.$metode_bayar.'</strong></div>';
        
        // Simpan data pembayaran di session untuk print nota
        $_SESSION['nota_data'][$sale_id] = [
            'paid_amount' => $paid_amount,
            'change_amount' => $change_amount
        ];
        
        // Redirect ke halaman print nota
        header("Location: print_nota.php?id=" . $sale_id);
        exit;
        
    } catch (Exception $e) {
        // ROLLBACK jika terjadi error
        if ($koneksi->in_transaction) {
            $koneksi->rollback();
        }
        $_SESSION['trans_message'] = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>Transaksi GAGAL!</strong><br>'. nl2br(htmlspecialchars($e->getMessage())) .'</div>';
    } catch (Error $e) {

        if ($koneksi->in_transaction) {
            $koneksi->rollback();
        }
        $_SESSION['trans_message'] = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>Error Sistem!</strong><br>'. htmlspecialchars($e->getMessage()) .'</div>';
    }

    // Kembalikan ke autocommit=TRUE
    $koneksi->autocommit(TRUE);
    
    // Jika error, redirect kembali ke halaman transaksi
    header($redirect_to_self);
    exit;
}

// --- LOGIKA UPDATE TRANSAKSI (Hanya untuk Manager & Supervisor) ---
if ($is_connected && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'update' && in_array($user_role, ['manager', 'supervisor'])) {
    $sale_id_to_edit = intval($_POST['sale_id'] ?? 0);
    
    if ($sale_id_to_edit <= 0) {
        $_SESSION['trans_message'] = '<div class="alert alert-danger">ID Transaksi tidak valid.</div>';
        header("Location: sales_transaction.php");
        exit;
    }
    
    $redirect_to_self = "Location: sales_transaction.php?edit=" . $sale_id_to_edit;
    
    // Pastikan koneksi support transaksi
    if ($koneksi->autocommit(FALSE)) { 
        $koneksi->begin_transaction();
    }
    
    try {
        // Validasi input
        if (empty($_POST['items_json'])) {
            throw new Exception("Data keranjang belanja tidak valid.");
        }
        
        $items = json_decode($_POST['items_json'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($items) || !is_array($items)) {
            throw new Exception("Format data keranjang belanja tidak valid.");
        }
        
        // Validasi setiap item
        foreach ($items as $item) {
            if (empty($item['id']) || empty($item['qty']) || empty($item['price'])) {
                throw new Exception("Data item tidak lengkap.");
            }
            if (intval($item['qty']) <= 0) {
                throw new Exception("Quantity harus lebih dari 0.");
            }
            if (floatval($item['price']) <= 0) {
                throw new Exception("Harga harus lebih dari 0.");
            }
        }
        
        $pelanggan_id = empty($_POST['pelanggan_id']) || $_POST['pelanggan_id'] === '' ? NULL : intval($_POST['pelanggan_id']);
        $total_bayar = floatval($_POST['final_total'] ?? 0);
        $diskon = floatval($_POST['discount'] ?? 0);
        $metode_bayar = trim($_POST['metode_bayar'] ?? 'Cash');
        
        if ($total_bayar <= 0) {
            throw new Exception("Total pembayaran harus lebih dari 0.");
        }
        
        if ($diskon < 0) {
            throw new Exception("Diskon tidak boleh negatif.");
        }
        
        // Validasi metode bayar
        $allowed_methods = ['Cash', 'Debit', 'Credit', 'QRIS', 'Transfer'];
        if (!in_array($metode_bayar, $allowed_methods)) {
            $metode_bayar = 'Cash';
        }
        
        // 1. Ambil data transaksi lama untuk rollback stok
        $stmt_old = $koneksi->prepare("SELECT product_id, qty FROM $DB_DETAIL WHERE sale_id = ?");
        $old_items = [];
        if ($stmt_old) {
            $stmt_old->bind_param("i", $sale_id_to_edit);
            $stmt_old->execute();
            $result_old = $stmt_old->get_result();
            while ($row = $result_old->fetch_assoc()) {
                $old_items[] = $row;
            }
            $stmt_old->close();
        }
        
        // 2. Rollback stok dari transaksi lama
        foreach ($old_items as $old_item) {
            $stmt_rollback = $koneksi->prepare("UPDATE $DB_PRODUCTS SET stok = stok + ? WHERE product_id = ?");
            if ($stmt_rollback) {
                $stmt_rollback->bind_param("ii", $old_item['qty'], $old_item['product_id']);
                $stmt_rollback->execute();
                $stmt_rollback->close();
            }
        }
        
        // 3. Hapus detail lama
        $stmt_delete_detail = $koneksi->prepare("DELETE FROM $DB_DETAIL WHERE sale_id = ?");
        if ($stmt_delete_detail) {
            $stmt_delete_detail->bind_param("i", $sale_id_to_edit);
            $stmt_delete_detail->execute();
            $stmt_delete_detail->close();
        }
        
        // 4. Update header transaksi
        if ($pelanggan_id === NULL) {
            $sql_update_header = "UPDATE $DB_HEADER SET pelanggan_id = NULL, total_bayar = ?, diskon = ?, metode_bayar = ? WHERE sale_id = ?";
            $stmt_header = $koneksi->prepare($sql_update_header);
            if ($stmt_header) {
                // total_bayar (double), diskon (double), metode_bayar (string), sale_id (int)
                $stmt_header->bind_param("ddsi", $total_bayar, $diskon, $metode_bayar, $sale_id_to_edit);
            }
        } else {
            $sql_update_header = "UPDATE $DB_HEADER SET pelanggan_id = ?, total_bayar = ?, diskon = ?, metode_bayar = ? WHERE sale_id = ?";
            $stmt_header = $koneksi->prepare($sql_update_header);
            if ($stmt_header) {
                // pelanggan_id (int), total_bayar (double), diskon (double), metode_bayar (string), sale_id (int)
                $stmt_header->bind_param("iddsi", $pelanggan_id, $total_bayar, $diskon, $metode_bayar, $sale_id_to_edit);
            }
        }
        
        if (!$stmt_header || !$stmt_header->execute()) {
            throw new Exception("Update header gagal: " . ($stmt_header->error ?? $koneksi->error));
        }
        $stmt_header->close();
        
        // 5. Validasi stok sebelum insert detail baru
        $stock_errors = [];
        foreach ($items as $item) {
            $product_id = intval($item['id']);
            $qty = intval($item['qty']);
            
            $stmt_check = $koneksi->prepare("SELECT stok, nama_barang FROM $DB_PRODUCTS WHERE product_id = ?");
            if ($stmt_check) {
                $stmt_check->bind_param("i", $product_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                
                if ($result_check->num_rows === 0) {
                    $stock_errors[] = "Barang dengan ID {$product_id} tidak ditemukan.";
                } else {
                    $product_data = $result_check->fetch_assoc();
                    if ($product_data['stok'] < $qty) {
                        $stock_errors[] = "Stok " . htmlspecialchars($product_data['nama_barang']) . " tidak mencukupi. Tersedia: {$product_data['stok']}, Dibutuhkan: {$qty}";
                    }
                }
                $stmt_check->close();
            }
        }
        
        if (!empty($stock_errors)) {
            throw new Exception("Validasi stok gagal:\n" . implode("\n", $stock_errors));
        }
        
     
        $sql_detail = "INSERT INTO $DB_DETAIL (sale_id, product_id, qty, harga_satuan, subtotal) VALUES (?, ?, ?, ?, ?)";
        $stmt_detail = $koneksi->prepare($sql_detail);
        if (!$stmt_detail) throw new Exception("Prepare detail gagal: " . $koneksi->error);
        
        $sql_update_stock = "UPDATE $DB_PRODUCTS SET stok = stok - ? WHERE product_id = ? AND stok >= ?";
        $stmt_stock = $koneksi->prepare($sql_update_stock);
        if (!$stmt_stock) throw new Exception("Prepare stock gagal: " . $koneksi->error);
        
        foreach ($items as $item) {
            $product_id = intval($item['id']);
            $qty = intval($item['qty']);
            $harga = floatval($item['price']);
            $subtotal = $qty * $harga;
            
            // Update Stok
            $stmt_stock->bind_param("iii", $qty, $product_id, $qty);
            if (!$stmt_stock->execute()) {
                throw new Exception("Update stok gagal untuk product_id {$product_id}: " . $stmt_stock->error);
            }
            
            if ($koneksi->affected_rows === 0) {
                $stmt_check = $koneksi->prepare("SELECT stok, nama_barang FROM $DB_PRODUCTS WHERE product_id = ?");
                $stmt_check->bind_param("i", $product_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                $stock_data = $result_check->fetch_assoc() ?? ['nama_barang' => 'Unknown', 'stok' => 0];
                $stmt_check->close();
                throw new Exception("Stok barang " . htmlspecialchars($stock_data['nama_barang']) . " tidak mencukupi. Sisa stok: " . $stock_data['stok']);
            }
            
            // Insert Detail
            $stmt_detail->bind_param("iiidd", $sale_id_to_edit, $product_id, $qty, $harga, $subtotal);
            if (!$stmt_detail->execute()) {
                throw new Exception("Insert detail gagal untuk product_id {$product_id}: " . $stmt_detail->error);
            }
        }
        
        $stmt_detail->close();
        $stmt_stock->close();
        
        // COMMIT jika semua berhasil
        $koneksi->commit();
        $transaction_no = 'TRJ' . str_pad($sale_id_to_edit, 6, '0', STR_PAD_LEFT);
        $_SESSION['trans_message'] = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>Transaksi Berhasil Diupdate!</strong><br>No. Transaksi: <strong>'.$transaction_no.'</strong><br>Total: <strong>'.formatRupiah($total_bayar).'</strong><br>Metode: <strong>'.$metode_bayar.'</strong></div>';
        
    } catch (Exception $e) {
        if ($koneksi->in_transaction) {
            $koneksi->rollback();
        }
        $_SESSION['trans_message'] = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>Update GAGAL!</strong><br>'. nl2br(htmlspecialchars($e->getMessage())) .'</div>';
    } catch (Error $e) {
        if ($koneksi->in_transaction) {
            $koneksi->rollback();
        }
        $_SESSION['trans_message'] = '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><strong>Error Sistem!</strong><br>'. htmlspecialchars($e->getMessage()) .'</div>';
    }
    
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
<title>Penjualan (POS) | Minimarket App</title>
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
#productResults, #customerResults {
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
.product-item, .customer-item {
    cursor: pointer;
    padding:10px;
    border-bottom:1px solid #eee;
}
.product-item:hover, .customer-item:hover {
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
            <a class="nav-link active-page" href="sales_transaction.php"><i class="fas fa-cash-register"></i> Penjualan (POS)</a>
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

<div id="main-content">
    <!-- Header Hijau -->
    <div class="header-green">
        <div class="header-subtitle"><i class="fas fa-file-invoice me-2"></i>FORM TRANSAKSI PENJUALAN</div>
        <div class="header-title"><?= $edit_mode ? 'Edit Transaksi Penjualan' : 'Transaksi Penjualan' ?></div>
    </div>

    <?= $message ?>
    
    <?php if($edit_mode): ?>
    <div class="form-container">
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Mode Edit:</strong> Anda sedang mengedit transaksi <?= htmlspecialchars($next_transaction_no) ?> yang dibuat oleh <?= htmlspecialchars($edit_transaction_data['nama_pegawai'] ?? 'Unknown') ?>.
            <?php if(!in_array($user_role, ['manager', 'supervisor'])): ?>
            <br><small>Hanya Manager dan Supervisor yang dapat mengedit transaksi.</small>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

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

    <!-- Form Input Pelanggan dan Barang -->
    <div class="form-container">
        <div class="row">
            <!-- Bagian Kiri: Pelanggan -->
            <div class="col-md-6">
                <div class="form-group">
                    <label>ID Pelanggan</label>
                    <div class="search-wrapper">
                        <div class="input-group">
                            <input type="text" class="form-control" id="customerIdInput" placeholder="Masukkan ID Pelanggan" autocomplete="off">
                            <button type="button" class="btn btn-search" onclick="openCustomerSearchModal()">
                                <i class="fas fa-search"></i> Cari
                            </button>
                        </div>
                        <div id="customerResults"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Nama Pelanggan</label>
                    <input type="text" class="form-control" id="customerNameInput" placeholder="Nama Pelanggan" readonly>
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
                            <label>Harga Beli</label>
                            <input type="text" class="form-control text-end" id="productHargaBeliInput" readonly>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Harga Jual</label>
                            <?php if(in_array($user_role, ['manager', 'supervisor'])): ?>
                            <input type="number" class="form-control text-end" id="productHargaJualInput" min="1" step="100">
                            <?php else: ?>
                            <input type="text" class="form-control text-end" id="productHargaJualInput" readonly>
                            <?php endif; ?>
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
                    <th>ID Pelanggan</th>
                    <th>Nama Pelanggan</th>
                    <th>Kode Barang</th>
                    <th>Nama Barang</th>
                    <th>Harga Beli</th>
                    <th>Harga Jual</th>
                    <th>QTY</th>
                    <th>Sub Total</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="cartBody">
                <tr>
                    <td colspan="9" class="text-center text-muted" style="padding:50px;">Keranjang kosong. Tambahkan barang.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Bagian Pembayaran -->
    <div class="row">
        <div class="col-md-8"></div>
        <div class="col-md-4">
            <div class="payment-section">
                <form id="checkoutForm" method="POST" action="sales_transaction.php">
                    <input type="hidden" name="action" value="<?= $edit_mode ? 'update' : 'checkout' ?>">
                    <?php if($edit_mode): ?>
                    <input type="hidden" name="sale_id" value="<?= $edit_sale_id ?>">
                    <?php endif; ?>
                    <input type="hidden" name="final_total" id="finalTotalInput" value="0">
                    <input type="hidden" name="discount" id="discountInput" value="<?= $edit_mode && $edit_transaction_data ? $edit_transaction_data['diskon'] : '0' ?>">
                    <input type="hidden" name="pelanggan_id" id="pelangganIdInput" value="<?= $edit_mode && $edit_transaction_data ? ($edit_transaction_data['pelanggan_id'] ?? '') : '' ?>">
                    <input type="hidden" name="items_json" id="itemsJsonInput" value="[]">
                    <input type="hidden" name="metode_bayar" id="metodeBayar" value="<?= $edit_mode && $edit_transaction_data ? htmlspecialchars($edit_transaction_data['metode_bayar']) : 'Cash' ?>">
                    <input type="hidden" name="paid_amount" id="paidAmountInput" value="0">
                    <input type="hidden" name="change_amount" id="changeAmountInput" value="0">

                    <div class="form-group">
                        <label>Sub Total</label>
                        <input type="text" class="form-control payment-input text-end" id="subTotalDisplay" value="<?= formatRupiah(0) ?>" readonly>
                    </div>
                    
                    <?php if(in_array($user_role, ['manager', 'supervisor'])): ?>
                    <div class="form-group">
                        <label>Diskon (Rp)</label>
                        <input type="number" class="form-control payment-input text-end" id="discountAmount" value="<?= $edit_mode && $edit_transaction_data ? $edit_transaction_data['diskon'] : '0' ?>" min="0" step="100">
                    </div>
                    <?php else: ?>
                    <input type="hidden" id="discountAmount" value="0">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Total</label>
                        <input type="text" class="form-control payment-input text-end" id="grandTotalDisplay" value="<?= formatRupiah(0) ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Bayar</label>
                        <input type="number" class="form-control payment-input text-end" id="paidAmount" value="<?= $edit_mode && $edit_transaction_data ? $edit_transaction_data['total_bayar'] : '0' ?>" min="0">
                    </div>

                    <div class="form-group">
                        <label>Sisa</label>
                        <input type="text" class="form-control payment-input text-end" id="changeDisplay" value="<?= formatRupiah(0) ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Metode Bayar</label>
                        <select class="form-control payment-input" id="metodeBayarSelect">
                            <option value="Cash" <?= ($edit_mode && $edit_transaction_data && $edit_transaction_data['metode_bayar'] == 'Cash') ? 'selected' : '' ?>>Cash</option>
                            <option value="Debit" <?= ($edit_mode && $edit_transaction_data && $edit_transaction_data['metode_bayar'] == 'Debit') ? 'selected' : '' ?>>Debit</option>
                            <option value="Credit" <?= ($edit_mode && $edit_transaction_data && $edit_transaction_data['metode_bayar'] == 'Credit') ? 'selected' : '' ?>>Credit</option>
                            <option value="QRIS" <?= ($edit_mode && $edit_transaction_data && $edit_transaction_data['metode_bayar'] == 'QRIS') ? 'selected' : '' ?>>QRIS</option>
                            <option value="Transfer" <?= ($edit_mode && $edit_transaction_data && $edit_transaction_data['metode_bayar'] == 'Transfer') ? 'selected' : '' ?>>Transfer</option>
                        </select>
                    </div>

                    <div class="text-end mt-3">
                        <?php if($edit_mode): ?>
                        <a href="report_sales.php" class="btn btn-cancel">
                            <i class="fas fa-times"></i> Batal
                        </a>
                        <?php else: ?>
                        <button type="button" class="btn btn-cancel" onclick="clearCart()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-save" id="btnCheckout" disabled>
                            <i class="fas fa-save"></i> <?= $edit_mode ? 'Update Transaksi' : 'Simpan' ?>
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

<!-- Modal Pencarian Pelanggan -->
<div class="modal fade" id="customerSearchModal" tabindex="-1" aria-labelledby="customerSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="customerSearchModalLabel">
                    <i class="fas fa-users me-2"></i>Popup Pelanggan
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="modal-search-bar">
                    <input type="text" class="form-control" id="customerSearchInput" placeholder="Masukkan ID atau nama pelanggan..." autocomplete="off">
                    <button type="button" class="btn-search-modal" onclick="searchCustomerInModal($('#customerSearchInput').val())">
                        <i class="fas fa-search me-1"></i> Cari
                    </button>
                    <button type="button" class="btn-new-item" onclick="window.open('data_pelanggan.php', '_blank')">
                        <i class="fas fa-plus me-1"></i> Pelanggan Baru
                    </button>
                </div>
                <div class="modal-table-container">
                    <table class="modal-table">
                        <thead>
                            <tr>
                                <th>ID Pelanggan</th>
                                <th>Nama Pelanggan</th>
                                <th>No. Telepon</th>
                            </tr>
                        </thead>
                        <tbody id="customerSearchResults">
                            <tr>
                                <td colspan="3" class="text-center text-muted" style="padding: 50px;">
                                    Masukkan ID atau nama pelanggan untuk mencari...
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
        const discount = parseFloat($('#discountAmount').val()) || 0;
        let finalTotal = Math.max(0, subtotal - discount);

        $('#subTotalDisplay').val(formatRupiah(subtotal));
        $('#grandTotalDisplay').val(formatRupiah(finalTotal));
        $('#finalTotalInput').val(finalTotal);
        $('#discountInput').val(discount);

        return finalTotal;
    }
    
    function updateChange() {
        const finalTotal = calculateTotal(); // calculateTotal() sudah menghitung dan update semua field UI
        const paid = parseFloat($('#paidAmount').val()) || 0;
        const change = paid - finalTotal;
        
        // Hanya update field yang spesifik untuk change (karena calculateTotal() sudah handle yang lain)
        $('#changeDisplay').val(formatRupiah(change > 0 ? change : 0));
        
        // Update hidden input untuk paid_amount dan change_amount
        $('#paidAmountInput').val(paid);
        $('#changeAmountInput').val(change > 0 ? change : 0);
        
        // Aktifkan tombol checkout jika: ada item, uang diterima cukup, dan kembalian >= 0
        const isReady = cart.length > 0 && paid >= finalTotal && finalTotal > 0;
        $('#btnCheckout').prop('disabled', !isReady);
    }

    // --- FUNGSI KERANJANG ---
    function renderCart() {
        const cartBody = $('#cartBody');
        cartBody.empty();

        // Ambil data pelanggan
        const customerId = $('#pelangganIdInput').val() || '';
        const customerName = $('#customerNameInput').val() || 'Umum';

        if (cart.length === 0) {
            cartBody.append('<tr><td colspan="9" class="text-center text-muted" style="padding:50px;">Keranjang kosong. Tambahkan barang.</td></tr>');
            calculateTotal();
            updateChange();
            return;
        }

        cart.forEach((item, index) => {
            const subtotal = item.qty * item.price;
            const row = `
                <tr class="item-row">
                    <td>${customerId || '-'}</td>
                    <td>${customerName}</td>
                    <td>${item.code}</td>
                    <td>${item.name}</td>
                    <td class="text-end">${formatRupiah(item.price_beli || 0)}</td>
                    <td class="text-end">
                        <?php if(in_array($user_role, ['manager', 'supervisor'])): ?>
                        <input type="number" data-id="${item.id}" value="${item.price}" min="1" step="100" class="form-control form-control-sm text-end input-price" style="width: 120px; display: inline-block;">
                        <?php else: ?>
                        ${formatRupiah(item.price)}
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <input type="number" data-id="${item.id}" value="${item.qty}" min="1" max="${item.stock}" class="form-control form-control-sm text-center input-qty" style="width: 70px; display: inline-block;">
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

    function addItem(product, qty = 1) {
        // Validasi input
        if (!product || !product.id && !product.product_id) {
            alert('Data produk tidak valid.');
            return;
        }
        
        const productId = parseInt(product.id || product.product_id);
        const productStock = parseInt(product.stok || product.stock || 0);
        const productName = product.nama_barang || product.name || 'Unknown';
        
        if (isNaN(productId) || productId <= 0) {
            alert('ID produk tidak valid.');
            return;
        }
        
        if (productStock <= 0) {
            alert(`Stok barang "${productName}" kosong.`);
            return;
        }
        
        qty = parseInt(qty) || 1;
        if (qty <= 0) {
            alert('Quantity harus lebih dari 0.');
            return;
        }
        
        const existingItemIndex = cart.findIndex(item => item.id === productId);

        if (existingItemIndex !== -1) {
            const existingItem = cart[existingItemIndex];
            const newQty = existingItem.qty + qty;
            
            if (newQty <= productStock) {
                existingItem.qty = newQty;
                // Update stock info jika berubah
                existingItem.stock = productStock;
            } else {
                alert(`Penambahan ${qty} unit melebihi stok maksimal ${productStock}. Qty saat ini: ${existingItem.qty}`);
                return;
            }
        } else {
            // Validasi qty tidak melebihi stok
            if (qty > productStock) {
                alert(`Quantity (${qty}) melebihi stok tersedia (${productStock}).`);
                qty = productStock;
            }
            
            // Tambahkan item baru
            cart.push({
                id: productId,
                code: product.kode_barang || product.code || '',
                name: productName,
                price: parseFloat(product.harga_jual || product.price || 0),
                price_beli: parseFloat(product.harga_beli || 0),
                stock: productStock,
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
        const hargaJualInput = $('#productHargaJualInput');
        let hargaJual = 0;
        
        // Handle input number (manager/supervisor) atau text (kasir)
        if (hargaJualInput.attr('type') === 'number') {
            hargaJual = parseFloat(hargaJualInput.val()) || 0;
        } else {
            hargaJual = parseFloat(hargaJualInput.val().replace(/[^\d]/g, '')) || 0;
        }
        
        const hargaBeli = parseFloat($('#productHargaBeliInput').val().replace(/[^\d]/g, '')) || 0;
        const stock = parseInt($('#productStockInput').val()) || 0;
        const productId = $('#productCodeInput').data('product-id');

        if (!productId) {
            alert('Data produk tidak valid. Silakan cari ulang.');
            return;
        }

        if (qty > stock) {
            alert(`Quantity (${qty}) melebihi stok tersedia (${stock}).`);
            $('#productQtyInput').val(stock);
            return;
        }

        // Tambahkan ke cart
        addItem({
            id: productId,
            product_id: productId,
            code: productCode,
            name: productName,
            price: hargaJual,
            price_beli: hargaBeli,
            stok: stock,
            stock: stock
        }, qty);

        // Reset form
        $('#productCodeInput').val('').data('product-id', '');
        $('#productNameInput').val('');
        $('#productStockInput').val('');
        $('#productHargaBeliInput').val('');
        $('#productHargaJualInput').val('');
        $('#productQtyInput').val(1);
        $('#productSubTotalInput').val(formatRupiah(0));
        $('#productCodeInput').focus();
    }

    // Fungsi untuk membuka modal pencarian pelanggan
    function openCustomerSearchModal() {
        const customerId = $('#customerIdInput').val().trim();
        const modal = new bootstrap.Modal(document.getElementById('customerSearchModal'));
        $('#customerSearchInput').val(customerId);
        modal.show();
        
        const tbody = $('#customerSearchResults');
        
        // Tampilkan opsi "Umum" jika tidak ada ID
        if (!customerId) {
            const html = `
                <tr class="customer-select-item" 
                    data-id="" 
                    data-name="Umum"
                    onclick="selectCustomer('', 'Umum')">
                    <td>-</td>
                    <td><strong>Umum (Non-Member)</strong></td>
                    <td>-</td>
                </tr>
            `;
            tbody.html(html);
        } else {
            // Jika ada ID, langsung cari
            searchCustomerInModal(customerId);
        }
    }
    
    // Fungsi untuk mencari pelanggan di modal
    function searchCustomerInModal(query) {
        if (!query || query.trim() === '') {
            const tbody = $('#customerSearchResults');
            tbody.html('<tr><td colspan="3" class="text-center text-muted" style="padding: 50px;">Masukkan ID atau nama pelanggan untuk mencari...</td></tr>');
            return;
        }
        
        const tbody = $('#customerSearchResults');
        tbody.html('<tr><td colspan="3" class="text-center" style="padding: 50px;"><i class="fas fa-spinner fa-spin"></i> Mencari...</td></tr>');
        
        // Cek apakah query adalah angka (ID) atau teks (nama)
        const isNumeric = /^\d+$/.test(query);
        const action = isNumeric ? 'search_customer_by_id' : 'search_customer_live';
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
                            <tr class="customer-select-item" 
                                data-id="${response.id}" 
                                data-name="${response.name}"
                                onclick="selectCustomer(${response.id}, '${response.name.replace(/'/g, "\\'")}')">
                                <td>${response.id}</td>
                                <td>${response.name}</td>
                                <td>${response.phone || '-'}</td>
                            </tr>
                        `;
                        tbody.html(row);
                    } else {
                        tbody.html('<tr><td colspan="3" class="text-center text-danger" style="padding: 50px;">Pelanggan tidak ditemukan.</td></tr>');
                    }
                } else {
                    // Multiple results untuk nama
                    if (response.length > 0) {
                        response.forEach(c => {
                            const row = `
                                <tr class="customer-select-item" 
                                    data-id="${c.id}" 
                                    data-name="${c.name}"
                                    onclick="selectCustomer(${c.id}, '${c.name.replace(/'/g, "\\'")}')">
                                    <td>${c.id}</td>
                                    <td>${c.name}</td>
                                    <td>${c.phone || '-'}</td>
                                </tr>
                            `;
                            tbody.append(row);
                        });
                    } else {
                        tbody.html('<tr><td colspan="3" class="text-center text-danger" style="padding: 50px;">Pelanggan tidak ditemukan.</td></tr>');
                    }
                }
            },
            error: function() {
                tbody.html('<tr><td colspan="3" class="text-center text-danger" style="padding: 50px;">Error saat mencari pelanggan.</td></tr>');
            }
        });
    }
    
    // Fungsi untuk memilih pelanggan dari modal
    function selectCustomer(id, name) {
        // Jika id kosong atau 'Umum', set ke empty string
        const finalId = (id === '' || id === 'Umum' || !id) ? '' : id;
        $('#pelangganIdInput').val(finalId);
        $('#customerNameInput').val(name);
        $('#customerIdInput').val(finalId);
        bootstrap.Modal.getInstance(document.getElementById('customerSearchModal')).hide();
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
            const tbody = $('#productSearchResults');
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
                            onclick="selectProductFromTable(${p.product_id}, '${p.kode_barang.replace(/'/g, "\\'")}', '${p.nama_barang.replace(/'/g, "\\'")}', ${p.harga_jual}, ${p.harga_beli || 0}, ${p.stok})">
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
            $('#productSearchResults').html('<tr><td colspan="5" class="text-center text-danger" style="padding: 50px;">Error saat mencari produk.</p></td></tr>');
        });
    }
    
    
    // Fungsi untuk memilih produk dari modal
    function selectProduct(id, code, name, hargaJual, hargaBeli, stok) {
        selectProductFromTable(id, code, name, hargaJual, hargaBeli, stok);
    }
    
    // Fungsi untuk memilih produk dari tabel
    function selectProductFromTable(id, code, name, hargaJual, hargaBeli, stok) {
        // Set data produk ke form
        $('#productCodeInput').data('product-id', id);
        $('#productCodeInput').val(code);
        $('#productNameInput').val(name);
        $('#productStockInput').val(stok);
        $('#productHargaBeliInput').val(formatRupiah(hargaBeli || 0));
        <?php if(in_array($user_role, ['manager', 'supervisor'])): ?>
        $('#productHargaJualInput').val(hargaJual);
        <?php else: ?>
        $('#productHargaJualInput').val(formatRupiah(hargaJual));
        <?php endif; ?>
        $('#productQtyInput').val(1).attr('max', stok);
        updateSubTotal();
        
        // Tutup modal
        bootstrap.Modal.getInstance(document.getElementById('productSearchModal')).hide();
        $('#productQtyInput').focus();
    }

    // Fungsi untuk update subtotal
    function updateSubTotal() {
        const qty = parseInt($('#productQtyInput').val()) || 1;
        const hargaJualInput = $('#productHargaJualInput');
        let hargaJual = 0;
        
        // Handle input number (manager/supervisor) atau text (kasir)
        if (hargaJualInput.attr('type') === 'number') {
            hargaJual = parseFloat(hargaJualInput.val()) || 0;
        } else {
            hargaJual = parseFloat(hargaJualInput.val().replace(/[^\d]/g, '')) || 0;
        }
        
        const subtotal = qty * hargaJual;
        $('#productSubTotalInput').val(formatRupiah(subtotal));
    }

    // Fungsi untuk clear form produk
    function clearProductForm() {
        $('#productCodeInput').val('').data('product-id', '');
        $('#productNameInput').val('');
        $('#productStockInput').val('');
        $('#productHargaBeliInput').val('');
        $('#productHargaJualInput').val('');
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
            $('#customerIdInput').val('');
            $('#customerNameInput').val('');
            $('#pelangganIdInput').val('');
            clearProductForm();
            renderCart();
            $('#productCodeInput').focus();
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
        
        if (newQty > item.stock) {
            alert(`Stok maksimal ${item.stock} untuk ${item.name}.`);
            newQty = item.stock;
            $(this).val(newQty);
        }

        item.qty = newQty;
        renderCart();
    });
    
    // Validasi input qty saat mengetik
    $(document).on('keyup', '.input-qty', function() {
        const val = $(this).val();
        if (val === '' || isNaN(parseInt(val)) || parseInt(val) < 1) {
            // Biarkan user mengetik, validasi saat blur/change
        }
    });
    
    // Event handler untuk edit harga (hanya manager/supervisor)
    $(document).on('change blur', '.input-price', function() {
        const id = parseInt($(this).data('id'));
        let newPrice = parseFloat($(this).val());
        const item = cart.find(i => i.id === id);
        
        if (!item) {
            renderCart();
            return;
        }
        
        if (isNaN(newPrice) || newPrice < 1) {
            newPrice = item.price;
            $(this).val(newPrice);
        }
        
        item.price = newPrice;
        renderCart();
    });

    // 2. Update subtotal saat qty berubah di form produk
    $('#productQtyInput').on('keyup change', function() {
        updateSubTotal();
    });

    // 3. Kalkulasi Ulang saat Bayar berubah
    $('#paidAmount').on('keyup change', function() {
        // Validasi input negatif
        if (parseFloat($(this).val()) < 0 || isNaN(parseFloat($(this).val()))) {
             $(this).val(0);
        }
        updateChange();
    });

    // 4. Enter key untuk search produk
    $('#productCodeInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            openProductSearchModal();
        }
    });

    // 5. Enter key untuk search pelanggan
    $('#customerIdInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            openCustomerSearchModal();
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
    
    // Event handler untuk pencarian di modal pelanggan
    $('#customerSearchInput').on('keyup', function(e) {
        if (e.which === 13) {
            // Enter key
            searchCustomerInModal($(this).val().trim());
        } else {
            const query = $(this).val().trim();
            if (query.length >= 1) {
                clearTimeout(window.customerSearchTimeout);
                window.customerSearchTimeout = setTimeout(function() {
                    searchCustomerInModal(query);
                }, 500);
            } else if (query.length === 0) {
                $('#customerSearchResults').html('<tr><td colspan="3" class="text-center text-muted" style="padding: 50px;">Masukkan ID atau nama pelanggan untuk mencari...</td></tr>');
            }
        }
    });

    // 6. Pencarian Produk (LIVE SEARCH by Name) - untuk referensi, tidak digunakan di format baru
    // Tetap disimpan untuk kompatibilitas
    
    // 7. Barcode/Kode Barang Input - Enter key sudah dihandle di atas

    // 5. Klik pada hasil Pencarian Produk
    $(document).on('click', '.product-item', function(e) {
        e.preventDefault();
        const productData = JSON.parse($(this).data('product'));
        addItem(productData);
        $('#productResults').empty(); 
        $('#searchProduct').val('');
        $('#searchProduct').focus();
    });
    
    // 6. Pencarian Pelanggan (LIVE SEARCH)
    $('#searchCustomer').on('keyup', function() {
        const query = $(this).val();
        const resultsDiv = $('#customerResults');
        resultsDiv.empty();
        
        // Jika input kosong, tampilkan opsi Umum
        if (query.length === 0) {
            resultsDiv.append('<a href="#" class="list-group-item list-group-item-action customer-item" data-id="" data-name="Umum"><b>Umum (Non-Member)</b></a>');
            return;
        }

        if (query.length < 3) return;

        $.ajax({
            url: 'ajax_handler.php', // Pastikan nama file ini benar
            method: 'GET',
            data: { action: 'search_customer_live', q: query },
            dataType: 'json',
            success: function(customers) {
                if (customers.length > 0) {
                    customers.forEach(c => {
                        resultsDiv.append(`
                            <a href="#" class="list-group-item list-group-item-action customer-item" 
                               data-id="${c.id}" data-name="${c.name}">
                                <b>${c.name}</b> (ID: ${c.id}) ${c.phone ? ' - Telp: ' + c.phone : ''}
                            </a>
                        `);
                    });
                } else {
                    resultsDiv.append('<div class="list-group-item">Pelanggan tidak ditemukan.</div>');
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = 'Gagal memuat data pelanggan.';
                if (xhr.status === 0) {
                    errorMsg = 'Tidak dapat terhubung ke server.';
                } else if (xhr.status === 404) {
                    errorMsg = 'File ajax_handler.php tidak ditemukan.';
                } else if (xhr.status === 500) {
                    errorMsg = 'Error server.';
                }
                resultsDiv.append(`<div class="list-group-item text-danger"><i class="fas fa-exclamation-triangle me-2"></i>${errorMsg}</div>`);
                console.error("AJAX Error (Customer Live Search):", status, error, xhr.responseText);
            }
        });
    });

    // 7. Klik pada hasil Pencarian Pelanggan
    $(document).on('click', '.customer-item', function(e) {
        e.preventDefault();
        const id = $(this).data('id');
        const name = $(this).data('name');
        
        $('#searchCustomer').val(name);
        $('#pelangganIdInput').val(id); // Value "" atau ID Pelanggan
        
        $('#customerResults').empty(); 
        $('#productCodeInput').focus();
    });
    
    // 8. Sembunyikan hasil search saat klik di luar
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#searchProduct, #productResults').length) {
            $('#productResults').empty();
        }
        if (!$(e.target).closest('#searchCustomer, #customerResults').length) {
            $('#customerResults').empty();
        }
    });
    
    // 9. Submit Form dengan validasi lengkap
    $('#checkoutForm').on('submit', function(e) {
        e.preventDefault();
        
        // Validasi cart
        if (cart.length === 0) {
            alert('Keranjang belanja kosong. Tidak bisa checkout.');
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
            if (item.qty > item.stock) {
                cartErrors.push(`Item #${index + 1} (${item.name}): Quantity (${item.qty}) melebihi stok (${item.stock})`);
            }
            if (item.price <= 0) {
                cartErrors.push(`Item #${index + 1} (${item.name}): Harga tidak valid`);
            }
        });
        
        if (cartErrors.length > 0) {
            alert('Validasi cart gagal:\n' + cartErrors.join('\n'));
            renderCart(); // Refresh cart
            return false;
        }
        
        const finalTotal = parseFloat($('#finalTotalInput').val()) || 0;
        const paidAmount = parseFloat($('#paidAmount').val()) || 0;
        const discount = parseFloat($('#discountInput').val()) || 0;
        
        if (finalTotal <= 0) {
            alert('Total pembayaran harus lebih dari 0.');
            return false;
        }
        
        if (paidAmount < finalTotal) {
            alert(`Uang diterima (${formatRupiah(paidAmount)}) tidak cukup untuk total (${formatRupiah(finalTotal)})!`);
            $('#paidAmount').focus();
            return false;
        }
        
        // Konfirmasi sebelum menyimpan
        const change = paidAmount - finalTotal;
        const confirmMsg = `Konfirmasi Transaksi:\n\n` +
                          `Total: ${formatRupiah(finalTotal)}\n` +
                          `Diskon: ${formatRupiah(discount)}\n` +
                          `Bayar: ${formatRupiah(paidAmount)}\n` +
                          `Kembalian: ${formatRupiah(change)}\n\n` +
                          `Lanjutkan transaksi?`;
        
        if (!confirm(confirmMsg)) {
            return false;
        }
        
        // Set hidden input untuk paid_amount dan change_amount
        $('#paidAmountInput').val(paidAmount);
        $('#changeAmountInput').val(change);
        
        // Disable tombol checkout untuk mencegah double submit
        $('#btnCheckout').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Memproses...');
        
        // Submit form
        this.submit();
    });


    // Update metode bayar
    $('#metodeBayarSelect').on('change', function() {
        $('#metodeBayar').val($(this).val());
    });
    
    // Update diskon dan recalculate
    $('#discountAmount').on('input', function() {
        calculateTotal();
        updateChange();
    });
    

    // Inisialisasi
    $(document).ready(function() {
        <?php if($edit_mode && !empty($edit_items_data)): ?>
        // Load data edit ke cart
        cart = <?= json_encode($edit_items_data) ?>;
        renderCart();
        
        // Load data pelanggan
        <?php if($edit_transaction_data && $edit_transaction_data['pelanggan_id']): ?>
        $('#pelangganIdInput').val('<?= $edit_transaction_data['pelanggan_id'] ?>');
        $('#customerNameInput').val('<?= htmlspecialchars($edit_transaction_data['nama_pelanggan'] ?? 'Umum') ?>');
        <?php else: ?>
        $('#customerNameInput').val('Umum');
        $('#pelangganIdInput').val('');
        <?php endif; ?>
        
        // Set metode bayar
        $('#metodeBayar').val('<?= htmlspecialchars($edit_transaction_data['metode_bayar'] ?? 'Cash') ?>');
        $('#metodeBayarSelect').val('<?= htmlspecialchars($edit_transaction_data['metode_bayar'] ?? 'Cash') ?>');
        <?php else: ?>
        // Set default pelanggan ke "Umum" saat load
        $('#customerNameInput').val('Umum');
        $('#pelangganIdInput').val('');
        renderCart();
        $('#paidAmount').val(0);
        $('#productCodeInput').focus();
        <?php endif; ?>
        
        // Update total saat load
        updateChange();
    });
</script>
</body>
</html>
<?php 
if($is_connected && $koneksi instanceof mysqli) $koneksi->close(); 
?>