<?php

namespace App\Services;

use App\Models\detail_pesanan;
use App\Models\invoice_non_taxs;
use App\Models\invoice_taxs;
use App\Models\orders;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function simpanDetailOrder(array $record, array $dataOrder, ?array $invoice = null): array
    {
        $createdRows = [];
        $lastDetailId = $this->getLastDetailSequence();

        DB::beginTransaction();

        try {
            if ($invoice) {
                $invoiceData = $this->simpanInvoice($invoice);

                if (!empty($invoice['status_tax'])) {
                    orders::where('id_order', $dataOrder['id_order'])->update([
                        'no_inv' => $invoiceData['id_invoice_tax'],
                        'jenis_invoice' => 'tax',
                    ]);
                } else {
                    orders::where('id_order', $dataOrder['id_order'])->update([
                        'no_inv' => $invoiceData['id_invoice_non_tax'],
                        'jenis_invoice' => 'non tax',
                    ]);
                }
            }

            if (isset($dataOrder['id_order'])) {
                detail_pesanan::where('id_order', $dataOrder['id_order'])->delete();
            }

            foreach ($record as $item) {
                $lastDetailId++;
                $payload = [
                    'id_detail_pesanan' => $this->buildDetailId($lastDetailId),
                    'id_order' => $dataOrder['id_order'],
                    'id_pekerjaan' => $item['id_pekerjaan'],
                    'jenis_pekerjaan' => $item['jenis_pekerjaan'],
                    'nama_pekerjaan' => $item['nama_pekerjaan'],
                    'no_pekerjaan' => $item['no_pekerjaan'],
                    'harga' => $item['harga'] ?? 0,
                    'tanggal_pekerjaan' => $item['tanggal_pekerjaan'],
                    'pembuat' => $item['pembuat'],
                ];

                detail_pesanan::create($payload);
                $createdRows[] = $payload;
            }

            orders::where('id_order', $dataOrder['id_order'])->update([
                'status_order' => 'Selesai',
            ]);

            if ($invoice) {
                $this->markReferencedJobsAsCompleted($record);
            }

            DB::commit();
            return $createdRows;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function simpanInvoice(array $payload): array
    {
        if (!empty($payload['status_tax'])) {
            $exists = invoice_taxs::where('id_order', $payload['id_order'])->exists();
            $data = [
                'id_invoice_tax' => $exists ? $payload['id_invoice_tax'] : $this->pembuatanNomorTax(),
                'id_order' => $payload['id_order'],
                'status_tax' => $payload['status_tax'],
                'tax' => $payload['tax'],
                'status_diskon' => $payload['status_diskon'],
                'nilai_diskon' => $payload['nilai_diskon'],
                'diskon' => $payload['diskon'],
                'grand_total' => $payload['grand_total'],
            ];

            if ($exists) {
                invoice_taxs::where('id_order', $payload['id_order'])->update($data);
            } else {
                invoice_taxs::create($data);
            }

            return $data;
        }

        $exists = invoice_non_taxs::where('id_order', $payload['id_order'])->exists();
        $data = [
            'id_invoice_non_tax' => $exists ? $payload['id_invoice_non_tax'] : $this->pembuatanNomorNonTax(),
            'id_order' => $payload['id_order'],
            'status_tax' => $payload['status_tax'],
            'tax' => $payload['tax'],
            'status_diskon' => $payload['status_diskon'],
            'nilai_diskon' => $payload['nilai_diskon'],
            'diskon' => $payload['diskon'],
            'grand_total' => $payload['grand_total'],
        ];

        if ($exists) {
            invoice_non_taxs::where('id_order', $payload['id_order'])->update($data);
        } else {
            invoice_non_taxs::create($data);
        }

        return $data;
    }

    public function pembuatanNomorTax(): int
    {
        $no = DB::table('invoice_taxs')
            ->whereYear('created_at', date('Y'))
            ->orderBy('invoice_taxs.id_invoice_tax', 'DESC')
            ->first();

        if (empty($no->id_invoice_tax)) {
            return 1;
        }

        return (int) $no->id_invoice_tax + 1;
    }

    public function pembuatanNomorNonTax(): int
    {
        $no = DB::table('invoice_non_taxs')
            ->whereYear('created_at', date('Y'))
            ->orderBy('invoice_non_taxs.id_invoice_non_tax', 'DESC')
            ->first();

        if (empty($no->id_invoice_non_tax)) {
            return 1;
        }

        return (int) $no->id_invoice_non_tax + 1;
    }

    public function updateStatusInvoice(array $payload): void
    {
        if (!empty($payload['total_invoice'][0]['status_tax'])) {
            invoice_taxs::where('id_order', $payload['id_order'])->update([
                'status_invoice' => $payload['total_invoice'][0]['status_invoice'],
            ]);
        } else {
            invoice_non_taxs::where('id_order', $payload['id_order'])->update([
                'status_invoice' => $payload['total_invoice'][0]['status_invoice'],
            ]);
        }

        orders::where('id_order', $payload['id_order'])->update([
            'ket_noinv' => $payload['ket_noinv'],
        ]);
    }

    private function getLastDetailSequence(): int
    {
        $latest = DB::table('detail_pesanans')
            ->orderBy('id_detail_pesanan', 'DESC')
            ->value('id_detail_pesanan');

        if (!$latest) {
            return 0;
        }

        return (int) substr($latest, 3);
    }

    private function buildDetailId(int $sequence): string
    {
        return 'DTP' . str_pad((string) $sequence, 7, '0', STR_PAD_LEFT);
    }

    private function markReferencedJobsAsCompleted(array $record): void
    {
        foreach ($record as $item) {
            if (!is_array($item)) {
                continue;
            }

            $idPekerjaan = trim((string) ($item['id_pekerjaan'] ?? ''));
            $jenisPekerjaan = strtolower(trim((string) ($item['jenis_pekerjaan'] ?? '')));
            $noPekerjaan = trim((string) ($item['no_pekerjaan'] ?? ''));

            if ($idPekerjaan === '' && $noPekerjaan === '') {
                continue;
            }

            if ($this->isJobTypeMatch($idPekerjaan, $jenisPekerjaan, ['bkp', 'ppat'])) {
                $this->updateJobCompletionStatus(
                    'buku_ppats',
                    'id_buku_ppat',
                    $idPekerjaan,
                    'no_akta',
                    $noPekerjaan,
                    'status_akta'
                );
                continue;
            }

            if ($this->isJobTypeMatch($idPekerjaan, $jenisPekerjaan, ['bkn', 'notaris', 'akta'])) {
                $this->updateJobCompletionStatus(
                    'buku_notaris',
                    'id_buku_notaris',
                    $idPekerjaan,
                    'no_akta',
                    $noPekerjaan,
                    'status_akta'
                );
                continue;
            }

            if ($this->isJobTypeMatch($idPekerjaan, $jenisPekerjaan, ['bkl', 'legalisasi'])) {
                $this->updateJobCompletionStatus(
                    'buku_legalisasis',
                    'id_buku_legalisasi',
                    $idPekerjaan,
                    'no_legalisasi',
                    $noPekerjaan,
                    'status_legalisasi'
                );
                continue;
            }

            if ($this->isJobTypeMatch($idPekerjaan, $jenisPekerjaan, ['bkw', 'warmerking', 'waarmerking'])) {
                $this->updateJobCompletionStatus(
                    'buku_warmerkings',
                    'id_buku_warmerking',
                    $idPekerjaan,
                    'no_warmerking',
                    $noPekerjaan,
                    'status_warmerking'
                );
            }
        }
    }

    private function isJobTypeMatch(string $idPekerjaan, string $jenisPekerjaan, array $keywords): bool
    {
        $normalizedId = strtoupper($idPekerjaan);

        foreach ($keywords as $keyword) {
            $normalizedKeyword = strtoupper($keyword);

            if ($normalizedId !== '' && str_starts_with($normalizedId, $normalizedKeyword)) {
                return true;
            }

            if ($jenisPekerjaan !== '' && str_contains($jenisPekerjaan, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function updateJobCompletionStatus(
        string $table,
        string $idField,
        string $idValue,
        string $nomorField,
        string $nomorValue,
        string $statusField
    ): void {
        $query = DB::table($table);

        if ($idValue !== '') {
            $query->where($idField, $idValue);
        } elseif ($nomorValue !== '') {
            $query->where($nomorField, $nomorValue);
        } else {
            return;
        }

        $query->update([$statusField => 'Selesai']);
    }
}
