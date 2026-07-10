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
            $scan->update([
                'status' => 'failed',
                'error' => 'Không đọc được Gmail. Vui lòng thử kết nối lại và quét lại.',
            ]);
        }
    }
}
