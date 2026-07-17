<?php

namespace App\Admin\Controller\Scheduler;

use App\Cart\Command\CartReminderCommand;
use App\Marketing\Command\NotifyStockAlertsCommand;
use App\Marketing\Command\SendNewsletterReminderCommand;
use App\Scheduler\Entity\CronJob;
use App\Scheduler\Repository\CronJobRepository;
use App\Scheduler\Service\CronJobRunner;
use App\User\Command\SendVerificationReminderCommand;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
#[Route('/api/admin/cron-jobs', name: 'admin_cron_jobs_')]
class CronJobController extends AbstractController
{
    public function __construct(
        private readonly CronJobRepository $cronJobRepository,
        private readonly EntityManagerInterface $em,
        private readonly CronJobRunner $runner,
        private readonly CartReminderCommand $cartReminderCommand,
        private readonly NotifyStockAlertsCommand $notifyStockAlertsCommand,
        private readonly SendNewsletterReminderCommand $sendNewsletterReminderCommand,
        private readonly SendVerificationReminderCommand $sendVerificationReminderCommand,
    ) {}

    /**
     * GET /api/admin/cron-jobs
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $jobs = $this->cronJobRepository->findBy([], ['id' => 'ASC']);

        return $this->json(array_map($this->serialize(...), $jobs));
    }

    /**
     * GET /api/admin/cron-jobs/{id}/preview
     *
     * Nombre de destinataires qui seraient touchés si la tâche s'exécutait
     * maintenant. Aucun email n'est envoyé, aucune écriture en base.
     */
    #[Route('/{id}/preview', name: 'preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function preview(int $id): JsonResponse
    {
        $job = $this->cronJobRepository->find($id);
        if (!$job) {
            return $this->json(['error' => 'Cron job introuvable'], 404);
        }

        $pendingCount = match ($job->getCommandName()) {
            'cart:reminder' => $this->cartReminderCommand->countPending(),
            'app:notify-stock-alerts' => $this->notifyStockAlertsCommand->countPending(),
            'app:send-newsletter-reminder' => $this->sendNewsletterReminderCommand->countPending(),
            'app:send-verification-reminder' => $this->sendVerificationReminderCommand->countPending(),
            default => null,
        };

        return $this->json(['pendingCount' => $pendingCount]);
    }

    /**
     * PATCH /api/admin/cron-jobs/{id}
     *
     * Body: { "enabled": true|false }
     */
    #[Route('/{id}', name: 'toggle', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function toggle(int $id, Request $request): JsonResponse
    {
        $job = $this->cronJobRepository->find($id);
        if (!$job) {
            return $this->json(['error' => 'Cron job introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (!array_key_exists('enabled', $data)) {
            return $this->json(['error' => "Le champ 'enabled' est requis."], 400);
        }

        $job->setEnabled((bool) $data['enabled']);
        $this->em->flush();

        return $this->json($this->serialize($job));
    }

    /**
     * POST /api/admin/cron-jobs/{id}/run
     *
     * Exécution immédiate, indépendamment de la planification et de l'état activé/désactivé.
     */
    #[Route('/{id}/run', name: 'run', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function run(int $id): JsonResponse
    {
        $job = $this->cronJobRepository->find($id);
        if (!$job) {
            return $this->json(['error' => 'Cron job introuvable'], 404);
        }

        $this->runner->run($job);

        return $this->json($this->serialize($job));
    }

    private function serialize(CronJob $job): array
    {
        $nextRunAt = null;
        if ($job->isEnabled()) {
            try {
                $nextRunAt = (new CronExpression($job->getCronExpression()))
                    ->getNextRunDate(new \DateTimeImmutable())
                    ->format('c');
            } catch (\Throwable) {
                $nextRunAt = null;
            }
        }

        return [
            'id'              => $job->getId(),
            'key'             => $job->getKey(),
            'label'           => $job->getLabel(),
            'description'     => $job->getDescription(),
            'commandName'     => $job->getCommandName(),
            'cronExpression'  => $job->getCronExpression(),
            'enabled'         => $job->isEnabled(),
            'lastRunAt'       => $job->getLastRunAt()?->format('c'),
            'lastRunStatus'   => $job->getLastRunStatus()?->value,
            'lastRunSummary'  => $job->getLastRunSummary(),
            'nextRunAt'       => $nextRunAt,
            'audience'        => $this->getAudience($job->getCommandName()),
            'rules'           => $this->getRules($job->getCommandName()),
        ];
    }

    /**
     * À qui s'adresse la tâche — informatif, dérivé du code des commandes.
     */
    private function getAudience(string $commandName): ?string
    {
        return match ($commandName) {
            'cart:reminder' => 'Invités et clients connectés ayant un panier non validé',
            'app:notify-stock-alerts' => 'Clients ayant une alerte de retour en stock active',
            'app:send-newsletter-reminder' => 'Contacts newsletter non confirmés (double opt-in)',
            'app:send-verification-reminder' => 'Clients inscrits dont l\'email de compte n\'est jamais confirmé',
            default => null,
        };
    }

    /**
     * Règles de fréquence/limite exactes appliquées par la commande —
     * informatif, dérivé du code des commandes.
     */
    private function getRules(string $commandName): ?string
    {
        return match ($commandName) {
            'cart:reminder' => "Invités : relance hebdomadaire, puis quotidienne durant les 3 derniers jours avant suppression du panier (30 jours). Connectés : relance tous les 10 jours, 4 relances maximum.",
            'app:notify-stock-alerts' => "Notification dès qu'une alerte en attente correspond à un produit dont le stock est de nouveau supérieur à 0 (vérifié toutes les heures).",
            'app:send-newsletter-reminder' => "Relance hebdomadaire tant que l'inscription n'est pas confirmée, jusqu'à 8 relances maximum puis arrêt automatique de la séquence.",
            'app:send-verification-reminder' => "Relance hebdomadaire tant que l'email du compte n'est pas confirmé (comptes invités exclus), jusqu'à 8 relances maximum puis arrêt automatique de la séquence.",
            default => null,
        };
    }
}
