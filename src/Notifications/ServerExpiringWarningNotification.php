<?php

namespace PelicanDev\ServerExpiry\Notifications;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServerExpiringWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Server $server,
        public readonly int $warningDay,
        public readonly int $daysRemaining,
    ) {
    }

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $expiresAt = $this->server->expires_at;

        return (new MailMessage)
            ->subject("Server Expiring Soon: {$this->server->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your server '{$this->server->name}' (ID: {$this->server->id}) will expire in {$this->daysRemaining} day(s).")
            ->line("Expiration date: {$expiresAt}")
            ->line('If you do not renew before this date, the server will be automatically suspended and taken offline.')
            ->action('View Server', url("/server/{$this->server->uuid}"))
            ->line('Please renew your server subscription or contact support to keep your server running.');
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'expires_at' => (string) $this->server->expires_at,
            'warning_day' => $this->warningDay,
            'days_remaining' => $this->daysRemaining,
            'message' => "Server '{$this->server->name}' expires in {$this->daysRemaining} day(s) on {$this->server->expires_at}. Renew to avoid automatic suspension.",
        ];
    }
}
