import { describe, it, expect } from 'vitest';
import { statusLabel, cycleLabel, endedLabel, periodsLabel, formatVnd } from './labels.js';

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

describe('periodsLabel', () => {
    it('is empty for an open-ended subscription', () => {
        expect(periodsLabel({ total_periods: null, periods_remaining: null, has_ended: false })).toBe('');
    });

    it('counts the periods still to be paid', () => {
        expect(periodsLabel({ total_periods: 12, periods_remaining: 9, has_ended: false })).toBe('Còn 9/12 kỳ');
    });

    it('reports a finished plan as fully paid', () => {
        expect(periodsLabel({ total_periods: 12, periods_remaining: 0, has_ended: true })).toBe('Đã trả xong 12/12 kỳ');
    });
});

describe('endedLabel', () => {
    it('names the derived ended state', () => {
        expect(endedLabel).toBe('Đã kết thúc');
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
