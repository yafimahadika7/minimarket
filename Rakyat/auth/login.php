<?php
session_start();
include "../config/koneksi.php";

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error_message = '<div class="alert alert-danger mt-2">Username dan password harus diisi.</div>';
    } else {

        $stmt = $koneksi->prepare("SELECT user_id, username, password, full_name, role, status FROM users WHERE username = ? LIMIT 1");
        $user_data = null;
        
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user_data = $result->fetch_assoc();
                $table_source = 'users';
            }
            $stmt->close();
        }
        
       
        if (!$user_data) {
            $stmt = $koneksi->prepare("SELECT user_id, username, password, full_name, role, status FROM pegawai WHERE username = ? LIMIT 1");
            
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user_data = $result->fetch_assoc();
                    $table_source = 'pegawai';
                }
                $stmt->close();
            }
        }
        
        if ($user_data) {
          
            if (isset($user_data['status']) && $user_data['status'] !== 'active') {
                $error_message = '<div class="alert alert-warning mt-2">Akun Anda tidak aktif. Silakan hubungi administrator.</div>';
            } else {
              
                $password_valid = false;
                
             
                if (preg_match('/^\$2[ayb]\$/', $user_data['password'])) {
                   
                    $password_valid = password_verify($password, $user_data['password']);
                } else {
                  
                    $password_valid = ($password === $user_data['password']);
                    
          
                    if ($password_valid) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $update_stmt = $koneksi->prepare("UPDATE {$table_source} SET password = ? WHERE user_id = ?");
                        if ($update_stmt) {
                            $update_stmt->bind_param("si", $hashed_password, $user_data['user_id']);
                            $update_stmt->execute();
                            $update_stmt->close();
                        }
                    }
                }
                
                if ($password_valid) {
                  
                    session_regenerate_id(true);
                    
                   
                    $_SESSION['username'] = $user_data['username'];
                    $_SESSION['user_id'] = $user_data['user_id'];
                    $_SESSION['nama_lengkap'] = $user_data['full_name'] ?? $user_data['username'];
                    $_SESSION['role'] = $user_data['role'] ?? 'kasir';
                    $_SESSION['table_source'] = $table_source; 
                    
                    header("Location: ../pages/dashboard.php");
                    exit;
                } else {
                    $error_message = '<div class="alert alert-danger mt-2">Password salah.</div>';
                }
            }
        } else {
            $error_message = '<div class="alert alert-danger mt-2">Username tidak ditemukan.</div>';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | Minimarket Rakyat</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root{
    --brand:#16a34a;         
    --brand-dark:#0f7a37;
    --ink:#0f172a;
    --muted:#64748b;
    --card:#ffffff;
    --border:rgba(148, 163, 184, 0.35);
}

body{
    font-family:'Inter', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    background:
        radial-gradient(900px 500px at 15% 10%, rgba(22,163,74,0.35), transparent 60%),
        radial-gradient(800px 500px at 85% 90%, rgba(34,197,94,0.25), transparent 60%),
        linear-gradient(135deg, #0b1220, #0b1a2a);
}

.auth-shell{
    width:min(980px, 100%);
    border-radius:22px;
    overflow:hidden;
    background:rgba(255,255,255,0.06);
    border:1px solid rgba(255,255,255,0.12);
    box-shadow: 0 30px 80px rgba(0,0,0,0.45);
    backdrop-filter: blur(14px);
}

.auth-grid{
    display:grid;
    grid-template-columns: 1.05fr 0.95fr;
    min-height: 560px;
}

.brand-panel{
    position:relative;
    padding:46px 44px;
    color:white;
    background:
        radial-gradient(700px 400px at 20% 20%, rgba(22,163,74,0.55), transparent 60%),
        radial-gradient(700px 400px at 80% 80%, rgba(34,197,94,0.35), transparent 60%),
        linear-gradient(135deg, rgba(2,6,23,0.72), rgba(2,6,23,0.35));
}

.brand-badge{
    display:inline-flex;
    align-items:center;
    gap:10px;
    padding:10px 14px;
    border-radius:999px;
    background:rgba(255,255,255,0.08);
    border:1px solid rgba(255,255,255,0.14);
    font-weight:700;
    letter-spacing:0.3px;
}

.brand-title{
    margin-top:18px;
    font-size:40px;
    line-height:1.05;
    font-weight:900;
    letter-spacing:-0.6px;
}

.brand-title .accent{
    color:#86efac;
}

.brand-sub{
    margin-top:14px;
    color:rgba(255,255,255,0.78);
    font-size:14px;
    line-height:1.6;
    max-width: 44ch;
}

.feature-list{
    margin-top:22px;
    display:grid;
    gap:10px;
    font-size:14px;
    color:rgba(255,255,255,0.82);
}
.feature-item{
    display:flex;
    gap:10px;
    align-items:flex-start;
}
.feature-item i{
    margin-top:2px;
    color:#86efac;
}

.brand-illustration{
    position:absolute;
    right:-30px;
    bottom:-30px;
    width:280px;
    height:280px;
    border-radius:50%;
    background: radial-gradient(circle at 35% 35%, rgba(134,239,172,0.95), rgba(22,163,74,0.25) 55%, transparent 70%);
    filter: blur(0.2px);
    opacity:0.85;
    pointer-events:none;
}

.form-panel{
    padding:42px 40px;
    background:rgba(255,255,255,0.92);
}

.form-title{
    font-weight:900;
    color:var(--ink);
    margin:0;
    letter-spacing:-0.4px;
}
.form-desc{
    margin-top:8px;
    color:var(--muted);
    font-size:14px;
}

.form-label{
    font-weight:700;
    color:#0f172a;
    font-size:13px;
}

.form-control{
    height:48px;
    border-radius:14px;
    border:1px solid var(--border);
    background:#ffffff;
}
.form-control:focus{
    border-color: rgba(22,163,74,0.55);
    box-shadow: 0 0 0 4px rgba(22,163,74,0.14);
}

.input-group .btn{
    border-radius: 14px;
}

.btn-brand{
    height:48px;
    border-radius:14px;
    border:none;
    font-weight:800;
    background: linear-gradient(135deg, var(--brand), #22c55e);
    box-shadow: 0 10px 22px rgba(22,163,74,0.28);
}
.btn-brand:hover{
    background: linear-gradient(135deg, var(--brand-dark), var(--brand));
}

.mini-help{
    margin-top:12px;
    color:var(--muted);
    font-size:12px;
}

.login-footer{
    margin-top:18px;
    font-size:12px;
    color:var(--muted);
}

@media (max-width: 900px){
    .auth-grid{ grid-template-columns: 1fr; }
    .brand-panel{ padding:34px 28px; }
    .form-panel{ padding:34px 28px; }
    .brand-illustration{ width:240px; height:240px; }
}
</style>
</head>
<body>

<div class="auth-shell">
  <div class="auth-grid">
    <div class="brand-panel">
        <div class="brand-badge">
            <i class="fa-solid fa-store"></i>
            MINIMARKET RAKYAT
        </div>
        <div class="brand-title">
            Sistem <span class="accent">Kasir</span> &<br>Manajemen Stok
        </div>
        <div class="brand-sub">
            Masuk untuk mengelola transaksi penjualan, pembelian, dan laporan. Cepat, rapi, dan siap untuk operasional harian minimarket.
        </div>
        <div class="feature-list">
            <div class="feature-item"><i class="fa-solid fa-circle-check"></i><div>POS Penjualan + cetak nota</div></div>
            <div class="feature-item"><i class="fa-solid fa-circle-check"></i><div>Pengadaan & stok otomatis</div></div>
            <div class="feature-item"><i class="fa-solid fa-circle-check"></i><div>Laporan kasir & supervisor</div></div>
        </div>
        <div class="brand-illustration"></div>
    </div>

    <div class="form-panel">
        <h3 class="form-title">Masuk</h3>
        <div class="form-desc">Gunakan akun yang sudah didaftarkan admin.</div>

    <?= $error_message ?>

    <form method="POST">
        <div class="mb-3 text-start">
            <label class="form-label">Username</label>
            <div class="input-group">
                <span class="input-group-text bg-white" style="border-radius:14px 0 0 14px; border:1px solid var(--border); border-right:none;">
                    <i class="fa-regular fa-user"></i>
                </span>
                <input type="text" name="username" class="form-control" placeholder="Masukkan username" required style="border-left:none;">
            </div>
        </div>
        <div class="mb-4 text-start">
            <label class="form-label">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-white" style="border-radius:14px 0 0 14px; border:1px solid var(--border); border-right:none;">
                    <i class="fa-solid fa-lock"></i>
                </span>
                <input type="password" name="password" id="passwordInput" class="form-control" placeholder="Masukkan password" required style="border-left:none;">
                <button class="btn btn-outline-secondary" type="button" id="togglePassword" style="border-radius:0 14px 14px 0; border:1px solid var(--border); border-left:none;">
                    <i class="fa-regular fa-eye"></i>
                </button>
            </div>
        </div>
        <button class="btn btn-brand w-100"><i class="fa-solid fa-right-to-bracket me-2"></i>MASUK</button>
        <div class="mini-help">Jika lupa password, hubungi supervisor/manager.</div>
    </form>

        <p class="login-footer">© <?= date('Y') ?> Minimarket Rakyat • Sistem Informasi Minimarket</p>
    </div>
  </div>
</div>

<script>
  (function () {
    const btn = document.getElementById('togglePassword');
    const input = document.getElementById('passwordInput');
    if (!btn || !input) return;
    btn.addEventListener('click', () => {
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      btn.innerHTML = isPassword ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
    });
  })();
</script>

</body>
</html>