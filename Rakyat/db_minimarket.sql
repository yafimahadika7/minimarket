-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 19 Des 2025 pada 17.04
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_minimarket`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `pegawai`
--

CREATE TABLE `pegawai` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `role` enum('kasir','supervisor','manager') NOT NULL DEFAULT 'kasir',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `pegawai`
--

INSERT INTO `pegawai` (`user_id`, `full_name`, `username`, `password`, `phone_number`, `address`, `role`, `status`, `created_at`) VALUES
(3, 'Indra Gunawan', 'Manager1', '$2y$10$4F.t/cWzFb6gr.HHaMaUheXA0ryjTVrUAWTptHAv4Oaq/qJnClITy', '123456', 'Tangerang', 'manager', 'active', '2025-12-12 07:08:30'),
(12, 'Sarah', 'Kasir_sarah', '$2y$10$iinWyIVQFwe.5q.8BXuOj.w5ZOAbce3T/bUWTqWMm55vl6vLHSKnu', '089667404293', 'Jl raya Lengkong Tangerang', 'kasir', 'active', '2025-12-17 15:12:43'),
(17, 'Rinto Adiputra', 'super_Rinto', '$2y$10$U6yetTYz2olNIIOran7Sh.Ksuf33MFPQB.0FzGNknnxYmliXzh/Dy', '089611497947', 'Kp. Sidamukti Jl. Jati Raya Rt. 003/008', 'supervisor', 'active', '2025-12-17 15:18:44');

--
-- Trigger `pegawai`
--
DELIMITER $$
CREATE TRIGGER `sync_pegawai_to_users_insert` AFTER INSERT ON `pegawai` FOR EACH ROW BEGIN
    INSERT INTO users (user_id, username, password, full_name, role, status)
    VALUES (NEW.user_id, NEW.username, NEW.password, NEW.full_name, NEW.role, NEW.status)
    ON DUPLICATE KEY UPDATE
        password = NEW.password,
        full_name = NEW.full_name,
        role = NEW.role,
        status = NEW.status;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktur dari tabel `pelanggan`
--

CREATE TABLE `pelanggan` (
  `pelanggan_id` int(11) NOT NULL,
  `nama_pelanggan` varchar(100) NOT NULL,
  `no_hp` varchar(15) DEFAULT NULL,
  `alamat` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `pelanggan`
--

INSERT INTO `pelanggan` (`pelanggan_id`, `nama_pelanggan`, `no_hp`, `alamat`) VALUES
(1, 'Rania', '-', 'depok'),
(2, 'Aulia Putri', '081234567801', 'Bandung'),
(3, 'Dian Anggraini', '081234567802', 'Jakarta'),
(4, 'Fajar Pratama', '081234567803', 'Bogor'),
(5, 'Nanda Kurniawan', '081234567804', 'Bekasi'),
(6, 'Siti Rahmawati', '081234567805', 'Depok'),
(7, 'Rizky Ramadhan', '081234567806', 'Tangerang'),
(8, 'Maya Sari', '081234567807', 'Cimahi'),
(9, 'Bima Saputra', '081234567808', 'Cirebon'),
(10, 'Laras Atiqah', '081234567809', 'Garut'),
(11, 'Hendra Wijaya', '081234567810', 'Sukabumi'),
(12, 'Novi Lestari', '081234567811', 'Serang'),
(13, 'Agung Setiawan', '081234567812', 'Karawang'),
(14, 'Putri Amelia', '081234567813', 'Purwakarta'),
(15, 'Farhan Hidayat', '081234567814', 'Subang'),
(16, 'Dewi Kartika', '081234567815', 'Sumedang'),
(17, 'Ahmad Fauzi', '081234567816', 'Cianjur'),
(18, 'Nadya Azzahra', '081234567817', 'Bandung Barat'),
(19, 'Reza Gunawan', '081234567818', 'Indramayu'),
(20, 'Indah Kurniasih', '081234567819', 'Kuningan'),
(21, 'Bagus Pradipta', '081234567820', 'Majalengka');

-- --------------------------------------------------------

--
-- Struktur dari tabel `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `kode_barang` varchar(50) NOT NULL COMMENT 'Kode unik barang/SKU',
  `nama_barang` varchar(150) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `harga_beli` decimal(10,2) NOT NULL COMMENT 'Harga modal per satuan',
  `harga_jual` decimal(10,2) NOT NULL COMMENT 'Harga jual per satuan',
  `stok` int(11) NOT NULL DEFAULT 0 COMMENT 'Stok fisik saat ini',
  `satuan` varchar(20) DEFAULT NULL COMMENT 'Satuan barang (Pcs, Box, Kg)',
  `kategori` varchar(50) DEFAULT NULL,
  `tanggal_ed` date DEFAULT NULL COMMENT 'Tanggal Kedaluwarsa',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `products`
