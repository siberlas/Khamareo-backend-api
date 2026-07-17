<?php

namespace App\Scheduler\Enum;

enum CronRunStatus: string
{
    case SUCCESS = 'success';
    case ERROR = 'error';
    case RUNNING = 'running';

    public function label(): string
    {
        return match ($this) {
            self::SUCCESS => 'Succès',
            self::ERROR => 'Échec',
            self::RUNNING => 'En cours',
        };
    }
}
