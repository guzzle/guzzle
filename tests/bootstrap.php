<?php

namespace {
    \setlocale(\LC_ALL, 'C');
    \error_reporting(\E_ALL);
}

namespace GuzzleHttp\Test {
    require __DIR__.'/../vendor/autoload.php';
    use GuzzleHttp\Server\Server;

    Server::start();
    \register_shutdown_function(static function () {
        Server::stop();
    });
}

// Override curl_setopt(), curl_setopt_array(), curl_multi_setopt(), curl_multi_add_handle(), and curl_share_*() to get the last set curl options

namespace GuzzleHttp\Handler {
    function curl_setopt($handle, int $option, $value)
    {
        if (!empty($_SERVER['curl_test'])) {
            if ($option === \CURLOPT_CUSTOMREQUEST) {
                $_SERVER['_curl'] = [];
            }
            if ($value === null) {
                unset($_SERVER['_curl'][$option]);
            } else {
                $_SERVER['_curl'][$option] = $value;
            }
        } else {
            unset($_SERVER['_curl']);
        }

        if (isset($_SERVER['curl_setopt_fail']) && (int) $_SERVER['curl_setopt_fail'] === $option) {
            return false;
        }

        return \curl_setopt($handle, $option, $value);
    }

    function curl_setopt_array($handle, array $options)
    {
        if (!empty($_SERVER['curl_test'])) {
            $_SERVER['_curl'] = $options;
        } else {
            unset($_SERVER['_curl']);
        }

        return \curl_setopt_array($handle, $options);
    }

    function curl_multi_setopt($handle, $option, $value)
    {
        if (!empty($_SERVER['curl_test'])) {
            $_SERVER['_curl_multi'][$option] = $value;
        } else {
            unset($_SERVER['_curl_multi']);
        }

        if (isset($_SERVER['curl_multi_setopt_fail']) && (int) $_SERVER['curl_multi_setopt_fail'] === $option) {
            return false;
        }

        return \curl_multi_setopt($handle, $option, $value);
    }

    function curl_multi_add_handle($multiHandle, $handle)
    {
        if (isset($_SERVER['curl_multi_add_handle_result'])) {
            return (int) $_SERVER['curl_multi_add_handle_result'];
        }

        return \curl_multi_add_handle($multiHandle, $handle);
    }

    function curl_share_init()
    {
        if (!empty($_SERVER['curl_test'])) {
            $_SERVER['_curl_share_init_count'] = ($_SERVER['_curl_share_init_count'] ?? 0) + 1;
        }

        return \curl_share_init();
    }

    function curl_share_setopt($handle, int $option, $value)
    {
        if (!empty($_SERVER['curl_test'])) {
            $_SERVER['_curl_share'][$option][] = $value;
        } else {
            unset($_SERVER['_curl_share']);
        }

        if (isset($_SERVER['curl_share_setopt_fail']) && (int) $_SERVER['curl_share_setopt_fail'] === $value) {
            return false;
        }

        return \curl_share_setopt($handle, $option, $value);
    }

    function curl_share_close($handle): void
    {
        if (!empty($_SERVER['curl_test'])) {
            $_SERVER['_curl_share_close_count'] = ($_SERVER['_curl_share_close_count'] ?? 0) + 1;
        }

        \curl_share_close($handle);
    }
}
