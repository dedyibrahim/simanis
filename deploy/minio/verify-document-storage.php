<?php

// Run with Laravel's project root as the working directory.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

use App\Services\DocumentStorage;
use Illuminate\Http\UploadedFile;

if (config('filesystems.documents_disk') !== 'documents') {
    throw new RuntimeException('MinIO disk is not enabled.');
}
$name = 'storage-probe-'.bin2hex(random_bytes(12)).'.txt';
$body = 'SIMANIS MinIO storage probe '.$name;
$tmp = tempnam(sys_get_temp_dir(), 'simanis-probe-');
file_put_contents($tmp, $body);
try {
    foreach (DocumentStorage::PREFIXES as $prefix) {
        $result = DocumentStorage::disk()->getClient()->listObjectsV2([
            'Bucket' => config('filesystems.disks.documents.bucket'),
            'Prefix' => $prefix.'/',
            'MaxKeys' => 1,
        ]);
        $existing = $result['Contents'][0]['Key'] ?? null;
        if (!$existing) throw new RuntimeException('No existing document in '.$prefix);
        $stream = DocumentStorage::disk()->readStream($existing);
        if (!is_resource($stream)) throw new RuntimeException('Existing document unreadable');
        $sample = fread($stream, 32);
        fclose($stream);
        if ($sample === false) throw new RuntimeException('Existing document read failed');
        $key = $prefix.'/'.$name;
        $copy = $prefix.'/copy-'.$name;
        try {
            DocumentStorage::upload(new UploadedFile($tmp, $name, 'text/plain', null, true), $prefix, $name);
            if (!DocumentStorage::exists($key)) throw new RuntimeException('Upload missing');
            if (file_get_contents(DocumentStorage::temporaryFile($key)) !== $body) throw new RuntimeException('Read mismatch');
            if (!DocumentStorage::copy($key, $copy)) throw new RuntimeException('Copy failed');
            if (file_get_contents(DocumentStorage::temporaryFile($copy)) !== $body) throw new RuntimeException('Copy mismatch');
        } finally {
            DocumentStorage::delete($key);
            DocumentStorage::delete($copy);
        }
        if (DocumentStorage::exists($key) || DocumentStorage::exists($copy)) throw new RuntimeException('Delete failed');
        echo $prefix.": existing document + upload/read/copy/delete OK\n";
    }
} finally {
    unlink($tmp);
}
