<?php

namespace App\Http\Controllers;

use App\Services\TelegramConnectionService;
use Illuminate\Http\Request;

class TelegramConnectionController extends Controller
{
    public function issue(Request $request, TelegramConnectionService $connections)
    {
        return response()->json($connections->issueCode($request->user()));
    }

    public function connect(Request $request, TelegramConnectionService $connections)
    {
        if ($secret = config('services.telegram.connect_secret')) {
            abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Connect-Secret')), 403);
        }

        $data = $request->validate([
            'code' => ['required', 'digits:6'],
            'telegram_user_id' => ['required', 'string', 'max:32'],
            'telegram_chat_id' => ['required', 'string', 'max:32'],
            'telegram_username' => ['nullable', 'string', 'max:255'],
        ]);

        $connection = $connections->connect($data['code'], $data['telegram_user_id'], $data['telegram_chat_id'], $data['telegram_username'] ?? null);
        return response()->json(['connected' => true, 'connection' => $connection]);
    }
}
