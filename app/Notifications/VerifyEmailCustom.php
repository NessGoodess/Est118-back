<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailCustom extends VerifyEmail
{
    protected function buildMailMessage($url)
    {
        return (new MailMessage)
            ->subject(__('emails.verify.subject'))
            ->view('emails.verify-email', [
                'url' => $url,
                'title' => __('emails.verify.title'),
                'intro' => __('emails.verify.intro'),
                'content' => __('emails.verify.content'),
                'action' => __('emails.verify.action'),
                'alt' => __('emails.verify.alt'),
                'title_warning' => __('emails.verify.title-warning'),
                'warning' => __('emails.verify.warning'),
                'footer' => __('emails.verify.footer'),
                'school_name' => 'Escuela Secundaria Técnica No. 118',
            ]);
            /*->withSymfonyMessage(function ($message) {
                $path = public_path('storage/images/Logo_EST118.png');

                if (file_exists($path)) {
                    $message->embedFromPath($path, 'logo_est118');
                }
            });*/
    }
}
