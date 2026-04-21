<?php

namespace {
    \setlocale(\LC_ALL, 'C');
    \error_reporting(\E_ALL);
}

namespace GuzzleHttp\Test {
    require __DIR__.'/../vendor/autoload.php';
    require __DIR__.'/Server.php';
    use GuzzleHttp\Tests\Server;

    Server::start();
    \register_shutdown_function(static function () {
        Server::stop();
    });
}

// Override curl_setopt_array() and curl_multi_setopt() to get the last set curl options

namespace GuzzleHttp\Handler {
    function curl_setopt_array($handle, array $options)
    {
        if (!empty($_SERVER['curl_test'])) {
            $_SERVER['_curl'] = $options;
        } else {
            unset($_SERVER['_curl']);
        }

        return \curl_setopt_array($handle, $options);
    }

    function curl_setopt($handle, $option, $value)
    {
        if (!empty($_SERVER['curl_test'])) {
            $_SERVER['_curl'][$option] = $value;

            // Skip setting CURLOPT_SHARE if the value is not a valid cURL share handle
            // This allows tests to use mock share handles for assertions
            if ($option === \CURLOPT_SHARE) {
                $isValidShareHandle = is_resource($value)
                    || (PHP_VERSION_ID >= 80000 && ($value instanceof \CurlShareHandle || $value instanceof \CurlSharePersistentHandle));

                if (!$isValidShareHandle) {
                    return true;
                }
            }
        }

        return \curl_setopt($handle, $option, $value);
    }

    function curl_multi_setopt($handle, $option, $value)
    {
        if (!empty($_SERVER['curl_test'])) {
            $_SERVER['_curl_multi'][$option] = $value;
        } else {
            unset($_SERVER['_curl_multi']);
        }

        return \curl_multi_setopt($handle, $option, $value);
    }
}
