<?php

namespace App\Services;

interface MailService
{
    public function sendMails(string $title, string $body, array $recipients);
}
