export const statusLabel = {
    active: 'Đang hoạt động',
    pending_cancel: 'Sắp hủy',
    cancelled: 'Đã hủy',
};

export const cycleLabel = {
    monthly: 'Hàng tháng',
    yearly: 'Hàng năm',
};

export function formatVnd(amountVnd) {
    return new Intl.NumberFormat('vi-VN').format(amountVnd);
}
