<?php

// Subscription-provider catalog for rule-based Gmail detection.
//
// The catalog itself lives in `resources/data/services.json` so that ONE file
// feeds both this config (Gmail detection) and the frontend (brand colour,
// brand icon, and the service picker on the "add subscription" form). Keeping
// them in sync by hand is what this indirection exists to prevent.
//
// This file's job is to load that catalog and fill in the fields most entries
// share, so a service only spells out what makes it different:
//
//   name                     display name
//   domain                   canonical web domain (favicon fallback, frontend)
//   color                    brand colour (frontend)
//   icon                     simple-icons export name, or null (frontend)
//   sender_domains           From: hosts that prove the mail is from the vendor
//   match_keywords           product terms that identify the product in a body
//   requires_product_match   vendor sells more than one catalog product, so the
//                            sender domain alone proves nothing — the email
//                            must also name the product (see ProviderMatcher)
//   payment_keywords         terms marking a receipt (defaults below)
//   cancellation_keywords    terms marking a cancellation (defaults below)
//   default_currency         defaults to USD
//   default_cycle            monthly | yearly
//   cancel_url               where the user cancels
//
// `match_keywords` doubles as the resolver for payment aggregators (see
// `_aggregators`): a Google Play or Stripe receipt names the real product in
// its body rather than its sender.

$defaults = [
    'payment_keywords' => ['receipt', 'payment', 'invoice', 'subscription', 'hóa đơn', 'thanh toán', 'gia hạn'],
    'cancellation_keywords' => ['cancelled', 'canceled', 'subscription ended', 'membership ended', 'đã hủy'],
    'default_currency' => 'USD',
    'default_cycle' => 'monthly',
    'requires_product_match' => false,
];

$catalog = json_decode(file_get_contents(resource_path('data/services.json')), true, 512, JSON_THROW_ON_ERROR);

return [
    // Senders that forward/consolidate receipts for many different
    // products (payment processors / app stores). Entries may be an exact
    // email (e.g. Google Play) or a bare domain (e.g. Stripe, whose local
    // part varies per merchant). When ProviderMatcher sees such a sender (or
    // a subject matching an aggregator pattern), it resolves the real
    // provider by scanning the email body against each provider's
    // `match_keywords`. If nothing in the body matches a catalog provider,
    // the email is skipped (NO fall-back to sender-domain matching) — this
    // is what stops unknown-merchant receipts (e.g. Runpod via Stripe) from
    // being mis-attributed to whichever provider shares the processor domain.
    '_aggregators' => [
        'googleplay-noreply@google.com',
        'stripe.com',
    ],

    ...array_map(fn (array $service): array => [...$defaults, ...$service], $catalog),
];
