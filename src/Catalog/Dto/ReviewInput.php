<?php

namespace App\Catalog\Dto;

use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ReviewInput
{
    #[Groups(['review:write'])]
    #[Assert\NotBlank]
    public ?string $product = null;

    #[Groups(['review:write'])]
    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 5)]
    public ?int $rating = null;

    #[Groups(['review:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(min: 5, max: 1000)]
    public ?string $comment = null;

    #[Groups(['review:write'])]
    #[Assert\NotBlank(groups: ['guest'])]
    #[Assert\Length(min: 2, max: 255, groups: ['guest'])]
    public ?string $name = null;

    #[Groups(['review:write'])]
    #[Assert\NotBlank(groups: ['guest'])]
    #[Assert\Email(groups: ['guest'])]
    public ?string $email = null;
}
