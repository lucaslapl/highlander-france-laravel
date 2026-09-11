<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\AdminLogger;
use Illuminate\Console\Events\ScheduledTaskSkipped;

/**
 * Journalise les tâches planifiées sautées (statut "ignored").
 *
 * Le planificateur dispatch ScheduledTaskSkipped lorsqu'une commande due ne
 * s'exécute pas : verrou withoutOverlapping actif (chevauchement), scheduler
 * en pause ou filtre non passé. Comme le handle() de la commande n'est jamais
 * appelé, AdminLogger::log() ne produirait aucune trace : ce listener garantit
 * la visibilité sur /admin/view-logs.
 */
final class LogScheduledTaskSkipped
{
    public function handle(ScheduledTaskSkipped $event): void
    {
        $command = (string) ($event->task->command ?? '');

        $script = self::scriptName($command);
        if ($script === null || $script === 'sync_twitch.php') {
            return;
        }

        AdminLogger::skipped($script, 'Tâche ignorée par le planificateur');
    }

    /**
     * Convertit une commande Artisan ("app:update-stats") en nom de script
     * cohérent avec les SCRIPT_NAME des services ("update_stats.php").
     */
    private static function scriptName(string $command): ?string
    {
        if (preg_match('#\bapp:[a-z0-9][a-z0-9-]*\b#', $command, $m) !== 1) {
            return null;
        }

        $name = substr($m[0], 4);

        return str_replace('-', '_', $name).'.php';
    }
}
