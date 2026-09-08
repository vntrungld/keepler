// Maps a subscription name to a stable brand color, an initial letter, and a
// readable text color. Pure and unit-tested in brandColors.test.js.
//
// The brand list is NOT maintained here: it is read from the one catalog in
// `resources/data/services.json`, which also feeds config/providers.php (Gmail
// detection) and the service picker on the "add subscription" form.
import catalog from '../../data/services.json';

// Fallback palette for unknown services (all hex so contrastText works).
const PALETTE = [
    '#6366F1', '#EC4899', '#F59E0B', '#10B981',
    '#3B82F6', '#8B5CF6', '#EF4444', '#14B8A6',
];

export function normalize(name) {
    return (name || '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

/**
 * Every normalized keyword that identifies a catalog entry, longest first.
 *
 * A user's subscription is named freely ("Apple Music Family"), so brands are
 * found by substring. Sorting longest-first is what stops the five apple.com
 * products from collapsing into one: "applemusic" is tried before "apple".
 * `aliases` are display-only extra names — unlike `match_keywords` they are
 * never used for Gmail matching, where a bare "apple" would be far too loose.
 */
export const KEYWORDS = Object.entries(catalog)
    .flatMap(([key, service]) =>
        [service.name, ...(service.match_keywords ?? []), ...(service.aliases ?? [])]
            .map(normalize)
            .filter(Boolean)
            .map((keyword) => ({ keyword, key, service })),
    )
    .sort((a, b) => b.keyword.length - a.keyword.length);

/** The catalog entry a subscription name refers to, or null. */
export function resolveService(name) {
    const norm = normalize(name);
    if (!norm) return null;

    return KEYWORDS.find(({ keyword }) => norm.includes(keyword))?.service ?? null;
}

function hashString(s) {
    let h = 0;
    for (let i = 0; i < s.length; i++) {
        h = (h * 31 + s.charCodeAt(i)) >>> 0;
    }
    return h;
}

export function brandColor(name) {
    const service = resolveService(name);
    if (service) return service.color;

    return PALETTE[hashString(normalize(name)) % PALETTE.length];
}

/** The domain to pull a favicon from when no bundled icon exists, or null. */
export function brandDomain(name) {
    return resolveService(name)?.domain ?? null;
}

export function initial(name) {
    const match = (name || '').match(/[a-z0-9]/i);
    return match ? match[0].toUpperCase() : '?';
}

export function contrastText(hex) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
    return luminance > 150 ? '#000' : '#fff';
}
