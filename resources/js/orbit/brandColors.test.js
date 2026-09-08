import { describe, it, expect } from 'vitest';
import { brandColor, brandDomain, initial, contrastText } from './brandColors.js';
import catalog from '../../data/services.json';

describe('brandColor', () => {
    it('maps known brands to their color, case-insensitively', () => {
        expect(brandColor('Netflix')).toBe('#E50914');
        expect(brandColor('NETFLIX')).toBe('#E50914');
        expect(brandColor('Spotify')).toBe('#1DB954');
    });
    it('matches a brand keyword inside a longer name', () => {
        expect(brandColor('Netflix Premium 4K')).toBe('#E50914');
    });
    it('lets the most specific brand win over a shorter one it contains', () => {
        // Five apple.com products and three youtube.com ones share a prefix,
        // so a substring match alone would colour them all the same.
        expect(brandColor('Apple Music')).toBe('#FA243C');
        expect(brandColor('Apple')).toBe('#555555');
        expect(brandColor('YouTube Music')).toBe('#FF0000');
        expect(brandColor('Proton VPN')).toBe('#66DEB1');
        expect(brandColor('Proton Mail')).toBe('#6D4AFF');
    });
    it('colours every service in the catalog', () => {
        for (const service of Object.values(catalog)) {
            expect(brandColor(service.name)).toBe(service.color);
        }
    });
    it('keeps display-only aliases working', () => {
        expect(brandColor('OpenAI')).toBe('#10A37F');
        expect(brandColor('Prime')).toBe('#FF9900');
    });
    it('gives unknown names a stable, valid hex color', () => {
        const first = brandColor('Foobar Cloud');
        const second = brandColor('Foobar Cloud');
        expect(first).toBe(second);
        expect(first).toMatch(/^#[0-9A-Fa-f]{6}$/);
    });
});

describe('brandDomain', () => {
    it('returns the domain BrandIcon.vue pulls a favicon from', () => {
        expect(brandDomain('Disney+')).toBe('disneyplus.com');
        expect(brandDomain('Apple Music')).toBe('music.apple.com');
    });
    it('gives every icon-less service a favicon domain to fall back to', () => {
        // These are the entries simple-icons has no glyph for, so the favicon
        // is the only thing standing between them and a letter avatar.
        const iconless = Object.values(catalog).filter((service) => !service.icon);
        expect(iconless.length).toBeGreaterThan(0);
        for (const service of iconless) {
            expect(brandDomain(service.name), service.name).toBe(service.domain);
        }
    });
    it('returns null for a service it does not know', () => {
        expect(brandDomain('Some Random Local Gym')).toBeNull();
        expect(brandDomain('')).toBeNull();
    });
});

describe('initial', () => {
    it('returns the first letter uppercased', () => {
        expect(initial('Netflix')).toBe('N');
        expect(initial('  spotify')).toBe('S');
    });
    it('falls back to ? for empty names', () => {
        expect(initial('')).toBe('?');
        expect(initial('   ')).toBe('?');
    });
});

describe('contrastText', () => {
    it('uses dark text on light fills', () => {
        expect(contrastText('#FFFFFF')).toBe('#000');
    });
    it('uses light text on dark fills', () => {
        expect(contrastText('#000000')).toBe('#fff');
        expect(contrastText('#E50914')).toBe('#fff');
    });
});
