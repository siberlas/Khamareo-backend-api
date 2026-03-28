<?php

namespace App\Shipping\DTO\MondialRelay;

readonly class MondialRelayAddressDTO
{
    public function __construct(
        public string  $title = '',
        public string  $firstname = '',
        public string  $lastname = '',
        public string  $addressAdd1 = '',   // Required: name on label
        public string  $addressAdd2 = '',
        public string  $addressAdd3 = '',
        public string  $streetname = '',     // Required
        public string  $houseNo = '',
        public string  $postcode = '',       // Required
        public string  $city = '',           // Required
        public string  $countryCode = '',    // Required (ISO 2-letter)
        public string  $phoneNo = '',
        public string  $mobileNo = '',
        public string  $email = '',
    ) {}
}
