<?php

namespace App\Marketing\Validator\Constraints;

use App\Marketing\Repository\NewsletterSubscriberRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class UniqueNewsletterEmailValidator extends ConstraintValidator
{
    public function __construct(private NewsletterSubscriberRepository $repository) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $email = strtolower(trim((string) $value));

        $qb = $this->repository->createQueryBuilder('n');
        $exists = (bool) $qb
            ->select('COUNT(n.id)')
            ->andWhere('LOWER(n.email) = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();

        if ($exists) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
