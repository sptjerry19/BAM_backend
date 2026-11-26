<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PasswordResetCode extends Mailable
{
    public function __construct(public readonly string $code) {}

    public function build(): static
    {
        return $this->subject('Đặt lại mật khẩu JBadminton')
            ->text('emails.password-reset-code');
    }
}
