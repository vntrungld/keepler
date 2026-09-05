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

    it('falls back to created_at as the lower bound when started_at is null', () => {
        // Gmail-imported subscriptions (and any hand-created one where the
        // optional "Bắt đầu từ" field was left blank) have started_at:
        // null. Without the created_at fallback this projects a renewal
        // into March even though the subscription did not exist yet.
        const noStartedAt = { ...monthlySub, started_at: null, created_at: '2026-04-10T00:00:00.000000Z' };

        expect(projectOccurrences([noStartedAt], 2026, 2)).toHaveLength(0); // March, before created_at
        expect(projectOccurrences([noStartedAt], 2026, 3)).toHaveLength(1); // April, on/after created_at
    });

    it('clamps a monthly end-of-month renewal to the target month length instead of overflowing', () => {
        const eomSub = { ...monthlySub, next_renewal_date: '2026-01-31' };

        const feb = projectOccurrences([eomSub], 2026, 1); // February 2026 (28 days)
        expect(feb).toHaveLength(1);
        expect(feb[0].date).toBe('2026-02-28');

        const mar = projectOccurrences([eomSub], 2026, 2); // March 2026
        expect(mar).toHaveLength(1);
        expect(mar[0].date).toBe('2026-03-31'); // no fabricated 2026-03-03 artifact

        const apr = projectOccurrences([eomSub], 2026, 3); // April 2026 (30 days)
        expect(apr).toHaveLength(1);
        expect(apr[0].date).toBe('2026-04-30');

        const febLeap = projectOccurrences([eomSub], 2028, 1); // February 2028 (leap year)
        expect(febLeap).toHaveLength(1);
        expect(febLeap[0].date).toBe('2028-02-29');
    });

    it('still projects a monthly subscription more than 36 months from the anchor renewal date', () => {
        // Regression guard for unbounded month navigation (Item 3): the old
        // fixed MAX_STEPS = 36 constant made a monthly subscription vanish
        // from the grid once the user paged more than 36 months away from
        // next_renewal_date. July 2030 is 50 months after 2026-05-15.
        const occurrences = projectOccurrences([monthlySub], 2030, 6); // July 2030 (0-indexed)
        expect(occurrences).toHaveLength(1);
        expect(occurrences[0].date).toBe('2030-07-15');
    });

    it('still projects a yearly subscription more than 36 years from the anchor renewal date', () => {
        const occurrences = projectOccurrences([yearlySub], 2066, 0); // January 2066, 40 years after 2026-01-26
        expect(occurrences).toHaveLength(1);
        expect(occurrences[0].date).toBe('2066-01-26');
    });

    it('clamps a yearly Feb-29 renewal to Feb-28 in a non-leap year', () => {
        const leapYearlySub = { ...yearlySub, next_renewal_date: '2028-02-29' };

        const nonLeap = projectOccurrences([leapYearlySub], 2027, 1); // February 2027 (non-leap)
        expect(nonLeap).toHaveLength(1);
        expect(nonLeap[0].date).toBe('2027-02-28');
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
