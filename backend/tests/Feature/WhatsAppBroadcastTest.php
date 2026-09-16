<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Waha\WahaClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WhatsAppBroadcastTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $mysqlDatabase = getenv('SIMANIS_BROADCAST_TEST_DATABASE');
        if ($mysqlDatabase) {
            if (!preg_match('/^simanis_broadcast_test_[a-z0-9_]+$/', $mysqlDatabase)) {
                throw new \RuntimeException('Nama database pengujian tidak aman.');
            }
            config(['database.default' => 'mysql', 'database.connections.mysql.database' => $mysqlDatabase]);
            DB::purge('mysql');
            Schema::dropIfExists('whatsapp_broadcast_deliveries');
            Schema::dropIfExists('users');
        } else {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        }
        config(['cache.default' => 'array']);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nama_lengkap');
            $table->string('level_user');
            $table->string('status')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_09_16_030000_create_whatsapp_broadcast_deliveries_table.php'))->up();
    }

    private function person(string $role, string $status = '1', string $phone = '081234567890'): User
    {
        $user = new User();
        $user->forceFill(['nama_lengkap' => $role, 'level_user' => $role, 'status' => $status, 'phone' => $phone])->save();
        return $user;
    }

    private function payload(User $recipient): array
    {
        return ['batch_id' => 'd3dbb123-a129-4b75-9ec7-f460d41f554e', 'recipient_id' => $recipient->id, 'message' => 'Pengumuman pengujian'];
    }

    public function test_only_superadmin_can_access_all_endpoints(): void
    {
        $this->getJson('/api/auth/whatsapp-broadcast/assistants')->assertUnauthorized();
        foreach (['Admin', 'Asisten'] as $role) {
            $this->actingAs($this->person($role), 'sanctum');
            $this->getJson('/api/auth/whatsapp-broadcast/assistants')->assertForbidden();
            $this->getJson('/api/auth/whatsapp-broadcast/history')->assertForbidden();
            $this->postJson('/api/auth/whatsapp-broadcast/send', [])->assertForbidden();
        }
    }

    public function test_lists_active_users_from_all_levels_and_flags_missing_numbers(): void
    {
        $this->actingAs($this->person('Super Admin'), 'sanctum');
        $this->person('Asisten');
        $this->person('Asisten', '0');
        $this->person('Asisten', '1', '');
        $this->person('Admin');
        $this->person('Operator');
        $this->getJson('/api/auth/whatsapp-broadcast/assistants')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonFragment(['phone' => '6281234567890', 'available' => true])
            ->assertJsonFragment(['level' => 'Admin'])
            ->assertJsonFragment(['level' => 'Operator'])
            ->assertJsonFragment(['level' => 'Super Admin'])
            ->assertJsonFragment(['phone' => '', 'available' => false]);
    }

    public function test_duplicate_request_and_shared_phone_are_sent_once(): void
    {
        $this->actingAs($this->person('Super Admin'), 'sanctum');
        $recipient = $this->person('Asisten');
        $this->mock(WahaClient::class)->shouldReceive('sendMessage')->once()->andReturn(['success' => true]);
        $payload = $this->payload($recipient);
        $this->postJson('/api/auth/whatsapp-broadcast/send', $payload)->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson('/api/auth/whatsapp-broadcast/send', $payload)->assertOk();
        $payload['recipient_id'] = $this->person('Asisten')->id;
        $this->postJson('/api/auth/whatsapp-broadcast/send', $payload)->assertOk();
        $payload['message'] = 'Pesan berbeda';
        $this->postJson('/api/auth/whatsapp-broadcast/send', $payload)->assertStatus(409);
        $this->assertSame(1, DB::table('whatsapp_broadcast_deliveries')->count());
    }

    public function test_rejects_invalid_recipients_and_blank_messages_without_sending(): void
    {
        $this->actingAs($this->person('Super Admin'), 'sanctum');
        foreach ([$this->person('Asisten', '0'), $this->person('Asisten', '1', '')] as $recipient) {
            $this->postJson('/api/auth/whatsapp-broadcast/send', $this->payload($recipient))->assertStatus(422);
        }
        $admin = $this->person('Admin');
        $this->mock(WahaClient::class)->shouldReceive('sendMessage')->once()->andReturn(['success' => true]);
        $this->postJson('/api/auth/whatsapp-broadcast/send', $this->payload($admin))->assertOk()->assertJsonPath('data.status', 'sent');
        $payload = $this->payload($this->person('Asisten'));
        $payload['message'] = '   ';
        $this->postJson('/api/auth/whatsapp-broadcast/send', $payload)->assertStatus(422);
    }

    public function test_gateway_failure_is_recorded_and_not_retried(): void
    {
        $this->actingAs($this->person('Super Admin'), 'sanctum');
        $this->mock(WahaClient::class)->shouldReceive('sendMessage')->once()->andReturn(['success' => false, 'status' => 'failed']);
        $payload = $this->payload($this->person('Asisten'));
        $this->postJson('/api/auth/whatsapp-broadcast/send', $payload)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->postJson('/api/auth/whatsapp-broadcast/send', $payload)->assertOk()->assertJsonPath('data.status', 'failed');
        $this->getJson('/api/auth/whatsapp-broadcast/history')->assertOk()->assertJsonCount(1, 'data');
    }
}
