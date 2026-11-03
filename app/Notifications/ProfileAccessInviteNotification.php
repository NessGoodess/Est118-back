<?php

namespace App\Notifications;

use App\Models\ProfileAccessInvite;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProfileAccessInviteNotification extends Notification
{
    public function __construct(
        private readonly string $plainToken,
        private readonly ProfileAccessInvite $invite,
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
            .'/accept-invitation?token='.urlencode($this->plainToken);

        $name = $this->invite->profile?->fullName() ?: '';

        return (new MailMessage)
            ->subject(__('emails.access_invite.subject'))
            ->view('emails.verify-email', [
                'url' => $url,
                'title' => __('emails.access_invite.title'),
                'intro' => __('emails.access_invite.intro', ['name' => $name]),
                'content' => __('emails.access_invite.content'),
                'action' => __('emails.access_invite.action'),
                'alt' => __('emails.access_invite.alt'),
                'title_warning' => __('emails.access_invite.title-warning'),
                'warning' => __('emails.access_invite.warning'),
                'footer' => __('emails.access_invite.footer'),
                'school_name' => config('app.school_name'),
            ]);
    }
}
