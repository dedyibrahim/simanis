<?php

namespace Tests\Feature;

use App\Http\Controllers\PembuatanNomor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookDateEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('sqlite');

        foreach (['legalisasis', 'warmerkings'] as $type) {
            Schema::create('buku_'.$type, function (Blueprint $table) use ($type) {
                $table->string('id_buku_'.substr($type, 0, -1))->primary();
                $table->unsignedInteger('no_'.substr($type, 0, -1));
                $table->string('id_user');
                $table->string('judul_surat');
                $table->string('nama_client')->nullable();
                $table->text('keterangan_surat')->nullable();
                $table->date('tgl_surat');
                if ($type === 'warmerkings') {
                    $table->date('tgl_didaftarkan');
                }
                $table->timestamps();
            });
            Schema::create('penghadap_'.$type, function (Blueprint $table) use ($type) {
                $table->increments('id');
                $table->string('id_penghadap_'.substr($type, 0, -1))->unique();
                $table->string('id_buku_'.substr($type, 0, -1));
                $table->string('id_client');
                $table->string('id_mewakili')->nullable();
                $table->string('kedudukan');
                $table->timestamps();
            });
        }

        Schema::create('data_clients', function (Blueprint $table) {
            $table->string('id_client')->primary();
            $table->string('nama_client');
            $table->string('no_identitas');
            $table->string('jenis_client');
        });
        DB::table('data_clients')->insert(['id_client' => 'C000001', 'nama_client' => 'Contoh', 'no_identitas' => '123', 'jenis_client' => 'Perorangan']);
        foreach (['legalisasis' => 'BKL0000001', 'warmerkings' => 'BKW0000001'] as $type => $id) {
            $singular = substr($type, 0, -1);
            $row = [
                'id_buku_'.$singular => $id,
                'no_'.$singular => 7,
                'id_user' => '0001',
                'judul_surat' => 'Dokumen Contoh',
                'tgl_surat' => '2026-09-02',
                'created_at' => '2026-09-01 08:00:00',
                'updated_at' => '2026-09-01 08:00:00',
            ];
            if ($type === 'warmerkings') $row['tgl_didaftarkan'] = '2026-09-03';
            DB::table('buku_'.$type)->insert($row);
        }
    }

    private function save(string $module, string $role, array $dates = []): void
    {
        $isLegalisasi = $module === 'Legalisasi';
        $idField = $isLegalisasi ? 'id_buku_legalisasi' : 'id_buku_warmerking';
        $request = Request::create('/api/auth/SimpanNomor'.$module, 'POST', [
            'data_buku' => array_merge([
                $idField => $isLegalisasi ? 'BKL0000001' : 'BKW0000001',
                'judul_surat' => 'Dokumen Contoh',
                'keterangan_surat' => '',
            ], $dates),
            'penghadap' => [[
                'id_client' => 'C000001',
                'status_kedudukan' => 'Pemohon',
            ]],
        ]);
        $request->setUserResolver(fn () => (object) ['level_user' => $role]);
        $method = 'SimpanNomor'.$module;
        (new PembuatanNomor())->$method($request);
    }

    private function row(string $module): object
    {
        return DB::table($module === 'Legalisasi' ? 'buku_legalisasis' : 'buku_warmerkings')->first();
    }

    public function test_admin_and_super_admin_can_edit_legalisasi_date_without_changing_number_or_creator(): void
    {
        foreach (['Admin', 'Super Admin'] as $role) {
            $this->save('Legalisasi', $role, ['tgl_surat' => '2026-09-08']);
            $row = $this->row('Legalisasi');
            $this->assertSame('2026-09-08', $row->tgl_surat);
            $this->assertSame(7, $row->no_legalisasi);
            $this->assertSame('0001', $row->id_user);
            $this->assertSame('2026-09-01 08:00:00', $row->created_at);
        }
    }

    public function test_admins_can_edit_waarmerking_dates_independently(): void
    {
        $this->save('Warmerking', 'Admin', ['tgl_surat' => '2026-09-08']);
        $this->assertSame('2026-09-03', $this->row('Warmerking')->tgl_didaftarkan);

        $this->save('Warmerking', 'Super Admin', ['tgl_didaftarkan' => '2026-09-09']);
        $row = $this->row('Warmerking');
        $this->assertSame('2026-09-08', $row->tgl_surat);
        $this->assertSame('2026-09-09', $row->tgl_didaftarkan);
        $this->assertSame(7, $row->no_warmerking);
        $this->assertSame('0001', $row->id_user);
        $this->assertSame('2026-09-01 08:00:00', $row->created_at);
    }

    public function test_other_roles_cannot_change_dates_with_forged_payload(): void
    {
        $this->save('Legalisasi', 'Asisten', ['tgl_surat' => '2026-09-08']);
        $this->save('Warmerking', 'Asisten', ['tgl_surat' => '2026-09-08', 'tgl_didaftarkan' => '2026-09-09']);

        $this->assertSame('2026-09-02', $this->row('Legalisasi')->tgl_surat);
        $this->assertSame('2026-09-02', $this->row('Warmerking')->tgl_surat);
        $this->assertSame('2026-09-03', $this->row('Warmerking')->tgl_didaftarkan);
        $this->assertSame('0001', $this->row('Legalisasi')->id_user);
        $this->assertSame('0001', $this->row('Warmerking')->id_user);
    }

    public function test_invalid_dates_are_rejected_before_data_changes(): void
    {
        foreach (['Legalisasi' => 'tgl_surat', 'Warmerking' => 'tgl_didaftarkan'] as $module => $field) {
            try {
                $this->save($module, 'Admin', [$field => '2026-02-30']);
                $this->fail('Expected invalid date to be rejected.');
            } catch (ValidationException) {
                $row = $this->row($module);
                $this->assertSame($module === 'Legalisasi' ? '2026-09-02' : '2026-09-03', $row->$field);
            }
        }
    }

    public function test_edit_endpoints_return_existing_dates_for_the_form(): void
    {
        $controller = new PembuatanNomor();
        $legalisasi = $controller->EditLegalisasi(Request::create('/', 'POST', [
            'id_buku_legalisasi' => 'BKL0000001',
        ]))->getData(true)['data'];
        $warmerking = $controller->EditWarmerking(Request::create('/', 'POST', [
            'id_buku_warmerking' => 'BKW0000001',
        ]))->getData(true)['data'];

        $this->assertSame('2026-09-02', $legalisasi['tgl_surat']);
        $this->assertSame('2026-09-02', $warmerking['tgl_surat']);
        $this->assertSame('2026-09-03', $warmerking['tgl_didaftarkan']);
    }

    public function test_client_autocomplete_accepts_two_characters_and_get_query_parameters(): void
    {
        $request = Request::create('/api/auth/getDaftarClient', 'GET', ['nama_penghadap' => 'Co']);
        $response = (new PembuatanNomor())->getDaftarClient($request)->getData(true);
        $this->assertCount(1, $response['data']);
        $this->assertSame('C000001', $response['data'][0]['id_client']);
        $request = Request::create('/api/auth/getDaftarClient', 'POST', ['nama_penghadap' => ' C ']);
        $this->assertSame([], (new PembuatanNomor())->getDaftarClient($request)->getData(true)['data']);
    }
}
