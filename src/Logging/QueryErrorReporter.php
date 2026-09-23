<?php

namespace Laraigniter\Bugsnag\Logging;

use Bugsnag\Client;
use Bugsnag\Report;
use Elegant\Database\QueryException;
use Laraigniter\Bugsnag\BugsnagManager;
use RuntimeException;
use Throwable;

class QueryErrorReporter
{
    /**
     * @var array<string, float>
     */
    protected static $recentReports = [];

    /**
     * Report CI database query log lines to Bugsnag.
     */
    public static function capture(string $level, string $message): void
    {
        if ($level !== 'error' || strpos($message, 'Query error:') !== 0) {
            return;
        }

        $client = BugsnagManager::staticClient();

        if (! $client instanceof Client) {
            return;
        }

        $hash = md5($message);

        if (static::wasRecentlyReported($hash)) {
            return;
        }

        // db_debug throws RuntimeException immediately after this log line.
        // Report that exception once instead of a second DatabaseQueryError.
        if (static::databaseDebugThrows()) {
            return;
        }

        static::markReported($hash);

        $report = Report::fromNamedError($client->getConfig(), 'DatabaseQueryError', $message);
        $report->setSeverity('error');
        $report->setSeverityReason([
            'type' => 'log',
            'attributes' => ['level' => $level],
        ]);
        $report->setMetaData([
            'database' => static::parseQueryError($message),
        ]);

        $client->notify($report);
        $client->flush();
    }

    /**
     * Skip the exception report when the same query failure was already sent from the log.
     */
    public static function shouldSkipException(Throwable $exception): bool
    {
        if (! static::isDatabaseQueryFailure($exception)) {
            return false;
        }

        return static::hasRecentQueryReport();
    }

    /**
     * True when CI will throw from display_error() after writing the query log.
     */
    protected static function databaseDebugThrows(): bool
    {
        if (! function_exists('get_instance')) {
            return false;
        }

        try {
            $ci = @get_instance();
        } catch (Throwable $e) {
            return false;
        }

        return is_object($ci) && isset($ci->db) && ! empty($ci->db->db_debug);
    }

    protected static function isDatabaseQueryFailure(Throwable $exception): bool
    {
        if ($exception instanceof QueryException) {
            return true;
        }

        if (! $exception instanceof RuntimeException) {
            return false;
        }

        $message = strip_tags($exception->getMessage());

        return strpos($message, 'Duplicate entry') !== false
            || strpos($message, 'Error Number:') !== false
            || strpos($message, 'Query error:') !== false
            || strpos($message, 'Invalid query:') !== false;
    }

    /**
     * @return array<string, string|null>
     */
    protected static function parseQueryError(string $message): array
    {
        $details = [
            'message' => $message,
            'sql' => null,
        ];

        if (preg_match('/Invalid query:\s*(.+)$/s', $message, $matches)) {
            $details['sql'] = trim($matches[1]);
        }

        return $details;
    }

    protected static function wasRecentlyReported(string $hash): bool
    {
        return isset(static::$recentReports[$hash])
            && (microtime(true) - static::$recentReports[$hash]) < 2.0;
    }

    protected static function hasRecentQueryReport(): bool
    {
        $now = microtime(true);

        foreach (static::$recentReports as $reportedAt) {
            if (($now - $reportedAt) < 2.0) {
                return true;
            }
        }

        return false;
    }

    protected static function markReported(string $hash): void
    {
        static::$recentReports[$hash] = microtime(true);
    }
}
