import { describe, it, expect } from 'vitest';
import { statusLabel, cycleLabel, formatVnd } from './labels.js';

describe('statusLabel', () => {
    it('covers every subscription status', () => {
        expect(statusLabel).toEqual({
            active: 'Đang hoạt động',
            pending_cancel: 'Sắp hủy',
            cancelled: 'Đã hủy',
        });
    });
});

describe('cycleLabel', () => {
    it('covers every billing cycle', () => {
        expect(cycleLabel).toEqual({
            monthly: 'Hàng tháng',
            yearly: 'Hàng năm',
        });
    });
});

describe('formatVnd', () => {
    it('formats with Vietnamese thousands separators', () => {
        expect(formatVnd(260000)).toBe('260.000');
        expect(formatVnd(15596880)).toBe('15.596.880');
    });
    it('formats zero and small amounts without separators', () => {
        expect(formatVnd(0)).toBe('0');
        expect(formatVnd(59)).toBe('59');
    });
});
