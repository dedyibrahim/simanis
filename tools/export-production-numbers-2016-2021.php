<?php

require '/var/www/apinotaris/vendor/autoload.php';

$app = require '/var/www/apinotaris/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$start = '2016-01-01 00:00:00';
$end = '2021-12-31 23:59:59';

$queries = [
    [
        'module' => 'Notaris',
        'table' => 'buku_notaris',
        'id' => 'id_buku_notaris',
        'number' => 'no_akta',
        'date' => 'tgl_akta',
    ],
    [
        'module' => 'Legalisasi',
        'table' => 'buku_legalisasis',
        'id' => 'id_buku_legalisasi',
        'number' => 'no_legalisasi',
        'date' => 'tgl_surat',
    ],
    [
        'module' => 'Warmerking',
        'table' => 'buku_warmerkings',
        'id' => 'id_buku_warmerking',
        'number' => 'no_warmerking',
        'date' => 'tgl_didaftarkan',
    ],
    [
        'module' => 'PPAT',
        'table' => 'buku_ppats',
        'id' => 'id_buku_ppat',
        'number' => 'no_akta',
        'date' => 'tanggal_akta',
    ],
];

$rows = collect();

foreach ($queries as $query) {
    $items = DB::table($query['table'])
        ->select([
            DB::raw("'" . $query['module'] . "' as module"),
            DB::raw($query['id'] . ' as record_id'),
            DB::raw($query['number'] . ' as nomor'),
            DB::raw($query['date'] . ' as tanggal'),
        ])
        ->whereBetween($query['date'], [$start, $end])
        ->whereNotNull($query['number'])
        ->where($query['number'], '<>', '')
        ->orderBy($query['date'])
        ->orderBy($query['number'])
        ->get();

    $rows = $rows->merge($items);
}

echo json_encode($rows->values(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
