<?php

namespace App\Http\Controllers;

use App\Models\DataClient;
use App\Models\PenyimpananBantek;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends ApiController
{
    public function searchForChatbot(Request $request)
    {
        if ($request->header('X-API-Key') !== env('INTERNAL_API_KEY')) {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'jenis_client' => ['nullable', 'string', 'in:Perorangan,Badan Hukum'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $keyword = trim((string) $validated['q']);
        $jenisClient = trim((string) ($validated['jenis_client'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 10);

        $query = DataClient::query()
            ->leftJoin('users', 'data_clients.pembuat_client', '=', 'users.id_user')
            ->select(
                'data_clients.id_client',
                'data_clients.no_identitas',
                'data_clients.nama_client',
                'data_clients.jenis_client',
                'data_clients.alamat_client',
                'data_clients.contact_number',
                'data_clients.email',
                'users.nama_lengkap as pembuat_client'
            )
            ->where(function ($q) use ($keyword) {
                $q->where('data_clients.nama_client', 'like', '%'.$keyword.'%')
                    ->orWhere('data_clients.no_identitas', 'like', '%'.$keyword.'%')
                    ->orWhere('data_clients.alamat_client', 'like', '%'.$keyword.'%')
                    ->orWhere('data_clients.contact_number', 'like', '%'.$keyword.'%')
                    ->orWhere('data_clients.email', 'like', '%'.$keyword.'%');
            });

        if ($jenisClient !== '') {
            $query->where('data_clients.jenis_client', $jenisClient);
        }

        $rows = $query
            ->orderBy('data_clients.id_client', 'desc')
            ->limit($limit)
            ->get();

        $clientIds = $rows->pluck('id_client')->filter()->unique()->values();
        $bantekRows = collect();
        if ($clientIds->isNotEmpty()) {
            $bantekRows = PenyimpananBantek::query()
                ->whereIn('id_client', $clientIds->all())
                ->get(['id_client', 'no_bantek', 'lokasi_bantek']);
        }

        $bantekByClient = $bantekRows
            ->groupBy('id_client')
            ->map(function ($items) {
                return $items
                    ->map(function ($row) {
                        return [
                            'no_bantek' => (string) $row->no_bantek,
                            'lokasi_bantek' => (string) ($row->lokasi_bantek ?? ''),
                        ];
                    })
                    ->unique(function ($item) {
                        return ($item['no_bantek'] ?? '').'|'.($item['lokasi_bantek'] ?? '');
                    })
                    ->values()
                    ->all();
            });

        $enrichedRows = $rows->map(function ($row) use ($bantekByClient) {
            $key = (string) ($row->id_client ?? '');
            $dataBantek = $key !== '' ? ($bantekByClient->get($key, [])) : [];

            return [
                'id_client' => $row->id_client,
                'no_identitas' => $row->no_identitas,
                'nama_client' => $row->nama_client,
                'jenis_client' => $row->jenis_client,
                'alamat_client' => $row->alamat_client,
                'contact_number' => $row->contact_number,
                'email' => $row->email,
                'pembuat_client' => $row->pembuat_client,
                'data_bantek' => $dataBantek,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => $enrichedRows->isEmpty()
                ? 'Data client tidak ditemukan.'
                : 'Berhasil menemukan data client.',
            'data' => $enrichedRows,
        ], 200);
    }

    public function getDataClient(Request $request)
    {
        $data = DataClient::where('jenis_client', $request->post('jenis_client'))
        ->leftJoin('users', 'data_clients.pembuat_client', '=', 'users.id_user')
        ->orderBy('id_client', 'DESC')
        ->select(
            'data_clients.id_client',
            'data_clients.no_identitas',
            'data_clients.nama_client',
            'data_clients.jenis_client',
            'data_clients.alamat_client',
            'users.nama_lengkap as pembuat_client',
            'data_clients.nama_folder',
            'data_clients.contact_number',
            'data_clients.email',
        )
        ->get();
        foreach ($data as $r) {
            $result[] = [
                'id_client' => $r->id_client,
                'no_identitas' => $r->no_identitas,
                'nama_client' => $r->nama_client,
                'jenis_client' => $r->jenis_client,
                'alamat_client' => $r->alamat_client,
                'pembuat_client' => $r->pembuat_client,
                'nama_folder' => $r->nama_folder,
                'contact_number' => $r->contact_number,
                'email' => $r->email,
                'data_bantek' => PenyimpananBantek::where('id_client', $r->id_client)->get()->toArray(),
            ];
        }

        return $this->successResponse($result, 'Berhasil mengambil data client');
    }

    public function SimpanClientBaru(Request $request)
    {
        if ($request->post('id_client')) {
            $request->validate([
                'nama_client' => 'required|',
            ]);

            $data = [
            'nama_client' => $request->post('nama_client'),
            'no_identitas' => $request->post('no_identitas'),
            'jenis_client' => $request->post('jenis_client'),
            'alamat_client' => $request->post('alamat_client'),
            'pembuat_client' => auth()->user()->id_user,
            'email' => $request->post('email'),
            'contact_number' => $request->post('contact_number'),
            ];

            DataClient::where('id_client', $request->post('id_client'))->update($data);

            $response = [
                'status' => true,
                'message' => 'Update Client Berhasil',
                'data' => [],
            ];

            return response($response, 200);
        } else {
            $request->validate([
                'no_identitas' => 'required|unique:data_clients',
                'nama_client' => 'required|',
            ]);

            $client = DB::table('data_clients')
            ->orderBy('id_client', 'desc')
            ->limit(1)
            ->first();

            if (isset($client->id_client)) {
                $urutan = (int) substr($client->id_client, 1) + 1;
            } else {
                $urutan = 1;
            }

            $id_client = 'C'.str_pad($urutan, 6, 0, STR_PAD_LEFT);

            if (!file_exists('berkasclient/Dok'.$id_client)) {
                mkdir('berkasclient/Dok'.$id_client, 0777);
            }

            $data = [
            'id_client' => $id_client,
            'nama_client' => $request->post('nama_client'),
            'no_identitas' => $request->post('no_identitas'),
            'jenis_client' => $request->post('jenis_client'),
            'alamat_client' => $request->post('alamat_client'),
            'pembuat_client' => auth()->user()->id_user,
            'email' => $request->post('email'),
            'nama_folder' => 'Dok'.$id_client,
            'contact_number' => $request->post('contact_number'),
            ];

            DataClient::create($data);
            $response = [
               'status' => true,
               'message' => 'Add New Client Successfully',
               'data' => [],
    ];

            return response($response, 200);
        }
    }
}
