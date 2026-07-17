<?php

namespace App\Scheduler\Entity;

use App\Scheduler\Enum\CronRunStatus;
use App\Scheduler\Repository\CronJobRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CronJobRepository::class)]
class CronJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $key;

    #[ORM\Column(length: 150)]
    private string $label;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Nom de la commande Symfony Console à exécuter (ex: "cart:reminder") */
    #[ORM\Column(length: 100)]
    private string $commandName;

    /** Expression cron standard (ex: "0 10 * * *") — informatif + utilisé par le dispatcher */
    #[ORM\Column(length: 50)]
    private string $cronExpression;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 20, nullable: true, enumType: CronRunStatus::class)]
    private ?CronRunStatus $lastRunStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastRunSummary = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getKey(): string { return $this->key; }
    public function setKey(string $key): self { $this->key = $key; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): self { $this->label = $label; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getCommandName(): string { return $this->commandName; }
    public function setCommandName(string $commandName): self { $this->commandName = $commandName; return $this; }

    public function getCronExpression(): string { return $this->cronExpression; }
    public function setCronExpression(string $cronExpression): self { $this->cronExpression = $cronExpression; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }

    public function getLastRunAt(): ?\DateTimeImmutable { return $this->lastRunAt; }
    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): self { $this->lastRunAt = $lastRunAt; return $this; }

    public function getLastRunStatus(): ?CronRunStatus { return $this->lastRunStatus; }
    public function setLastRunStatus(?CronRunStatus $lastRunStatus): self { $this->lastRunStatus = $lastRunStatus; return $this; }

    public function getLastRunSummary(): ?string { return $this->lastRunSummary; }
    public function setLastRunSummary(?string $lastRunSummary): self { $this->lastRunSummary = $lastRunSummary; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
