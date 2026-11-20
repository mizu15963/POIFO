<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 管理者への異常通知。成果通知の応答（10秒以内）を待たせないようキューで送る。
 * 本文に口座番号・APIキー・ハッシュの値などの秘密は入れない。
 */
class AdminAlert extends Mailable implements ShouldQueue
{
    use Queueable;

    /** @param array<string, scalar|null> $details */
    public function __construct(public string $title, public array $details = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[POIFO] '.$this->title);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.admin-alert');
    }
}
