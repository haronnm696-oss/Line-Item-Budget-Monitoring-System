<?php

namespace App\Mail\Transport;

use Illuminate\Mail\Transport\Transport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Swift_Attachment;
use Swift_Mime_SimpleMessage;
use Swift_MimePart;
use Swift_TransportException;
use Throwable;

/**
 * Sends mail through Brevo's HTTPS API (port 443) instead of SMTP, because
 * Render's free tier blocks outbound SMTP ports. Selected with
 * MAIL_MAILER=brevo; every Mailable and mail notification uses it unchanged.
 *
 * A failure is logged with the provider's response (never the API key) and
 * rethrown as a Swift_TransportException with a key-free message, so callers
 * that already catch mailer errors keep working.
 */
class BrevoTransport extends Transport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    /** @var string */
    protected $key;

    /** @var int */
    protected $timeout;

    public function __construct(?string $key, int $timeout = 10)
    {
        $this->key = (string) $key;
        $this->timeout = $timeout;
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        if ($this->key === '') {
            Log::error('Brevo mail not sent: BREVO_API_KEY is not set.');

            throw new Swift_TransportException('Brevo mail not sent: BREVO_API_KEY is not set.');
        }

        $payload = $this->payload($message);

        try {
            $response = Http::withHeaders([
                'api-key' => $this->key,
                'accept' => 'application/json',
            ])->timeout($this->timeout)->asJson()->post(self::ENDPOINT, $payload);
        } catch (Throwable $e) {
            Log::error('Brevo mail request failed.', [
                'error' => $this->redact($e->getMessage()),
                'subject' => $message->getSubject(),
            ]);

            throw new Swift_TransportException('Brevo mail request failed.');
        }

        if (! $response->successful()) {
            Log::error('Brevo rejected the mail.', [
                'status' => $response->status(),
                'response' => $this->redact(mb_substr((string) $response->body(), 0, 2000)),
                'subject' => $message->getSubject(),
            ]);

            throw new Swift_TransportException('Brevo rejected the mail (HTTP ' . $response->status() . ').');
        }

        if ($messageId = $response->json('messageId')) {
            $message->getHeaders()->addTextHeader('X-Brevo-Message-ID', (string) $messageId);
        }

        $this->sendPerformed($message);

        return $this->numberOfRecipients($message);
    }

    /** The Brevo JSON body for this message. */
    protected function payload(Swift_Mime_SimpleMessage $message): array
    {
        $payload = [
            'sender' => $this->firstAddress((array) $message->getFrom()),
            'to' => $this->addresses((array) $message->getTo()),
            'subject' => (string) $message->getSubject(),
        ];

        foreach (['cc' => $message->getCc(), 'bcc' => $message->getBcc()] as $field => $list) {
            if ($list) {
                $payload[$field] = $this->addresses((array) $list);
            }
        }

        if ($replyTo = $message->getReplyTo()) {
            $payload['replyTo'] = $this->firstAddress((array) $replyTo);
        }

        [$html, $text, $attachments] = $this->contents($message);

        if ($html !== null) {
            $payload['htmlContent'] = $html;
        }
        if ($text !== null) {
            $payload['textContent'] = $text;
        }
        if ($html === null && $text === null) {
            $payload['textContent'] = ' ';
        }
        if ($attachments) {
            $payload['attachment'] = $attachments;
        }

        return $payload;
    }

    /** [html, text, attachments] from the message body and its parts. */
    protected function contents(Swift_Mime_SimpleMessage $message): array
    {
        $html = null;
        $text = null;
        $attachments = [];

        $body = $message->getBody();
        if (is_string($body) && $body !== '') {
            if (stripos((string) $message->getBodyContentType(), 'text/html') === 0) {
                $html = $body;
            } else {
                $text = $body;
            }
        }

        foreach ($message->getChildren() as $child) {
            if ($child instanceof Swift_Attachment) {
                $attachments[] = [
                    'name' => $child->getFilename() ?: 'attachment',
                    'content' => base64_encode((string) $child->getBody()),
                ];
            } elseif ($child instanceof Swift_MimePart) {
                $type = strtolower((string) $child->getContentType());
                if ($type === 'text/html' && $html === null) {
                    $html = (string) $child->getBody();
                } elseif ($type === 'text/plain' && $text === null) {
                    $text = (string) $child->getBody();
                }
            }
        }

        return [$html, $text, $attachments];
    }

    /** Swift's [email => name] map as Brevo's [{email, name}] list. */
    protected function addresses(array $map): array
    {
        $list = [];
        foreach ($map as $email => $name) {
            $entry = ['email' => (string) $email];
            if ($name !== null && $name !== '') {
                $entry['name'] = (string) $name;
            }
            $list[] = $entry;
        }

        return $list;
    }

    protected function firstAddress(array $map): array
    {
        return $this->addresses($map)[0] ?? ['email' => (string) config('mail.from.address')];
    }

    /** Keep the API key out of anything that gets logged. */
    protected function redact(string $text): string
    {
        return $this->key === '' ? $text : str_replace($this->key, '[redacted]', $text);
    }
}
