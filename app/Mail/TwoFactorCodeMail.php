<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/** El código del segundo paso. No va en el asunto: se ve sin desbloquear. */
class TwoFactorCodeMail extends Mailable
{
    public function __construct(
        public readonly string $memberName,
        #[SensitiveParameter]
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código de acceso a Traigo');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.two-factor-code');
    }
}
