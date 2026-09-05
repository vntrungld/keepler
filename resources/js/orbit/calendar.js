// Pure date-projection math for the Calendar page. No DOM, no Vue —
// unit-tested in calendar.test.js.

function daysInMonth(year, monthIndex0) {
    // Day 0 of the *next* month is the last day of monthIndex0 — leap
    // years are handled natively by the Date engine.
    return new Date(Date.UTC(year, monthIndex0 + 1, 0)).getUTCDate();
}

function addCycle(dateStr, cycle, steps) {
    const [y, m, d] = dateStr.slice(0, 10).split('-').map(Number);

    // Resolve the target year/month using day 1 (always valid, so this
    // never overflows) before touching the original day-of-month. Then
    // clamp that day to the target month's length — standard "billed on
    // the Nth, clamped to month end" billing semantics — so e.g. a
    // Jan-31 monthly renewal lands on Feb 28/29, Apr 30, etc., instead of
    // silently rolling into the following month.
    let targetYear;
    let targetMonthIndex0;
    if (cycle === 'yearly') {
        targetYear = y + steps;
        targetMonthIndex0 = m - 1;
    } else {
        const base = new Date(Date.UTC(y, m - 1 + steps, 1));
        targetYear = base.getUTCFullYear();
        targetMonthIndex0 = base.getUTCMonth();
    }
    const clampedDay = Math.min(d, daysInMonth(targetYear, targetMonthIndex0));

    return new Date(Date.UTC(targetYear, targetMonthIndex0, clampedDay));
}

function toDateStr(date) {
    return date.toISOString().slice(0, 10);
}

const MAX_STEPS = 36;

export function projectOccurrences(subscriptions, year, monthIndex) {
    const monthStart = Date.UTC(year, monthIndex, 1);
    const monthEnd = Date.UTC(year, monthIndex + 1, 0);
    const occurrences = [];

    for (const sub of subscriptions) {
        if (sub.status === 'cancelled') continue;

        const lowerBound = sub.started_at
            ? Date.parse(`${sub.started_at.slice(0, 10)}T00:00:00Z`)
            : -Infinity;

        for (let steps = -MAX_STEPS; steps <= MAX_STEPS; steps++) {
            const occurrence = addCycle(sub.next_renewal_date, sub.billing_cycle, steps);
            const ts = occurrence.getTime();
            if (ts < lowerBound) continue;
            if (ts >= monthStart && ts <= monthEnd) {
                occurrences.push({ date: toDateStr(occurrence), subscription: sub });
            }
        }
    }

    return occurrences;
}

export function groupByDate(occurrences) {
    const map = {};
    for (const occ of occurrences) {
        (map[occ.date] ??= []).push(occ);
    }
    return map;
}

export function monthTotals(occurrences, today) {
    const todayTs = Date.parse(`${today}T00:00:00Z`);
    let total = 0;
    let upcoming = 0;

    for (const occ of occurrences) {
        total += occ.subscription.amount_vnd;
        if (Date.parse(`${occ.date}T00:00:00Z`) >= todayTs) {
            upcoming += occ.subscription.amount_vnd;
        }
    }

    return { total, upcoming };
}
