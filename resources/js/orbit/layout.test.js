import { describe, it, expect } from 'vitest';
import {
    distributeAngles,
    planetRadius,
    polarToXy,
    daysUntil,
    isUrgent,
    annualizedVnd,
} from './layout.js';

describe('distributeAngles', () => {
    it('places a single planet at the offset', () => {
        expect(distributeAngles(1, 0)).toEqual([0]);
    });
    it('spreads two planets 180 apart', () => {
        expect(distributeAngles(2, 0)).toEqual([0, 180]);
    });
    it('spreads three planets 120 apart', () => {
        expect(distributeAngles(3, 0)).toEqual([0, 120, 240]);
    });
    it('spreads four planets 90 apart', () => {
        expect(distributeAngles(4, 0)).toEqual([0, 90, 180, 270]);
    });
    it('applies the rotation offset', () => {
        expect(distributeAngles(2, 45)).toEqual([45, 225]);
    });
    it('returns an empty array for zero planets', () => {
        expect(distributeAngles(0, 0)).toEqual([]);
    });
});

describe('planetRadius', () => {
    it('returns the midpoint when all amounts are equal', () => {
        expect(planetRadius(100, 100, 100)).toBe(26); // (12+40)/2
    });
    it('returns rMin at the cheapest amount', () => {
        expect(planetRadius(100, 100, 400)).toBe(12);
    });
    it('returns rMax at the priciest amount', () => {
        expect(planetRadius(400, 100, 400)).toBe(40);
    });
    it('is monotonic in amount', () => {
        const a = planetRadius(200, 100, 400);
        const b = planetRadius(300, 100, 400);
        expect(b).toBeGreaterThan(a);
    });
    it('clamps above rMax and never below rMin', () => {
        expect(planetRadius(100000, 100, 400)).toBe(40);
        expect(planetRadius(1, 100, 400)).toBe(12);
    });
    it('honors custom rMin/rMax', () => {
        expect(planetRadius(100, 100, 100, { rMin: 10, rMax: 30 })).toBe(20);
    });
});

describe('polarToXy', () => {
    it('puts angle 0 at the top', () => {
        const p = polarToXy(100, 100, 50, 0);
        expect(p.x).toBeCloseTo(100);
        expect(p.y).toBeCloseTo(50);
    });
    it('puts angle 90 to the right', () => {
        const p = polarToXy(100, 100, 50, 90);
        expect(p.x).toBeCloseTo(150);
        expect(p.y).toBeCloseTo(100);
    });
    it('puts angle 180 at the bottom', () => {
        const p = polarToXy(100, 100, 50, 180);
        expect(p.x).toBeCloseTo(100);
        expect(p.y).toBeCloseTo(150);
    });
});

describe('daysUntil', () => {
    it('counts whole days ahead', () => {
        expect(daysUntil('2026-07-17', '2026-07-10')).toBe(7);
    });
    it('is zero on the day', () => {
        expect(daysUntil('2026-07-10', '2026-07-10')).toBe(0);
    });
    it('is negative when overdue', () => {
        expect(daysUntil('2026-07-05', '2026-07-10')).toBe(-5);
    });
    it('crosses month and year boundaries', () => {
        expect(daysUntil('2026-08-01', '2026-07-10')).toBe(22);
        expect(daysUntil('2027-01-01', '2026-12-31')).toBe(1);
    });
    it('accepts ISO datetime strings', () => {
        expect(daysUntil('2026-07-17T00:00:00.000000Z', '2026-07-10')).toBe(7);
    });
});

describe('isUrgent', () => {
    it('is true within 7 days and when overdue', () => {
        expect(isUrgent(0)).toBe(true);
        expect(isUrgent(7)).toBe(true);
        expect(isUrgent(-3)).toBe(true);
    });
    it('is false beyond 7 days', () => {
        expect(isUrgent(8)).toBe(false);
        expect(isUrgent(30)).toBe(false);
    });
});

describe('annualizedVnd', () => {
    it('multiplies a monthly amount by 12', () => {
        expect(annualizedVnd({ amount_vnd: 100000, billing_cycle: 'monthly' })).toBe(1200000);
    });
    it('returns a yearly amount unchanged', () => {
        expect(annualizedVnd({ amount_vnd: 1200000, billing_cycle: 'yearly' })).toBe(1200000);
    });
});
