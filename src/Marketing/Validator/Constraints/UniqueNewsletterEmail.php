<?php

namespace App\Marketing\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class UniqueNewsletterEmail extends Constraint
{
    public string $message = 'Cet email est déjà inscrit à la newsletter.';

    public function validatedBy(): string
    {
        return UniqueNewsletterEmailValidator::class;
    }
}
