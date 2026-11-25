-- Jalankan satu kali melalui phpMyAdmin pada database db_kotak_saran.
-- Kategori penulis menjadi acuan kategori tujuan pada kotak saran.
ALTER TABLE users
ADD COLUMN kategori_penulis VARCHAR(50) NULL AFTER kategori;

CREATE TABLE IF NOT EXISTS artikel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    judul VARCHAR(200) NOT NULL,
    kategori VARCHAR(50) NOT NULL,
    isi LONGTEXT NOT NULL,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_artikel_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Setelah migrasi, buka Manajemen Admin sebagai Super Admin.
-- Edit setiap admin dan pilih kategori penulisnya.
