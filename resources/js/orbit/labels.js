export const statusLabel = {
    active: 'Đang hoạt động',
    pending_cancel: 'Sắp hủy',
    cancelled: 'Đã hủy',
};

export const cycleLabel = {
    monthly: 'Hàng tháng',
    yearly: 'Hàng năm',
};

// A fixed-term plan is never stored as a status — it is "ended" the moment
// its last billing is behind us, so this label is derived, like has_ended.
export const endedLabel = 'Đã kết thúc';

/**
 * "Còn 9/12 kỳ" for a plan with a fixed number of billings; empty for the
 * open-ended subscriptions that make up most of the list.
 */
export function periodsLabel(sub) {
    if (!sub.total_periods) return '';

    if (sub.has_ended) {
        return `Đã trả xong ${sub.total_periods}/${sub.total_periods} kỳ`;
    }

    return `Còn ${sub.periods_remaining}/${sub.total_periods} kỳ`;
}

export function formatVnd(amountVnd) {
    return new Intl.NumberFormat('vi-VN').format(amountVnd);
}
