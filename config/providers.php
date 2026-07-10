<?php

// Subscription-provider catalog for rule-based Gmail detection.
// Each key MUST match the SP2 brand-color catalog key so imported
// subscriptions render with the right planet color.
//
// `match_keywords` lists body-detectable product terms used to resolve the
// real provider when an email comes from a payment aggregator (see
// `_aggregators` below), e.g. Google Play receipts that mention the actual
// product ("Google One", "Spotify", ...) in the body rather than the sender.
return [
    // Senders that forward/consolidate receipts for many different
    // products. When ProviderMatcher sees a From address in this list (or a
    // subject matching an aggregator pattern), it resolves the real
    // provider by scanning the email body against each provider's
    // `match_keywords`, falling back to normal sender-domain matching only
    // if nothing in the body matches.
    '_aggregators' => [
        'googleplay-noreply@google.com',
    ],

    'netflix' => [
        'name' => 'Netflix',
        'sender_domains' => ['netflix.com', 'members.netflix.com'],
        'payment_keywords' => ['receipt', 'payment', 'hóa đơn', 'gia hạn'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'membership ended', 'đã hủy'],
        'match_keywords' => ['netflix'],
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://www.netflix.com/cancelplan',
    ],
    'spotify' => [
        'name' => 'Spotify',
        'sender_domains' => ['spotify.com'],
        'payment_keywords' => ['receipt', 'payment', 'premium'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended'],
        'match_keywords' => ['spotify'],
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://www.spotify.com/account/subscription/',
    ],
    'youtube' => [
        'name' => 'YouTube Premium',
        'sender_domains' => ['youtube.com'],
        'payment_keywords' => ['receipt', 'payment', 'youtube premium'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'membership paused'],
        'match_keywords' => ['youtube premium', 'youtube'],
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://www.youtube.com/paid_memberships',
    ],
    'chatgpt' => [
        'name' => 'ChatGPT Plus',
        'sender_domains' => ['openai.com', 'stripe.com'],
        'payment_keywords' => ['receipt', 'payment', 'chatgpt'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended'],
        'match_keywords' => ['chatgpt'],
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://chatgpt.com/#settings',
    ],
    'google' => [
        'name' => 'Google One',
        'sender_domains' => ['google.com', 'payments.google.com'],
        'payment_keywords' => ['google one', 'receipt', 'payment'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'membership ended'],
        'match_keywords' => ['google one', 'google ai'],
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://one.google.com/',
    ],
    'adobe' => [
        'name' => 'Adobe',
        'sender_domains' => ['adobe.com', 'mail.adobe.com'],
        'payment_keywords' => ['receipt', 'invoice', 'payment'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'plan cancelled'],
        'match_keywords' => ['adobe'],
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://account.adobe.com/plans',
    ],
    'apple' => [
        'name' => 'Apple',
        'sender_domains' => ['apple.com', 'email.apple.com'],
        'payment_keywords' => ['receipt', 'your invoice', 'subscription'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended'],
        'match_keywords' => ['apple'],
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://apps.apple.com/account/subscriptions',
    ],
    'amazon' => [
        'name' => 'Amazon Prime',
        'sender_domains' => ['amazon.com', 'primevideo.com'],
        'payment_keywords' => ['prime', 'receipt', 'payment', 'membership'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'membership ended'],
        'match_keywords' => ['amazon prime', 'prime video'],
        'default_currency' => 'USD',
        'default_cycle' => 'yearly',
        'cancel_url' => 'https://www.amazon.com/gp/primecentral',
    ],
    'disney' => [
        'name' => 'Disney+',
        'sender_domains' => ['disneyplus.com', 'mail.disneyplus.com'],
        'payment_keywords' => ['receipt', 'payment', 'subscription'],
        'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended'],
        'match_keywords' => ['disney'],
        'default_currency' => 'USD',
        'default_cycle' => 'monthly',
        'cancel_url' => 'https://www.disneyplus.com/account/subscription',
    ],
];
