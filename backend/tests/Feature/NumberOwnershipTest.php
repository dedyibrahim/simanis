<?php
namespace Tests\Feature;

use App\Http\Controllers\PembuatanNomor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NumberOwnershipTest extends TestCase
{
    private const BOOKS = [
        ['Notaris', 'buku_notaris', 'id_buku_notaris', 'EditAktaNotaris', 'data_buku.', 'id_user'],
        ['PPAT', 'buku_ppats', 'id_buku_ppat', 'EditAktaPPAT', '', 'id_user'],
        ['Legalisasi', 'buku_legalisasis', 'id_buku_legalisasi', 'EditLegalisasi', 'data_buku.', 'id_user'],
        ['Warmerking', 'buku_warmerkings', 'id_buku_warmerking', 'EditWarmerking', 'data_buku.', 'id_user'],
        ['SuratNotaris', 'buku_surat_notaris', 'id_surat_notaris', 'EditSuratNotaris', '', 'pengirim'],
        ['SuratPPAT', 'buku_surat_ppats', 'id_surat_ppat', 'EditSuratPPAT', '', 'pengirim'],
    ];
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('sqlite');
        foreach (self::BOOKS as [$name, $table, $key, $edit, $prefix, $owner]) {
            Schema::create($table, function (Blueprint $t) use ($key, $owner) {
                $t->string($key)->primary(); $t->string($owner);
            });
            DB::table($table)->insert([$key => 'BOOK1', $owner => 'OWNER']);
        }
    }
    public function test_assistant_cannot_load_edit_save_or_delete_another_users_number(): void
    {
        foreach (self::BOOKS as [$name, $table, $key, $edit, $prefix]) {
            foreach ([$edit, 'SimpanNomor'.$name, 'DeleteNomor'.$name] as $method) {
                $path = str_starts_with($method, 'Simpan') ? $prefix : '';
                $data = $path ? ['data_buku' => [$key => 'BOOK1']] : [$key => 'BOOK1'];
                $request = Request::create('/', 'POST', $data);
                $request->setUserResolver(fn () => (object) ['id_user' => 'OTHER', 'level_user' => 'Asisten']);
                try {
                    (new PembuatanNomor())->$method($request);
                    $this->fail($method.' must reject another owner');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode(), $method);
                    $this->assertSame('OWNER', DB::table($table)->value($name === 'SuratNotaris' || $name === 'SuratPPAT' ? 'pengirim' : 'id_user'));
                }
            }
        }
    }
    public function test_policy_allows_owner_and_admins_but_denies_arsip_even_for_own_number(): void
    {
        $policy = new \ReflectionMethod(PembuatanNomor::class, 'assertNumberOwner');
        $policy->setAccessible(true);
        foreach (self::BOOKS as [$name, $table, $key, $edit, $prefix, $owner]) {
            foreach (['Asisten', 'Admin', 'Super Admin', 'SuperAdmin'] as $role) {
                $request = Request::create('/');
                $request->setUserResolver(fn () => (object) ['id_user' => $role === 'Asisten' ? 'OWNER' : 'OTHER', 'level_user' => $role]);
                $policy->invoke(new PembuatanNomor(), $request, $table, $key, 'BOOK1', $owner);
                $this->addToAssertionCount(1);
            }
            $request->setUserResolver(fn () => (object) ['id_user' => 'OWNER', 'level_user' => 'Arsip']);
            try {
                $policy->invoke(new PembuatanNomor(), $request, $table, $key, 'BOOK1', $owner);
                $this->fail('Arsip must be read-only');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }
}
