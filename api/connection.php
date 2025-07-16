<?php
/**
 * db_connect.php
 * File ini menangani koneksi ke database MySQL.
 * Ganti nilai variabel di bawah ini sesuai dengan konfigurasi server Anda.
 */

// Konfigurasi Database
$servername = "localhost"; // Biasanya "localhost" atau alamat IP server database Anda
$username = "root";        // Username database Anda
$password = "yanghackjahat";            // Password database Anda (kosongkan jika tidak ada)
$dbname = "antripool";  // Nama database yang telah kita buat

// Membuat koneksi
$conn = new mysqli($servername, $username, $password, $dbname);

// Memeriksa koneksi
if ($conn->connect_error) {
    // Jika koneksi gagal, hentikan skrip dan tampilkan pesan error.
    // Di lingkungan produksi, sebaiknya error ini dicatat ke file log, bukan ditampilkan ke pengguna.
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Koneksi database gagal: ' . $conn->connect_error]));
}

// Mengatur character set ke utf8mb4 untuk mendukung karakter yang lebih luas.
$conn->set_charset("utf8mb4");
?>
