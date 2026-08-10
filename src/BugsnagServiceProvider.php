<?php

namespace Laraigniter\Bugsnag;

use Bugsnag\Callbacks\CustomUser;
use Bugsnag\Client;
use Bugsnag\Configuration;
use Bugsnag\PsrLogger\BugsnagLogger;
use Bugsnag\Report;
use Elegant\Contracts\Hook\Boot;
use Elegant\Contracts\Hook\PostControllerConstructor;
use Elegant\Contracts\Hook\PreSystem;
use Elegant\Foundation\Application;
use Elegant\Support\ServiceProvider;
use Laraigniter\Bugsnag\Middleware\UnhandledState;
use Laraigniter\Bugsnag\Request\LaraigniterResolver;
use Throwable;

class BugsnagServiceProvider extends ServiceProvider implements Boot, PreSystem, PostControllerConstructor
{
    /**
     * The package version.
     *
     * @var string
     */
    public const VERSION = '1.0.0';

    /**
     * Whether the exception handler wrapper was registered.
     *
     * @var bool
     */
    protected static $exceptionHandlerRegistered = false;

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/bugsnag.php' => base_path('config/bugsnag.php'),
        ], 'bugsnag-config');
    }

    public function preSystem(): void
    {
        // handleException / handleShutdown are already registered by CodeIgniter
        // before pre_system. Wrapping here covers uncaught errors during routing
        // and controller loading — before post_controller_constructor runs.
        $apiKey = env('BUGSNAG_API_KEY', '');

        if ($apiKey === '' || $apiKey === null) {
            return;
        }

        $client = $this->makeClient($this->configFromEnvironment());
        BugsnagManager::setStaticClient($client);
        $this->registerExceptionHandler();
    }

    public function postControllerConstructor(&$params): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bugsnag.php', 'bugsnag');
        app('load')->config('bugsnag', true);

        $config = $this->getConfig();

        if (empty($config['api_key'])) {
            return;
        }

        $client = $this->makeClient($config);
        $manager = new BugsnagManager($client);

        BugsnagManager::setStaticClient($client);

        app('bugsnag', $manager);
        app('bugsnag.client', $client);
        app('bugsnag.logger', new BugsnagLogger($client));

        $this->registerExceptionHandler();
        $this->startSessionIfNeeded($client, $config);
    }

    /**
     * Build a fully configured Bugsnag client.
     */
    protected function makeClient(array $config): Client
    {
        $configuration = new Configuration($config['api_key']);
        $guzzle = Client::makeGuzzle(
            $config['endpoint'] ?? null,
            $this->guzzleOptions($config)
        );

        $client = new Client($configuration, new LaraigniterResolver(), $guzzle);

        $this->configureClient($client, $config);
        $this->setupCallbacks($client, $config);
        $this->setupPaths($client, $config);

        return $client;
    }

    protected function configureClient(Client $client, array $config): void
    {
        $client->setReleaseStage($config['release_stage'] ?? (defined('ENVIRONMENT') ? ENVIRONMENT : null));
        $client->setHostname($config['hostname'] ?? null);
        $client->getConfig()->mergeDeviceData(['runtimeVersions' => $this->runtimeVersions()]);

        $client->setFallbackType($this->isCli() ? 'Console' : 'HTTP');
        $client->setAppType($config['app_type'] ?? null);
        $client->setAppVersion($config['app_version'] ?? Application::VERSION);
        $client->setBatchSending(array_key_exists('batch_sending', $config) ? (bool) $config['batch_sending'] : true);
        $client->setSendCode(array_key_exists('send_code', $config) ? (bool) $config['send_code'] : true);

        $client->getPipeline()->insertBefore(new UnhandledState(), 'Bugsnag\\Middleware\\SessionData');

        $client->setNotifier([
            'name' => 'Bugsnag Laraigniter',
            'version' => static::VERSION,
            'url' => 'https://github.com/lara-igniter/bugsnag',
        ]);

        if (!empty($config['notify_release_stages']) && is_array($config['notify_release_stages'])) {
            $client->setNotifyReleaseStages($config['notify_release_stages']);
        }

        if (!empty($config['filters']) && is_array($config['filters'])) {
            $client->setFilters($config['filters']);
        }

        if (!empty($config['redacted_keys']) && is_array($config['redacted_keys'])) {
            $client->setRedactedKeys($config['redacted_keys']);
        }

        if (!empty($config['endpoint'])) {
            $client->setNotifyEndpoint($config['endpoint']);
        }

        if (!empty($config['build_endpoint'])) {
            $client->setBuildEndpoint($config['build_endpoint']);
        }

        if (!empty($config['discard_classes']) && is_array($config['discard_classes'])) {
            $client->setDiscardClasses($config['discard_classes']);
        }

        if (isset($config['max_breadcrumbs'])) {
            $client->setMaxBreadcrumbs((int) $config['max_breadcrumbs']);
        }

        if ($this->isSessionTrackingAllowed($config)) {
            $client->setAutoCaptureSessions(true);

            if (!empty($config['session_endpoint'])) {
                $client->setSessionEndpoint($config['session_endpoint']);
            }

            $this->setupSessionStorage($client);
        }
    }

    protected function setupCallbacks(Client $client, array $config): void
    {
        if (!isset($config['callbacks']) || $config['callbacks']) {
            $client->registerDefaultCallbacks();

            $client->registerCallback(static function (Report $report) {
                $report->setMetaData([
                    'Laraigniter' => [
                        'version' => Application::VERSION,
                        'environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown',
                        'base_url' => function_exists('base_url') ? base_url() : '',
                    ],
                ]);
            });
        }

        if (!isset($config['user']) || $config['user']) {
            $client->registerCallback(new CustomUser(static function () {
                try {
                    if (!function_exists('get_instance')) {
                        return null;
                    }

                    $ci = @get_instance();

                    if (!$ci || !isset($ci->ion_auth) || !method_exists($ci->ion_auth, 'logged_in')) {
                        return null;
                    }

                    if (!$ci->ion_auth->logged_in()) {
                        return null;
                    }

                    $user = $ci->ion_auth->user()->row();

                    if (!$user) {
                        return null;
                    }

                    return [
                        'id' => (string) ($user->id ?? ''),
                        'email' => $user->email ?? '',
                        'name' => $user->username ?? ($user->email ?? ''),
                    ];
                } catch (Throwable $e) {
                    return null;
                }
            }));
        }
    }

    protected function setupPaths(Client $client, array $config): void
    {
        if (!empty($config['project_root_regex'])) {
            $client->setProjectRootRegex($config['project_root_regex']);
        } elseif (!empty($config['project_root'])) {
            $client->setProjectRoot($config['project_root']);
        } else {
            $client->setProjectRoot(base_path());
        }

        if (!empty($config['strip_path_regex'])) {
            $client->setStripPathRegex($config['strip_path_regex']);
        } elseif (!empty($config['strip_path'])) {
            $client->setStripPath($config['strip_path']);
        } else {
            $client->setStripPath(base_path());
        }
    }

    protected function setupSessionStorage(Client $client): void
    {
        $tracker = $client->getSessionTracker();

        $tracker->setSessionFunction(static function ($session = null) {
            try {
                if (!function_exists('get_instance')) {
                    return is_null($session) ? [] : null;
                }

                $ci = @get_instance();

                if (!$ci || !isset($ci->session)) {
                    return is_null($session) ? [] : null;
                }

                if (is_null($session)) {
                    return $ci->session->userdata('bugsnag-session') ?: [];
                }

                $ci->session->set_userdata('bugsnag-session', $session);
            } catch (Throwable $e) {
                return is_null($session) ? [] : null;
            }
        });

        $tracker->setStorageFunction(static function ($key, $value = null) {
            static $store = [];

            if (is_null($value)) {
                return $store[$key] ?? null;
            }

            $store[$key] = $value;
        });
    }

    protected function startSessionIfNeeded(Client $client, array $config): void
    {
        if (!$this->isSessionTrackingAllowed($config) || $this->isCli()) {
            return;
        }

        $client->getSessionTracker()->startSession();
    }

    /**
     * Wrap the framework exception handler so uncaught errors are reported
     * without replacing Whoops / CI rendering.
     */
    protected function registerExceptionHandler(): void
    {
        if (static::$exceptionHandlerRegistered) {
            return;
        }

        static::$exceptionHandlerRegistered = true;

        $previous = set_exception_handler(static function ($throwable) use (&$previous) {
            try {
                $client = BugsnagManager::staticClient();

                if ($client instanceof Client) {
                    $report = Report::fromPHPThrowable($client->getConfig(), $throwable);
                    $report->setUnhandled(true);
                    $report->setSeverity('error');
                    $report->setSeverityReason(['type' => 'unhandledException']);
                    $client->notify($report);
                }
            } catch (Throwable $e) {
                // Never break the framework error page.
            }

            if (is_callable($previous)) {
                return call_user_func($previous, $throwable);
            }
        });
    }

    protected function getConfig(): array
    {
        return app('config')->config['bugsnag'] ?? [];
    }

    /**
     * Minimal config available during pre_system (env only).
     */
    protected function configFromEnvironment(): array
    {
        $notifyStages = env('BUGSNAG_NOTIFY_RELEASE_STAGES');

        return [
            'api_key' => env('BUGSNAG_API_KEY', ''),
            'app_type' => env('BUGSNAG_APP_TYPE', 'web'),
            'app_version' => env('BUGSNAG_APP_VERSION', Application::VERSION),
            'release_stage' => env('BUGSNAG_RELEASE_STAGE', defined('ENVIRONMENT') ? ENVIRONMENT : 'production'),
            'notify_release_stages' => empty($notifyStages)
                ? ['production', 'staging']
                : explode(',', str_replace(' ', '', $notifyStages)),
            'batch_sending' => filter_var(env('BUGSNAG_BATCH_SENDING', true), FILTER_VALIDATE_BOOLEAN),
            'send_code' => filter_var(env('BUGSNAG_SEND_CODE', true), FILTER_VALIDATE_BOOLEAN),
            'endpoint' => env('BUGSNAG_ENDPOINT'),
            'session_endpoint' => env('BUGSNAG_SESSION_ENDPOINT', env('BUGSNAG_SESSIONS_ENDPOINT')),
            'project_root' => base_path(),
            'strip_path' => base_path(),
            'callbacks' => true,
            'user' => true,
            'auto_capture_sessions' => false,
            'hostname' => env('BUGSNAG_HOSTNAME'),
            'filters' => [
                'password',
                'password_confirmation',
                'token',
                'api_key',
                'secret',
                'authorization',
                'cookie',
            ],
            'proxy' => [],
        ];
    }

    protected function guzzleOptions(array $config): array
    {
        $options = [];

        if (!empty($config['proxy']) && is_array($config['proxy'])) {
            $proxy = $config['proxy'];

            if (isset($proxy['http']) && !$this->isCli()) {
                unset($proxy['http']);
            }

            $options['proxy'] = $proxy;
        }

        return $options;
    }

    protected function runtimeVersions(): array
    {
        return [
            'laraigniter' => Application::VERSION,
        ];
    }

    protected function isSessionTrackingAllowed(array $config): bool
    {
        return !empty($config['auto_capture_sessions']);
    }

    protected function isCli(): bool
    {
        return function_exists('is_cli') ? is_cli() : (PHP_SAPI === 'cli' || defined('STDIN'));
    }
}
