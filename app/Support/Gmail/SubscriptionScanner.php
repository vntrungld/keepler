<?php

namespace App\Support\Gmail;

use App\Models\User;

class SubscriptionScanner
{
    private const MAX_MESSAGES = 100;

    public function __construct(private GmailClient $client)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function scan(User $user): array
    {
        $ids = $this->client->listMessageIds($this->buildQuery(), self::MAX_MESSAGES);

        // Parse each message into a raw candidate keyed by provider+cycle,
        // keeping only the latest email per group.
        $byGroup = [];
        foreach ($ids as $id) {
            $msg = $this->client->getMessage($id);
            $key = ProviderMatcher::match($msg['from'], $msg['subject']);
            if ($key === null) {
                continue;
            }

            $provider = config("providers.$key");
            $parsed = ReceiptParser::parse($provider, $msg['subject'], $msg['body'], $msg['date']);
            $group = $key.'|'.$parsed['billing_cycle'];

            if (! isset($byGroup[$group]) || $msg['date'] > $byGroup[$group]['date']) {
                $byGroup[$group] = [
                    'provider_key' => $key,
                    'name' => $provider['name'],
                    'cancel_url' => $provider['cancel_url'] ?? null,
                    'date' => $msg['date'],
                    'source_email_id' => $id,
                    'parsed' => $parsed,
                ];
            }
        }

        return $this->applyDedup($user, array_values($byGroup));
    }

    public function buildQuery(): string
    {
        $domains = collect(config('providers'))
            ->flatMap(fn ($p) => $p['sender_domains'])
            ->unique()
            ->implode(' OR ');

        return "from:($domains) newer_than:1y";
    }

    /** @return array<int,array<string,mixed>> */
    private function applyDedup(User $user, array $groups): array
    {
        $existing = $user->subscriptions()
            ->whereNotNull('provider_key')
            ->get()
            ->keyBy(fn ($s) => $s->provider_key.'|'.$s->billing_cycle);

        $candidates = [];
        foreach ($groups as $g) {
            $parsed = $g['parsed'];
            $groupKey = $g['provider_key'].'|'.$parsed['billing_cycle'];
            $match = $existing->get($groupKey);
            $matchActive = $match && $match->status !== 'cancelled';

            if ($parsed['intent'] === 'cancellation') {
                $action = $matchActive ? 'update_status' : 'skip';
            } else {
                $action = $matchActive ? 'skip' : 'create';
            }

            $candidates[] = [
                'provider_key' => $g['provider_key'],
                'name' => $g['name'],
                'intent' => $parsed['intent'],
                'amount' => $parsed['amount'],
                'currency' => $parsed['currency'],
                'billing_cycle' => $parsed['billing_cycle'],
                'next_renewal_date' => $parsed['next_renewal_date'],
                'cancel_url' => $g['cancel_url'],
                'confidence' => $parsed['confidence'],
                'source_email_id' => $g['source_email_id'],
                'action' => $action,
                'duplicate_of' => $match?->id,
            ];
        }

        return $candidates;
    }
}
