<?php

namespace App\Shared\Service;

use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;

class PhoneNumberService
{
    private PhoneNumberUtil $util;

    public function __construct()
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    public function normalizeToE164(?string $phone, ?string $country): ?string
    {
        $phone = $phone !== null ? trim($phone) : '';
        if ($phone === '') {
            return null;
        }

        $country = $this->normalizeCountry($country);

        try {
            $number = $this->util->parse($phone, $country);
        } catch (NumberParseException $e) {
            throw new \InvalidArgumentException("Numéro de téléphone invalide pour le pays {$country}.");
        }

        if (!$this->util->isValidNumber($number) || !$this->util->isValidNumberForRegion($number, $country)) {
            throw new \InvalidArgumentException("Numéro de téléphone invalide pour le pays {$country}.");
        }

        return $this->util->format($number, PhoneNumberFormat::E164);
    }

    public function isValid(?string $phone, ?string $country): bool
    {
        $phone = $phone !== null ? trim($phone) : '';
        if ($phone === '') {
            return true;
        }

        $country = $this->normalizeCountry($country);

        try {
            $number = $this->util->parse($phone, $country);
        } catch (NumberParseException $e) {
            return false;
        }

        return $this->util->isValidNumber($number) && $this->util->isValidNumberForRegion($number, $country);
    }

    private function normalizeCountry(?string $country): string
    {
        $normalized = strtoupper(trim((string) $country));
        if ($normalized === '' || $normalized === 'FRANCE') {
            return 'FR';
        }

        return $normalized;
    }
}
