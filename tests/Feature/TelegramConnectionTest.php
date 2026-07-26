<?php

namespace Tests\Feature;

use App\Models\User;
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
}
