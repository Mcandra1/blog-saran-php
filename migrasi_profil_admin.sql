-- Jalankan satu kali di database db_kotak_saran melalui phpMyAdmin.
ALTER TABLE users
    ADD COLUMN asal VARCHAR(120) NULL AFTER kategori_penulis,
    ADD COLUMN no_hp VARCHAR(25) NULL AFTER asal,
    ADD COLUMN foto_profil VARCHAR(255) NULL AFTER no_hp;
