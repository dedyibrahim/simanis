<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
});
$base = $argv[1] ?? 'http://192.168.0.12:8000';
foreach (App\Services\DocumentStorage::PREFIXES as $prefix) {
    $result = App\Services\DocumentStorage::disk()->getClient()->listObjectsV2([
        'Bucket' => config('filesystems.disks.documents.bucket'), 'Prefix' => $prefix.'/', 'MaxKeys' => 1,
    ]);
    $key = $result['Contents'][0]['Key'];
    $url = $base.'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $size = curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($curl);
    if ($status !== 200 || $size != $result['Contents'][0]['Size']) {
        throw new RuntimeException($prefix.': HTTP '.$status.' or length mismatch');
    }
    echo $prefix.': HTTP 200, Content-Length matches object'.PHP_EOL;
}