--

INSERT INTO `products` (`product_id`, `kode_barang`, `nama_barang`, `supplier_id`, `harga_beli`, `harga_jual`, `stok`, `satuan`, `kategori`, `tanggal_ed`, `created_at`) VALUES
(61, 'BRG001', 'Beras Premium', 1, 12000.00, 15000.00, 50, 'Kg', 'Bahan Pokok', '2025-12-31', '2025-12-11 13:08:47'),
(62, 'BRG002', 'Gula Pasir', 2, 11000.00, 13500.00, 60, 'Kg', 'Bahan Pokok', '2025-11-30', '2025-12-11 13:08:47'),
(63, 'BRG003', 'Minyak Goreng', 3, 15000.00, 18000.00, 40, 'Ltr', 'Bahan Pokok', '2025-10-31', '2025-12-11 13:08:47'),
(64, 'BRG004', 'Telur Ayam', 4, 20000.00, 23000.00, 100, 'Kg', 'Bahan Pokok', '2025-12-20', '2025-12-11 13:08:47'),
(65, 'BRG005', 'Susu UHT', 5, 10000.00, 12500.00, 70, 'Pcs', 'Minuman', '2025-09-30', '2025-12-11 13:08:47'),
(66, 'BRG006', 'Roti Tawar', 6, 8000.00, 10000.00, 50, 'Pcs', 'Makanan', '2025-09-25', '2025-12-11 13:08:47'),
(67, 'BRG007', 'Teh Celup', 7, 5000.00, 7000.00, 200, 'Pcs', 'Minuman', '2026-01-31', '2025-12-11 13:08:47'),
(68, 'BRG008', 'Kopi Bubuk', 8, 25000.00, 30000.00, 30, 'Pcs', 'Minuman', '2025-12-31', '2025-12-11 13:08:47'),
(69, 'BRG009', 'Mi Instan', 9, 2000.00, 3000.00, 150, 'Pcs', 'Makanan', '2026-03-31', '2025-12-11 13:08:47'),
(70, 'BRG010', 'Biskuit', 10, 5000.00, 7000.00, 120, 'Pcs', 'Snack', '2025-12-31', '2025-12-11 13:08:47'),
(71, 'BRG011', 'Cokelat Batangan', 1, 12000.00, 15000.00, 40, 'Pcs', 'Snack', '2025-11-30', '2025-12-11 13:08:47'),
(72, 'BRG012', 'Air Mineral 600ml', 2, 3000.00, 5000.00, 200, 'Pcs', 'Minuman', '2026-02-28', '2025-12-11 13:08:47'),
(73, 'BRG013', 'Sosis Ayam', 3, 15000.00, 18000.00, 60, 'Pcs', 'Makanan', '2025-12-15', '2025-12-11 13:08:47'),
(74, 'BRG014', 'Keju Cheddar', 4, 25000.00, 30000.00, 5, 'Pcs', 'Makanan', '2025-12-31', '2025-12-11 13:08:47'),
(75, 'BRG015', 'Margarin', 5, 10000.00, 12000.00, 38, 'Pcs', 'Bahan Pokok', '2025-12-31', '2025-12-11 13:08:47'),
(76, 'BRG016', 'Tepung Terigu', 6, 12000.00, 14000.00, 80, 'Kg', 'Bahan Pokok', '2026-01-31', '2025-12-11 13:08:47'),
(77, 'BRG017', 'Kecap Manis', 7, 8000.00, 10000.00, 60, 'Pcs', 'Bumbu', '2025-12-31', '2025-12-11 13:08:47'),
(78, 'BRG018', 'Saus Tomat', 8, 9000.00, 11000.00, 50, 'Pcs', 'Bumbu', '2025-12-31', '2025-12-11 13:08:47'),
(79, 'BRG019', 'Garam', 9, 2000.00, 3500.00, 99, 'Kg', 'Bumbu', '2026-06-30', '2025-12-11 13:08:47'),
(80, 'BRG020', 'Mie Telur', 10, 4000.00, 6000.00, 90, 'Pcs', 'Makanan', '2026-03-31', '2025-12-11 13:08:47'),
(81, 'BRG021', 'Snack Kentang', 1, 7000.00, 9000.00, 120, 'Pcs', 'Snack', '2026-01-31', '2025-12-11 13:08:47'),
(82, 'BRG022', 'Kacang Goreng', 2, 6000.00, 8000.00, 100, 'Pcs', 'Snack', '2025-12-31', '2025-12-11 13:08:47'),
(83, 'BRG023', 'Minuman Isotonik', 3, 8000.00, 12000.00, 80, 'Pcs', 'Minuman', '2025-12-31', '2025-12-11 13:08:47'),
(84, 'BRG024', 'Sirup Rasa Stroberi', 4, 5000.00, 7500.00, 150, 'Pcs', 'Minuman', '2025-12-31', '2025-12-11 13:08:47'),
(85, 'BRG025', 'Selai Cokelat', 5, 15000.00, 18000.00, 60, 'Pcs', 'Makanan', '2025-12-31', '2025-12-11 13:08:47'),
(86, 'BRG026', 'Buah Kaleng', 6, 20000.00, 25000.00, 70, 'Pcs', 'Makanan', '2026-03-31', '2025-12-11 13:08:47'),
(87, 'BRG027', 'Susu Bubuk', 7, 30000.00, 35000.00, 30, 'Pcs', 'Minuman', '2026-01-31', '2025-12-11 13:08:47'),
(88, 'BRG028', 'Snack Jagung', 8, 7000.00, 9000.00, 80, 'Pcs', 'Snack', '2026-02-28', '2025-12-11 13:08:47'),
(89, 'BRG029', 'Kopi Sachet', 9, 5000.00, 7000.00, 200, 'Pcs', 'Minuman', '2026-03-31', '2025-12-11 13:08:47'),
(90, 'BRG030', 'Chips Kentang', 10, 8000.00, 10000.00, 100, 'Pcs', 'Snack', '0000-00-00', '2025-12-11 13:08:47');

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_detail`
--

CREATE TABLE `purchases_detail` (
  `detail_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL COMMENT 'Quantity yang dibeli',
  `harga_beli` decimal(10,2) NOT NULL COMMENT 'Harga beli per satuan saat pembelian',
  `subtotal` decimal(10,2) NOT NULL COMMENT 'Subtotal (qty * harga_beli)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `purchases_detail`
