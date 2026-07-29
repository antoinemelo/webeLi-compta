<?php
declare(strict_types=1);

namespace Compta\Core\Mail;

interface Mailer
{
    public function send(string $recipient, string $subject, string $text): void;
}
