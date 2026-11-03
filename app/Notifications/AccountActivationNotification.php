<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountActivationNotification extends Notification
{
    public function __construct(
        private readonly string $plainToken,
        private readonly User $user,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/activate-account?token='.urlencode($this->plainToken);

        return (new MailMessage)
            ->subject(__('emails.account_activation.subject'))
            ->view('emails.verify-email', [
                'url' => $url,
                'title' => __('emails.account_activation.title'),
                'intro' => __('emails.account_activation.intro', ['name' => $this->user->name]),
                'content' => __('emails.account_activation.content'),
                'action' => __('emails.account_activation.action'),
                'alt' => __('emails.account_activation.alt'),
                'title_warning' => __('emails.account_activation.title-warning'),
                'warning' => __('emails.account_activation.warning'),
                'footer' => __('emails.account_activation.footer'),
                'school_name' => config('app.school_name'),
            ]);
    }
}
