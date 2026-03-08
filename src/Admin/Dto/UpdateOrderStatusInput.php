<?php

namespace App\Admin\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class UpdateOrderStatusInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le statut est obligatoire.')]
        #[Assert\Type('string')]
        public ?string $status = null,
    ) {}
}
