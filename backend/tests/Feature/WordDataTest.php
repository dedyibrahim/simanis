<?php
namespace Tests\Feature;

use App\Http\Controllers\WordDataController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WordDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('sqlite');
        Schema::create('data_clients', function (Blueprint $t) {
            $t->string('id_client')->primary(); $t->string('nama_client'); $t->string('no_identitas');
            $t->string('jenis_client'); $t->string('alamat_client'); $t->json('ktp_data')->nullable(); $t->string('email');
        });
        foreach (['C1' => 'Client sendiri', 'C2' => 'Client lain', 'C3' => 'Perseroan diwakili'] as $id => $name) {
            DB::table('data_clients')->insert(['id_client' => $id, 'nama_client' => $name, 'no_identitas' => '123',
                'jenis_client' => 'Perorangan', 'alamat_client' => 'Alamat contoh',
                'ktp_data' => json_encode(['pekerjaan' => 'Wiraswasta', 'secret' => 'JANGAN KIRIM']), 'email' => 'private@example.test']);
        }
        Schema::create('orders', function (Blueprint $t) { $t->string('id_order')->primary(); $t->string('nama_pesanan'); });
        DB::table('orders')->insert(['id_order' => 'O1', 'nama_pesanan' => 'Transaksi']);
        Schema::create('work_order_items', function (Blueprint $t) {
            $t->id(); $t->string('id_order'); $t->string('type'); $t->string('title'); $t->string('id_user');
            $t->json('details'); $t->string('book_id')->nullable(); $t->timestamps();
        });
        Schema::create('work_order_participants', function (Blueprint $t) {
            $t->id(); $t->integer('work_order_item_id'); $t->string('id_client'); $t->string('id_mewakili')->nullable(); $t->string('status_kedudukan');
        });
        foreach (['U1' => 'C1', 'U2' => 'C2'] as $owner => $client) {
            $id = DB::table('work_order_items')->insertGetId(['id_order' => 'O1', 'type' => 'ppat', 'title' => 'Jual beli '.$owner,
                'id_user' => $owner, 'details' => json_encode(['jenis_akta' => 'A1', 'harga_transaksi' => 125000000, 'no_hak_milik' => 'SHM-1']), 'created_at' => now()]);
            DB::table('work_order_participants')->insert(['work_order_item_id' => $id, 'id_client' => $client,
                'id_mewakili' => $owner === 'U1' ? 'C3' : null, 'status_kedudukan' => 'Penjual']);
        }
        Schema::create('buku_ppats', function (Blueprint $t) {
            $t->string('id_buku_ppat')->primary(); $t->string('id_user'); $t->string('id_akta');
            $t->integer('no_akta'); $t->date('tanggal_akta'); $t->string('no_hak_milik'); $t->integer('harga_transaksi'); $t->timestamps();
        });
        Schema::create('penghadap_ppats', function (Blueprint $t) {
            $t->string('id_penghadap_ppat')->primary(); $t->string('id_buku_ppat'); $t->string('id_client');
            $t->string('id_mewakili')->nullable(); $t->string('status_kedudukan');
        });
        DB::table('buku_ppats')->insert(['id_buku_ppat' => 'B1', 'id_user' => 'U2', 'id_akta' => 'A1', 'no_akta' => 7,
            'tanggal_akta' => '2026-11-27', 'no_hak_milik' => 'SHM-lain', 'harga_transaksi' => 900000000, 'created_at' => now()]);
        Schema::create('daftar_aktas', function (Blueprint $t) { $t->string('id_akta'); $t->string('nama_akta'); });
        DB::table('daftar_aktas')->insert(['id_akta' => 'A1', 'nama_akta' => 'Jual Beli']);
    }

    private function request(string $role = 'Asisten'): Request
    {
        return Request::create('/')->setUserResolver(fn () => (object) ['id_user' => 'U1', 'level_user' => $role]);
    }

    public function test_assistant_only_lists_own_participants_and_represented_party(): void
    {
        $response = (new WordDataController())->clients($this->request());
        $this->assertSame(['C1', 'C3'], array_column($response->getData(true)['data'], 'id_client'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $data = (new WordDataController())->client($this->request(), 'C1')->getData(true)['data'];
        $this->assertContains('pekerjaan', array_column($data['fields'], 'key'));
        $this->assertStringNotContainsString('JANGAN KIRIM', json_encode($data));
        $this->assertStringNotContainsString('private@example', json_encode($data));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new WordDataController())->client($this->request(), 'C2');
    }

    public function test_transaction_data_includes_roles_representatives_and_values_without_cross_owner_access(): void
    {
        $controller = new WordDataController();
        $list = $controller->ppat($this->request())->getData(true)['data'];
        $this->assertCount(1, $list);
        $this->assertSame('work', $list[0]['source']);
        $data = $controller->transaction($this->request(), 'work', '1')->getData(true)['data'];
        $fields = array_column($data['fields'], 'value', 'key');
        $this->assertSame('125000000', $fields['harga_transaksi']);
        $this->assertSame('Penjual', $fields['penghadap_1.kedudukan']);
        $this->assertSame('Perseroan diwakili', $fields['penghadap_1.mewakili.nama_client']);
        foreach ([['work', '2'], ['book', 'B1']] as [$source, $id]) {
            try { $controller->transaction($this->request(), $source, $id); $this->fail('Cross-owner access must be rejected'); }
            catch (HttpException $e) { $this->assertSame(404, $e->getStatusCode()); }
        }
    }

    public function test_admin_can_read_all_and_arsip_cannot_use_word_data(): void
    {
        $controller = new WordDataController();
        foreach (['Admin', 'Super Admin', 'SuperAdmin'] as $role) {
            $this->assertCount(3, $controller->clients($this->request($role))->getData(true)['data']);
            $data = $controller->transaction($this->request($role), 'book', 'B1')->getData(true)['data'];
            $this->assertContains('900000000', array_column($data['fields'], 'value'));
        }
        try { $controller->ppat($this->request('Arsip')); $this->fail('Arsip denied'); }
        catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->getJson('/api/auth/word/clients')->assertStatus(401);
    }
}
