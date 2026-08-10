<?php

namespace Laraigniter\Bugsnag\Request;

use Bugsnag\Request\RequestInterface;
use Throwable;

class LaraigniterRequest implements RequestInterface
{
    public function isRequest(): bool
    {
        return true;
    }

    public function getSession(): array
    {
        try {
            $ci = $this->ci();

            if ($ci && isset($ci->session)) {
                return $ci->session->userdata() ?: [];
            }
        } catch (Throwable $e) {
            // Ignore session access failures.
        }

        return [];
    }

    public function getCookies(): array
    {
        return $_COOKIE ?: [];
    }

    public function getMetaData(): array
    {
        $data = [
            'url' => $this->url(),
            'httpMethod' => $this->method(),
            'params' => $this->params(),
            'clientIp' => $this->clientIp(),
            'userAgent' => $this->userAgent(),
            'headers' => $this->headers(),
        ];

        try {
            $ci = $this->ci();

            if ($ci && isset($ci->router)) {
                $data['route'] = [
                    'controller' => $ci->router->class ?? '',
                    'method' => $ci->router->method ?? '',
                    'directory' => $ci->router->directory ?? '',
                ];
            }
        } catch (Throwable $e) {
            // Ignore.
        }

        return ['request' => $data];
    }

    public function getContext(): ?string
    {
        try {
            $ci = $this->ci();

            if ($ci && isset($ci->router)) {
                $controller = $ci->router->class ?? '';
                $method = $ci->router->method ?? '';

                if ($controller !== '' || $method !== '') {
                    return $this->method().' '.$controller.'@'.$method;
                }
            }
        } catch (Throwable $e) {
            // Ignore.
        }

        $path = parse_url($this->url(), PHP_URL_PATH) ?: '/';

        return $this->method().' '.$path;
    }

    public function getUserId(): ?string
    {
        try {
            $ci = $this->ci();

            if ($ci && isset($ci->ion_auth) && method_exists($ci->ion_auth, 'logged_in') && $ci->ion_auth->logged_in()) {
                return (string) $ci->ion_auth->get_user_id();
            }
        } catch (Throwable $e) {
            // Ignore.
        }

        return $this->clientIp();
    }

    protected function ci()
    {
        if (!function_exists('get_instance')) {
            return null;
        }

        try {
            return @get_instance();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function url(): string
    {
        if (function_exists('current_url')) {
            try {
                return current_url();
            } catch (Throwable $e) {
                // Fall through.
            }
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return ($https ? 'https' : 'http').'://'.$host.$uri;
    }

    protected function method(): string
    {
        try {
            $ci = $this->ci();

            if ($ci && isset($ci->input)) {
                return strtoupper($ci->input->method(true) ?: 'GET');
            }
        } catch (Throwable $e) {
            // Fall through.
        }

        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    protected function clientIp(): ?string
    {
        try {
            $ci = $this->ci();

            if ($ci && isset($ci->input)) {
                return $ci->input->ip_address();
            }
        } catch (Throwable $e) {
            // Fall through.
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function userAgent(): ?string
    {
        try {
            $ci = $this->ci();

            if ($ci && isset($ci->input)) {
                return $ci->input->user_agent() ?: null;
            }
        } catch (Throwable $e) {
            // Fall through.
        }

        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    protected function params(): array
    {
        $params = array_merge($_GET ?: [], $_POST ?: []);

        try {
            $ci = $this->ci();

            if ($ci && isset($ci->input)) {
                $raw = $ci->input->raw_input_stream;

                if (!empty($raw)) {
                    $decoded = json_decode($raw, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $params['json_payload'] = $decoded;
                    }
                }
            }
        } catch (Throwable $e) {
            // Ignore.
        }

        return $params;
    }

    protected function headers(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                    $headers[$header] = $value;
                }
            }
        }

        return $headers;
    }
}
