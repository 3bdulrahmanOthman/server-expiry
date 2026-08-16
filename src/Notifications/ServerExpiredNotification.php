<?php

namespace PelicanDev\ServerExpiry\Notifications;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServerExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Server $server)
    {
    }

    /**
     * Get the notification's delivery channels.
     * The `database` channel is safe here — Pelican ships the notifications table.
     */
    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Server Suspended: {$this->server->name} has expired")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your server '{$this->server->name}' (ID: {$this->server->id}) has reached its expiration date ({$this->server->expires_at}).")
            ->line('In accordance with system policy, the server has been automatically suspended.')
            ->action('View Server', url("/server/{$this->server->uuid}"))
            ->line('Please renew your server subscription or contact support to reactivate your server.');
    }

    /**
     * Get the array representation of the notification for the database channel.
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'server_id' => $this->server->id,
            'server_name' => $this->server->name,
            'expires_at' => (string) $this->server->expires_at,
            'suspended_at' => now()->toDateTimeString(),
            'message' => "Server '{$this->server->name}' auto-suspended due to expiration at {$this->server->expires_at}.",
        ];
    }
}
