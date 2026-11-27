<?php

namespace App\Http\Controllers;

use App\Models\DataClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WordDataController extends Controller
{
    private const BOOKS = [
        ['buku_notaris', 'penghadap_notaris', 'id_buku_notaris'],
        ['buku_ppats', 'penghadap_ppats', 'id_buku_ppat'],
        ['buku_legalisasis', 'penghadap_legalisasis', 'id_buku_legalisasi'],
        ['buku_warmerkings', 'penghadap_warmerkings', 'id_buku_warmerking'],
    ];
    private const KTP = [
        'tempat_tgl_lahir' => 'Tempat/tanggal lahir', 'jenis_kelamin' => 'Jenis kelamin',
        'pekerjaan' => 'Pekerjaan', 'kewarganegaraan' => 'Kewarganegaraan',
        'status_perkawinan' => 'Status perkawinan', 'agama' => 'Agama',
        'alamat' => 'Alamat KTP', 'rt_rw' => 'RT/RW', 'kel_desa' => 'Kelurahan/desa',
        'kecamatan' => 'Kecamatan', 'kabupaten_kota' => 'Kabupaten/kota', 'provinsi' => 'Provinsi',
    ];
    private const PPAT = [
        'no_akta' => 'Nomor akta', 'tanggal_akta' => 'Tanggal akta',
        'no_hak_milik' => 'Nomor hak milik', 'luas_tanah' => 'Luas tanah',
        'luas_bangunan' => 'Luas bangunan', 'harga_transaksi' => 'Harga transaksi',
        'nop' => 'NOP', 'harga_njop' => 'Nilai NJOP', 'tgl_bphtb' => 'Tanggal BPHTB',
        'harga_bphtb' => 'Nilai BPHTB', 'tgl_pph' => 'Tanggal PPh', 'harga_pph' => 'Nilai PPh',
        'keterangan' => 'Keterangan',
    ];

    public function clients(Request $request)
    {
        $actor = $this->actor($request);
        $q = $this->search($request);
        $rows = $this->clientQuery($actor)->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
            $query->where('nama_client', 'like', '%'.$q.'%')->orWhere('no_identitas', 'like', '%'.$q.'%');
        }))->orderBy('nama_client')->limit(25)->get(['id_client', 'nama_client', 'jenis_client']);
        return $this->result($rows);
    }

    public function client(Request $request, string $id)
    {
        $client = $this->clientQuery($this->actor($request))->where('id_client', $id)->firstOrFail();
        return $this->result(['title' => $client->nama_client, 'fields' => $this->clientFields($client)]);
    }

    public function ppat(Request $request)
    {
        $actor = $this->actor($request);
        $q = $this->search($request);
        $books = DB::table('buku_ppats as b')->when(!$this->admin($actor), fn ($query) => $query->where('b.id_user', $actor->id_user))
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('b.no_akta', 'like', '%'.$q.'%')->orWhere('b.no_hak_milik', 'like', '%'.$q.'%')
                    ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('penghadap_ppats as p')
                        ->join('data_clients as c', 'c.id_client', '=', 'p.id_client')
                        ->whereColumn('p.id_buku_ppat', 'b.id_buku_ppat')->where('c.nama_client', 'like', '%'.$q.'%'));
            }))->orderByDesc('b.created_at')->limit(25)
            ->get(['b.id_buku_ppat', 'b.no_akta', 'b.tanggal_akta', 'b.no_hak_milik'])
            ->map(fn ($b) => ['id' => $b->id_buku_ppat, 'source' => 'book',
                'title' => 'Akta '.$b->no_akta.' / '.$b->tanggal_akta.' / '.($b->no_hak_milik ?: '-')]);
        $work = collect();
        if (Schema::hasTable('work_order_items')) {
            $work = DB::table('work_order_items as w')->join('orders as o', 'o.id_order', '=', 'w.id_order')
                ->where('w.type', 'ppat')->whereNull('w.book_id')
                ->when(!$this->admin($actor), fn ($query) => $query->where('w.id_user', $actor->id_user))
                ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                    $query->where('w.title', 'like', '%'.$q.'%')->orWhere('o.nama_pesanan', 'like', '%'.$q.'%')
                        ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('work_order_participants as p')
                            ->join('data_clients as c', 'c.id_client', '=', 'p.id_client')
                            ->whereColumn('p.work_order_item_id', 'w.id')->where('c.nama_client', 'like', '%'.$q.'%'));
                }))->orderByDesc('w.created_at')->limit(25)->get(['w.id', 'w.title', 'o.nama_pesanan'])
                ->map(fn ($w) => ['id' => $w->id, 'source' => 'work', 'title' => $w->nama_pesanan.' / '.$w->title.' (belum bernomor)']);
        }
        return $this->result($work->concat($books)->values());
    }

    public function transaction(Request $request, string $source, string $id)
    {
        $actor = $this->actor($request);
        abort_unless(in_array($source, ['work', 'book'], true), 404);
        $fields = [];
        if ($source === 'work') {
            abort_unless(Schema::hasTable('work_order_items'), 404);
            $item = DB::table('work_order_items')->where('id', $id)->where('type', 'ppat')
                ->when(!$this->admin($actor), fn ($query) => $query->where('id_user', $actor->id_user))->first();
            abort_unless($item, 404);
            if ($item->book_id) return $this->transaction($request, 'book', $item->book_id);
            $details = json_decode((string) $item->details, true) ?: [];
            $title = $item->title;
            $actId = $details['jenis_akta'] ?? null;
            $participants = DB::table('work_order_participants')->where('work_order_item_id', $id)->orderBy('id')->get();
        } else {
            $item = DB::table('buku_ppats')->where('id_buku_ppat', $id)
                ->when(!$this->admin($actor), fn ($query) => $query->where('id_user', $actor->id_user))->first();
            abort_unless($item, 404);
            $details = (array) $item;
            $title = 'Akta PPAT '.$item->no_akta;
            $actId = $item->id_akta;
            $participants = DB::table('penghadap_ppats')->where('id_buku_ppat', $id)->orderBy('id_penghadap_ppat')->get();
        }
        $this->field($fields, 'judul', 'Pekerjaan', $title);
        $this->field($fields, 'jenis_akta', 'Jenis akta', $actId ? DB::table('daftar_aktas')->where('id_akta', $actId)->value('nama_akta') : '');
        foreach (self::PPAT as $key => $label) $this->field($fields, $key, $label, $details[$key] ?? null);
        $this->participantFields($fields, $participants);
        return $this->result(['title' => $title, 'fields' => $fields]);
    }

    public function jobs(Request $request)
    {
        $actor = $this->actor($request);
        $q = $this->search($request);
        $rows = DB::table('work_order_items as w')->join('orders as o', 'o.id_order', '=', 'w.id_order')
            ->when(!$this->admin($actor), fn ($query) => $query->where('w.id_user', $actor->id_user))
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('w.title', 'like', '%'.$q.'%')->orWhere('o.nama_pesanan', 'like', '%'.$q.'%')
                    ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('work_order_participants as p')
                        ->join('data_clients as c', 'c.id_client', '=', 'p.id_client')
                        ->whereColumn('p.work_order_item_id', 'w.id')->where('c.nama_client', 'like', '%'.$q.'%'));
            }))->orderByDesc('w.created_at')->orderByDesc('w.id')->limit(50)
            ->get(['w.id', 'w.type', 'w.title', 'o.nama_pesanan'])
            ->map(fn ($w) => ['id' => $w->id, 'type' => $w->type,
                'title' => strtoupper($w->type).' | '.$w->nama_pesanan.' / '.$w->title]);
        return $this->result($rows);
    }

    public function job(Request $request, string $id)
    {
        $actor = $this->actor($request);
        $item = DB::table('work_order_items')->where('id', $id)
            ->when(!$this->admin($actor), fn ($query) => $query->where('id_user', $actor->id_user))->first();
        abort_unless($item, 404);
        $fields = [];
        $this->field($fields, 'pesanan', 'Pesanan', DB::table('orders')->where('id_order', $item->id_order)->value('nama_pesanan'));
        $this->field($fields, 'jenis_pekerjaan', 'Jenis pekerjaan', strtoupper($item->type));
        if ($item->type === 'ppat') {
            $transaction = $this->transaction($request, 'work', $id)->getData(true)['data'];
            return $this->result(['title' => $item->title, 'fields' => array_merge($fields, $transaction['fields'])]);
        }
        $details = json_decode((string) $item->details, true) ?: [];
        $this->field($fields, 'judul', 'Pekerjaan', $item->title);
        $actId = $details['jenis_akta'] ?? null;
        $this->field($fields, 'jenis_akta', 'Jenis akta', $actId ? DB::table('daftar_aktas')->where('id_akta', $actId)->value('nama_akta') : '');
        foreach (['judul_surat' => 'Judul surat', 'keterangan' => 'Keterangan'] as $key => $label) {
            $this->field($fields, $key, $label, $details[$key] ?? null);
        }
        $participants = DB::table('work_order_participants')->where('work_order_item_id', $id)->orderBy('id')->get();
        $this->participantFields($fields, $participants);
        return $this->result(['title' => $item->title, 'fields' => $fields]);
    }

    private function participantFields(array &$fields, $participants): void
    {
        foreach ($participants as $i => $participant) {
            $client = DataClient::find($participant->id_client);
            if (!$client) continue;
            $prefix = 'penghadap_'.($i + 1).'.';
            $label = 'Penghadap '.($i + 1).' · ';
            foreach ($this->clientFields($client) as $field) {
                $fields[] = ['key' => $prefix.$field['key'], 'label' => $label.$field['label'], 'value' => $field['value']];
            }
            $this->field($fields, $prefix.'kedudukan', $label.'Kedudukan', $participant->status_kedudukan ?? $participant->kedudukan ?? '');
            if ($participant->id_mewakili) {
                $represented = DataClient::find($participant->id_mewakili);
                if ($represented) foreach ($this->clientFields($represented) as $field) {
                    $fields[] = ['key' => $prefix.'mewakili.'.$field['key'], 'label' => $label.'Mewakili · '.$field['label'], 'value' => $field['value']];
                }
            }
        }
    }

    public function closeSession(Request $request)
    {
        $this->actor($request);
        $request->user()->currentAccessToken()?->delete();
        return $this->result(['closed' => true]);
    }

    private function clientQuery(object $actor)
    {
        $query = DataClient::query();
        if ($this->admin($actor)) return $query;
        return $query->where(function ($scope) use ($actor) {
            // Start false, then include clients actually involved in this assistant's work.
            $scope->whereRaw('1 = 0');
            if (Schema::hasTable('work_order_items')) {
                $scope->orWhereExists(fn ($p) => $p->selectRaw('1')->from('work_order_participants as wp')
                    ->join('work_order_items as w', 'w.id', '=', 'wp.work_order_item_id')
                    ->where('w.id_user', $actor->id_user)->where(fn ($q) => $q->whereColumn('wp.id_client', 'data_clients.id_client')
                        ->orWhereColumn('wp.id_mewakili', 'data_clients.id_client')));
            }
            foreach (self::BOOKS as [$book, $people, $key]) {
                if (!Schema::hasTable($book) || !Schema::hasTable($people)) continue;
                $scope->orWhereExists(fn ($p) => $p->selectRaw('1')->from($people.' as p')
                    ->join($book.' as b', 'b.'.$key, '=', 'p.'.$key)->where('b.id_user', $actor->id_user)
                    ->where(fn ($q) => $q->whereColumn('p.id_client', 'data_clients.id_client')->orWhereColumn('p.id_mewakili', 'data_clients.id_client')));
            }
        });
    }

    private function actor(Request $request): object
    {
        $actor = $request->user();
        abort_unless($actor, 401);
        abort_unless($this->admin($actor) || (strtoupper(trim((string) $actor->level_user)) === 'ASISTEN' && !empty($actor->id_user)), 403);
        return $actor;
    }

    private function admin(object $actor): bool
    {
        return in_array(strtoupper(trim((string) $actor->level_user)), ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'], true);
    }

    private function clientFields(DataClient $client): array
    {
        $fields = [];
        foreach (['nama_client' => 'Nama', 'no_identitas' => 'NIK/identitas', 'jenis_client' => 'Jenis client', 'alamat_client' => 'Alamat'] as $key => $label) {
            $this->field($fields, $key, $label, $client->{$key});
        }
        foreach (self::KTP as $key => $label) $this->field($fields, $key, $label, ($client->ktp_data ?? [])[$key] ?? '');
        return $fields;
    }

    private function field(array &$fields, string $key, string $label, mixed $value): void
    {
        if ($value !== null && is_scalar($value) && trim((string) $value) !== '') $fields[] = ['key' => $key, 'label' => $label, 'value' => (string) $value];
    }

    private function search(Request $request): string
    {
        return trim($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');
    }

    private function result(mixed $data)
    {
        return response()->json(['status' => true, 'data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
