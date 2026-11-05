# Sistem Pendaftaran dan Pemantauan Calon

Antara muka sistem ini menggunakan Bahasa Melayu. PHP menyimpan data dalam MySQL/MariaDB tempatan; Google Sheets dan Google Apps Script tidak digunakan. Fail `database.sql` boleh diimport melalui phpMyAdmin.

## Pemasangan pangkalan data

1. Mulakan **Apache** dan **MySQL** melalui XAMPP.
2. Buka `http://localhost/phpmyadmin`.
3. Pilih tab **Import**, pilih fail `database.sql`, kemudian tekan **Import**.
4. Fail SQL mencipta pangkalan data `candidate_registration`, enam belas pasukan rasmi, jadual pendaftaran, wakil, calon, kuota, tetapan dan log aktiviti. Kuota awal ialah sifar kerana nilai kuota acara perlu diluluskan terlebih dahulu.
5. Tetapan sambungan pangkalan data lalai dalam `config.php` ialah hos `127.0.0.1`, pangkalan data `candidate_registration`, pengguna `root`, tanpa kata laluan. Jika MySQL anda menggunakan tetapan lain, ubah nilai `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` dan `DB_PASSWORD` dalam fail itu.

Import SQL sekali sahaja pada pemasangan baharu. Untuk pemasangan yang sudah mengandungi data, buat sandaran sebelum mengimport atau menjalankan sebarang perubahan skema.
Import semula fail ini akan menormalkan kategori umur sedia ada kepada kod dalaman ASCII berdasarkan umur calon, supaya data kategori kekal konsisten walaupun alat import tidak mengendalikan aksara khas dengan betul.

## Menetapkan kata laluan pentadbir

Jana cincangan kata laluan melalui PowerShell:

```powershell
C:\xampp\php\php.exe -r "echo password_hash('GANTIKAN_DENGAN_KATA_LALUAN_KUKUH', PASSWORD_DEFAULT), PHP_EOL;"
```

Salin cincangan yang terhasil ke dalam `ADMIN_PASSWORD_HASH` di `config.php`. Simpan kata laluan sebenar secara peribadi; jangan letak kata laluan itu dalam kod halaman atau fail SQL. Pastikan `config.php` tidak boleh dimuat turun terus melalui web.
Konfigurasi setempat semasa menggunakan kata laluan sementara yang telah diberikan untuk ujian. Gantikannya dengan kata laluan sendiri sebelum penggunaan sebenar.

## Menjalankan sistem

1. Letakkan projek di `C:\xampp\htdocs\candidate_registration_two_pages`.
2. Mulakan Apache dan MySQL di panel kawalan XAMPP.
3. Buka `http://localhost/candidate_registration_two_pages/register.html` untuk pendaftaran.
4. Buka `http://localhost/candidate_registration_two_pages/login.html` dan masukkan kata laluan pentadbir. Halaman `admin.html` akan mengalihkan pengguna yang belum log masuk ke halaman ini.
5. Selepas log masuk, buka **Pengurusan kuota** dan tetapkan kuota keseluruhan serta kuota umur bagi setiap pasukan sebelum pendaftaran calon dibuka.

Kedua-dua halaman mesti dibuka melalui Apache. Membuka fail HTML secara terus tidak akan menjalankan API PHP. Akaun `root` tanpa kata laluan hanya sesuai untuk pembangunan XAMPP setempat; tetapkan pengguna dan kata laluan pangkalan data yang terhad sebelum mengehos sistem pada pelayan.

## Peraturan pendaftaran

- Terdapat 16 pasukan rasmi dengan ID stabil `TEAM-01` hingga `TEAM-16`.
- Nombor kad pengenalan mestilah 12 digit dan unik.
- Umur calon ialah 4 hingga 15 tahun; kategori ditentukan pada pelayan.
- Kategori umur ialah 4–6, 7–12 dan 13–15 tahun.
- Pendaftaran menyemak kuota dalam transaksi pangkalan data dan menyokong penerimaan sebahagian calon.
- Pentadbir boleh menyemak dan mengubah calon, memadam rekod, mengurus kuota, menjana laporan, mengeksport CSV dan melihat log aktiviti.
- Data calon hanya tersedia melalui laluan pentadbir yang dilindungi sesi dan token CSRF. Halaman awam hanya memaparkan jumlah kemajuan pasukan yang dipilih.
- Pemadaman rekod calon adalah kekal. Buat sandaran pangkalan data secara berkala.

Untuk akses awam melalui Internet, gunakan HTTPS, kata laluan pentadbir yang kukuh dan akaun pangkalan data yang khusus dengan keizinan minimum. Hadkan akses kepada phpMyAdmin dan fail konfigurasi.
