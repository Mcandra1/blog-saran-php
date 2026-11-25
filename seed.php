
<?php
require_once 'config.php';

$username = 'admin';
$password = 'admin123';
$role = 'superadmin';

$password_value = password_hash($password, PASSWORD_DEFAULT);

// Cek apakah username sudah ada
$stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
$stmt_check->bind_param("s", $username);
$stmt_check->execute();
$result_check = $stmt_check->get_result();

if ($result_check->num_rows > 0) {
    echo "User superadmin '{$username}' sudah ada. Tidak ada user baru yang dimasukkan.";
} else {

    $stmt_insert = $conn->prepare(
        "INSERT INTO users (username, password, role) VALUES (?, ?, ?)"
    );

    $stmt_insert->bind_param("sss", $username, $password_value, $role);

    if ($stmt_insert->execute()) {
        echo "User superadmin berhasil dibuat!";
        echo "<br>Username: {$username}";
        echo "<br>Password: {$password}";
        echo "<br>Role: {$role}";
        echo "<br>Silakan hapus file seed.php setelah selesai.";
    } else {
        echo "Error: " . $stmt_insert->error;
    }

    $stmt_insert->close();
}

$stmt_check->close();
$conn->close();
?>
