<?php


namespace App\Helpers;


use GuzzleHttp\Exception\GuzzleException;
use YaangVu\EurekaClient\EurekaProvider;

class ServiceDiscoveryHelper
{
    /**
     * @throws GuzzleException
     */
    public static function getServiceUri(string $service)
    {
        $instances = EurekaProvider::$client->getApp($service)["application"]["instance"];
        \Log::info("Get Instances of Service: $service", $instances);
        $randId       = rand(0, count($instances) - 1);
        $randInstance = $instances[$randId];

        return $randInstance ? $randInstance['homePageUrl'] : null;
    }

}
