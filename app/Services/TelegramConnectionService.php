<?php

namespace App\Services;

use App\Models\{TelegramConnection, TelegramConnectionCode, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TelegramConnectionService
{
    public function issueCode(User $user): array
    {
        // Codes are intentionally short for chat input; only their SHA-256 digest is persisted.
        return DB::transaction(function () use ($user) {
            TelegramConnectionCode::where('user_id', $user->id)->whereNull('used_at')->delete();
            do {
                $code = (string) random_int(100000, 999999);
                $hash = hash('sha256', $code);
            } while (TelegramConnectionCode::where('code_hash', $hash)->exists());

            $record = TelegramConnectionCode::create([
                'user_id' => $user->id,
                'code_hash' => $hash,
                'expires_at' => now()->addMinutes(10),
            ]);

            return ['code' => $code, 'expires_at' => $record->expires_at];
        });
    }

    public function connect(string $code, string $telegramUserId, string $telegramChatId, ?string $username = null): TelegramConnection
    {
        return DB::transaction(function () use ($code, $telegramUserId, $telegramChatId, $username) {
            $code = TelegramConnectionCode::where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
            if (! $code || $code->used_at || $code->expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => 'کد اتصال نامعتبر است یا اعتبار آن تمام شده است.']);
            }

            $existing = TelegramConnection::where('telegram_user_id', $telegramUserId)->lockForUpdate()->first();
            if ($existing && $existing->user_id !== $code->user_id) {
                throw ValidationException::withMessages(['telegram_user_id' => 'این حساب تلگرام قبلاً به حساب دیگری متصل شده است.']);
            }

            $connection = TelegramConnection::updateOrCreate(
                ['user_id' => $code->user_id],
                ['telegram_user_id' => $telegramUserId, 'telegram_chat_id' => $telegramChatId, 'telegram_username' => $username, 'connected_at' => now()]
            );
            $code->update(['used_at' => now()]);

            return $connection;
        });
    }
}
