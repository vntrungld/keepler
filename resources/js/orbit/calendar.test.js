import { describe, it, expect } from 'vitest';
import { projectOccurrences, groupByDate, monthTotals } from './calendar.js';

const monthlySub = {
    id: 1,
    name: 'Netflix',
    billing_cycle: 'monthly',
    next_renewal_date: '2026-05-15',
    status: 'active',
    amount_vnd: 260000,
    started_at: null,
};

const yearlySub = {
    id: 2,
    name: 'Adobe',
    billing_cycle: 'yearly',
    next_renewal_date: '2026-01-26',
    status: 'active',
    amount_vnd: 15000000,
    started_at: null,
};

describe('projectOccurrences', () => {
    it('projects a monthly subscription into an earlier month', () => {
        const occurrences = projectOccurrences([monthlySub], 2026, 3); // April 2026 (0-indexed)
        expect(occurrences).toHaveLength(1);
        expect(occurrences[0].date).toBe('2026-04-15');
    });

    it('projects a monthly subscription into a later month', () => {
        const occurrences = projectOccurrences([monthlySub], 2026, 6); // July 2026
        expect(occurrences).toHaveLength(1);
        expect(occurrences[0].date).toBe('2026-07-15');
    });

    it('only projects a yearly subscription into the matching month', () => {
        expect(projectOccurrences([yearlySub], 2026, 0)).toHaveLength(1); // January
        expect(projectOccurrences([yearlySub], 2026, 1)).toHaveLength(0); // February
        expect(projectOccurrences([yearlySub], 2027, 0)).toHaveLength(1); // January next year
    });

    it('excludes cancelled subscriptions', () => {
        const cancelled = { ...monthlySub, status: 'cancelled' };
        expect(projectOccurrences([cancelled], 2026, 3)).toHaveLength(0);
    });

    it('does not project occurrences before started_at', () => {
        const startedLate = { ...monthlySub, started_at: '2026-06-01' };
        expect(projectOccurrences([startedLate], 2026, 3)).toHaveLength(0); // April, before start
        expect(projectOccurrences([startedLate], 2026, 6)).toHaveLength(1); // July, after start
    });
});

describe('groupByDate', () => {
    it('groups multiple occurrences on the same date', () => {
        const occurrences = [
            { date: '2026-04-15', subscription: monthlySub },
            { date: '2026-04-15', subscription: yearlySub },
        ];
        const grouped = groupByDate(occurrences);
        expect(grouped['2026-04-15']).toHaveLength(2);
    });
});

describe('monthTotals', () => {
    const occurrences = [
        { date: '2026-04-01', subscription: { amount_vnd: 100000 } },
        { date: '2026-04-20', subscription: { amount_vnd: 200000 } },
    ];

    it('sums every occurrence for total', () => {
        expect(monthTotals(occurrences, '2026-04-25').total).toBe(300000);
    });

    it('only counts occurrences on/after today for upcoming', () => {
        expect(monthTotals(occurrences, '2026-04-10').upcoming).toBe(200000);
    });

    it('upcoming is 0 once every occurrence is in the past', () => {
        expect(monthTotals(occurrences, '2026-04-25').upcoming).toBe(0);
    });

    it('upcoming equals total when every occurrence is still ahead', () => {
        expect(monthTotals(occurrences, '2026-03-01').upcoming).toBe(300000);
    });
});