--

INSERT INTO `purchases_detail` (`detail_id`, `purchase_id`, `product_id`, `qty`, `harga_beli`, `subtotal`) VALUES
(1, 1, 86, 30, 20000.00, 600000.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_header`
--

CREATE TABLE `purchases_header` (
  `purchase_id` int(11) NOT NULL,
  `tanggal_transaksi` date NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Pegawai yang melakukan pembelian',
  `supplier_id` int(11) NOT NULL COMMENT 'Supplier yang menyuplai barang',
  `total_bayar` decimal(10,2) NOT NULL COMMENT 'Total pembayaran',
  `metode_bayar` varchar(50) NOT NULL DEFAULT 'Cash' COMMENT 'Metode pembayaran',
  `keterangan` text DEFAULT NULL COMMENT 'Keterangan tambahan',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `purchases_header`
--

INSERT INTO `purchases_header` (`purchase_id`, `tanggal_transaksi`, `user_id`, `supplier_id`, `total_bayar`, `metode_bayar`, `keterangan`, `created_at`) VALUES
(1, '2025-12-17', 1, 5, 600000.00, 'Cash', NULL, '2025-12-17 14:57:06');

-- --------------------------------------------------------

--
-- Struktur dari tabel `sales_detail`
--

CREATE TABLE `sales_detail` (
  `detail_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `harga_satuan` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `sales_detail`
--

INSERT INTO `sales_detail` (`detail_id`, `sale_id`, `product_id`, `qty`, `harga_satuan`, `subtotal`) VALUES
(16, 9, 74, 30, 30000.00, 900000.00),
(17, 10, 75, 12, 12000.00, 144000.00),
(18, 10, 79, 1, 3500.00, 3500.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `sales_header`
--

CREATE TABLE `sales_header` (
  `sale_id` int(11) NOT NULL,
  `tanggal_transaksi` date NOT NULL,
  `user_id` int(11) NOT NULL,
  `pelanggan_id` int(11) DEFAULT NULL,
  `total_bayar` decimal(10,2) NOT NULL,
  `diskon` decimal(10,2) NOT NULL DEFAULT 0.00,
  `metode_bayar` varchar(50) NOT NULL DEFAULT 'Cash'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `sales_header`
--

INSERT INTO `sales_header` (`sale_id`, `tanggal_transaksi`, `user_id`, `pelanggan_id`, `total_bayar`, `diskon`, `metode_bayar`) VALUES
(9, '2025-12-17', 1, 5, 900000.00, 0.00, 'Cash'),
(10, '2025-12-17', 12, 5, 147500.00, 0.00, 'Cash');

-- --------------------------------------------------------

--
-- Struktur dari tabel `supplier`
--

CREATE TABLE `supplier` (
  `supplier_id` int(11) NOT NULL,
  `nama_supplier` varchar(150) NOT NULL COMMENT 'Nama Perusahaan Supplier',
  `alamat` text DEFAULT NULL COMMENT 'Alamat Kantor Supplier',
  `no_telepon` varchar(15) DEFAULT NULL COMMENT 'Nomor Telepon/Kontak',
  `nama_kontak` varchar(100) DEFAULT NULL COMMENT 'Nama Kontak Person',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `supplier`
--

INSERT INTO `supplier` (`supplier_id`, `nama_supplier`, `alamat`, `no_telepon`, `nama_kontak`, `created_at`) VALUES
(1, 'PT. Mondar Mandir', 'Tangerang', '123456789', 'Haji Imron', '2025-12-11 12:22:38'),
(2, 'PT. Mandar Mandir', 'Tangerang', '08123456789', 'Haji Imron', '2025-12-11 13:05:51'),
(3, 'CV. Sinar Jaya', 'Jakarta', '08198765432', 'Budi Santoso', '2025-12-11 13:05:51'),
(4, 'PT. Mega Abadi', 'Bandung', '08211223344', 'Andi Wijaya', '2025-12-11 13:05:51'),
(5, 'CV. Surya Kencana', 'Depok', '08155667788', 'Rina Sari', '2025-12-11 13:05:51'),
(6, 'PT. Cahaya Abadi', 'Bekasi', '08122334455', 'Dedi Pratama', '2025-12-11 13:05:51'),
(7, 'CV. Harapan Baru', 'Bogor', '08133445566', 'Siti Aminah', '2025-12-11 13:05:51'),
(8, 'PT. Maju Bersama', 'Surabaya', '08244556677', 'Tono Saputra', '2025-12-11 13:05:51'),
(9, 'CV. Bumi Indah', 'Semarang', '08199887766', 'Eka Putra', '2025-12-11 13:05:51'),
(10, 'PT. Sentosa Jaya', 'Yogyakarta', '08111222333', 'Lina Marlina', '2025-12-11 13:05:51'),
(11, 'CV. Kencana Mandiri', 'Malang', '08222333444', 'Ahmad Fauzi', '2025-12-11 13:05:51');

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('manager','supervisor','kasir') NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `full_name`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'manager', '$2y$10$sgMU0aNAUwzaAoRLXAmWDOT2qkW1NURW3Vx8a3sjFWYk1Z94x46EC', 'Budi Santoso', 'manager', 'active', '2025-12-10 22:19:13', '2025-12-12 11:28:49'),
(3, 'indra', '$2y$10$4F.t/cWzFb6gr.HHaMaUheXA0ryjTVrUAWTptHAv4Oaq/qJnClITy', 'Indra Gunawan', 'manager', 'active', '2025-12-10 22:19:13', '2025-12-17 22:11:02'),
(12, 'Kasir_sarah', '$2y$10$iinWyIVQFwe.5q.8BXuOj.w5ZOAbce3T/bUWTqWMm55vl6vLHSKnu', 'Sarah', 'kasir', 'active', '2025-12-17 22:12:43', '2025-12-17 22:12:43'),
(17, 'super_Rinto', '$2y$10$U6yetTYz2olNIIOran7Sh.Ksuf33MFPQB.0FzGNknnxYmliXzh/Dy', 'Rinto Adiputra', 'supervisor', 'active', '2025-12-17 22:18:44', '2025-12-17 22:18:44');

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `pegawai`
--
ALTER TABLE `pegawai`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indeks untuk tabel `pelanggan`
--
ALTER TABLE `pelanggan`
  ADD PRIMARY KEY (`pelanggan_id`);

--
-- Indeks untuk tabel `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `kode_barang` (`kode_barang`),
  ADD KEY `fk_product_supplier` (`supplier_id`);

--
-- Indeks untuk tabel `purchases_detail`
--
ALTER TABLE `purchases_detail`
  ADD PRIMARY KEY (`detail_id`),
  ADD KEY `fk_purchase_detail_header` (`purchase_id`),
  ADD KEY `fk_purchase_detail_product` (`product_id`);

--
-- Indeks untuk tabel `purchases_header`
--
ALTER TABLE `purchases_header`
  ADD PRIMARY KEY (`purchase_id`),
  ADD KEY `fk_purchase_user` (`user_id`),
  ADD KEY `fk_purchase_supplier` (`supplier_id`);

--
-- Indeks untuk tabel `sales_detail`
--
ALTER TABLE `sales_detail`
  ADD PRIMARY KEY (`detail_id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indeks untuk tabel `sales_header`
--
ALTER TABLE `sales_header`
  ADD PRIMARY KEY (`sale_id`),
  ADD KEY `pelanggan_id` (`pelanggan_id`),
  ADD KEY `fk_sales_header_user` (`user_id`);

--
-- Indeks untuk tabel `supplier`
--
ALTER TABLE `supplier`
  ADD PRIMARY KEY (`supplier_id`),
  ADD UNIQUE KEY `nama_supplier` (`nama_supplier`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `pegawai`
--
ALTER TABLE `pegawai`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT untuk tabel `pelanggan`
--
ALTER TABLE `pelanggan`
  MODIFY `pelanggan_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT untuk tabel `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT untuk tabel `purchases_detail`
--
ALTER TABLE `purchases_detail`
  MODIFY `detail_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_header`
--
ALTER TABLE `purchases_header`
  MODIFY `purchase_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT untuk tabel `sales_detail`
--
ALTER TABLE `sales_detail`
  MODIFY `detail_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT untuk tabel `sales_header`
--
ALTER TABLE `sales_header`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `supplier`
--
ALTER TABLE `supplier`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_product_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`supplier_id`) ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `purchases_detail`
--
ALTER TABLE `purchases_detail`
  ADD CONSTRAINT `fk_purchase_detail_header` FOREIGN KEY (`purchase_id`) REFERENCES `purchases_header` (`purchase_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_purchase_detail_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `purchases_header`
--
ALTER TABLE `purchases_header`
  ADD CONSTRAINT `fk_purchase_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`supplier_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `sales_detail`
--
ALTER TABLE `sales_detail`
  ADD CONSTRAINT `sales_detail_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales_header` (`sale_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_detail_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);

--
-- Ketidakleluasaan untuk tabel `sales_header`
--
ALTER TABLE `sales_header`
  ADD CONSTRAINT `fk_sales_header_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sales_header_ibfk_2` FOREIGN KEY (`pelanggan_id`) REFERENCES `pelanggan` (`pelanggan_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
