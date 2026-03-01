<?php

namespace App\Admin\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class UpdateOrderStatusInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Type('string')]
        public ?string $status = null,
    ) {}
}
