<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 会員のメールアドレスの確認メール（機能仕様 3.5）。
 * SES が遅い・失敗しても登録の API を待たせないよう、キューで送る。
 */
class EmailVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $url, public int $validHours) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【ポイフォ】メールアドレスの確認');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.email-verification');
    }
}
