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

        assert($constraint instanceof UniqueNewsletterEmail);

        $email = strtolower(trim((string) $value));

        $subscriber = $this->repository->createQueryBuilder('n')
            ->andWhere('LOWER(n.email) = :email')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($subscriber === null) {
            return;
        }

        $message = $subscriber->isConfirmed()
            ? $constraint->message
            : $constraint->pendingMessage;

        $this->context->buildViolation($message)
            ->addViolation();
    }
}
