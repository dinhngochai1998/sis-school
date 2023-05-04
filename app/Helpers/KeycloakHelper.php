<?php


namespace App\Helpers;


use App\Constants\KeycloakConstant;
use App\Traits\CanRetry;
use Cache;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;
use YaangVu\Constant\StatusConstant;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\UnauthorizedException;

class KeycloakHelper
{
    use CanRetry;

    private static ?KeycloakHelper $_instance                  = null;
    public static ?string          $accessToken                = null;
    public static ?string          $url                        = null;
    public static string           $username                   = '';
    public static string           $password                   = '';
    public static string           $keycloakTokenSuffix        = 'keycloak_token';
    public static string           $keycloakRefreshTokenSuffix = 'keycloak_refresh_token';

    /**
     * @return string|null
     */
    public static function getUrl(): ?string
    {
        if (self::$url === null)
            self::$url = env('KEYCLOAK_URL') . '/admin/realms/' . env('KEYCLOAK_REALM');

        return self::$url;
    }

    /**
     * @return KeycloakHelper|null
     */
    public static function getInstance(): ?KeycloakHelper
    {
        if (self::$_instance === null) {
            self::$_instance = new self;
        }

        return self::$_instance;
    }

    /**
     * login with rest api keycloak
     *
     * @param string $username
     * @param string $password
     *
     * @return KeycloakHelper
     * @throws InvalidArgumentException
     */
    public static function login(string $username, string $password): static
    {
        self::$username = $username;
        self::$password = $password;
        $user           = self::_getCacheUser($username);
        if ($user) {
            self::$accessToken = $user;

            return self::getInstance();
        }
        $url    = env('KEYCLOAK_URL') . '/realms/' . env('KEYCLOAK_REALM') . '/protocol/openid-connect/token';
        $params = [
            'client_id'  => KeycloakConstant::CLIENT_ID,
            'username'   => $username,
            'password'   => $password,
            'grant_type' => KeycloakConstant::GRANT_TYPE
        ];

        Log::info("Start Login Keycloak - url: $url", $params);

        $response = Http::asForm()->post($url, $params);

        if ($response->status() == 500) {
            Log::info("Login Keycloak fail: " . $response->body());

            // If the server has any error, retry call api
            return self::retry('self::login', $username, $password);
        } elseif ($response->status() < 200 || $response->status() >= 300) {
            Log::info("Login Keycloak fail : " . $response->body());
            throw new UnauthorizedException("Can not login in to Keycloak system", new Exception());
        } else {
            $user = json_decode($response->body());
            Log::info("Login Keycloak success with username : $username");
            self::_setCacheUser($username, $user);
            self::$accessToken = $user->access_token;

            return self::getInstance();
        }
    }

    /**
     * @return static
     * @throws InvalidArgumentException
     */
    public static function loginAsAdmin(): static
    {
        $username = env('KEYCLOAK_USER', KeycloakConstant::USERNAME);
        $password = env('KEYCLOAK_PASSWORD', KeycloakConstant::PASSWORD);

        return self::login($username, $password);
    }

    /**
     * @param string $username
     *
     * @return mixed
     */
    private static function _getCacheUser(string $username): mixed
    {
        return Cache::get($username . '_' . self::$keycloakTokenSuffix);
    }

    /**
     * @param string $username
     * @param object $user
     *
     * @throws InvalidArgumentException
     */
    private static function _setCacheUser(string $username, object $user)
    {
        Cache::set($username . '_' . self::$keycloakTokenSuffix,
                   $user->access_token,
                   $user->expires_in ?? 3600
        );
        Cache::set($username . '_' . self::$keycloakRefreshTokenSuffix,
                   $user->refresh_token,
                   $user->refresh_expires_in ?? 3600
        );
    }

