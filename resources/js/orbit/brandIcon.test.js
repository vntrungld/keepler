import { describe, it, expect } from 'vitest';
import { resolveBrandIcon } from './brandIcon.js';
import catalog from '../../data/services.json';

describe('resolveBrandIcon', () => {
    it('resolves a known brand case-insensitively', () => {
        const icon = resolveBrandIcon('Netflix');
        expect(icon).not.toBeNull();
        expect(icon.title.toLowerCase()).toContain('netflix');
        expect(typeof icon.path).toBe('string');
        expect(typeof icon.hex).toBe('string');
    });

    it('resolves other brands available in the curated npm icon set', () => {
        expect(resolveBrandIcon('Spotify Premium')).not.toBeNull();
        expect(resolveBrandIcon('YouTube Premium')).not.toBeNull();
    });

    it('returns null for brands without an available simple-icons icon', () => {
        // simple-icons@16 does not ship Adobe/Amazon/Disney+/OpenAI icons
        // (they were pulled for trademark reasons), so these catalog entries
        // carry a null `icon`. BrandIcon.vue falls back to the domain favicon
        // and then to the letter avatar from brandColors.js for these names.
        expect(resolveBrandIcon('ChatGPT Plus')).toBeNull();
        expect(resolveBrandIcon('OpenAI')).toBeNull();
    });

    it('resolves the most specific product rather than a shorter sibling', () => {
        expect(resolveBrandIcon('Apple Music').title).toBe('Apple Music');
        expect(resolveBrandIcon('Apple').title).toBe('Apple');
        expect(resolveBrandIcon('YouTube TV').title).toBe('YouTube TV');
        expect(resolveBrandIcon('YouTube Premium').title).toBe('YouTube');
    });

    it('resolves an icon for every catalog entry that declares one', () => {
        for (const service of Object.values(catalog)) {
            if (!service.icon) continue;
            expect(resolveBrandIcon(service.name), service.name).not.toBeNull();
        }
    });

    it('returns null for an unknown service', () => {
        expect(resolveBrandIcon('Some Random Local Gym')).toBeNull();
    });

    it('returns null for an empty name', () => {
        expect(resolveBrandIcon('')).toBeNull();
    });
});
