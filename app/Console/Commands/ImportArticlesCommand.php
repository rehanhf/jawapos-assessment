<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\ArticleProcessor;
//import semua model yang dibuat
use App\Models\Article;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Reporter;
use App\Models\Tag;
use App\Models\User;

class ImportArticlesCommand extends Command
{
    //nama command untuk di call pada terminal dengan argumen file opsional
    protected $signature = 'app:import-articles {file?}';

    //deskripsi singkat tentang command
    protected $description = 'Memproses data dari article.csv ke dalam database MariaDB';

    //fungsi utama:
    public function handle(ArticleProcessor $processor)
    {
        //ambil nama file dari argumen atau default ke article.csv
        $filename = $this->argument('file') ?? 'article.csv';
        $path = base_path($filename);

        //return hasil cek file ada/tidak
        if (!file_exists($path)) {
            $this->error("file {$filename} tidak ditemukan! pastikan {$filename} ada di folder root.");
            return 1;
        }

        //buka file dalam mode read
        $file = fopen($path, 'r');

        //mengambil baris pertama sebagai header
        $header = fgetcsv($file);

        $stats = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        //logic:hitung total baris untuk inisialisasi progress bar
        $totalRows = count(file($path)) - 1;
        $bar = $this->output->createProgressBar($totalRows);

        $this->info("menginisiasi ingest data dari: {$filename}");
        $startTime = microtime(true);
        
        $bar->start();
    
        //baca row per row sampai habis
        while (($row = fgetcsv($file, 0, ',', '"', '"')) !== false) {
            $stats['total']++;
            
            //validasi jumlah kolom harus sama dengan header
            if (count($row) !== count($header)) {
                $msg = "baris {$stats['total']}: jumlah kolom tidak sesuai";
                Log::error("[import] " . $msg, ['row' => $row]);
                $stats['failed']++;
                $bar->advance();
                continue;
            }

            //melakukan pengabungan header dengan isi baris untuk memudahkan membaca & akses data
            $data = array_combine($header, $row);
            
            //sanity check mencegah data kotor masuk ke logic proses
            $validator = Validator::make($data, [
                'title' => 'required|string',
                'publisher' => 'required|string',
                'editor' => 'required|json', //memastikan format json valid
                'author' => 'required|json',
            ]);

            if ($validator->fails()) {
                $msg = "baris {$stats['total']}: validasi gagal. " . implode(', ', $validator->errors()->all());
                Log::error("[import] " . $msg, ['data' => $data]);
                $stats['failed']++;
                $bar->advance();
                continue;
            }

            //lanjut ke proses database...
            try {
                DB::transaction(function () use ($data, $processor) {
                    $this->processRow($data, $processor);
                });
                $stats['success']++;
            } catch (\Exception $e) {
                //catch error tak terduga
                $msg = "baris {$stats['total']}: gagal memproses. " . $e->getMessage();
                
                //log ke file dengan stack trace lengkap untuk debugging nanti
                Log::error("[import exception] " . $msg, [
                    'exception' => $e,
                    'row_data' => $data
                ]);
                
                $stats['failed']++;
            }
            
            $bar->advance();
        }

        //tutup file setelah selesai
        fclose($file);
        $bar->finish();

        //log report output
        $duration = round(microtime(true) - $startTime, 2);
        
        $this->newLine(2);
        $this->info("====================================");
        $this->info(" LAPORAN IMPORT: {$filename}");
        $this->info("====================================");
        $this->table(
            ['metric', 'value'],
            [
                ['total baris', $stats['total']],
                ['berhasil', $stats['success']],
                ['gagal', $stats['failed']],
                ['durasi', $duration . ' detik'],
                ['memori', round(memory_get_peak_usage() / 1024 / 1024, 2) . ' mb'],
            ]
        );
        $this->info("====================================");
        
        if ($stats['failed'] > 0) {
            $this->warn("cek storage/logs/laravel.log untuk detail error.");
            return 1; 
        }
        
        return 0;
    }

    //function khusus menangani kompleksitas satu baris data
    private function processRow(array $row, ArticleProcessor $processor)
    {
        //1 simpan publisher
        //memakai firstorcreate agar tidak ada duplikat
        $publisher = Publisher::firstOrCreate(
            ['name' => $row['publisher']]
        );

        //2 simpan editor
        //data editor di csv bentuknya teks json, jadi harus di-decode dulu
        $editorData = json_decode($row['editor'], true);
        
        if ($editorData) {
            $user = User::firstOrCreate(
                ['email' => $editorData['email']], //cari berdasarkan email
                [
                    'name' => $editorData['name'],
                    'publisher_id' => $publisher->id,
                    'password' => bcrypt('password') //membuat password dummy karena tidak ada di csv
                ]
            );
        } else {
            //jika data editor rusak/kosong, skip baris ini
            return; 
        }

        //3 simpan kategori
        $categoryData = json_decode($row['category'], true);
        
        $category = Category::firstOrCreate(
            [
                //cari berdasarkan slug dan publisher agar unik
                'slug' => Str::slug($categoryData['name']), 
                'publisher_id' => $publisher->id
            ],
            [
                'name' => $categoryData['name'],
                'description' => $categoryData['description'] ?? null,
                'parent_id' => null //csv tidak punya data parent, jadi null
            ]
        );

        //4 cleaning konten artikel
        //menggunakan service articleprocessor
        $photos = json_decode($row['photo'], true) ?? [];
        $cleanContent = $processor->cleanContent($row['content'], $photos);

        //5 simpan artikel
        //slug sebagai identitas unik artikel yang lebih stabil dari judul mentah
        $slug = Str::slug($row['title']);
        
        //logic:cari berdasarkan slug+publisher agar article_id tetap stabil jika judul diedit kecil
        $article = Article::firstOrNew([
            'slug' => $slug,
            'publisher_id' => $publisher->id
        ]);

        //logic:generate random id hanya jika artikel benar-benar baru di database
        if (!$article->exists) {
            $article->article_id = Str::random(10);
        }

        //update data artikel baru/lama menggunakan fillable array
        $article->fill([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'title' => $row['title'],
            'description' => $row['description'],
            'content' => $cleanContent,
            'status' => 'published',
            'published_at' => $row['published_date'],
            'show_ads' => 1,
            'is_public' => 1
        ])->save();

        //6 simpan author - relasi polymorphic
        $authorData = json_decode($row['author'], true);
        
        //normalisasi author jadi array
        if (isset($authorData['name'])) {
            $authorData = [$authorData];
        }

        $reporterIds = [];
        foreach ($authorData as $a) {
            $reporter = Reporter::firstOrCreate(
                [
                    'slug' => Str::slug($a['name']),
                    'publisher_id' => $publisher->id
                ],
                [
                    'name' => $a['name']
                ]
            );
            $reporterIds[] = $reporter->id;
        }
        
        //sambung artikel ke reporter = isi tabel article_meta
        //sync() akan menghapus koneksi lama dan pasang yang baru menghindari duplikat
        $article->reporters()->sync($reporterIds);

        //7 simpan tags - relasi polymorphic
        $tagData = json_decode($row['tag'], true) ?? [];
        $tagIds = [];

        foreach ($tagData as $t) {
            $tag = Tag::firstOrCreate(
                [
                    'slug' => Str::slug($t['name']),
                    'publisher_id' => $publisher->id
                ],
                [
                    'name' => $t['name']
                ]
            );
            $tagIds[] = $tag->id;
        }
        
        //sambung artikel ke tag = isi tabel article_meta
        $article->tags()->sync($tagIds);
    }
}