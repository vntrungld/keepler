<?php

namespace App\Http\Controllers;

use App\Http\Requests\GmailImportRequest;
use App\Support\Gmail\GmailClient;
use App\Support\Gmail\SubscriptionScanner;
use Illuminate\Http\Request;
use Inertia\Inertia;
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

    public function scan(Request $request)
    {
        $user = $request->user();

        if (! $user->hasGmailConnected()) {
            return redirect()->route('gmail.connect');
        }

        $scanner = new SubscriptionScanner(new GmailClient($user));

        return Inertia::render('Gmail/ScanResults', [
            'candidates' => $scanner->scan($user),
        ]);
    }

    public function import(GmailImportRequest $request)
    {
        $user = $request->user();

        foreach ($request->validated()['items'] as $item) {
            if ($item['action'] === 'create') {
                $user->subscriptions()->create([
                    'name' => $item['name'],
                    'provider_key' => $item['provider_key'] ?? null,
                    'amount' => $item['amount'],
                    'currency' => $item['currency'],
                    'billing_cycle' => $item['billing_cycle'],
                    'next_renewal_date' => $item['next_renewal_date'],
                    'status' => 'active',
                    'cancel_url' => $item['cancel_url'] ?? null,
                ]);
            } elseif ($item['action'] === 'update_status' && ! empty($item['duplicate_of'])) {
                // Scope to the user's own subscriptions — a foreign id simply finds nothing.
                $user->subscriptions()
                    ->whereKey($item['duplicate_of'])
                    ->update(['status' => 'cancelled']);
            }
        }

        return redirect()->route('dashboard');
    }
}
