<?php

namespace Laraigniter\Bugsnag;

use Bugsnag\Client;
use Bugsnag\Report;
use Throwable;

/**
 * Thin wrapper around Bugsnag\Client with Laraigniter convenience methods.
 *
 * The Elegant facade resolves this instance via app('bugsnag').
 */
class BugsnagManager
{
    /**
     * @var \Bugsnag\Client|null
     */
    protected static $staticClient;

    /**
     * @var \Bugsnag\Client
     */
    protected $client;

    /**
     * Manually set user data applied to every subsequent report.
     *
     * @var array|null
     */
    protected $user;

    /**
     * Extra metadata sections applied to every subsequent report.
     *
     * @var array
     */
    protected $metaData = [];

    public function __construct(Client $client)
    {
        $this->client = $client;

        $this->client->registerCallback(function (Report $report) {
            if ($this->user !== null) {
                $report->setUser($this->user);
            }

            if ($this->metaData !== []) {
                $report->setMetaData($this->metaData);
            }
        });
    }

    /**
     * Store the active client before CI's app() container is ready.
     */
    public static function setStaticClient(?Client $client): void
    {
        static::$staticClient = $client;
    }

    public static function staticClient(): ?Client
    {
        return static::$staticClient;
    }

    public function client(): Client
    {
        return $this->client;
    }

    /**
     * Report an exception with optional metadata.
     *
     * @param array|callable|null $callbackOrContext
     */
    public function report(Throwable $exception, $callbackOrContext = null): void
    {
        $callback = null;

        if (is_callable($callbackOrContext)) {
            $callback = $callbackOrContext;
        } elseif (is_array($callbackOrContext) && $callbackOrContext !== []) {
            $callback = static function (Report $report) use ($callbackOrContext) {
                $report->setMetaData(['context' => $callbackOrContext]);
            };
        }

        $this->client->notifyException($exception, $callback);
        $this->client->flush();
    }

    /**
     * Report a named error.
     */
    public function log(string $name, string $message, array $context = [], string $severity = 'error'): void
    {
        $this->client->notifyError($name, $message, static function (Report $report) use ($context, $severity) {
            $report->setSeverity($severity);

            if ($context !== []) {
                $report->setMetaData(['context' => $context]);
            }
        });
    }

    /**
     * Leave a breadcrumb.
     */
    public function breadcrumb(string $message, array $metaData = [], ?string $type = null): void
    {
        $this->client->leaveBreadcrumb($message, $type, $metaData);
    }

    /**
     * Set user information for subsequent reports.
     *
     * @param int|string $id
     */
    public function user($id, ?string $email = null, ?string $name = null, array $additional = []): void
    {
        $user = array_merge(['id' => (string) $id], $additional);

        if ($email !== null) {
            $user['email'] = $email;
        }

        if ($name !== null) {
            $user['name'] = $name;
        }

        $this->user = $user;
        $this->client->registerCallback(function (Report $report) use ($user) {
            $report->setUser($user);
        });
    }

    /**
     * Merge a metadata section for subsequent reports.
     */
    public function context(string $section, array $data): void
    {
        $this->metaData[$section] = isset($this->metaData[$section])
            ? array_merge($this->metaData[$section], $data)
            : $data;

        $this->client->setMetaData([$section => $data]);
    }

    /**
     * @param string $method
     * @param array  $arguments
     * @return mixed
     */
    public function __call($method, $arguments)
    {
        return $this->client->{$method}(...$arguments);
    }
}
