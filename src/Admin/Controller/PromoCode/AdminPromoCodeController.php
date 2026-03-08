<?php

namespace App\Admin\Controller\PromoCode;

use App\Marketing\Entity\PromoCode;
use App\Marketing\Entity\PromoCodeRecipient;
use App\Marketing\Repository\PromoCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/promo-codes', name: 'admin_promo_codes_')]
class AdminPromoCodeController extends AbstractController
{
    public function __construct(
        private PromoCodeRepository $promoCodeRepository,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = max(1, min(100, (int) $request->query->get('itemsPerPage', 20)));
        $codeFilter = $request->query->get('code');
        $typeFilter = $request->query->get('type');
        $activeFilter = $request->query->get('active');
        $eligibleCustomer = $request->query->get('eligibleCustomer');

        $qb = $this->promoCodeRepository->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC');

        if ($codeFilter) {
            $qb->andWhere('p.code LIKE :code')->setParameter('code', '%' . $codeFilter . '%');
        }
        if ($typeFilter) {
            $qb->andWhere('p.type = :type')->setParameter('type', $typeFilter);
        }
        if ($activeFilter !== null && $activeFilter !== '') {
            $isActive = filter_var($activeFilter, FILTER_VALIDATE_BOOLEAN);
            $qb->andWhere('p.isActive = :isActive')->setParameter('isActive', $isActive);
        }
        if ($eligibleCustomer) {
            $qb->andWhere('p.eligibleCustomer = :eligibleCustomer')->setParameter('eligibleCustomer', $eligibleCustomer);
        }

        $totalQb = clone $qb;
        $total = (int) $totalQb->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $promoCodes = $qb->select('p')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
            ->getQuery()
            ->getResult();

        return $this->json([
            '@context' => '/api/contexts/PromoCode',
            '@id' => '/api/admin/promo-codes',
            '@type' => 'hydra:Collection',
            'hydra:member' => array_map(fn($p) => $this->serialize($p), $promoCodes),
            'hydra:totalItems' => $total,
        ]);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $promoCode = $this->findOrFail($id);
        return $this->json($this->serialize($promoCode, withRedemptions: true));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['code'])) {
            return $this->json(['error' => 'Le champ code est requis'], 400);
        }

        $existing = $this->promoCodeRepository->findOneBy(['code' => strtoupper($data['code'])]);
        if ($existing) {
            return $this->json(['error' => 'Ce code promo existe déjà'], 409);
        }

        $promoCode = new PromoCode();
        $this->hydrate($promoCode, $data);

        $this->em->persist($promoCode);
        $this->em->flush();

        return $this->json($this->serialize($promoCode), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $promoCode = $this->findOrFail($id);
        $data = json_decode($request->getContent(), true) ?? [];

        $this->hydrate($promoCode, $data);
        $this->em->flush();

        return $this->json($this->serialize($promoCode));
    }

    #[Route('/{id}/redemptions', name: 'redemptions', methods: ['GET'])]
    public function redemptions(string $id): JsonResponse
    {
        $promoCode = $this->findOrFail($id);

        $rows = $this->em->createQuery(
            'SELECT r FROM App\Marketing\Entity\PromoCodeRedemption r WHERE r.promoCode = :promo ORDER BY r.usedAt DESC'
        )->setParameter('promo', $promoCode)->getResult();

        $result = array_map(fn($r) => [
            'id'             => $r->getId()?->toRfc4122(),
            '@id'            => '/api/admin/promo-codes/' . $id . '/redemptions/' . $r->getId()?->toRfc4122(),
            'email'          => $r->getEmail(),
            'usedAt'         => $r->getUsedAt()?->format(\DateTime::ATOM),
            'discountAmount' => $r->getDiscountAmount(),
            'customerType'   => $r->getCustomerType(),
            'order'          => $r->getOrder() ? '/api/orders/' . $r->getOrder()->getId() : null,
        ], $rows);

        return $this->json([
            'hydra:member'     => $result,
            'hydra:totalItems' => count($result),
        ]);
    }

    #[Route('/{id}/recipients/import', name: 'recipients_import', methods: ['POST'])]
    public function importRecipients(string $id, Request $request): JsonResponse
    {
        $promoCode = $this->findOrFail($id);

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'Fichier CSV requis'], 400);
        }

        $content = file_get_contents($file->getPathname());
        $lines = preg_split('/[\r\n]+/', trim($content));

        $recipientRepo = $this->em->getRepository(PromoCodeRecipient::class);
        $imported = 0;

        foreach ($lines as $line) {
            $email = strtolower(trim($line));
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $existing = $recipientRepo->findOneBy(['promoCode' => $promoCode, 'email' => $email]);
            if (!$existing) {
                $recipient = new PromoCodeRecipient();
                $recipient->setPromoCode($promoCode);
                $recipient->setEmail($email);
                $this->em->persist($recipient);
                $imported++;
            }
        }

        $this->em->flush();

        return $this->json(['imported' => $imported, 'success' => true]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $promoCode = $this->findOrFail($id);
        $this->em->remove($promoCode);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    private function findOrFail(string $id): PromoCode
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\Exception) {
            throw $this->createNotFoundException('UUID invalide');
        }

        $promoCode = $this->promoCodeRepository->findOneBy(['id' => $uuid]);
        if (!$promoCode) {
            throw $this->createNotFoundException('Code promo introuvable');
        }

        return $promoCode;
    }

    private function hydrate(PromoCode $promoCode, array $data): void
    {
        if (isset($data['code'])) $promoCode->setCode(strtoupper($data['code']));
        if (isset($data['type'])) $promoCode->setType($data['type']);
        if (isset($data['discountPercentage'])) $promoCode->setDiscountPercentage((string) $data['discountPercentage']);
        if (array_key_exists('discountAmount', $data)) $promoCode->setDiscountAmount($data['discountAmount'] !== null ? (string) $data['discountAmount'] : null);
        if (array_key_exists('email', $data)) $promoCode->setEmail($data['email'] ?? '');
        if (isset($data['expiresAt'])) $promoCode->setExpiresAt(new \DateTimeImmutable($data['expiresAt']));
        if (array_key_exists('startsAt', $data)) $promoCode->setStartsAt($data['startsAt'] ? new \DateTimeImmutable($data['startsAt']) : null);
        if (isset($data['isActive'])) $promoCode->setIsActive((bool) $data['isActive']);
        if (isset($data['active'])) $promoCode->setIsActive((bool) $data['active']);
        if (isset($data['isUsed'])) $promoCode->setIsUsed((bool) $data['isUsed']);
        if (array_key_exists('minOrderAmount', $data)) $promoCode->setMinOrderAmount($data['minOrderAmount'] !== null ? (string) $data['minOrderAmount'] : null);
        if (array_key_exists('maxUses', $data)) $promoCode->setMaxUses($data['maxUses']);
        if (array_key_exists('maxUsesPerEmail', $data)) $promoCode->setMaxUsesPerEmail($data['maxUsesPerEmail']);
        if (isset($data['eligibleCustomer'])) $promoCode->setEligibleCustomer($data['eligibleCustomer']);
        if (isset($data['stackable'])) $promoCode->setStackable((bool) $data['stackable']);
        if (isset($data['firstOrderOnly'])) $promoCode->setFirstOrderOnly((bool) $data['firstOrderOnly']);
        if (array_key_exists('maxDiscountAmount', $data)) $promoCode->setMaxDiscountAmount($data['maxDiscountAmount'] !== null ? (string) $data['maxDiscountAmount'] : null);
        if (array_key_exists('usageWindowDays', $data)) $promoCode->setUsageWindowDays($data['usageWindowDays']);
        if (isset($data['autoApply'])) $promoCode->setAutoApply((bool) $data['autoApply']);
        if (array_key_exists('allowedCountries', $data)) $promoCode->setAllowedCountries($data['allowedCountries']);
        if (array_key_exists('allowedLocales', $data)) $promoCode->setAllowedLocales($data['allowedLocales']);
        if (array_key_exists('allowedChannels', $data)) $promoCode->setAllowedChannels($data['allowedChannels']);
    }

    private function serialize(PromoCode $p, bool $withRedemptions = false): array
    {
        $usesCount = (int) $this->em->createQuery(
            'SELECT COUNT(r.id) FROM App\Marketing\Entity\PromoCodeRedemption r WHERE r.promoCode = :promo'
        )->setParameter('promo', $p)->getSingleScalarResult();

        $redemptions = [];
        if ($withRedemptions) {
            $rows = $this->em->createQuery(
                'SELECT r FROM App\Marketing\Entity\PromoCodeRedemption r WHERE r.promoCode = :promo ORDER BY r.usedAt DESC'
            )->setParameter('promo', $p)->getResult();

            foreach ($rows as $r) {
                $redemptions[] = [
                    'id'             => $r->getId()?->toRfc4122(),
                    '@id'            => '/api/admin/promo-codes/' . $p->getId()->toRfc4122() . '/redemptions/' . $r->getId()?->toRfc4122(),
                    'email'          => $r->getEmail(),
                    'usedAt'         => $r->getUsedAt()?->format(\DateTime::ATOM),
                    'discountAmount' => $r->getDiscountAmount(),
                    'customerType'   => $r->getCustomerType(),
                    'order'          => $r->getOrder() ? '/api/orders/' . $r->getOrder()->getId() : null,
                ];
            }
        }

        return [
            '@id' => '/api/admin/promo-codes/' . $p->getId()->toRfc4122(),
            'id' => $p->getId()->toRfc4122(),
            'code' => $p->getCode(),
            'type' => $p->getType(),
            'discountPercentage' => $p->getDiscountPercentage(),
            'discountAmount' => $p->getDiscountAmount(),
            'email' => $p->getEmail(),
            'createdAt' => $p->getCreatedAt()?->format(\DateTime::ATOM),
            'usedAt' => $p->getUsedAt()?->format(\DateTime::ATOM),
            'expiresAt' => $p->getExpiresAt()?->format(\DateTime::ATOM),
            'startsAt' => $p->getStartsAt()?->format(\DateTime::ATOM),
            'isUsed' => $p->isUsed(),
            'isActive' => $p->isActive(),
            'active' => $p->isActive(),
            'minOrderAmount' => $p->getMinOrderAmount(),
            'maxUses' => $p->getMaxUses(),
            'maxUsesPerEmail' => $p->getMaxUsesPerEmail(),
            'eligibleCustomer' => $p->getEligibleCustomer(),
            'stackable' => $p->isStackable(),
            'firstOrderOnly' => $p->isFirstOrderOnly(),
            'maxDiscountAmount' => $p->getMaxDiscountAmount(),
            'usageWindowDays' => $p->getUsageWindowDays(),
            'autoApply' => $p->isAutoApply(),
            'allowedCountries' => $p->getAllowedCountries(),
            'allowedLocales' => $p->getAllowedLocales(),
            'allowedChannels' => $p->getAllowedChannels(),
            'isValid' => $p->isValid(),
            'usesCount' => $usesCount,
            'redemptions' => $redemptions,
        ];
    }
}
