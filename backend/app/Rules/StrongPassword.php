<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * The single password policy shared by registration and password reset.
 * Keep the rules aligned with frontend/src/lib/authValidation.js.
 */
class StrongPassword implements Rule
{
    private string $firstName;
    private string $lastName;
    private string $email;

    public function __construct(?string $firstName = '', ?string $lastName = '', ?string $email = '')
    {
        $this->firstName = trim((string) $firstName);
        $this->lastName = trim((string) $lastName);
        $this->email = trim((string) $email);
    }

    public function passes($attribute, $value): bool
    {
        $password = (string) $value;

        if (mb_strlen($password) < 8) {
            return false;
        }

        if (! preg_match('/\d/', $password)) {
            return false;
        }

        if (! preg_match('/[^A-Za-z0-9]/', $password)) {
            return false;
        }

        return ! $this->containsPersonalInformation($password);
    }

    public function message(): string
    {
        return 'The password must be at least 8 characters and include a number and a symbol. It must not contain your name or email.';
    }

    private function containsPersonalInformation(string $password): bool
    {
        $value = mb_strtolower($password);
        $localPart = strstr($this->email, '@', true) ?: '';

        $parts = [
            $this->firstName,
            $this->lastName,
            $this->firstName . $this->lastName,
            $this->firstName . ' ' . $this->lastName,
            $localPart,
        ];

        foreach ($parts as $part) {
            $part = mb_strtolower(trim((string) $part));

            if ($part === '' || mb_strlen($part) < 3) {
                continue;
            }

            $compact = preg_replace('/\s+/', '', $part);

            if ($compact !== '' && strpos($value, $compact) !== false) {
                return true;
            }

            if (strpos($value, $part) !== false) {
                return true;
            }
        }

        return false;
    }
}
