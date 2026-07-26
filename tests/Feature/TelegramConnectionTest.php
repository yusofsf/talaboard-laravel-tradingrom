<?php

namespace Tests\Feature;

use App\Models\{TelegramConnectionCode, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_connect_telegram_once_with_a_short_lived_code(): void
    {
        $user = User::factory()->create();
        $issued = $this->actingAs($user)->postJson('/api/v1/telegram/connect-code')->assertOk();
        $code = $issued->json('code');

        $this->postJson('/api/v1/telegram/connect', [
            'code' => $code,
            'telegram_user_id' => '123456789',
            'telegram_chat_id' => '123456789',
            'telegram_username' => 'talaboard_user',
        ])->assertOk()->assertJsonPath('connected', true);

        $this->assertDatabaseHas('telegram_connections', ['user_id' => $user->id, 'telegram_user_id' => '123456789']);
        $this->postJson('/api/v1/telegram/connect', ['code' => $code, 'telegram_user_id' => '123456789', 'telegram_chat_id' => '123456789'])->assertUnprocessable();
    }

    public function test_connection_code_expires_after_ten_minutes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/telegram/connect-code')->assertOk();
        $code = TelegramConnectionCode::firstOrFail();

        $this->assertSame(
            $code->created_at->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
            $code->expires_at->format('Y-m-d H:i:s'),
        );
    }
}
