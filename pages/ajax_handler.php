<?php
include "../config/koneksi.php"; 

header('Content-Type: application/json');

if (!$koneksi || $koneksi->connect_error) {
    echo json_encode(['error' => 'Koneksi database gagal.', 'details' => $koneksi->connect_error ?? 'Koneksi object not set.']);
    exit;
}

$DB_PRODUCTS = "products";
$DB_PELANGGAN = "pelanggan";
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'search_product_live':
       
        $query_raw = $_GET['q'] ?? '';
        $query_param = "%" . $query_raw . "%";
        $products = [];
        
        $sql = "SELECT product_id, kode_barang, nama_barang, harga_beli, harga_jual, stok 
                FROM $DB_PRODUCTS 
                WHERE nama_barang LIKE ? 
                ORDER BY nama_barang ASC 
                LIMIT 10";
        
        $stmt = $koneksi->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("s", $query_param);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $products[] = [
                    'product_id' => (int)$row['product_id'],
                    'kode_barang' => $row['kode_barang'],
                    'nama_barang' => $row['nama_barang'],
                    'harga_beli' => (float)$row['harga_beli'],
                    'harga_jual' => (float)$row['harga_jual'],
                    'stok' => (int)$row['stok']
                ];
            }
            $stmt->close();
        }
        echo json_encode($products);
        break;

    case 'search_product_code':
        
        $code_raw = $_GET['code'] ?? '';
        $response = ['id' => 0, 'error' => 'Barang tidak ditemukan.'];
        
        $sql = "SELECT product_id AS id, kode_barang, nama_barang, harga_beli, harga_jual, stok 
                FROM $DB_PRODUCTS 
                WHERE kode_barang = ? LIMIT 1";

        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $code_raw);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 1) {
                $product = $result->fetch_assoc();
                $formatted_product = [
                    'id' => (int)$product['id'],
                    'kode_barang' => $product['kode_barang'],
                    'nama_barang' => $product['nama_barang'],
                    'harga_beli' => (float)$product['harga_beli'],
                    'harga_jual' => (float)$product['harga_jual'],
                    'stok' => (int)$product['stok']
                ];
                
             
                if ($formatted_product['stok'] > 0) {
                    $response = $formatted_product;
                } else {
                    $response['error'] = 'Stok barang kosong.';
                }
            } else {
                 $response['error'] = 'Kode barang tidak ditemukan.';
            }
            $stmt->close();
        }
        echo json_encode($response);
        break;

    case 'search_customer_live': 
       
        $query_raw = $_GET['q'] ?? '';
        $query_param = "%" . $query_raw . "%";
        $customers = [];
        
        $sql = "SELECT pelanggan_id, nama_pelanggan, no_hp as no_telepon FROM $DB_PELANGGAN 
                WHERE nama_pelanggan LIKE ? OR pelanggan_id LIKE ? 
                ORDER BY nama_pelanggan ASC 
                LIMIT 10";
        
        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ss", $query_param, $query_param);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $customers[] = [
                    'id' => (int)$row['pelanggan_id'],
                    'name' => $row['nama_pelanggan'],
                    'phone' => $row['no_telepon'] ?? ''
                ];
            }
            $stmt->close();
        }
        echo json_encode($customers);
        break;
    
    case 'search_customer_by_id':
       
        $customer_id = intval($_GET['id'] ?? 0);
        $response = ['id' => 0, 'error' => 'Pelanggan tidak ditemukan.'];
        
        if ($customer_id <= 0) {
            echo json_encode($response);
            break;
        }
        
        $sql = "SELECT pelanggan_id, nama_pelanggan, no_hp FROM $DB_PELANGGAN WHERE pelanggan_id = ? LIMIT 1";
        $stmt = $koneksi->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $customer = $result->fetch_assoc();
                $response = [
                    'id' => (int)$customer['pelanggan_id'],
                    'name' => $customer['nama_pelanggan'],
                    'phone' => $customer['no_hp'] ?? ''
                ];
            }
            $stmt->close();
        }
        
        echo json_encode($response);
        break;
    
    case 'check_stock':
       
        $product_id = intval($_GET['product_id'] ?? 0);
        $response = ['available' => false, 'stock' => 0, 'error' => ''];
        
        if ($product_id <= 0) {
            $response['error'] = 'Product ID tidak valid.';
            echo json_encode($response);
            break;
        }
        
        $sql = "SELECT stok, nama_barang FROM $DB_PRODUCTS WHERE product_id = ? LIMIT 1";
        $stmt = $koneksi->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $product = $result->fetch_assoc();
                $response['available'] = true;
                $response['stock'] = (int)$product['stok'];
                $response['product_name'] = $product['nama_barang'];
            } else {
                $response['error'] = 'Produk tidak ditemukan.';
            }
            $stmt->close();
        } else {
            $response['error'] = 'Error query: ' . $koneksi->error;
        }
        
        echo json_encode($response);
        break;
    
    case 'search_supplier_by_id':
     
        $supplier_id = intval($_GET['id'] ?? 0);
        $response = ['id' => 0, 'error' => 'Supplier tidak ditemukan.'];
        
        if ($supplier_id <= 0) {
            echo json_encode($response);
            break;
        }
        
        $sql = "SELECT supplier_id, nama_supplier, no_telepon, nama_kontak FROM supplier WHERE supplier_id = ? LIMIT 1";
        $stmt = $koneksi->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("i", $supplier_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $supplier = $result->fetch_assoc();
                $response = [
                    'id' => (int)$supplier['supplier_id'],
                    'name' => $supplier['nama_supplier'],
                    'phone' => $supplier['no_telepon'] ?? '',
                    'contact' => $supplier['nama_kontak'] ?? ''
                ];
            }
            $stmt->close();
        }
        
        echo json_encode($response);
        break;
    
    case 'search_supplier_live':
      
        $query_raw = $_GET['q'] ?? '';
        $query_param = "%" . $query_raw . "%";
        $suppliers = [];
        
        $sql = "SELECT supplier_id, nama_supplier, no_telepon, nama_kontak FROM supplier 
                WHERE nama_supplier LIKE ? OR supplier_id LIKE ? 
                ORDER BY nama_supplier ASC 
                LIMIT 10";
        
        $stmt = $koneksi->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ss", $query_param, $query_param);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $suppliers[] = [
                    'id' => (int)$row['supplier_id'],
                    'name' => $row['nama_supplier'],
                    'phone' => $row['no_telepon'] ?? '',
                    'contact' => $row['nama_kontak'] ?? ''
                ];
            }
            $stmt->close();
        }
        echo json_encode($suppliers);
        break;

    default:
        echo json_encode(['error' => 'Aksi tidak valid.']);
}

if ($koneksi) $koneksi->close();
?>