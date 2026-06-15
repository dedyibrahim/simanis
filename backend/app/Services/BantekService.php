<?php

namespace App\Services;

use App\Models\PenyimpananBantek;
use Illuminate\Support\Facades\DB;

class BantekService
{
    public function index(): array
    {
        $query = PenyimpananBantek::leftJoin('data_clients', 'penyimpanan_bantek.id_client', '=', 'data_clients.id_client')
            ->select(
                'penyimpanan_bantek.id',
                'penyimpanan_bantek.no_bantek',
                'penyimpanan_bantek.lokasi_bantek',
                'penyimpanan_bantek.id_client',
                'data_clients.nama_client',
                'data_clients.no_identitas'
            )
            ->orderBy('penyimpanan_bantek.no_bantek', 'desc')
            ->get();

        return $query->groupBy('no_bantek')->map(function ($rows) {
            $first = $rows->first();

            $clients = $rows
                ->whereNotNull('id_client')
                ->unique('id_client')
                ->map(function ($item) {
                    return [
                        'id_client' => $item->id_client,
                        'nama_client' => $item->nama_client,
                        'no_identitas' => $item->no_identitas,
                    ];
                })
                ->values();

            return [
                'no_bantek' => $first->no_bantek,
                'lokasi_bantek' => $first->lokasi_bantek,
                'total_client' => $clients->count(),
                'clients_list' => $clients->pluck('id_client')->toArray(),
                'clients_detail' => $clients,
            ];
        })->values()->all();
    }

    public function create(string $lokasiBantek, ?string $idClient = null): array
    {
        $lastBantek = DB::table('penyimpanan_bantek')
            ->orderByRaw('LENGTH(no_bantek) DESC')
            ->orderBy('no_bantek', 'desc')
            ->first();

        if ($lastBantek) {
            $lastNumber = (int) substr($lastBantek->no_bantek, 7);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        $noBantek = 'Bantex-' . str_pad((string) $newNumber, 4, '0', STR_PAD_LEFT);

        $data = [
            'no_bantek' => $noBantek,
            'lokasi_bantek' => $lokasiBantek,
            'id_client' => $idClient ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        PenyimpananBantek::create($data);

        return [
            'message' => 'Berhasil membuat Bantek baru: ' . $noBantek,
            'data' => $data,
        ];
    }

    public function addClients(string $noBantek, string $lokasi, array $clientsToAdd): int
    {
        $insertedCount = 0;

        DB::beginTransaction();

        try {
            foreach ($clientsToAdd as $clientId) {
                $exists = PenyimpananBantek::where('no_bantek', $noBantek)
                    ->where('id_client', $clientId)
                    ->exists();

                if (!$exists) {
                    PenyimpananBantek::create([
                        'no_bantek' => $noBantek,
                        'lokasi_bantek' => $lokasi,
                        'id_client' => $clientId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $insertedCount++;
                } else {
                    PenyimpananBantek::where('no_bantek', $noBantek)
                        ->where('id_client', $clientId)
                        ->update(['lokasi_bantek' => $lokasi]);
                }
            }

            if ($insertedCount > 0) {
                PenyimpananBantek::where('no_bantek', $noBantek)
                    ->whereNull('id_client')
                    ->delete();
            }

            DB::commit();
            return $insertedCount;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function removeClient(string $noBantek, string $idClient): bool
    {
        $data = PenyimpananBantek::where('no_bantek', $noBantek)
            ->where('id_client', $idClient)
            ->first();

        if (!$data) {
            return false;
        }

        $totalClient = PenyimpananBantek::where('no_bantek', $noBantek)
            ->whereNotNull('id_client')
            ->count();

        if ($totalClient > 1) {
            $data->delete();
        } else {
            $data->update(['id_client' => null]);
        }

        return true;
    }
}
