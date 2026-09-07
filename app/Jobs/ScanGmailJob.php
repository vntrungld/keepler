<?php

namespace App\Jobs;

use App\Models\GmailScan;
use App\Support\Gmail\GmailClient;
use App\Support\Gmail\SubscriptionScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanGmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A scan reads up to 100 messages one Gmail API call at a time, so give it
     * room. Keep this below the queue connection's `retry_after` so the worker
     * kills the job before the queue hands the same scan to a second worker.
     */
    public int $timeout = 280;

    /**
     * The dashboard polls a single scan row, so a silent retry would leave the
     * user staring at a stalled progress bar. Fail once and surface it.
     */
    public int $tries = 1;

    private const FAILURE_MESSAGE = 'Không đọc được Gmail. Vui lòng thử kết nối lại và quét lại.';

    public function __construct(public GmailScan $scan)
    {
    }

    public function handle(): void
    {
        $scan = $this->scan;
        $user = $scan->user;

        $scan->update(['status' => 'running']);

        try {
            $scanner = new SubscriptionScanner(new GmailClient($user));

            $candidates = $scanner->scan($user, function (int $processed, int $total) use ($scan) {
                $scan->update(['processed' => $processed, 'total' => $total]);
            });

            $scan->update([
                'candidates' => $candidates,
                'status' => 'done',
            ]);
        } catch (\Throwable $e) {
            report($e);

            $this->markFailed();
        }
    }

    /**
     * Called when the worker kills the job outright — a timeout, an out-of-memory
     * kill, or the container shutting down mid-scan. Without this the scan row
     * would sit at `running` forever and the dashboard would poll indefinitely.
     */
    public function failed(?\Throwable $e): void
    {
        $this->markFailed();
    }

    private function markFailed(): void
    {
        $this->scan->update([
            'status' => 'failed',
            'error' => self::FAILURE_MESSAGE,
        ]);
    }
}
