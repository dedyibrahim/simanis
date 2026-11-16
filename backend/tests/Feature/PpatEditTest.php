<?php

namespace Tests\Feature;

use App\Http\Controllers\PembuatanNomor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PpatEditTest extends TestCase
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

        Schema::create('daftar_aktas', function (Blueprint $table) {
            $table->string('id_akta')->primary();
            $table->string('nama_akta');
        });
        Schema::create('users', function (Blueprint $table) {
            $table->string('id_user')->primary();
        });
        Schema::create('buku_ppats', function (Blueprint $table) {
            $table->string('id_buku_ppat')->primary();
            $table->unsignedInteger('no_akta');
            $table->date('tanggal_akta');
            $table->string('id_akta')->nullable();
            $table->string('id_user');
            $table->string('status_akta');
            $table->string('no_hak_milik')->nullable();
            $table->decimal('luas_tanah')->nullable();
            $table->decimal('luas_bangunan')->nullable();
            $table->decimal('harga_transaksi')->nullable();
            $table->string('nop')->nullable();
            $table->decimal('harga_njop')->nullable();
            $table->date('tgl_bphtb')->nullable();
            $table->decimal('harga_bphtb')->nullable();
            $table->date('tgl_pph')->nullable();
            $table->decimal('harga_pph')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
        });
        Schema::create('penghadap_ppats', function (Blueprint $table) {
            $table->increments('id');
            $table->string('id_penghadap_ppat')->unique();
            $table->string('id_buku_ppat');
            $table->string('id_client');
            $table->string('status_kedudukan');
            $table->string('id_mewakili')->nullable();
            $table->timestamps();
        });

        DB::table('daftar_aktas')->insert(['id_akta' => 'J_0028', 'nama_akta' => 'Akta Jual Beli (AJB)']);
        DB::table('users')->insert([['id_user' => '0001'], ['id_user' => '0002']]);
        DB::table('buku_ppats')->insert([
            'id_buku_ppat' => 'BKP0000001',
            'no_akta' => 7,
            'tanggal_akta' => '2026-01-01',
            'id_akta' => 'J_0028',
            'id_user' => '0001',
            'status_akta' => 'Selesai',
            'no_hak_milik' => 'SHM 1',
            'luas_tanah' => 100,
            'luas_bangunan' => 50,
            'harga_transaksi' => 500000000,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function editRequest(string $role, array $overrides = []): Request
    {
        $request = Request::create('/api/auth/SimpanNomorPPAT', 'POST', array_merge([
            'id_buku_ppat' => 'BKP0000001',
            'jenis_akta' => 'J_0028',
            'no_hak_milik' => 'SHM 1',
            'luas_tanah' => 100,
            'luas_bangunan' => 50,
            'harga_transaksi' => 500000000,
            'penghadap' => [[
                'id_client' => 'C000001',
                'status_kedudukan' => 'Pembeli',
                'id_mewakili' => null,
            ]],
        ], $overrides));
        $request->setUserResolver(fn () => (object) ['level_user' => $role]);

        return $request;
    }

    public function test_admin_can_change_assignee_without_losing_akta_or_original_metadata(): void
    {
        (new PembuatanNomor())->SimpanNomorPPAT($this->editRequest('Admin', [
            'pengambil_nomor' => '0002',
        ]));

        $row = DB::table('buku_ppats')->first();
        $this->assertSame('J_0028', $row->id_akta);
        $this->assertSame('0002', $row->id_user);
        $this->assertSame('Selesai', $row->status_akta);
        $this->assertSame('2026-01-01 00:00:00', $row->created_at);
    }

    public function test_non_admin_cannot_change_assignee_through_payload(): void
    {
        (new PembuatanNomor())->SimpanNomorPPAT($this->editRequest('Asisten', [
            'pengambil_nomor' => '0002',
        ]));

        $row = DB::table('buku_ppats')->first();
        $this->assertSame('J_0028', $row->id_akta);
        $this->assertSame('0001', $row->id_user);
    }

    public function test_missing_akta_is_rejected_without_changing_record(): void
    {
        try {
            (new PembuatanNomor())->SimpanNomorPPAT($this->editRequest('Admin', [
                'jenis_akta' => '',
            ]));
            $this->fail('Expected validation to reject a missing akta.');
        } catch (ValidationException) {
            $this->assertSame('J_0028', DB::table('buku_ppats')->value('id_akta'));
        }
    }

    public function test_admin_and_super_admin_can_correct_date_without_renumbering(): void
    {
        foreach (['Admin', 'Super Admin'] as $role) {
            (new PembuatanNomor())->SimpanNomorPPAT($this->editRequest($role, [
                'tanggal_akta' => '2026-02-03',
            ]));

            $row = DB::table('buku_ppats')->first();
            $this->assertSame('2026-02-03', $row->tanggal_akta);
            $this->assertSame(7, $row->no_akta);
            $this->assertSame('0001', $row->id_user);
        }
    }

    public function test_non_admin_cannot_change_date_even_with_forged_payload(): void
    {
        (new PembuatanNomor())->SimpanNomorPPAT($this->editRequest('Asisten', [
            'tanggal_akta' => '2026-02-03',
        ]));

        $this->assertSame('2026-01-01', DB::table('buku_ppats')->value('tanggal_akta'));
    }

    public function test_invalid_or_cross_year_date_is_rejected_without_changing_record(): void
    {
        foreach (['2026-02-30', '2027-01-02'] as $date) {
            try {
                (new PembuatanNomor())->SimpanNomorPPAT($this->editRequest('Admin', [
                    'tanggal_akta' => $date,
                ]));
                $this->fail('Expected invalid PPAT date to be rejected.');
            } catch (ValidationException) {
                $this->assertSame('2026-01-01', DB::table('buku_ppats')->value('tanggal_akta'));
                $this->assertSame(7, DB::table('buku_ppats')->value('no_akta'));
            }
        }
    }
}
