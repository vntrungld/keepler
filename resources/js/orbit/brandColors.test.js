import { describe, it, expect } from 'vitest';
import { brandColor, initial, contrastText } from './brandColors.js';

describe('brandColor', () => {
    it('maps known brands to their color, case-insensitively', () => {
        expect(brandColor('Netflix')).toBe('#E50914');
        expect(brandColor('NETFLIX')).toBe('#E50914');
        expect(brandColor('Spotify')).toBe('#1DB954');
    });
    it('matches a brand keyword inside a longer name', () => {
        expect(brandColor('Netflix Premium 4K')).toBe('#E50914');
    });
    it('gives unknown names a stable, valid hex color', () => {
        const first = brandColor('Foobar Cloud');
        const second = brandColor('Foobar Cloud');
        expect(first).toBe(second);
        expect(first).toMatch(/^#[0-9A-Fa-f]{6}$/);
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
