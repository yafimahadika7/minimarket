<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


$DB_HOST  = "localhost";      
$DB_USER  = "root";           
$DB_PASS  = "janganangel";               
$DB_NAME  = "db_minimarket";  


$DB_TABLE = "pegawai";

try {
 
    $koneksi = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);


    $koneksi->set_charset("utf8mb4");

} catch (mysqli_sql_exception $e) {
    echo '<div class="alert alert-danger">';
    echo '<strong>ERROR KONEKSI:</strong> Tidak dapat terhubung ke database.<br>';
    echo 'Pesan: ' . htmlspecialchars($e->getMessage());
    echo '</div>';
    exit;
}


function esc($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

?>
