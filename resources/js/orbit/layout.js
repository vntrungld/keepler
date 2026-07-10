// Pure geometry + day math for the orbit visualization. No DOM, no Vue —
// everything here is unit-tested in layout.test.js.

const DEFAULT_R_MIN = 12;
const DEFAULT_R_MAX = 40;

export function distributeAngles(count, rotationOffset = 0) {
    if (count <= 0) return [];
    const step = 360 / count;
    return Array.from({ length: count }, (_, i) => rotationOffset + i * step);
}

export function planetRadius(amountVnd, minVnd, maxVnd, opts = {}) {
    const rMin = opts.rMin ?? DEFAULT_R_MIN;
    const rMax = opts.rMax ?? DEFAULT_R_MAX;
    const s = Math.sqrt(Math.max(0, amountVnd));
    const sMin = Math.sqrt(Math.max(0, minVnd));
    const sMax = Math.sqrt(Math.max(0, maxVnd));
    if (sMax === sMin) return (rMin + rMax) / 2;
    let t = (s - sMin) / (sMax - sMin);
    t = Math.min(1, Math.max(0, t));
    return rMin + t * (rMax - rMin);
}

export function polarToXy(cx, cy, orbitRadius, angleDeg) {
    const rad = (angleDeg * Math.PI) / 180;
    return {
        x: cx + orbitRadius * Math.sin(rad),
        y: cy - orbitRadius * Math.cos(rad),
    };
}

function toUtcMidnight(dateStr) {
    const [y, m, d] = dateStr.slice(0, 10).split('-').map(Number);
    return Date.UTC(y, m - 1, d);
}

export function daysUntil(renewalDate, today) {
    const ms = toUtcMidnight(renewalDate) - toUtcMidnight(today);
    return Math.round(ms / 86400000);
}

export function isUrgent(days) {
    return days <= 7;
}
