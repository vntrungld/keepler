// Curated brand-icon lookup for the subscription list/detail UI. Mirrors
// the keyword catalog in brandColors.js — small and hand-picked, not the
// full simple-icons dataset, to keep the bundle lean.
//
// NOTE: simple-icons@16 does not ship Adobe, Amazon, Disney+, or OpenAI
// icons (pulled from the package for trademark reasons), so those brands
// are intentionally left out of ICON_CATALOG below even though they have
// entries in brandColors.js. resolveBrandIcon() returns null for them and
// BrandIcon.vue falls back to the letter avatar.
import { siNetflix, siSpotify, siYoutube, siApple, siGoogle } from 'simple-icons';
import { normalize } from './brandColors.js';

const ICON_CATALOG = [
    ['netflix', siNetflix],
    ['spotify', siSpotify],
    ['youtube', siYoutube],
    ['apple', siApple],
    ['google', siGoogle],
];

export function resolveBrandIcon(name) {
    const norm = normalize(name);
    if (!norm) return null;

    for (const [keyword, icon] of ICON_CATALOG) {
        if (norm.includes(keyword)) {
            return { path: icon.path, hex: icon.hex, title: icon.title };
        }
    }

    return null;
}
