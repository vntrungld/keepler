// Maps a subscription name to a stable brand color, an initial letter, and a
// readable text color. Pure and unit-tested in brandColors.test.js.

// Ordered so earlier keywords win when a name contains several.
const CATALOG = [
    ['netflix', '#E50914'],
    ['spotify', '#1DB954'],
    ['youtube', '#FF0000'],
    ['chatgpt', '#10A37F'],
    ['openai', '#10A37F'],
    ['adobe', '#FF0000'],
    ['apple', '#555555'],
    ['google', '#4285F4'],
    ['amazon', '#FF9900'],
    ['prime', '#FF9900'],
    ['disney', '#113CCF'],
];

// Fallback palette for unknown services (all hex so contrastText works).
const PALETTE = [
    '#6366F1', '#EC4899', '#F59E0B', '#10B981',
    '#3B82F6', '#8B5CF6', '#EF4444', '#14B8A6',
];

function normalize(name) {
    return (name || '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

function hashString(s) {
    let h = 0;
    for (let i = 0; i < s.length; i++) {
        h = (h * 31 + s.charCodeAt(i)) >>> 0;
    }
    return h;
}

export function brandColor(name) {
    const norm = normalize(name);
    for (const [keyword, color] of CATALOG) {
        if (norm.includes(keyword)) return color;
    }
    return PALETTE[hashString(norm) % PALETTE.length];
}

export function initial(name) {
    const match = (name || '').match(/[a-z0-9]/i);
    return match ? match[0].toUpperCase() : '?';
}

export function contrastText(hex) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
    return luminance > 150 ? '#000' : '#fff';
}
