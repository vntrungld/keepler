// Gmail scan lifecycle — starting a background scan and polling it to
// completion. Lives here rather than inside a page because two surfaces
// drive the same scan: the dashboard's empty state (first run) and the
// Gmail card on the settings page (every run after the user has
// subscriptions, where the empty state no longer exists).
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import axios from 'axios';

const POLL_INTERVAL_MS = 1000;

export function useGmailScan() {
    const scanning = ref(false);
    const scanError = ref(null);
    const processed = ref(0);
    const total = ref(0);
    const percent = ref(0);
    let pollTimer = null;

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function resetScanState() {
        scanError.value = null;
        processed.value = 0;
        total.value = 0;
        percent.value = 0;
    }

    function pollScan(id) {
        pollTimer = setInterval(async () => {
            try {
                const { data } = await axios.get(route('gmail.scans.show', id));
                processed.value = data.processed;
                total.value = data.total;
                percent.value = data.percent;

                if (data.status === 'done') {
                    stopPolling();
                    router.visit(route('gmail.scans.results', id));
                } else if (data.status === 'failed') {
                    stopPolling();
                    scanError.value =
                        'Quét Gmail thất bại. Vui lòng thử kết nối lại và quét lại.';
                }
            } catch (e) {
                stopPolling();
                scanError.value = 'Mất kết nối khi theo dõi tiến trình.';
            }
        }, POLL_INTERVAL_MS);
    }

    async function startScan() {
        scanning.value = true;
        resetScanState();

        try {
            const { data } = await axios.post(route('gmail.scans.store'));
            pollScan(data.id);
        } catch (e) {
            // The server answers 409 with a consent URL when the stored
            // Gmail grant is missing or expired — send the user to re-consent
            // rather than showing an error they can't act on.
            if (e.response?.status === 409 && e.response.data?.connect_url) {
                window.location.href = e.response.data.connect_url;
                return;
            }
            scanError.value = 'Không bắt đầu quét được. Vui lòng thử lại.';
        }
    }

    function closeScan() {
        stopPolling();
        scanning.value = false;
    }

    onBeforeUnmount(stopPolling);

    return { scanning, scanError, processed, total, percent, startScan, closeScan };
}
