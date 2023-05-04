<?php


namespace App\Traits;


use Exception;
use Illuminate\Support\Facades\Log;
use YaangVu\Exceptions\TooManyRequestException;

trait CanRetry
{
    public static int $retry      = 0;
    public static int $maxRetries = 3;

    /**
     * @param string $method
     * @param mixed  ...$params
     *
     * @return mixed
     */
    private static function retry(string $method, ...$params): mixed
    {
        if (self::$retry > self::$maxRetries)
            throw new TooManyRequestException(
                "Too many times to try to call API to Keycloak: " . self::$retry, new Exception()
            );

        Log::info("Retry to execute function: '$method' time: " . self::$retry . ' with params: ', $params);
        self::$retry++;

        return call_user_func_array($method, $params);
    }
}
