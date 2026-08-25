<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Import de la base SQLite legacy (stats.db) vers MySQL.
 *
 * Usage : php artisan app:import-legacy ../highlander-france-mvc/_scripts/stats.db
 * Les tables de destination doivent être vides (migrations passées) ; l'option
 * --truncate vide les tables métier avant l'import.
 */
final class ImportLegacyCommand extends Command
{
    protected $signature = 'app:import-legacy
                            {path : Chemin vers stats.db (base SQLite legacy)}
                            {--force : Écrase les données MySQL existantes}';

    protected $description = 'Importe les données de l\'ancienne base SQLite dans MySQL';

    /** Tables à importer, dans l'ordre des dépendances. */
    private const TABLES = [
        'players_info',
        'player_stats',
        'processed_logs',
        'player_matches',
        'matches_cache',
        'log_dates',
        'log_blacklist',
        'log_length_cache',
        'match_scores',
        'etf2l_matches',
        'etf2l_teams',
        'etf2l_players',
        'etf2l_api_cache',
    ];

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("Base SQLite introuvable : {$path}");

            return self::FAILURE;
        }

        // Vérifie que la base est lisible avant tout.
        try {
            $sqlite = new PDO('sqlite:' . $path);
            $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $sqlite->exec('PRAGMA journal_mode=WAL');
        } catch (\Throwable $e) {
            $this->error('Impossible d\'ouvrir la base SQLite : ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach (self::TABLES as $table) {
            $count = (int) DB::table($table)->count();
            if ($count > 0 && ! $this->option('force')) {
                $this->error("La table `{$table}` contient déjà {$count} lignes. Utilisez --force pour écraser.");

                return self::FAILURE;
            }
        }

        if ($this->option('force')) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (self::TABLES as $table) {
                DB::table($table)->truncate();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        foreach (self::TABLES as $table) {
            $columns = $this->sqliteColumns($sqlite, $table);

            if ($columns === []) {
                $this->warn("Table SQLite `{$table}` absente — ignorée.");

                continue;
            }

            $count = $this->copyTable($sqlite, $table, $columns);
            $this->info("{$table} : {$count} ligne(s) importée(s).");
        }

        $this->newLine();
        $this->info('Import terminé.');

        return self::SUCCESS;
    }

    /**
     * Colonnes réellement présentes dans la table SQLite.
     *
     * @return string[]
     */
    private function sqliteColumns(PDO $sqlite, string $table): array
    {
        $stmt = $sqlite->query('PRAGMA table_info(' . $table . ')');

        if ($stmt === false) {
            return [];
        }

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (! empty($col['name'])) {
                $columns[] = $col['name'];
            }
        }

        return $columns;
    }

    private function copyTable(PDO $sqlite, string $table, array $columns): int
    {
        $columnList = implode(', ', array_map(
            static fn (string $c): string => '`' . str_replace('`', '', $c) . '`',
            $columns,
        ));

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $selectSql = 'SELECT ' . $columnList . ' FROM `' . str_replace('`', '', $table) . '`';

        $insertSql = 'INSERT IGNORE INTO `' . str_replace('`', '', $table) . "` ({$columnList}) VALUES ({$placeholders})";

        $pdo = DB::connection()->getPdo();

        $readStmt = $sqlite->prepare($selectSql);
        $writeStmt = $pdo->prepare($insertSql);

        $readStmt->execute();

        $total = 0;
        $batch = [];

        while (($row = $readStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $batch[] = array_values($row);

            if (count($batch) >= 500) {
                foreach ($batch as $values) {
                    $writeStmt->execute($values);
                }
                $total += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            foreach ($batch as $values) {
                $writeStmt->execute($values);
            }
            $total += count($batch);
        }

        return $total;
    }
}
