-- MIGRASI KATEGORI KOTAK SARAN KE KATEGORI PENULIS
-- Backup database terlebih dahulu sebelum menjalankan migrasi ini.
-- Jalankan satu kali melalui phpMyAdmin pada database db_kotak_saran.

-- Pastikan kolom kategori_penulis sudah ada. Jika belum ada, jalankan: 
-- ALTER TABLE users ADD COLUMN kategori_penulis VARCHAR(50) NULL AFTER role;

-- Setelah kategori_penulis setiap akun admin terisi dengan benar, hapus kategori lama.
ALTER TABLE users DROP COLUMN kategori;

-- Catatan data lama:
-- Nilai saran.kategori yang lama (Umum/Kepengurusan/Akademik/Pelatihan)
-- tidak dapat dipetakan otomatis ke kategori penulis. Data lama tetap tersimpan.
-- Periksa dengan: SELECT id, kategori FROM saran;
-- Jika ingin memindahkan pesan lama, ubah kategori secara manual sesuai penulis tujuan.
