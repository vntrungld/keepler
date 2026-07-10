<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GmailController extends Controller
{
    private const GMAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    public function connect()
    {
        return Socialite::driver('google')
            ->scopes([self::GMAIL_SCOPE])
            ->redirectUrl(route('gmail.callback'))
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(Request $request)
    {
        $googleUser = Socialite::driver('google')
            ->redirectUrl(route('gmail.callback'))
            ->user();

        $attributes = [
            'gmail_access_token' => $googleUser->token,
            'gmail_token_expires_at' => now()->addSeconds((int) ($googleUser->expiresIn ?? 3600)),
        ];

        // Google only returns a refresh token on the first offline consent;
        // keep the existing one if this consent didn't include a new one.
        if (! empty($googleUser->refreshToken)) {
            $attributes['gmail_refresh_token'] = $googleUser->refreshToken;
        }

        $request->user()->forceFill($attributes)->save();

        return redirect()->route('dashboard');
    }

    public function disconnect(Request $request)
    {
        $request->user()->forceFill([
            'gmail_access_token' => null,
            'gmail_refresh_token' => null,
            'gmail_token_expires_at' => null,
        ])->save();

        return redirect()->back();
    }
}
