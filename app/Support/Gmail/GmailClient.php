<?php

namespace App\Support\Gmail;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GmailClient
{
    private const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private User $user)
    {
    }

    /** @return string[] */
    public function listMessageIds(string $query, int $max = 100): array
    {
        $response = $this->authorized()->get(self::BASE.'/messages', [
            'q' => $query,
            'maxResults' => $max,
        ]);

        $this->assertOk($response);

        return collect($response->json('messages', []))
            ->pluck('id')
            ->all();
    }

    /** @return array{from:string,subject:string,date:string,body:string} */
    public function getMessage(string $id): array
    {
        $response = $this->authorized()->get(self::BASE.'/messages/'.$id, ['format' => 'full']);
        $this->assertOk($response);

        $payload = $response->json('payload', []);
        $headers = collect($payload['headers'] ?? [])
            ->mapWithKeys(fn ($h) => [strtolower($h['name']) => $h['value']]);

        $rawDate = $headers['date'] ?? null;

        return [
            'from' => $headers['from'] ?? '',
            'subject' => $headers['subject'] ?? '',
            'date' => $rawDate ? Carbon::parse($rawDate)->toDateString() : Carbon::now()->toDateString(),
            'body' => $this->extractBody($payload),
        ];
    }

    private function extractBody(array $payload): string
    {
        if (! empty($payload['body']['data'])) {
            return $this->decode($payload['body']['data']);
        }

        // Prefer text/plain, then any part with body data (recursively).
        foreach (($payload['parts'] ?? []) as $part) {
            if (($part['mimeType'] ?? '') === 'text/plain' && ! empty($part['body']['data'])) {
                return $this->decode($part['body']['data']);
            }
        }
        foreach (($payload['parts'] ?? []) as $part) {
            $nested = $this->extractBody($part);
            if ($nested !== '') {
                return $nested;
            }
        }

        return '';
    }

    private function decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }

    private function authorized(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->freshAccessToken());
    }

    private function freshAccessToken(): string
    {
        $expiry = $this->user->gmail_token_expires_at;
        if ($expiry && $expiry->isFuture()) {
            return $this->user->gmail_access_token;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $this->user->gmail_refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Gmail token refresh failed: '.$response->status());
        }

        $this->user->forceFill([
            'gmail_access_token' => $response->json('access_token'),
            'gmail_token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ])->save();

        return $this->user->gmail_access_token;
    }

    private function assertOk(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->failed()) {
            throw new \RuntimeException('Gmail API error: '.$response->status());
        }
    }
}
