<?php

namespace Laraigniter\Bugsnag\Request;

use Bugsnag\Request\ConsoleRequest;
use Bugsnag\Request\ResolverInterface;

class LaraigniterResolver implements ResolverInterface
{
    /**
     * Resolve the current request.
     *
     * @return \Bugsnag\Request\RequestInterface
     */
    public function resolve()
    {
        if (!is_cli()) {
            return new LaraigniterRequest();
        }

        $command = isset($_SERVER['argv']) && is_array($_SERVER['argv'])
            ? $_SERVER['argv']
            : [];

        return new ConsoleRequest($command);
    }
}
