<?php

namespace App\Services;

class ArticleProcessor
{
    #hapus blok "baca-juga" dan mengganti tag gambar
    public function cleanContent(string $content, array $photos): string
    {
        #1 hapus blok"baca-juga"
        #cari pola "p-strong-class-read-others" hingga penutup p
        $content = preg_replace('/<p><strong class="read__others">.*?<\/strong><\/p>/s', '', $content);

        #2 ganti "tag-img" dengan "url-foto"
        #cari pola img angka
        foreach ($photos as $index => $photo) {
            $tag = '<!--img' . ($index + 1) . '-->';
            #ambil url dari array foto json
            $url = $photo['src'] ?? '';
            $content = str_replace($tag, $url, $content);
        }

        return $content;
    }
}