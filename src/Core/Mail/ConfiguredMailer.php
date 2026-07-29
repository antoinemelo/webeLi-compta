<?php
declare(strict_types=1);

namespace Compta\Core\Mail;

use Compta\Core\Config\AppConfig;
use RuntimeException;

final class ConfiguredMailer implements Mailer
{
    public function __construct(private readonly AppConfig $config)
    {
    }

    public function send(string $recipient, string $subject, string $text): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Destinataire e-mail invalide.');
        }
        $transport = mb_strtolower(trim($this->config->string('mail_transport')));
        if ($transport === 'smtp') {
            $this->sendSmtp($recipient, $subject, $text);
            return;
        }
        if ($transport !== 'php') {
            throw new RuntimeException('Transport e-mail non configuré.');
        }
        $from = $this->fromAddress();
        $name = $this->headerValue($this->config->string('mail_from_name'));
        $headers = [
            'From: ' . ($name !== '' ? $name . ' ' : '') . '<' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        if (!mail(
            $recipient,
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            $text,
            implode("\r\n", $headers)
        )) {
            throw new RuntimeException('Le message de sécurité n’a pas pu être envoyé.');
        }
    }

    private function sendSmtp(string $recipient, string $subject, string $text): void
    {
        $host = trim($this->config->string('smtp_host'));
        $port = $this->config->int('smtp_port');
        $encryption = mb_strtolower(trim($this->config->string('smtp_encryption')));
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('Configuration SMTP incomplète.');
        }
        if (!in_array($encryption, ['', 'none', 'tls', 'ssl'], true)) {
            throw new RuntimeException('Chiffrement SMTP invalide.');
        }
        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
            ],
        ]);
        $socket = @stream_socket_client(
            $remote,
            $errorNumber,
            $errorMessage,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('Connexion SMTP impossible.');
        }
        stream_set_timeout($socket, 10);
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO localhost', [250]);
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Négociation TLS SMTP impossible.');
                }
                $this->command($socket, 'EHLO localhost', [250]);
            }
            $username = trim($this->config->string('smtp_username'));
            if ($username !== '') {
                if ($encryption === '' || $encryption === 'none') {
                    throw new RuntimeException(
                        'L’authentification SMTP exige TLS ou SSL.'
                    );
                }
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($username), [334]);
                $this->command(
                    $socket,
                    base64_encode($this->config->string('smtp_password')),
                    [235]
                );
            }
            $from = $this->fromAddress();
            $this->command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $fromName = $this->headerValue($this->config->string('mail_from_name'));
            $message = implode("\n", [
                'From: ' . ($fromName !== '' ? $fromName . ' ' : '') . '<' . $from . '>',
                'To: <' . $recipient . '>',
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                str_replace("\n.", "\n..", str_replace(["\r\n", "\r"], "\n", $text)),
            ]);
            fwrite($socket, str_replace("\n", "\r\n", $message) . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket @param list<int> $codes */
    private function command($socket, string $command, array $codes): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $codes);
    }

    /** @param resource $socket @param list<int> $codes */
    private function expect($socket, array $codes): void
    {
        $response = '';
        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new RuntimeException('Réponse SMTP absente.');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('Le serveur SMTP a refusé le message.');
        }
    }

    private function fromAddress(): string
    {
        $from = trim($this->config->string('mail_from_address'));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresse d’expédition invalide.');
        }
        return $from;
    }

    private function headerValue(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
