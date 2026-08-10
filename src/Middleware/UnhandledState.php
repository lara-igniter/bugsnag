<?php

namespace Laraigniter\Bugsnag\Middleware;

use Bugsnag\Report;

/**
 * Marks exceptions that bubbled through Laraigniter's uncaught handlers as unhandled.
 */
class UnhandledState
{
    /**
     * @param \Bugsnag\Report $report
     * @param callable        $next
     * @return void
     */
    public function __invoke(Report $report, callable $next)
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        if (!is_array($frames)) {
            $frames = [];
        }

        foreach ($frames as $frame) {
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';

            // Framework uncaught exception / fatal entry points.
            if (in_array($function, ['handleException', 'handleShutdown', 'handleError'], true)
                && ($class === '' || $class === null)
            ) {
                $report->setUnhandled(true);
                $report->setSeverityReason([
                    'type' => 'unhandledExceptionMiddleware',
                    'attributes' => ['framework' => 'Laraigniter'],
                ]);
                break;
            }

            // Our own exception wrapper registered by BugsnagServiceProvider.
            if ($function === '{closure}'
                && isset($frame['file'])
                && strpos(str_replace('\\', '/', $frame['file']), 'BugsnagServiceProvider.php') !== false
            ) {
                $report->setUnhandled(true);
                $report->setSeverityReason([
                    'type' => 'unhandledExceptionMiddleware',
                    'attributes' => ['framework' => 'Laraigniter'],
                ]);
                break;
            }
        }

        $next($report);
    }
}
