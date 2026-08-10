<?php

namespace Laraigniter\Bugsnag\Facades;

use Elegant\Support\Facades\Facade;

/**
 * @method static \Bugsnag\Client client()
 * @method static void report(\Throwable $exception, array|callable|null $callbackOrContext = null)
 * @method static void log(string $name, string $message, array $context = [], string $severity = 'error')
 * @method static void breadcrumb(string $message, array $metaData = [], string|null $type = null)
 * @method static void user(int|string $id, string|null $email = null, string|null $name = null, array $additional = [])
 * @method static void context(string $section, array $data)
 * @method static void notifyException(\Throwable $throwable, callable|null $callback = null)
 * @method static void notifyError(string $name, string $message, callable|null $callback = null)
 * @method static void leaveBreadcrumb(string $name, string|null $type = null, array $metaData = [])
 * @method static void clearBreadcrumbs()
 * @method static void flush()
 * @method static void registerCallback(callable $callback)
 * @method static void setMetaData(array $metaData, bool $merge = true)
 *
 * @see \Laraigniter\Bugsnag\BugsnagManager
 * @see \Bugsnag\Client
 */
class Bugsnag extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'bugsnag';
    }
}
