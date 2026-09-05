// Pure date-projection math for the Calendar page. No DOM, no Vue —
// unit-tested in calendar.test.js.

function addCycle(dateStr, cycle, steps) {
    const [y, m, d] = dateStr.slice(0, 10).split('-').map(Number);
    if (cycle === 'yearly') {
        return new Date(Date.UTC(y + steps, m - 1, d));
    }
    return new Date(Date.UTC(y, m - 1 + steps, d));
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
