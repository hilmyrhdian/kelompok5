<?php
// --- DEBUGGING: Baris ini akan menampilkan semua error PHP untuk membantu menemukan masalah. ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- Akhir Bagian Debugging ---

/**
 * api.php
 * Backend utama untuk aplikasi Antripool.
 * Menangani semua logika bisnis dan interaksi database.
 */

// Mengatur header agar outputnya selalu berupa JSON.
header('Content-Type: application/json');

// Memasukkan file koneksi database.
// DISESUAIKAN: Nama file diubah menjadi 'connection.php' sesuai struktur file Anda.
require 'connection.php';

// Memeriksa ulang koneksi setelah di-include
if (!$conn || $conn->connect_error) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Koneksi database gagal. Periksa file connection.php. Detail: ' . ($conn->connect_error ?? 'Tidak dapat terhubung')
    ]);
    // Hentikan eksekusi jika koneksi gagal.
    die();
}


// Menentukan aksi apa yang diminta oleh frontend.
$action = $_REQUEST['action'] ?? '';

// Menggunakan switch-case untuk menjalankan fungsi yang sesuai dengan aksi.
switch ($action) {
    case 'get_tables':
        getTables($conn);
        break;
    case 'get_bookings':
        getBookings($conn);
        break;
    case 'create_booking':
        createBooking($conn);
        break;
    case 'cancel_booking':
        cancelBooking($conn);
        break;
    default:
        // Jika aksi tidak dikenali, kirim pesan error.
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.']);
        break;
}

// Menutup koneksi database setelah semua proses selesai.
$conn->close();

// --- FUNGSI-FUNGSI UNTUK SETIAP AKSI ---

/**
 * Mengambil semua data meja dari database.
 */
function getTables($conn) {
    $sql = "SELECT id, name, status FROM tables ORDER BY id";
    $result = $conn->query($sql);
    $tables = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $tables[] = $row;
        }
    }
    echo json_encode($tables);
}

/**
 * Mengambil semua data booking dari database.
 * VERSI PERBAIKAN: Query SQL disederhanakan untuk menghindari error.
 */
function getBookings($conn) {
    // Query yang lebih sederhana dan andal.
    $sql = "
        SELECT 
            b.id, 
            b.customer_name, 
            b.customer_phone, 
            b.table_id, 
            t.name as table_name,
            b.booking_date, 
            b.start_time, 
            b.duration_hours
        FROM bookings b
        JOIN tables t ON b.table_id = t.id
        ORDER BY b.booking_date DESC, b.start_time DESC
    ";
    $result = $conn->query($sql);
    $bookings = [];
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            // Mengambil detail pesanan untuk setiap booking secara terpisah.
            $order_sql = "SELECT mi.name, bo.quantity, mi.price FROM booking_orders bo JOIN menu_items mi ON bo.menu_item_id = mi.id WHERE bo.booking_id = ?";
            $stmt = $conn->prepare($order_sql);
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $order_result = $stmt->get_result();
            $row['orders'] = $order_result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            $bookings[] = $row;
        }
    }
    echo json_encode($bookings);
}

/**
 * Membuat booking baru dan menyimpan ke database.
 */
function createBooking($conn) {
    // Mengambil data dari request POST.
    $name = $_POST['name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $tableNumber = (int)($_POST['tableNumber'] ?? 0);
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $duration = (int)($_POST['duration'] ?? 0);
    $menuItems = json_decode($_POST['menuItems'] ?? '{}', true);

    // Validasi sederhana.
    if (empty($name) || empty($phone) || empty($tableNumber) || empty($date) || empty($time) || empty($duration)) {
        echo json_encode(['status' => 'error', 'message' => 'Mohon lengkapi semua data booking.']);
        return;
    }

    // Memulai transaksi untuk memastikan semua query berhasil atau tidak sama sekali.
    $conn->begin_transaction();

    try {
        // 1. Simpan data booking utama.
        $sql = "INSERT INTO bookings (customer_name, customer_phone, table_id, booking_date, start_time, duration_hours) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssissi", $name, $phone, $tableNumber, $date, $time, $duration);
        $stmt->execute();
        $booking_id = $stmt->insert_id; // Dapatkan ID dari booking yang baru saja dibuat.
        $stmt->close();

        // 2. Simpan pesanan makanan/minuman jika ada.
        if (!empty($menuItems)) {
            $sql_order = "INSERT INTO booking_orders (booking_id, menu_item_id, quantity) VALUES (?, ?, ?)";
            $stmt_order = $conn->prepare($sql_order);
            foreach ($menuItems as $item) {
                if ($item['quantity'] > 0) {
                    $stmt_order->bind_param("iii", $booking_id, $item['id'], $item['quantity']);
                    $stmt_order->execute();
                }
            }
            $stmt_order->close();
        }

        // 3. Update status meja menjadi 'booked'.
        $sql_update_table = "UPDATE tables SET status = 'booked' WHERE id = ?";
        $stmt_update = $conn->prepare($sql_update_table);
        $stmt_update->bind_param("i", $tableNumber);
        $stmt_update->execute();
        $stmt_update->close();

        // Jika semua query berhasil, commit transaksi.
        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Booking berhasil dikonfirmasi!']);

    } catch (Exception $e) {
        // Jika terjadi error, batalkan semua perubahan (rollback).
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
    }
}

/**
 * Membatalkan booking yang ada.
 */
function cancelBooking($conn) {
    $booking_id = (int)($_POST['id'] ?? 0);

    if (empty($booking_id)) {
        echo json_encode(['status' => 'error', 'message' => 'ID Booking tidak valid.']);
        return;
    }

    $conn->begin_transaction();

    try {
        // 1. Ambil table_id dari booking yang akan dihapus.
        $sql_get_table = "SELECT table_id FROM bookings WHERE id = ?";
        $stmt_get = $conn->prepare($sql_get_table);
        $stmt_get->bind_param("i", $booking_id);
        $stmt_get->execute();
        $result = $stmt_get->get_result();
        $booking = $result->fetch_assoc();
        $table_id = $booking['table_id'];
        $stmt_get->close();

        // 2. Hapus booking dari tabel bookings (dan orders akan terhapus otomatis karena ON DELETE CASCADE).
        $sql_delete = "DELETE FROM bookings WHERE id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $booking_id);
        $stmt_delete->execute();
        $stmt_delete->close();

        // 3. Update status meja kembali menjadi 'available'.
        if ($table_id) {
            $sql_update = "UPDATE tables SET status = 'available' WHERE id = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("i", $table_id);
            $stmt_update->execute();
            $stmt_update->close();
        }
        
        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => 'Booking berhasil dibatalkan.']);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Gagal membatalkan booking: ' . $e->getMessage()]);
    }
}
?>
