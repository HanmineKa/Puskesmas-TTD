---
name: web-development
description: 'Use for web development: building or changing websites, web apps, frontend UI, backend APIs, and full-stack features; debugging web behavior; responsive design; browser and build verification.'
argument-hint: 'Describe the web feature, bug, or page to build'
---

# Web Development

## Tujuan
Selesaikan perubahan web secara menyeluruh dan selaras dengan proyek: pahami perilaku yang diminta, ubah bagian yang mengendalikan perilaku tersebut, lalu verifikasi hasilnya.

## Kapan Digunakan
- Membuat atau mengubah website, aplikasi web, halaman, komponen UI, API, atau alur full-stack.
- Memperbaiki bug web, perilaku responsif, aksesibilitas, performa, atau integrasi browser.
- Menjalankan dan memverifikasi aplikasi web setelah perubahan.

## Prosedur

1. **Tetapkan hasil yang diminta.** Identifikasi perilaku atau tampilan yang harus berubah dan batasan eksplisit. Ajukan pertanyaan singkat hanya bila ketidakjelasan menghalangi keputusan penting; selain itu, pilih asumsi konservatif dan nyatakan bila relevan.

2. **Temukan titik implementasi terdekat.** Baca instruksi proyek dan periksa berkas yang menjadi jangkar, implementasi pemilik perilaku, pemanggil atau tes terdekat, serta skrip yang relevan. Untuk proyek yang sudah ada, ikuti framework, pola komponen, sistem desain, dan konvensi yang digunakan. Untuk proyek baru, siapkan struktur minimum yang sesuai kebutuhan, bukan scaffold yang tidak diperlukan.

3. **Buat hipotesis dan cek pembeda.** Sebelum mengedit, rumuskan dugaan lokal tentang bagaimana perilaku seharusnya bekerja atau penyebab bug, lalu pilih tes, pemanggil, atau pemeriksaan termurah yang dapat membantahnya. Jika kode yang diperiksa hanya meneruskan atau mendaftarkan perilaku, ikuti satu langkah ke implementasi yang benar-benar mengontrolnya.

4. **Pilih perubahan terkecil yang lengkap.** Perbaiki penyebab pada pemilik perilaku dan gunakan utilitas atau dependensi proyek yang sudah ada. Jangan melakukan refactor atau mengubah berkas yang tidak berkaitan. Pertahankan perubahan pengguna dan jangan membatalkan perubahan yang tidak dibuat untuk tugas ini.

5. **Bangun UI untuk penggunaan nyata bila frontend terlibat.** Pertahankan bahasa visual proyek atau brief desain. Pastikan alur utama berfungsi dan sediakan status kosong, memuat, berhasil, serta gagal bila sesuai. Periksa navigasi keyboard, label kontrol, fokus, kontras, dan tata letak pada layar sempit maupun lebar. Gunakan kontrol yang cocok dengan tindakan dan data; hindari teks atau dekorasi yang tidak membantu tugas pengguna.

6. **Jaga batas backend dan data bila relevan.** Validasi input di server, tegakkan autentikasi dan otorisasi pada operasi yang dilindungi, gunakan query terparameterisasi atau ORM proyek, dan jangan mengekspos rahasia atau data sensitif di klien maupun log. Ikuti pola migrasi dan transaksi proyek; jangan menjalankan perubahan destruktif pada database tanpa otorisasi yang jelas.

7. **Validasi langsung setelah edit pertama.** Jalankan pemeriksaan perilaku paling sempit yang dapat membantah hipotesis: tes terkait, lint/typecheck untuk bagian yang diubah, atau pemeriksaan build yang relevan. Jika gagal karena cacat lokal, perbaiki bagian yang sama dan ulangi pemeriksaan itu sebelum memperluas cakupan. Setelah lolos, jalankan gerbang tambahan yang diwajibkan proyek dan sesuai risiko perubahan.

8. **Verifikasi aplikasi yang berjalan bila UI berubah.** Jalankan server pengembangan sesuai skrip proyek dan buka aplikasinya di browser. Uji alur utama, interaksi penting, serta tampilan desktop dan mobile; periksa screenshot atau console bila tersedia. Untuk satu berkas HTML statis yang bisa dibuka langsung, gunakan berkas tersebut tanpa server. Jika browser atau server tidak tersedia, jelaskan bagian yang belum terverifikasi.

9. **Tutup dengan ringkasan yang dapat ditindaklanjuti.** Sebutkan perubahan utama dengan tautan berkas, pemeriksaan yang berhasil atau gagal, URL server bila masih berjalan, dan batasan yang belum teruji. Perbarui dokumentasi atau catatan progres hanya bila perubahan membuatnya perlu.

## Kriteria Selesai
- Perubahan memenuhi perilaku yang diminta dan tetap dalam cakupan.
- Pemeriksaan terfokus lulus, atau kegagalan dan penyebabnya dilaporkan dengan jelas.
- Jika UI berubah, alur utama dan ukuran layar yang relevan sudah diperiksa di browser bila alat tersedia.
- Tidak ada klaim bahwa tes, build, atau pemeriksaan visual berhasil jika belum dijalankan.
