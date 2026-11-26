<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\PembuatanNomor;
use App\Services\GoogleCalendarSyncService;
use App\Services\OrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('id_user', 15)->unique();
            $table->string('nama_lengkap');
            $table->string('level_user');
            $table->string('phone')->nullable();
        });
        Schema::create('data_clients', function (Blueprint $table) {
            $table->string('id_client', 15)->primary();
            $table->string('nama_client');
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->string('id_order', 15)->primary();
            $table->string('nama_pesanan');
            $table->string('id_user', 15);
            $table->text('keterangan_order');
            $table->string('status_order');
            $table->string('no_inv')->nullable();
            $table->string('jenis_invoice')->nullable();
            $table->timestamps();
        });
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->timestamps();
        });
        Schema::create('event_user', function (Blueprint $table) {
            $table->foreignId('event_id');
            $table->foreignId('user_id');
        });
        Schema::create('detail_pesanans', function (Blueprint $table) {
            $table->string('id_detail_pesanan', 15)->primary();
            $table->string('id_order', 15);
            $table->string('id_pekerjaan', 15);
            $table->string('jenis_pekerjaan');
            $table->string('nama_pekerjaan');
            $table->string('no_pekerjaan')->nullable();
            $table->string('pembuat');
            $table->date('tanggal_pekerjaan');
            $table->integer('harga')->nullable();
            $table->timestamps();
        });
        Schema::create('daftar_aktas', function (Blueprint $table) {
            $table->string('id_akta')->primary();
            $table->string('nama_akta');
            $table->string('pekerjaan_milik');
        });
        DB::table('daftar_aktas')->insert([
            ['id_akta' => 'A1', 'nama_akta' => 'Akta Notaris', 'pekerjaan_milik' => 'NOTARIS'],
            ['id_akta' => 'A2', 'nama_akta' => 'Akta PPAT', 'pekerjaan_milik' => 'PPAT'],
        ]);
        foreach ([
            ['notaris', 'notaris', 'no_akta', 'tgl_akta', 'status_akta'],
            ['ppats', 'ppat', 'no_akta', 'tanggal_akta', 'status_akta'],
            ['legalisasis', 'legalisasi', 'no_legalisasi', 'tgl_surat', 'status_legalisasi'],
            ['warmerkings', 'warmerking', 'no_warmerking', 'tgl_didaftarkan', 'status_warmerking'],
        ] as [$suffix, $singular, $number, $date, $status]) {
            Schema::create('buku_'.$suffix, function (Blueprint $table) use ($singular, $number, $date, $status) {
                $table->string('id_buku_'.$singular)->primary();
                $table->string($number);
                $table->date($date);
                $table->string($status)->default('Proses');
                $table->string('id_user')->default('USR1');
                $table->string('id_akta')->nullable();
                $table->string('created_by')->nullable();
                $table->timestamp('tgl_signing')->nullable();
                if ($singular === 'warmerking') $table->date('tgl_surat')->nullable();
                foreach (['judul_pekerjaan', 'judul_surat', 'keterangan_surat', 'nama_client', 'no_hak_milik', 'luas_tanah', 'luas_bangunan', 'harga_transaksi', 'nop', 'harga_njop', 'tgl_bphtb', 'harga_bphtb', 'tgl_pph', 'harga_pph', 'keterangan'] as $column) $table->text($column)->nullable();
                $table->timestamps();
            });
            Schema::create('penghadap_'.$suffix, function (Blueprint $table) use ($singular) {
                $table->string('id_penghadap_'.$singular)->primary();
                $table->string('id_buku_'.$singular);
                $table->string('id_client');
                $table->string('id_mewakili')->nullable();
                $table->string($singular === 'ppat' ? 'status_kedudukan' : 'kedudukan');
                $table->timestamps();
            });
        }
        (require base_path('database/migrations/2026_11_26_100000_create_work_order_items.php'))->up();
        DB::table('users')->insert(['id' => 1, 'id_user' => 'USR1', 'nama_lengkap' => 'Asisten', 'level_user' => 'Asisten']);
        DB::table('data_clients')->insert([
            ['id_client' => 'C1', 'nama_client' => 'Penghadap'],
            ['id_client' => 'C2', 'nama_client' => 'Perusahaan'],
        ]);
    }

    private function request(string $method, string $uri, array $data = []): Request
    {
        $request = Request::create($uri, $method, $data);
        $request->setUserResolver(fn () => (object) ['id' => 1, 'id_user' => 'USR1', 'level_user' => 'Asisten']);
        return $request;
    }

    private function job(string $type, string $title): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'id_user' => 'USR1',
            'details' => ['jenis_akta' => $type === 'ppat' ? 'A2' : 'A1'],
            'penghadap' => [[
                'id_client' => 'C1',
                'status_kedudukan' => 'Direktur',
                'id_mewakili' => 'C2',
            ]],
        ];
    }

    public function test_one_order_can_hold_multiple_independent_jobs(): void
    {
        $controller = app(WorkOrderController::class);
        $result = $controller->store($this->request('POST', '/api/auth/work-orders', [
            'nama_pesanan' => 'Pendirian dan jual beli',
            'id_user' => 'USR1',
            'jobs' => [$this->job('notaris', 'Pendirian'), $this->job('ppat', 'Jual beli')],
        ]));
        $this->assertSame(201, $result->getStatusCode());
        $orderId = $result->getData(true)['data']['id_order'];
        $this->assertSame(2, DB::table('work_order_items')->where('id_order', $orderId)->count());
        $this->assertSame(2, DB::table('work_order_participants')->count());
        $this->assertSame('Proses', DB::table('orders')->where('id_order', $orderId)->value('status_order'));
        try {
            app(OrderService::class)->simpanDetailOrder([], ['id_order' => $orderId]);
            $this->fail('Pesanan dengan pekerjaan belum bernomor tidak boleh ditutup lewat alur lama.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $list = $controller->index($this->request('GET', '/api/auth/work-orders', ['month' => now()->format('Y-m')]))->getData(true)['data'];
        $this->assertCount(1, $list);
        $this->assertCount(2, $list[0]['jobs']);
    }

    public function test_number_requires_signed_job_and_matching_participants(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/api/auth/work-orders', [
            'nama_pesanan' => 'Pendirian', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pendirian')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        $request = $this->request('POST', '/api/auth/work-jobs/'.$id.'/take-number', [
            'work_item_id' => $id,
            'penghadap' => $this->job('notaris', 'Pendirian')['penghadap'],
        ]);
        try {
            WorkOrderController::assertNumberRequest($request, 'notaris');
            $this->fail('Pekerjaan belum TTD seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_item_id', $exception->errors());
        }
        DB::table('work_order_items')->where('id', $id)->update(['status' => 'signed']);
        $this->assertSame($id, WorkOrderController::assertNumberRequest($request, 'notaris')->id);
        WorkOrderController::attachNumber((object) ['id' => $id], 'BKN0000001', '1');
        $this->assertSame('numbered', DB::table('work_order_items')->where('id', $id)->value('status'));
        try {
            WorkOrderController::assertNumberRequest($request, 'notaris');
            $this->fail('Nomor ganda seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_item_id', $exception->errors());
        }
        WorkOrderController::releaseNumber('BKN0000001');
        $this->assertSame('signed', DB::table('work_order_items')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('work_order_items')->where('id', $id)->value('book_id'));
    }

    public function test_schedule_sign_and_complete_keep_order_details_linked(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/api/auth/work-orders', [
            'nama_pesanan' => 'Pendirian', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pendirian')],
        ]));
        $item = DB::table('work_order_items')->first();
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $calendar->shouldReceive('syncEvent')->once()->andReturn(false);
        $controller->schedule($this->request('POST', '/api/auth/work-jobs/'.$item->id.'/schedule', [
            'start_datetime' => '2026-12-01T09:00',
            'end_datetime' => '2026-12-01T10:00',
        ]), $item->id, $calendar);
        $this->assertSame('scheduled', DB::table('work_order_items')->where('id', $item->id)->value('status'));
        $this->assertSame('2026-12-01 09:00:00', DB::table('events')->value('start_datetime'));
        $controller->confirmSigning($this->request('POST', '/api/auth/work-jobs/'.$item->id.'/confirm-signing'), $item->id);
        WorkOrderController::attachNumber((object) ['id' => $item->id], 'BKN0000001', '1');
        DB::table('buku_notaris')->insert(['id_buku_notaris' => 'BKN0000001', 'no_akta' => '1', 'tgl_akta' => '2026-12-01']);
        $controller->complete($this->request('POST', '/api/auth/work-orders/'.$item->id_order.'/complete'), $item->id_order, app(OrderService::class));
        $this->assertSame('Selesai', DB::table('orders')->where('id_order', $item->id_order)->value('status_order'));
        $this->assertSame('BKN0000001', DB::table('detail_pesanans')->value('id_pekerjaan'));
        $this->expectException(ValidationException::class);
        WorkOrderController::releaseNumber('BKN0000001');
    }

    public function test_signing_requires_schedule_and_overlapping_calendar_slot_is_rejected(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/api/auth/work-orders', [
            'nama_pesanan' => 'Dua pekerjaan',
            'id_user' => 'USR1',
            'jobs' => [$this->job('notaris', 'Akta A'), $this->job('ppat', 'Akta B')],
        ]));
        $ids = DB::table('work_order_items')->orderBy('id')->pluck('id');
        try {
            $controller->confirmSigning($this->request('POST', '/sign'), $ids[0]);
            $this->fail('TTD tanpa jadwal seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $calendar->shouldReceive('syncEvent')->once()->andReturn(false);
        $controller->schedule($this->request('POST', '/schedule', [
            'start_datetime' => '2026-12-01T09:00',
            'end_datetime' => '2026-12-01T10:00',
        ]), $ids[0], $calendar);
        $this->expectException(ValidationException::class);
        $controller->schedule($this->request('POST', '/schedule', [
            'start_datetime' => '2026-12-01T09:30',
            'end_datetime' => '2026-12-01T10:30',
        ]), $ids[1], $calendar);
    }

    public function test_number_endpoint_links_the_result_from_existing_book_controller(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/api/auth/work-orders', [
            'nama_pesanan' => 'Pendirian', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pendirian')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        DB::table('work_order_items')->where('id', $id)->update(['status' => 'signed', 'signed_at' => now()]);
        $mock = $this->mock(PembuatanNomor::class);
        $mock->shouldReceive('SimpanNomorNotaris')->once()->andReturn(response()->json([
            'status' => true, 'data' => ['id' => 'BKN0000001', 'number' => '1'],
        ]));
        $response = $controller->takeNumber($this->request('POST', '/take-number', [
            'penghadap' => $this->job('notaris', 'Pendirian')['penghadap'],
        ]), $id);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('BKN0000001', DB::table('work_order_items')->where('id', $id)->value('book_id'));
    }

    public function test_cancel_schedule_keeps_job_and_participants_and_allows_rescheduling(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', [
            'nama_pesanan' => 'Pendirian', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pendirian')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $calendar->shouldReceive('syncEvent')->twice()->andReturn(false);
        $range = ['start_datetime' => '2026-12-01T09:00', 'end_datetime' => '2026-12-01T10:00'];
        $controller->schedule($this->request('POST', '/schedule', $range), $id, $calendar);
        $eventId = DB::table('work_order_items')->value('event_id');
        $controller->cancelSchedule($this->request('POST', '/cancel-schedule'), $id, $calendar);
        $this->assertDatabaseCount('work_order_items', 1);
        $this->assertDatabaseCount('work_order_participants', 1);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_user', 0);
        $this->assertSame('draft', DB::table('work_order_items')->value('status'));
        $this->assertNull(DB::table('work_order_items')->value('event_id'));
        $controller->schedule($this->request('POST', '/schedule', $range), $id, $calendar);
        $this->assertDatabaseCount('events', 1);
        $this->assertNotEquals($eventId, DB::table('work_order_items')->value('event_id'));
        $controller->confirmSigning($this->request('POST', '/confirm-signing'), $id);
        $this->expectException(ValidationException::class);
        $controller->cancelSchedule($this->request('POST', '/cancel-schedule'), $id, $calendar);
    }

    public function test_failed_google_cancellation_keeps_local_schedule(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->string('google_event_id')->nullable());
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', [
            'nama_pesanan' => 'Pendirian', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pendirian')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $calendar->shouldReceive('syncEvent')->once()->andReturn(false);
        $controller->schedule($this->request('POST', '/schedule', [
            'start_datetime' => '2026-12-01T09:00', 'end_datetime' => '2026-12-01T10:00',
        ]), $id, $calendar);
        DB::table('events')->update(['google_event_id' => 'google-1']);
        $calendar->shouldReceive('deleteEvent')->once()->andReturn(false);
        try {
            $controller->cancelSchedule($this->request('POST', '/cancel-schedule'), $id, $calendar);
            $this->fail('Local schedule must remain when Google deletion fails.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('schedule', $exception->errors());
        }
        $this->assertSame('scheduled', DB::table('work_order_items')->value('status'));
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_user', 1);
        $calendar->shouldReceive('deleteEvent')->once()->andReturn(true);
        $controller->cancelSchedule($this->request('POST', '/cancel-schedule'), $id, $calendar);
        $this->assertDatabaseCount('events', 0);
        $this->assertSame('draft', DB::table('work_order_items')->value('status'));
    }

    public function test_invoice_and_detail_save_do_not_complete_new_order_and_completion_keeps_prices(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', [
            'nama_pesanan' => 'Pendirian', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pendirian')],
        ]));
        $item = DB::table('work_order_items')->first();
        DB::table('work_order_items')->where('id', $item->id)->update([
            'status' => 'numbered', 'signed_at' => now(), 'book_id' => 'BKN0000001', 'book_number' => '1',
        ]);
        $records = [[
            'id_pekerjaan' => 'BKN0000001', 'jenis_pekerjaan' => 'notaris', 'nama_pekerjaan' => 'Pendirian',
            'no_pekerjaan' => '1', 'tanggal_pekerjaan' => '2026-12-01', 'pembuat' => 'Asisten', 'harga' => 1500000,
        ]];
        $service = app(OrderService::class);
        $service->simpanDetailOrder($records, ['id_order' => $item->id_order]);
        $this->assertSame('Proses', DB::table('orders')->value('status_order'));
        $invoiceService = $this->partialMock(OrderService::class);
        $invoiceService->shouldReceive('simpanInvoice')->once()->andReturn(['id_invoice_non_tax' => 1]);
        $invoiceService->simpanDetailOrder($records, ['id_order' => $item->id_order], ['status_tax' => false]);
        $this->assertSame('Proses', DB::table('orders')->value('status_order'));
        $this->assertSame('non tax', DB::table('orders')->value('jenis_invoice'));
        DB::table('buku_notaris')->insert(['id_buku_notaris' => 'BKN0000001', 'no_akta' => '1', 'tgl_akta' => '2026-12-01']);
        $controller->complete($this->request('POST', '/complete'), $item->id_order, $service);
        $this->assertSame('2026-12-01', DB::table('detail_pesanans')->value('tanggal_pekerjaan'));
        $this->assertSame('Selesai', DB::table('buku_notaris')->value('status_akta'));
        $this->assertSame('Selesai', DB::table('orders')->value('status_order'));
        $this->assertEquals(1500000, DB::table('detail_pesanans')->value('harga'));
    }

    public function test_legacy_orders_still_save_without_work_order_tables(): void
    {
        Schema::drop('work_order_participants');
        Schema::drop('work_order_items');
        DB::table('orders')->insert([
            'id_order' => 'ORD0000001', 'nama_pesanan' => 'Lama', 'id_user' => 'USR1', 'keterangan_order' => '',
            'status_order' => 'Proses', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(OrderService::class)->simpanDetailOrder([], ['id_order' => 'ORD0000001']);
        $this->assertSame('Selesai', DB::table('orders')->value('status_order'));
    }

    public function test_cancellation_rejects_other_order_owner(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', [
            'nama_pesanan' => 'Pendirian', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pendirian')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        DB::table('work_order_items')->where('id', $id)->update(['status' => 'scheduled']);
        $request = $this->request('POST', '/cancel-schedule');
        $request->setUserResolver(fn () => (object) ['id' => 2, 'id_user' => 'USR2', 'level_user' => 'Asisten']);
        try {
            $controller->cancelSchedule($request, $id, app(GoogleCalendarSyncService::class));
            $this->fail('Other order owner must not cancel this schedule.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('scheduled', DB::table('work_order_items')->value('status'));
    }

    public function test_deleted_calendar_event_cannot_be_confirmed_as_signed(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', [
            'nama_pesanan' => 'Pendirian', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pendirian')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        DB::table('work_order_items')->where('id', $id)->update(['status' => 'scheduled', 'event_id' => null]);
        $this->expectException(ValidationException::class);
        $controller->confirmSigning($this->request('POST', '/confirm-signing'), $id);
    }

    public function test_repeated_invoice_save_preserves_existing_invoice_id_without_client_supplied_id(): void
    {
        foreach (['invoice_taxs', 'invoice_non_taxs'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->string($tableName === 'invoice_taxs' ? 'id_invoice_tax' : 'id_invoice_non_tax')->primary();
                $table->string('id_order');
                foreach (['status_tax', 'tax', 'status_diskon', 'nilai_diskon', 'diskon', 'grand_total'] as $column) $table->integer($column);
                $table->timestamps();
            });
        }
        $service = app(OrderService::class);
        foreach ([true, false] as $tax) {
            $payload = ['id_order' => 'ORD0000001', 'status_tax' => $tax, 'tax' => 0, 'status_diskon' => false, 'nilai_diskon' => 0, 'diskon' => 0, 'grand_total' => 1000];
            $first = $service->simpanInvoice($payload);
            $payload['grand_total'] = 2000;
            $second = $service->simpanInvoice($payload);
            $key = $tax ? 'id_invoice_tax' : 'id_invoice_non_tax';
            $this->assertSame((string) $first[$key], (string) $second[$key]);
            $this->assertDatabaseCount($tax ? 'invoice_taxs' : 'invoice_non_taxs', 1);
        }
    }

    public function test_numbering_with_real_controllers_preserves_assignee_and_complete_updates_all_books(): void
    {
        DB::table('users')->insert(['id' => 2, 'id_user' => 'USR2', 'nama_lengkap' => 'Penanggung Jawab', 'level_user' => 'Asisten']);
        $actor = new \App\Models\User(['id_user' => 'USR1', 'nama_lengkap' => 'Admin', 'level_user' => 'Admin']);
        $actor->id = 1;
        $this->actingAs($actor);
        $this->mock(\App\Services\Waha\WahaClient::class)->shouldReceive('sendMessage')->andReturn([]);
        $bookConfigs = [
            'notaris' => ['notaris', 'BKN', 'PHN', 'no_akta', 'tgl_akta', 'status_akta'],
            'ppat' => ['ppats', 'BKP', 'PHP', 'no_akta', 'tanggal_akta', 'status_akta'],
            'legalisasi' => ['legalisasis', 'BKL', 'PHL', 'no_legalisasi', 'tgl_surat', 'status_legalisasi'],
            'waarmerking' => ['warmerkings', 'BKW', 'PHW', 'no_warmerking', 'tgl_didaftarkan', 'status_warmerking'],
        ];
        foreach ($bookConfigs as $type => [$suffix, $prefix, $personPrefix, $number, $date, $status]) {
            $singular = $type === 'waarmerking' ? 'warmerking' : $type;
            DB::table('buku_'.$suffix)->insert(['id_buku_'.$singular => $prefix.'0010000', $number => '10000', $date => '2026-01-01']);
            DB::table('penghadap_'.$suffix)->insert(['id_penghadap_'.$singular => $personPrefix.'0010000', 'id_buku_'.$singular => $prefix.'0010000', 'id_client' => 'C1', $type === 'ppat' ? 'status_kedudukan' : 'kedudukan' => 'Diri sendiri']);
        }
        $controller = app(WorkOrderController::class);
        $jobs = [];
        foreach (['notaris', 'ppat', 'legalisasi', 'waarmerking'] as $type) {
            $job = $this->job($type, 'Pekerjaan '.$type);
            $job['id_user'] = 'USR2';
            $jobs[] = $job;
        }
        $controller->store($this->request('POST', '/work-orders', ['nama_pesanan' => 'Semua jenis', 'id_user' => 'USR1', 'jobs' => $jobs]));
        $orderId = DB::table('orders')->value('id_order');
        foreach (DB::table('work_order_items')->get() as $item) {
            DB::table('work_order_items')->where('id', $item->id)->update(['status' => 'signed', 'signed_at' => '2026-01-01 09:00:00']);
            $data = $item->type === 'ppat'
                ? ['jenis_akta' => 'A2', 'apht' => 'TRUE', 'sudah_tanda_tangan' => true, 'no_hak_milik' => 'SHM1', 'luas_tanah' => 10, 'luas_bangunan' => 0, 'harga_transaksi' => 1000]
                : ['data_buku' => ['jenis_akta' => 'A1', 'judul_pekerjaan' => $item->title, 'judul_surat' => $item->title, 'tgl_akta' => '2026-12-01', 'sudah_tanda_tangan' => true, 'nama_asisten' => 'USR1']];
            $data['penghadap'] = $this->job($item->type, $item->title)['penghadap'];
            $request = $this->request('POST', '/take-number', $data);
            $request->setUserResolver(fn () => $actor);
            $result = $controller->takeNumber($request, $item->id)->getData(true);
            $this->assertTrue($result['status']);
            $this->assertSame($bookConfigs[$item->type][1].'0010001', $result['data']['id']);
        }
        $controller->complete($this->request('POST', '/complete'), $orderId, app(OrderService::class));
        foreach (['buku_notaris' => 'status_akta', 'buku_ppats' => 'status_akta', 'buku_legalisasis' => 'status_legalisasi', 'buku_warmerkings' => 'status_warmerking'] as $table => $statusColumn) {
            $this->assertSame('USR2', DB::table($table)->orderByDesc('created_at')->value('id_user'));
            $this->assertSame('Selesai', DB::table($table)->orderByDesc('created_at')->value($statusColumn));
        }
        $this->assertSame('2026-12-01', DB::table('detail_pesanans')->where('jenis_pekerjaan', 'notaris')->value('tanggal_pekerjaan'));
        $this->assertDatabaseCount('detail_pesanans', 4);
        foreach ($bookConfigs as $type => [$suffix, $prefix, $personPrefix, $number, $date, $status]) {
            $singular = $type === 'waarmerking' ? 'warmerking' : $type;
            $this->assertSame('Proses', DB::table('buku_'.$suffix)->where('id_buku_'.$singular, $prefix.'0010000')->value($status));
            $this->assertSame($personPrefix.'0010001', DB::table('penghadap_'.$suffix)->orderByDesc('id_penghadap_'.$singular)->value('id_penghadap_'.$singular));
        }
    }

    public function test_number_endpoint_rejects_attempt_to_update_an_existing_book(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', ['nama_pesanan' => 'Test', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Test')]]));
        $id = DB::table('work_order_items')->value('id');
        DB::table('work_order_items')->where('id', $id)->update(['status' => 'signed']);
        $this->expectException(ValidationException::class);
        $controller->takeNumber($this->request('POST', '/take-number', ['data_buku' => ['id_buku_notaris' => 'BKN0000001']]), $id);
    }

    public function test_work_order_rejects_incompatible_act_negative_amount_and_duplicate_or_self_representing_participants(): void
    {
        $controller = app(WorkOrderController::class);
        foreach (['wrong_act', 'missing_act', 'negative', 'self', 'duplicate'] as $case) {
            $job = $this->job('notaris', 'Test');
            $key = 'jobs.0.details.jenis_akta';
            if ($case === 'wrong_act') $job['details']['jenis_akta'] = 'A2';
            if ($case === 'missing_act') $job['details'] = [];
            if ($case === 'negative') { $job['details']['harga_transaksi'] = -1; $key = 'jobs.0.details.harga_transaksi'; }
            if ($case === 'self') { $job['penghadap'][0]['id_mewakili'] = 'C1'; $key = 'jobs.0.penghadap.0.id_mewakili'; }
            if ($case === 'duplicate') { $job['penghadap'][] = $job['penghadap'][0]; $key = 'jobs.0.penghadap.1.id_client'; }
            try {
                $controller->store($this->request('POST', '/work-orders', ['nama_pesanan' => 'Test', 'id_user' => 'USR1', 'jobs' => [$job]]));
                $this->fail('Invalid job accepted: '.$case);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors(), $case);
            }
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_list_returns_current_schedule_when_event_is_rescheduled(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', ['nama_pesanan' => 'Test', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Test')]]));
        $id = DB::table('work_order_items')->value('id');
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $calendar->shouldReceive('syncEvent')->twice()->andReturn(false);
        $controller->schedule($this->request('POST', '/schedule', ['start_datetime' => '2026-12-01T09:00', 'end_datetime' => '2026-12-01T10:00', 'location' => 'Ruang A']), $id, $calendar);
        $eventId = DB::table('work_order_items')->value('event_id');
        $controller->schedule($this->request('POST', '/schedule', ['start_datetime' => '2026-12-02T11:00', 'end_datetime' => '2026-12-02T12:00', 'location' => 'Ruang B']), $id, $calendar);
        $data = $controller->index($this->request('GET', '/work-orders', ['month' => now()->format('Y-m')]))->getData(true)['data'][0]['jobs'][0];
        $this->assertSame($eventId, $data['event_id']);
        $this->assertSame('2026-12-02 11:00:00', $data['scheduled_start']);
        $this->assertSame('2026-12-02 12:00:00', $data['scheduled_end']);
        $this->assertSame('Ruang B', $data['scheduled_location']);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_delete_unnumbered_job_preserves_other_jobs_and_last_delete_removes_empty_order(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', ['nama_pesanan' => 'Test', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Pertama'), $this->job('ppat', 'Kedua')]]));
        $items = DB::table('work_order_items')->orderBy('id')->get();
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $controller->destroy($this->request('DELETE', '/work-jobs/'.$items[0]->id), $items[0]->id, $calendar);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('work_order_items', 1);
        $this->assertDatabaseCount('work_order_participants', 1);
        $this->assertSame('Kedua', DB::table('work_order_items')->value('title'));
        DB::table('work_order_items')->update(['status' => 'signed', 'signed_at' => now()]);
        $controller->destroy($this->request('DELETE', '/work-jobs/'.$items[1]->id), $items[1]->id, $calendar);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('work_order_items', 0);
        $this->assertDatabaseCount('work_order_participants', 0);
        $this->assertDatabaseCount('data_clients', 2);
    }

    public function test_numbered_job_and_other_owner_cannot_be_deleted(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', ['nama_pesanan' => 'Test', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Test')]]));
        $id = DB::table('work_order_items')->value('id');
        $request = $this->request('DELETE', '/work-jobs/'.$id);
        $request->setUserResolver(fn () => (object) ['id' => 2, 'id_user' => 'USR2', 'level_user' => 'Asisten']);
        try {
            $controller->destroy($request, $id, app(GoogleCalendarSyncService::class));
            $this->fail('Other owner must not delete job.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        DB::table('work_order_items')->update(['status' => 'numbered', 'book_id' => 'BKN0000001', 'book_number' => '1']);
        try {
            $controller->destroy($this->request('DELETE', '/work-jobs/'.$id), $id, app(GoogleCalendarSyncService::class));
            $this->fail('Numbered job must not be deleted.');
        } catch (ValidationException $e) { $this->assertArrayHasKey('status', $e->errors()); }
        $this->assertDatabaseCount('work_order_items', 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_job_deletion_removes_calendar_but_rolls_back_if_google_deletion_fails(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->string('google_event_id')->nullable());
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', ['nama_pesanan' => 'Test', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Test')]]));
        $id = DB::table('work_order_items')->value('id');
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $calendar->shouldReceive('syncEvent')->once()->andReturn(false);
        $controller->schedule($this->request('POST', '/schedule', ['start_datetime' => '2026-12-01T09:00', 'end_datetime' => '2026-12-01T10:00']), $id, $calendar);
        DB::table('events')->update(['google_event_id' => 'google-1']);
        $calendar->shouldReceive('deleteEvent')->once()->andReturn(false);
        try {
            $controller->destroy($this->request('DELETE', '/work-jobs/'.$id), $id, $calendar);
            $this->fail('Failed Google delete must preserve work.');
        } catch (ValidationException $e) { $this->assertArrayHasKey('schedule', $e->errors()); }
        $this->assertDatabaseCount('work_order_items', 1);
        $this->assertDatabaseCount('events', 1);
        $calendar->shouldReceive('deleteEvent')->once()->andReturn(true);
        $controller->destroy($this->request('DELETE', '/work-jobs/'.$id), $id, $calendar);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_user', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_last_job_is_preserved_if_order_has_documents(): void
    {
        Schema::create('dokumen_orders', function (Blueprint $table) { $table->id(); $table->string('id_order'); });
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/work-orders', ['nama_pesanan' => 'Test', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Test')]]));
        $item = DB::table('work_order_items')->first();
        DB::table('dokumen_orders')->insert(['id_order' => $item->id_order]);
        try {
            $controller->destroy($this->request('DELETE', '/work-jobs/'.$item->id), $item->id, app(GoogleCalendarSyncService::class));
            $this->fail('Order documents must be preserved.');
        } catch (ValidationException $e) { $this->assertArrayHasKey('status', $e->errors()); }
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('work_order_items', 1);
        $this->assertDatabaseCount('dokumen_orders', 1);
    }

    public function test_stage_notifications_deduplicate_phones_and_wait_for_commit(): void
    {
        $controller = app(WorkOrderController::class);
        $controller->store($this->request('POST', '/api/work-orders', [
            'nama_pesanan' => 'Monitor', 'id_user' => 'USR1',
            'jobs' => [$this->job('notaris', 'Akta monitor')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        DB::table('users')->where('id_user', 'USR1')->update(['phone' => '08123456789']);
        DB::table('users')->insert([
            ['id_user' => 'ADMIN1', 'nama_lengkap' => 'Admin', 'level_user' => 'Admin', 'phone' => '+62 8123456789'],
            ['id_user' => 'ADMIN2', 'nama_lengkap' => 'Super', 'level_user' => 'Super Admin', 'phone' => '08111111111'],
            ['id_user' => 'OTHER', 'nama_lengkap' => 'Other', 'level_user' => 'Asisten', 'phone' => '08222222222'],
        ]);
        $sent = [];
        $this->mock(\App\Services\Waha\WahaClient::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendMessage')->andReturnUsing(function ($phone, $message, $context) use (&$sent) {
                $sent[] = compact('phone', 'message', 'context');
                return [];
            });
        });
        $service = app(\App\Services\Waha\WorkOrderNotificationService::class);
        DB::beginTransaction();
        $service->stage($id, 'TTD dikonfirmasi');
        $this->assertCount(0, $sent);
        DB::rollBack();
        $this->assertCount(0, $sent);
        DB::transaction(function () use ($service, $id, &$sent) {
            $service->stage($id, 'Nomor berhasil diambil');
            $this->assertCount(0, $sent);
        });
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Akta monitor', $sent[0]['message']);
        $this->assertSame('work_order.stage', $sent[0]['context']['source']);
    }

    public function test_notification_failure_does_not_prevent_saving_or_other_recipients(): void
    {
        DB::table('users')->where('id_user', 'USR1')->update(['phone' => '08123456789']);
        DB::table('users')->insert(['id_user' => 'ADMIN1', 'nama_lengkap' => 'Admin', 'level_user' => 'Admin', 'phone' => '08111111111']);
        $this->mock(\App\Services\Waha\WahaClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->once()->with('08123456789', \Mockery::any(), \Mockery::any())->andThrow(new \RuntimeException('Gateway down'));
            $mock->shouldReceive('sendMessage')->once()->with('08111111111', \Mockery::any(), \Mockery::any())->andReturn([]);
        });
        $response = app(WorkOrderController::class)->store($this->request('POST', '/api/work-orders', [
            'nama_pesanan' => 'Monitor', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Akta monitor')],
        ]));
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(1, DB::table('work_order_items')->count());
    }

    public function test_calendar_and_work_order_share_create_update_delete_and_reschedule(): void
    {
        $work = app(WorkOrderController::class);
        $work->store($this->request('POST', '/work', [
            'nama_pesanan' => 'Kalender', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Signing')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $calendar->shouldReceive('syncEvent')->times(3)->andReturn(true);
        $work->schedule($this->request('POST', '/schedule', [
            'start_datetime' => '2026-12-01T09:00', 'end_datetime' => '2026-12-01T10:00',
        ]), $id, $calendar);
        $eventId = DB::table('work_order_items')->value('event_id');
        $events = app(\App\Http\Controllers\EventController::class);
        $this->assertSame($eventId, $events->index()->getData(true)['data'][0]['id']);
        $events->update($this->request('PUT', '/events/'.$eventId, [
            'start_datetime' => '2026-12-02T11:00', 'end_datetime' => '2026-12-02T12:00', 'location' => 'Kantor',
        ]), $eventId);
        $this->assertSame(1, DB::table('events')->count());
        $list = $work->index($this->request('GET', '/work', ['month' => now()->format('Y-m')]))->getData(true);
        $this->assertSame('2026-12-02 11:00:00', $list['data'][0]['jobs'][0]['scheduled_start']);
        $this->actingAs(\App\Models\User::where('id_user', 'USR1')->first());
        $events->destroy($eventId);
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(0, DB::table('event_user')->count());
        $this->assertSame('draft', DB::table('work_order_items')->value('status'));
        $this->assertNull(DB::table('work_order_items')->value('event_id'));
        $this->assertSame(1, DB::table('work_order_items')->count());
        $work->schedule($this->request('POST', '/schedule', [
            'start_datetime' => '2026-12-03T09:00', 'end_datetime' => '2026-12-03T10:00',
        ]), $id, $calendar);
        $work->destroy($this->request('DELETE', '/work/'.$id), $id, $calendar);
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(0, DB::table('event_user')->count());
    }

    public function test_calendar_cannot_delete_signed_work_schedule(): void
    {
        $work = app(WorkOrderController::class);
        $work->store($this->request('POST', '/work', [
            'nama_pesanan' => 'Kalender', 'id_user' => 'USR1', 'jobs' => [$this->job('notaris', 'Signing')],
        ]));
        $id = DB::table('work_order_items')->value('id');
        $calendar = $this->mock(GoogleCalendarSyncService::class);
        $calendar->shouldReceive('syncEvent')->once()->andReturn(true);
        $work->schedule($this->request('POST', '/schedule', [
            'start_datetime' => '2026-12-01T09:00', 'end_datetime' => '2026-12-01T10:00',
        ]), $id, $calendar);
        $work->confirmSigning($this->request('POST', '/sign'), $id);
        $this->actingAs(\App\Models\User::where('id_user', 'USR1')->first());
        try {
            app(\App\Http\Controllers\EventController::class)->destroy(DB::table('events')->value('id'));
            $this->fail('Jadwal yang sudah TTD tidak boleh dihapus dari kalender.');
        } catch (ValidationException $exception) {
            $this->assertSame(1, DB::table('events')->count());
            $this->assertSame('signed', DB::table('work_order_items')->value('status'));
        }
    }
}
