<?php

namespace App\Marketing\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use App\Marketing\Validator\Constraints\UniqueNewsletterEmail;
use Symfony\Component\Serializer\Annotation\Groups;

class NewsletterSubscriptionInput
{
    #[Groups(['newsletter:create'])]
    #[Assert\NotBlank(message: "L'email est obligatoire.", groups: ['newsletter:create'])]
    #[Assert\Email(message: "L'adresse email '{{ value }}' n'est pas valide.", groups: ['newsletter:create'])]
    #[UniqueNewsletterEmail(groups: ['newsletter:create'])]
    public ?string $email = null;
}
