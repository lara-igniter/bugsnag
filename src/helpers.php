<?php

use Laraigniter\Bugsnag\BugsnagManager;

if (!function_exists('bugsnag')) {
    /**
     * Get the Bugsnag manager (or underlying client when requested).
     *
     * @return \Laraigniter\Bugsnag\BugsnagManager|\Bugsnag\Client|null
     */
    function bugsnag(bool $client = false)
    {
        $manager = null;

        try {
            if (function_exists('get_instance')) {
                $ci = @get_instance();

                if ($ci && isset($ci->bugsnag)) {
                    $manager = $ci->bugsnag;
                }
            }
        } catch (Throwable $e) {
            $manager = null;
        }

        if ($manager) {
            return $client ? $manager->client() : $manager;
        }

        $static = BugsnagManager::staticClient();

        return $client ? $static : null;
    }
}

if (!function_exists('bugsnag_report')) {
    /**
     * Report an exception to Bugsnag.
     *
     * @param \Throwable          $exception
     * @param array|callable|null $context
     * @return void
     */
    function bugsnag_report($exception, $context = null)
    {
        if ($manager = bugsnag()) {
            $manager->report($exception, $context);

            return;
        }

        $client = BugsnagManager::staticClient();

        if ($client) {
            $client->notifyException($exception);
        }
    }
}

if (!function_exists('bugsnag_log')) {
    /**
     * Report a custom named error to Bugsnag.
     *
     * @param string $name
     * @param string $message
     * @param array  $context
     * @param string $severity
     * @return void
     */
    function bugsnag_log($name, $message, array $context = [], $severity = 'error')
    {
        if ($manager = bugsnag()) {
            $manager->log($name, $message, $context, $severity);

            return;
        }

        $client = BugsnagManager::staticClient();

        if ($client) {
            $client->notifyError($name, $message);
        }
    }
}

if (!function_exists('bugsnag_breadcrumb')) {
    /**
     * Leave a Bugsnag breadcrumb.
     *
     * @param string $message
     * @param array  $metaData
     * @param string|null $type
     * @return void
     */
    function bugsnag_breadcrumb($message, array $metaData = [], $type = null)
    {
        if ($manager = bugsnag()) {
            $manager->breadcrumb($message, $metaData, $type);

            return;
        }

        $client = BugsnagManager::staticClient();

        if ($client) {
            $client->leaveBreadcrumb($message, $type, $metaData);
        }
    }
}

if (!function_exists('bugsnag_user')) {
    /**
     * Set the Bugsnag user for subsequent reports.
     *
     * @param int|string  $id
     * @param string|null $email
     * @param string|null $name
     * @param array       $additional
     * @return void
     */
    function bugsnag_user($id, $email = null, $name = null, array $additional = [])
    {
        if ($manager = bugsnag()) {
            $manager->user($id, $email, $name, $additional);
        }
    }
}

if (!function_exists('bugsnag_context')) {
    /**
     * Add metadata context for subsequent reports.
     *
     * @param string $section
     * @param array  $data
     * @return void
     */
    function bugsnag_context($section, array $data)
    {
        if ($manager = bugsnag()) {
            $manager->context($section, $data);
        }
    }
}

if (!function_exists('report')) {
    /**
     * Report an exception without rethrowing it.
     *
     * @param \Throwable $exception
     * @return void
     */
    function report($exception)
    {
        bugsnag_report($exception);
    }
}
