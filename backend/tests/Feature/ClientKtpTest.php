<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ClientKtpTest extends TestCase
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

        Schema::create('data_clients', function (Blueprint $table) {
            $table->string('id_client')->primary();
            $table->string('no_identitas')->unique();
            $table->boolean('has_npwp')->default(true);
            $table->string('nama_client');
            $table->string('jenis_client');
            $table->text('alamat_client')->nullable();
            $table->json('ktp_data')->nullable();
            $table->string('pembuat_client');
            $table->string('nama_folder')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
        Schema::create('number_counters', function (Blueprint $table) {
            $table->string('key_name')->primary();
            $table->unsignedInteger('last_number');
            $table->timestamps();
        });
        Schema::create('tb_berkas', function (Blueprint $table) {
            $table->string('id_berkas')->primary();
            $table->string('id_client');
            $table->string('id_dokumen')->nullable();
            $table->string('id_user');
            $table->string('nama_berkas');
            $table->string('nama_dokumen');
            $table->timestamps();
        });

        $this->actingAs(new User(['id_user' => '0001']));
    }

    private function personalPayload(): array
    {
        return [
            'no_identitas' => '3174010101900001',
            'has_npwp' => true,
            'nama_client' => 'Siti Rahma',
            'jenis_client' => 'Perorangan',
            'ktp_data' => [
                'provinsi' => 'DKI Jakarta',
                'kabupaten_kota' => 'Jakarta Selatan',
                'tempat_tgl_lahir' => 'Jakarta, 01-01-1990',
                'jenis_kelamin' => 'Perempuan',
                'gol_darah' => '',
                'alamat' => 'Jl. Melati No. 1',
                'rt_rw' => '001/002',
                'kel_desa' => 'Pondok Pinang',
                'kecamatan' => 'Kebayoran Lama',
                'agama' => 'Islam',
                'status_perkawinan' => 'Kawin',
                'pekerjaan' => 'Wiraswasta',
                'kewarganegaraan' => 'WNI',
                'berlaku_hingga' => 'Seumur Hidup',
            ],
        ];
    }

    private function save(array $payload)
    {
        return (new ClientController())->SimpanClientBaru(
            Request::create('/api/auth/SimpanClientBaru', 'POST', $payload)
        );
    }

    public function test_personal_client_requires_valid_nik_and_complete_ktp(): void
    {
        $invalidCases = [
            ['no_identitas', ['no_identitas' => '12345']],
            ['nama_client', ['nama_client' => '']],
        ];
        foreach (array_keys($this->personalPayload()['ktp_data']) as $field) {
            if ($field === 'gol_darah') {
                continue;
            }
            $invalidCases[] = [
                'ktp_data.'.$field,
                ['ktp_data' => array_replace($this->personalPayload()['ktp_data'], [$field => ''])],
            ];
        }

        foreach ($invalidCases as [$field, $override]) {
            try {
                $this->save(array_replace($this->personalPayload(), $override));
                $this->fail("Expected validation error for {$field}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertDatabaseCount('data_clients', 0);
    }

    public function test_personal_client_stores_ktp_details_without_changing_identity_contract(): void
    {
        $response = $this->save($this->personalPayload());

        $this->assertSame(200, $response->getStatusCode());
        $client = DB::table('data_clients')->first();
        $this->assertSame('C000001', $client->id_client);
        $this->assertSame('3174010101900001', $client->no_identitas);
        $this->assertSame('Perorangan', $client->jenis_client);
        $this->assertStringContainsString('Jakarta Selatan', $client->alamat_client);
        $this->assertSame('Wiraswasta', json_decode($client->ktp_data, true)['pekerjaan']);

        $updated = $this->personalPayload();
        $updated['id_client'] = $client->id_client;
        $updated['ktp_data']['pekerjaan'] = 'Pegawai Swasta';
        $this->assertSame(200, $this->save($updated)->getStatusCode());
        $this->assertDatabaseCount('data_clients', 1);
        $this->assertSame('Pegawai Swasta', json_decode(DB::table('data_clients')->value('ktp_data'), true)['pekerjaan']);
    }

    public function test_business_client_does_not_require_ktp_details(): void
    {
        $response = $this->save([
            'no_identitas' => '123456789012345',
            'has_npwp' => true,
            'nama_client' => 'PT Contoh',
            'jenis_client' => 'Badan Hukum',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(DB::table('data_clients')->value('ktp_data'));
    }

    public function test_ocr_save_requires_complete_personal_ktp(): void
    {
        $payload = $this->personalPayload();
        $payload['ktp_data']['kabupaten_kota'] = '';
        $request = Request::create(
            '/api/auth/ktp-ocr/save-client',
            'POST',
            $payload,
            [],
            ['ktp_image' => UploadedFile::fake()->image('ktp.jpg')]
        );

        try {
            (new ClientController())->saveKtpOcrClient($request);
            $this->fail('Expected validation error for missing kabupaten/kota');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ktp_data.kabupaten_kota', $exception->errors());
        }

        $this->assertDatabaseCount('data_clients', 0);
    }

    public function test_one_form_saves_client_and_ktp_image_on_create_and_edit(): void
    {
        config()->set('filesystems.documents_disk', 'documents_local');
        config()->set('filesystems.documents_peer_disk', null);
        Storage::fake('documents_local');

        $payload = $this->personalPayload();
        $payload['has_npwp'] = '1';
        $request = Request::create('/api/auth/SimpanClientBaru', 'POST', $payload, [], [
            'ktp_image' => UploadedFile::fake()->image('ktp.jpg'),
        ]);
        $this->assertSame(200, (new ClientController())->SimpanClientBaru($request)->getStatusCode());
        $client = DB::table('data_clients')->first();
        $this->assertSame('C000001', $client->id_client);
        $this->assertDatabaseCount('tb_berkas', 1);
        $firstFile = DB::table('tb_berkas')->value('nama_berkas');
        Storage::disk('documents_local')->assertExists('berkasclient/DokC000001/'.$firstFile);

        $updated = $this->personalPayload();
        $updated['has_npwp'] = '1';
        $updated['id_client'] = $client->id_client;
        $updated['ktp_data']['pekerjaan'] = 'Pegawai Swasta';
        $request = Request::create('/api/auth/SimpanClientBaru', 'POST', $updated, [], [
            'ktp_image' => UploadedFile::fake()->image('ktp-baru.jpg'),
        ]);
        $this->assertSame(200, (new ClientController())->SimpanClientBaru($request)->getStatusCode());
        $this->assertDatabaseCount('data_clients', 1);
        $this->assertDatabaseCount('tb_berkas', 2);
        $this->assertSame('Pegawai Swasta', json_decode(DB::table('data_clients')->value('ktp_data'), true)['pekerjaan']);
    }

    public function test_one_form_rejects_non_image_ktp_upload(): void
    {
        $request = Request::create('/api/auth/SimpanClientBaru', 'POST', $this->personalPayload(), [], [
            'ktp_image' => UploadedFile::fake()->create('catatan.txt', 1, 'text/plain'),
        ]);

        try {
            (new ClientController())->SimpanClientBaru($request);
            $this->fail('Expected validation error for non-image upload');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ktp_image', $exception->errors());
        }

        $this->assertDatabaseCount('data_clients', 0);
    }
}
