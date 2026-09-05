<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionRenewalReminder extends Notification
{
    use Queueable;

    public function __construct(public Subscription $subscription) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Sắp đến hạn thanh toán: {$this->subscription->name}")
            ->greeting("Xin chào {$notifiable->name},")
            ->line(sprintf(
                'Dịch vụ "%s" sẽ gia hạn vào ngày %s với số tiền %s %s.',
                $this->subscription->name,
                $this->subscription->next_renewal_date->format('d/m/Y'),
                $this->subscription->amount,
                $this->subscription->currency,
            ))
            ->action('Xem chi tiết', route('subscriptions.show', $this->subscription))
            ->line('Nếu muốn hủy trước ngày gia hạn, vào trang chi tiết dịch vụ để thao tác.');
    }
}
