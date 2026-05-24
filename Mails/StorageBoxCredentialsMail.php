<?php

namespace Paymenter\Extensions\Servers\HetznerStorageBox\Mails;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StorageBoxCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User   $user,
        public readonly string $username,
        public readonly string $hostname,
        public readonly string $password,
        public readonly bool   $isReset = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isReset
            ? 'Your Storage Box — New Credentials'
            : 'Your Storage Box is Ready';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        \Illuminate\Support\Facades\View::addNamespace(
            'hetzner_storage_box',
            __DIR__ . '/../resources/views'
        );

        return new Content(
            view: 'hetzner_storage_box::credentials',
        );
    }
}
