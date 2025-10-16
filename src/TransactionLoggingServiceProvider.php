<?php

namespace MysqlDeadlocks\RetryHelper;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class TransactionLoggingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mysql-deadlock-retry.php',
            'mysql-deadlock-retry'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/mysql-deadlock-retry.php' => $this->configPath('mysql-deadlock-retry.php'),
        ], 'mysql-deadlock-retry-config');

        $this->registerTransactionLogging();
    }

    protected function registerTransactionLogging(): void
    {
        $config = config('mysql-deadlock-retry.log_transactions', []);

        if (! ($config['enabled'] ?? false)) {
            return;
        }

        if (! $this->isEnvironmentAllowed($config['environments'] ?? [])) {
            return;
        }

        $minTransactionMs = (int) ($config['min_transaction_ms'] ?? 2000);
        $minQueryMs = (int) ($config['min_query_ms'] ?? 1000);
        $commitLogLevel = $config['commit_log_level'] ?? 'warning';
        $rollbackLogLevel = $config['rollback_log_level'] ?? 'error';
        $logChannel = $config['log_channel'] ?? null;

        $stacks = [];
        $self = $this;

        Event::listen(TransactionBeginning::class, static function (TransactionBeginning $event) use (&$stacks): void {
            $name = $event->connectionName;
            $stacks[$name] ??= [];

            $stacks[$name][] = [
                'started_ns' => hrtime(true),
                'queries' => [],
                'label' => app()->bound('tx.label') ? app('tx.label') : null,
            ];
        });

        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$stacks): void {
            $name = $event->connectionName;

            if (empty($stacks[$name])) {
                return;
            }

            $topIndex = array_key_last($stacks[$name]);

            $stacks[$name][$topIndex]['queries'][] = [
                'sql' => $event->toRawSql(),
                'time_ms' => (int) round($event->time),
                'connection' => $name,
            ];
        });

        Event::listen(TransactionCommitted::class, static function (TransactionCommitted $event) use (&$stacks, $self, $minTransactionMs, $minQueryMs, $logChannel, $commitLogLevel): void {
            $name = $event->connectionName;

            if (empty($stacks[$name])) {
                return;
            }

            $frame = array_pop($stacks[$name]);
            $elapsedMs = (int) round((hrtime(true) - $frame['started_ns']) / 1e6);

            if (! empty($stacks[$name])) {
                return;
            }

            unset($stacks[$name]);

            if ($elapsedMs < $minTransactionMs) {
                return;
            }

            $payload = $self->formatPayload($name, $elapsedMs, $frame['queries'], $minQueryMs);
            $payload['transaction_label'] = $frame['label'];

            $self->writeLog(
                $logChannel,
                $commitLogLevel,
                sprintf(
                    'TRX%s COMMITTED in %0.2f sec (queries: %d)',
                    $frame['label'] ? " [{$frame['label']}]" : '',
                    $elapsedMs / 1000,
                    count($frame['queries'])
                ),
                $payload
            );
        });

        Event::listen(TransactionRolledBack::class, static function (TransactionRolledBack $event) use (&$stacks, $self, $minQueryMs, $logChannel, $rollbackLogLevel): void {
            $name = $event->connectionName;

            if (empty($stacks[$name])) {
                return;
            }

            $frame = array_pop($stacks[$name]);
            $elapsedMs = (int) round((hrtime(true) - $frame['started_ns']) / 1e6);

            if (! empty($stacks[$name])) {
                return;
            }

            unset($stacks[$name]);

            $payload = $self->formatPayload($name, $elapsedMs, $frame['queries'], $minQueryMs);
            $payload['transaction_label'] = $frame['label'];

            if (! empty($frame['queries'])) {
                $lastQuery = $frame['queries'][array_key_last($frame['queries'])];
                $payload['rolled_back_query'] = $self->formatQueryForLog($lastQuery);
            }

            $self->writeLog(
                $logChannel,
                $rollbackLogLevel,
                sprintf(
                    'TRX%s ROLLED BACK in %0.2f sec (queries: %d)',
                    $frame['label'] ? " [{$frame['label']}]" : '',
                    $elapsedMs / 1000,
                    count($frame['queries'])
                ),
                $payload
            );
        });
    }

    protected function isEnvironmentAllowed(array $environments): bool
    {
        if (empty($environments)) {
            return true;
        }

        return in_array('*', $environments, true) || app()->environment($environments);
    }

    protected function writeLog(?string $channel, string $level, string $message, array $payload): void
    {
        $level = strtolower($level);

        if ($channel) {
            Log::channel($channel)->log($level, $message, $payload);

            return;
        }

        Log::log($level, $message, $payload);
    }

    /**
     * Build a compact log payload.
     */
    protected function formatPayload(string $connection, int $elapsedMs, array $queries, int $minQueryMs): array
    {
        $queriesAboveThreshold = array_filter(
            $queries,
            static fn (array $query): bool => $query['time_ms'] >= $minQueryMs
        );

        $sorted = array_values($queriesAboveThreshold);
        usort($sorted, static fn (array $a, array $b): int => $b['time_ms'] <=> $a['time_ms']);

        $queriesForLog = array_map(
            fn (array $query): array => $this->formatQueryForLog($query),
            $sorted
        );

        return array_merge([
            'connection' => $connection,
            'elapsed_ms' => $elapsedMs,
            'elapsed_sec' => round($elapsedMs / 1000, 3),
            'queries_total' => count($queries),
            'queries_over_threshold' => array_values($queriesForLog),
            'queries_over_threshold_count' => count($queriesForLog),
        ], $this->resolveRequestContext());
    }

    protected function formatQueryForLog(array $query): array
    {
        return [
            'time_ms' => $query['time_ms'],
            'time_sec' => round($query['time_ms'] / 1000, 3),
            'sql' => $query['sql'],
            'connection' => $query['connection'],
        ];
    }

    protected function resolveRequestContext(): array
    {
        if (! app()->bound('request') || app()->runningInConsole()) {
            return [
                'user_id' => app()->bound('auth') ? Auth::id() : null,
                'route' => null,
                'method' => null,
                'url' => null,
                'ip' => null,
            ];
        }

        $request = request();
        $routeName = null;

        if ($request) {
            $route = $request->route();

            if ($route && method_exists($route, 'getName')) {
                $routeName = $route->getName();
            }
        }

        return [
            'user_id' => app()->bound('auth') ? Auth::id() : null,
            'route' => $routeName,
            'method' => $request?->method(),
            'url' => $request?->fullUrl(),
            'ip' => $request?->ip(),
        ];
    }

    protected function configPath(string $file): string
    {
        if (function_exists('config_path')) {
            return config_path($file);
        }

        return app()->basePath('config/' . $file);
    }
}