    /**
     * create user with keycloak rest api
     *
     * @param object $user
     *
     * @return string|null
     * @throws InvalidArgumentException
     */
    public static function createUser(object $user): ?string
    {
        $status = true;
        if ($user->status ?? null) {
            $status = match ($user->status) {
                StatusConstant::INACTIVE => false,
                default => true
            };
        }
        $url    = self::getUrl() . '/users';
        $params = [
            'username'      => $user->username,
            'enabled'       => $status,
            'emailVerified' => true,
            'firstName'     => $user->first_name,
            'lastName'      => $user->last_name,
            'email'         => $user->email,
            'realmRoles'    => ["user"]
        ];
        Log::info("Start create Keycloak user - url: $url", $params);

        $response = Http::withToken(self::$accessToken)->post($url, $params);
        if ($response->status() === 201) {
            Log::info("Create user Keycloak $user->username success ");

            return ($response->headers()['location'] ?? null) ?
                str_replace($url . '/', '', $response->headers()['location'][0]) : null;
        } elseif ($response->status() === 409) {
            Log::info("Keycloak user $user->username already existed.");

            return null;
        } elseif ($response->status() >= 401) {
            Log::info("Create Keycloak user fail: " . $response->body());

            self::refreshToken();

            // If the server has any error, retry call api
            return self::retry('self::createUser', $user);
        } else {
            Log::info("Create user Keycloak $user->username fail ", (array)json_decode($response->body(), true));

            return null;
        }
    }

    /**
     * update user with keycloak rest api
     *
     * @param string $uuid
     * @param array  $user
     *
     * @return bool
     * @throws InvalidArgumentException
     */
    public static function updateUser(string $uuid, array $user): bool
    {
        if ($user['status'] ?? null) {
            $status = match ($user['status']) {
                StatusConstant::ACTIVE => ['enabled' => true],
                StatusConstant::INACTIVE => ['enabled' => false]
            };
            $user   = array_merge($user, $status);
            unset($user['status']);
        }

        $url = self::getUrl() . '/users/' . $uuid;
        Log::info("Start update Keycloak user - url: $url", $user);

        $response = Http::withToken(self::$accessToken)->put($url, $user);
        if ($response->status() >= 200 && $response->status() < 300) {
            Log::info("update user Keycloak $uuid success ");

            return true;

        } elseif ($response->status() == 401) {
            Log::info("Update Keycloak User fail: " . $response->body());
            self::refreshToken();

            // If the server has any error, retry call api
            return self::retry('self::updateUser', $uuid, $user);
        } elseif ($response->status() >= 500) {
            Log::info("Update Keycloak User fail: " . $response->body());

            // If the server has any error, retry call api
            return self::retry('self::updateUser', $uuid, $user);
        } else {
            Log::info("update user Keycloak $uuid fail ");

            return false;
        }
    }

    /**
     * update user with keycloak rest api
     *
     * @param string $uuid
     * @param string $password
     *
     * @return array|string|null
     * @throws InvalidArgumentException
     */
    public static function updatePassword(string $uuid, string $password): array|string|null
    {
        $url = self::getUrl() . '/users/' . $uuid . '/reset-password';
        Log::info("Start update Keycloak user password - url: $url");
        $response = Http::withToken(self::$accessToken)
                        ->put($url,
                              [
                                  'type'      => 'password',
                                  'value'     => $password,
                                  'temporary' => false
                              ]
                        );
        if ($response->status() === 204) {
            Log::info("Update password Keycloak user $uuid success , with password : $password");

            return true;
        } elseif ($response->status() == 401) {
            Log::info("Update Keycloak User fail: " . $response->body());
            self::refreshToken();

            // If the server has any error, retry call api
            return self::retry('self::updatePassword', $uuid, $password);
        } elseif ($response->status() >= 500) {
            Log::info("Update Keycloak User Password fail: " . $response->body());

            // If the server has any error, retry call api
            return self::retry('self::updatePassword', $uuid, $password);
        } else {
            Log::info("Update password Keycloak user $uuid fail");

            return false;
        }
    }

