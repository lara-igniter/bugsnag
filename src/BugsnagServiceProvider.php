<?php

namespace Laraigniter\Bugsnag;

use Bugsnag\Callbacks\CustomUser;
use Bugsnag\Client;
use Bugsnag\Configuration;
use Bugsnag\PsrLogger\BugsnagLogger;
use Bugsnag\Report;
use Elegant\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Elegant\Contracts\Hook\Boot;
use Elegant\Contracts\Hook\PostControllerConstructor;
use Elegant\Contracts\Hook\PostSystem;
use Elegant\Contracts\Hook\PreSystem;
use Elegant\Foundation\Application;
use Elegant\Foundation\Exceptions\Handler as ExceptionHandler;
use Elegant\Foundation\Http\Kernel;
use Elegant\Support\ServiceProvider;
use GuzzleHttp\HandlerStack;
use Laraigniter\Bugsnag\Logging\QueryErrorReporter;
use Laraigniter\Bugsnag\Middleware\UnhandledState;
use Laraigniter\Bugsnag\Request\LaraigniterResolver;
use Psr\Log\LoggerInterface;
use Throwable;

class BugsnagServiceProvider extends ServiceProvider implements Boot, PreSystem, PostControllerConstructor, PostSystem
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

    /**
     * Whether the Kernel exception handler reportable callback was registered.
     *
     * @var bool
     */
    protected static $reportableRegistered = false;

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/bugsnag.php' => base_path('config/bugsnag.php'),
        ], 'bugsnag-config');
    }

    public function preSystem(): void
    {
        // Apache/PHP-FPM workers reuse static state across requests. start.php
        // resets set_exception_handler('handleException') on every request, so we
        // must re-wrap the handler and re-bind reporting each time.
        static::$exceptionHandlerRegistered = false;
        static::$reportableRegistered = false;

        $apiKey = $this->resolveApiKey();

        if ($apiKey === '') {
            return;
        }

        $client = $this->makeClient($this->configFromEnvironment());
        BugsnagManager::setStaticClient($client);
        $this->registerExceptionReporting(new BugsnagLogger($client));
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

        $logger = new BugsnagLogger($client);
        app('bugsnag.logger', $logger);
        $this->registerExceptionReporting($logger);
        $this->startSessionIfNeeded($client, $config);
    }

    public function postSystem(): void
    {
        try {
            $client = BugsnagManager::staticClient();

            if ($client instanceof Client) {
                $client->flush();
            }
        } catch (Throwable $ignored) {
            //
        }
    }

    /**
     * Wire Bugsnag into the HTTP Kernel exception handler.
     */
    protected function registerExceptionReporting(BugsnagLogger $logger): void
    {
        $this->bindPsrLogger($logger);
        $this->registerReportableCallback();
    }

    /**
     * Bind Bugsnag as the PSR logger resolved by Handler::report().
     *
     * The HTTP Kernel catches controller exceptions and reports them through
     * App\Exceptions\Handler, which resolves Psr\Log\LoggerInterface from the
     * Laraigniter Application container — not via set_exception_handler.
     */
    protected function bindPsrLogger(BugsnagLogger $logger): void
    {
        $kernel = Kernel::getInstance();

        if ($kernel === null) {
            return;
        }

        $app = $kernel->getApplication();
        $app->instance(LoggerInterface::class, $logger);

        try {
            $handler = $app->make(ExceptionHandlerContract::class);

            if ($handler instanceof ExceptionHandler) {
                ExceptionHandler::setResolvedInstance($handler);
            }
        } catch (Throwable $ignored) {
            //
        }
    }

    /**
     * Report exceptions caught by the HTTP Kernel through Handler::report().
     */
    protected function registerReportableCallback(): void
    {
        if (static::$reportableRegistered) {
            return;
        }

        $kernel = Kernel::getInstance();

        if ($kernel === null) {
            return;
        }

        try {
            $handler = $kernel->getApplication()->make(ExceptionHandlerContract::class);

            $handler->reportable(static function (Throwable $e) {
                if (QueryErrorReporter::shouldSkipException($e)) {
                    return false;
                }

                return static::notifyThrowable($e) ? false : null;
            });

            static::$reportableRegistered = true;
        } catch (Throwable $ignored) {
            //
        }
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
        $client->setBatchSending(array_key_exists('batch_sending', $config) ? (bool) $config['batch_sending'] : false);
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
                if (! QueryErrorReporter::shouldSkipException($throwable)) {
                    static::notifyThrowable($throwable, true);
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
     * Resolve the Bugsnag API key from env() and superglobals.
     */
    protected function resolveApiKey(): string
    {
        $key = env('BUGSNAG_API_KEY', '');

        if ($key !== '' && $key !== null) {
            return (string) $key;
        }

        $key = getenv('BUGSNAG_API_KEY');

        if ($key !== false && $key !== '') {
            return (string) $key;
        }

        if (isset($_ENV['BUGSNAG_API_KEY']) && $_ENV['BUGSNAG_API_KEY'] !== '') {
            return (string) $_ENV['BUGSNAG_API_KEY'];
        }

        if (isset($_SERVER['BUGSNAG_API_KEY']) && $_SERVER['BUGSNAG_API_KEY'] !== '') {
            return (string) $_SERVER['BUGSNAG_API_KEY'];
        }

        return '';
    }

    /**
     * Minimal config available during pre_system (env only).
     */
    protected function configFromEnvironment(): array
    {
        $notifyStages = env('BUGSNAG_NOTIFY_RELEASE_STAGES');

        return [
            'api_key' => $this->resolveApiKey(),
            'app_type' => env('BUGSNAG_APP_TYPE', 'web'),
            'app_version' => env('BUGSNAG_APP_VERSION', Application::VERSION),
            'release_stage' => env('BUGSNAG_RELEASE_STAGE', defined('ENVIRONMENT') ? ENVIRONMENT : 'production'),
            'notify_release_stages' => empty($notifyStages)
                ? ['production', 'staging']
                : explode(',', str_replace(' ', '', $notifyStages)),
            'batch_sending' => filter_var(env('BUGSNAG_BATCH_SENDING', false), FILTER_VALIDATE_BOOLEAN),
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

    /**
     * Notify Bugsnag immediately. Returns false when nothing was sent so
     * Handler::report() can still fall through to the PSR logger.
     */
    protected static function notifyThrowable(Throwable $throwable, bool $unhandled = false): bool
    {
        $client = BugsnagManager::staticClient();

        if (!$client instanceof Client) {
            static::logDelivery('Bugsnag client is not registered');

            return false;
        }

        if (!$client->shouldNotify()) {
            $appData = $client->getConfig()->getAppData();
            static::logDelivery('Bugsnag skipped notification for release stage ['.($appData['releaseStage'] ?? '').']');

            return false;
        }

        try {
            $report = Report::fromPHPThrowable($client->getConfig(), $throwable);
            static::normalizeDatabaseMessage($report);

            if ($unhandled) {
                $report->setUnhandled(true);
                $report->setSeverity('error');
                $report->setSeverityReason(['type' => 'unhandledException']);
            }

            $client->notify($report);
            $client->flush();

            return true;
        } catch (Throwable $e) {
            static::logDelivery('Bugsnag notify failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * CI display_error() throws the SQL error as HTML paragraphs.
     */
    protected static function normalizeDatabaseMessage(Report $report): void
    {
        $message = $report->getMessage();

        if (! is_string($message) || strpos($message, '<p>') === false) {
            return;
        }

        $text = str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $message);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = trim((string) preg_replace("/[ \t]*\n[ \t]*/", "\n", $text));

        if ($text !== '') {
            $report->setMessage($text);
        }
    }

    protected static function logDelivery(string $message): void
    {
        if (function_exists('log_message')) {
            log_message('error', $message);
        }
    }

    protected function guzzleOptions(array $config): array
    {
        $stack = HandlerStack::create();
        $stack->push(static function (callable $handler) {
            return static function ($request, array $options) use ($handler) {
                return $handler($request, $options)->then(null, static function ($reason) {
                    $message = $reason instanceof Throwable ? $reason->getMessage() : (string) $reason;

                    if (function_exists('log_message')) {
                        log_message('error', 'Bugsnag delivery failed: '.$message);
                    }

                    if ($reason instanceof Throwable) {
                        throw $reason;
                    }

                    throw new \RuntimeException($message);
                });
            };
        });

        $options = ['handler' => $stack];

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
