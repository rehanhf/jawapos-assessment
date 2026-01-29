# Editorial ETL Engine
**Technical Assessment - IT Intern Jawapos**

## Fokus Proyek
Proyek ini diimplementasikan bukan sebagai skrip migrasi sekali jalan, melainkan sebagai **ELT-style pipeline** yang mengutamakan konsistensi data, repetibilitas, dan kontrol penuh terhadap state database.

### Implementation Highlights:
*   Implemented an idempotent ETL pipeline using a Service-Layer pattern.
*   Handled complex polymorphic relationships for article metadata.
*   Guaranteed data integrity via Database Transactions and automated normalization logic.

---
## Operational Demo 

### Ingestion Engine (Progress Bar & Logging)
*Membuktikan penanganan data skala besar dengan feedback visual real-time.*
![Terminal Demo](visuals/visualdemo.gif)

### Normalisasi Data (UUID & Article ID)
*Penggunaan skema UUID v7 internal dan string acak 10 karakter untuk referensi publik.*
![Articles Proof](visuals/database.png)

### Polymorhpic Relationship
*Mapping satu artikel ke berbagai entity (Reporter, Tag) dalam tabel meta.*
![Polymorphic Proof](visuals/database1.png)

---

## Arsitektur & Logika Teknis

### 1. Pemrosesan Konten (Service Layer)
Transformasi konten artikel diisolasi ke dalam `ArticleProcessor` service. 
*   **Decoupling:** Memastikan perintah CLI (Command) tetap ringkas dan logika pemrosesan teks tidak tercampur dengan concern I/O atau database.
*   **Sanitasi Regex:** Menggunakan pola non-greedy untuk menghapus blok legacy "Baca Juga" dan menyuntikkan URL gambar absolut ke placeholder `<!--img-->`.
*   **Result:** Menghasilkan state konten final yang bersih dan siap dikonsumsi oleh API (platform-agnostic).

### 2. Logika Ingest Idempoten
Sistem menjamin konsistensi meskipun perintah dijalankan berulang kali:
*   **Composite Identity:** Artikel diidentifikasi melalui kombinasi unik `slug` dan `publisher_id`.
*   **Relationship Delta:** Menggunakan metode `sync()` untuk relasi polimorfik pada tabel `article_meta`. Hal ini memastikan database selalu merefleksikan data sumber terbaru tanpa adanya redundansi binding.

### 3. Integritas Transaksional (ACID)
Setiap baris data diproses dalam lingkup **Database Transaction**.
*   **Atomicity:** Jika terjadi kegagalan pada tahap manapun (insert artikel, pemetaan meta, atau update pivot), sistem akan melakukan rollback total pada baris tersebut. 
*   **Integrity:** Menghindari adanya "partial writes" atau data korup yang dapat merusak integritas relasi antar tabel.

### 4. Perilaku CLI & Observabilitas
*   **Visibility:** Implementasi *Progress Bar* untuk memantau proses ingest secara real-time pada dataset besar.
*   **Deterministik:** Command dirancang tanpa *magic state*; input yang sama akan selalu menghasilkan output yang konsisten.
*   **Input Dinamis:** Mendukung argumen file kustom untuk kebutuhan backfill data di masa depan.

---

## Tech Stack
- **Framework:** Laravel 12 (PHP 8.4)
- **Database:** MariaDB (UUID Primary Keys)
- **Infrastructure:** Docker (Laravel Sail)

---

## Instalation guide & Eksekusi

1. **Setup Environment:**
   Pastikan Docker Desktop aktif, lalu jalankan:
   ```bash
   composer install
   ./vendor/bin/sail up -d
   ```

2. **Import Schema:**
   Gunakan perintah berikut untuk mengimpor struktur tabel Jawapos:
   ```bash
   ./vendor/bin/sail mariadb -u sail -ppassword jawapos_db < clean.sql
   ```

3. **Jalankan ETL:**
   ```bash
   ./vendor/bin/sail artisan app:import-articles
   ```

---

## Catatan Skalabilitas (Roadmap)
Sistem ini dirancang dengan kesadaran terhadap skalabilitas. Untuk volume data jutaan baris, langkah optimasi berikut telah dipertimbangkan:
1. **Chunked Transactions:** Memecah transaksi per-baris menjadi per-batch (misal: 500 baris) untuk mengurangi overhead penguncian (lock time) pada database.
2. **Queueing System:** Memindahkan proses transformasi regex yang berat ke *background worker* menggunakan Laravel Queue (Redis).
3. **Automated Testing:** Menjadikan unit pemrosesan regex sebagai target utama unit testing untuk menjamin akurasi pembersihan konten pada berbagai variasi HTML.