    /**
     * @param string $username
     *
     * @return mixed
     * @throws InvalidArgumentException
     */
    public static function getUserByUsername(string $username): mixed
    {
        $path     = '/users';
        $response = Http::withToken(self::$accessToken)->get(self::getUrl() . $path, ['username' => $username]);
        if ($response->status() >= 200 && $response->status() < 300) {
            $users = json_decode($response->body());
            Log::info("Get user Keycloak $username success: ", $users);
            foreach ($users as $user)
                if ($user->username === $username)
                    return $user;
        } elseif ($response->status() == 401) {
            Log::info("Get Keycloak User fail: " . $response->body());
            self::refreshToken($username);

            // If the server has any error, retry call api
            return self::retry('self::getUserByUsername', $username);
        } elseif ($response->status() >= 500) {
            Log::info("Get Keycloak User fail: " . $response->body());

            // If the server has any error, retry call api
            return self::retry('self::getUserByUsername', $username);
        } else {
            Log::info("Get user Keycloak $username fail");
        }

        return null;
    }

    /**
     * @param string $email
     *
     * @return mixed
     * @throws InvalidArgumentException
     */
    public static function getUserByEmail(string $email): mixed
    {
        $path = '/users';

        $emailExploded = explode('+', $email);

        $response = Http::withToken(self::$accessToken)->get(self::getUrl() . $path, ['email' => $emailExploded[0]]);
        if ($response->status() >= 200 && $response->status() < 300) {
            $users = json_decode($response->body());
            Log::info("Get user Keycloak $email success: ", $users);
            foreach ($users as $user)
                if ($user->email === $email)
                    return $user;
        } elseif ($response->status() == 401) {
            Log::info("Get Keycloak User fail: " . $response->body());
            self::refreshToken($email);

            // If the server has any error, retry call api
            return self::retry('self::getUserByUsername', $email);
        } elseif ($response->status() >= 500) {
            Log::info("Get Keycloak User fail: " . $response->body());

            // If the server has any error, retry call api
            return self::retry('self::getUserByUsername', $email);
        } else {
            Log::info("Get user Keycloak $email fail");
        }

        return null;
    }

    /**
     * @param string $username
     *
     * @return KeycloakHelper|null
     * @throws InvalidArgumentException
     */
    public static function refreshToken(string $username = ''): ?KeycloakHelper
    {
        if ($username === '')
            $username = self::$username;

        $url = env('KEYCLOAK_URL') . '/realms/' . env('KEYCLOAK_REALM') . '/protocol/openid-connect/token';

        $refreshToken = Cache::get($username . '_' . self::$keycloakRefreshTokenSuffix);
        if (!$refreshToken)
            throw new BadRequestException('Can not find Keycloak refresh token for: ' . $username . '_' . self::$keycloakRefreshTokenSuffix,
                                          new Exception());

        $params = [
            'client_id'     => KeycloakConstant::CLIENT_ID,
            'grant_type'    => KeycloakConstant::REFRESH_TOKEN,
            'refresh_token' => $refreshToken,
        ];

        Log::info("Start Refresh Keycloak token - url: $url", $params);

        $response = Http::asForm()->post($url, $params);

        if ($response->status() < 200 || $response->status() >= 300) {
            Log::info("Refresh Keycloak Token fail: " . $response->body());

            return self::reLogin();
        } else {
            $user = json_decode($response->body());
            Log::info("Refresh Keycloak Token success with username : $username");
            self::_setCacheUser($username, $user);
            self::$accessToken = $user->access_token;

            return self::getInstance();
        }
    }

    /**
     * @param string $username
     * @param string $password
     *
     * @return KeycloakHelper|null
     * @throws InvalidArgumentException
     */
    public static function reLogin(string $username = '', string $password = ''): ?KeycloakHelper
    {
        if (!$username || !$password) {
            $username = self::$username;
            $password = self::$password;
        }

        return self::forceLogin($username, $password);
    }

    /**
     * @param string $username
     * @param string $password
     *
     * @return KeycloakHelper
     * @throws InvalidArgumentException
     */
    public static function forceLogin(string $username, string $password): KeycloakHelper|static
    {
        Cache::delete($username . '_' . self::$keycloakTokenSuffix);
        Cache::delete($username . '_' . self::$keycloakRefreshTokenSuffix);

        return self::login($username, $password);
    }
}

