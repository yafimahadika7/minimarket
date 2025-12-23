<?php
?>

<nav id="sidebar" class="bg-dark text-white p-3">
    <h4 class="text-center mb-4 border-bottom pb-2">Minimarket App</h4>
    <p class="text-center badge bg-primary"><?php echo strtoupper($user_role); ?></p>
    
    <ul class="nav flex-column">

        <li class="nav-item">
            <a class="nav-link text-white" href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard Utama
            </a>
        </li>

        <?php if (in_array($user_role, ['manager', 'supervisor'])): ?>
        <li class="nav-item mt-3">
            <strong class="text-secondary">MASTER DATA</strong>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white" href="products.php">
                <i class="fas fa-box"></i> Data Barang
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white" href="suppliers.php">
                <i class="fas fa-truck"></i> Data Supplier
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white" href="data_pelanggan.php">
                <i class="fas fa-users"></i> Data Pelanggan
            </a>
        </li>
        <?php endif; ?>
        
        <?php if ($user_role == 'manager'): ?>
        <li class="nav-item">
            <a class="nav-link text-white" href="employees.php">
                <i class="fas fa-user-tie"></i> Data Pegawai
            </a>
        </li>
        <?php endif; ?>

        <li class="nav-item mt-3">
            <strong class="text-secondary">TRANSAKSI</strong>
        </li>
        <li class="nav-item">
            <a class="nav-link text-white" href="sales_transaction.php">
                <i class="fas fa-cash-register"></i> Penjualan (POS)
            </a>
        </li>
        <?php if (in_array($user_role, ['manager', 'supervisor'])): ?>
        <li class="nav-item">
            <a class="nav-link text-white" href="purchases_transaction.php">
                <i class="fas fa-shopping-cart"></i> Pembelian (Pengadaan)
            </a>
        </li>
        <?php endif; ?>

        <?php if (in_array($user_role, ['manager', 'supervisor'])): ?>
        <li class="nav-item mt-3">
            <strong class="text-secondary">LAPORAN</strong>
        </li>
        <li class="nav-item dropdown">
            <a class="nav-link text-white dropdown-toggle" href="#" id="laporanDropdown" role="button" data-bs-toggle="dropdown">
                <i class="fas fa-chart-line"></i> Laporan Transaksi
            </a>
            <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="laporanDropdown">
                <li><a class="dropdown-item" href="report_sales.php">Data Penjualan</a></li>
                <li><a class="dropdown-item" href="report_purchases.php">Data Pembelian</a></li>
            </ul>
        </li>
        <li class="nav-item dropdown">
            <a class="nav-link text-white dropdown-toggle" href="#" id="laporanMasterDropdown" role="button" data-bs-toggle="dropdown">
                <i class="fas fa-database"></i> Laporan Data Master
            </a>
            <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="laporanMasterDropdown">
                <li><a class="dropdown-item" href="report_products.php">Data Barang</a></li>
                <li><a class="dropdown-item" href="report_suppliers.php">Data Supplier</a></li>
                <li><a class="dropdown-item" href="report_customers.php">Data Pelanggan</a></li>
                <?php if ($user_role == 'manager'): ?>
                <li><a class="dropdown-item" href="report_employees.php">Data Pegawai</a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

        <li class="nav-item mt-5">
            <a class="nav-link text-danger" href="logout.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </li>

    </ul>
</nav>