<?php


namespace App\Helpers;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use YaangVu\Exceptions\BadRequestException;
use YaangVu\Exceptions\SystemException;

/**
 * Class FcmHelper Firebase cloud messaging Helper
 * @package App\Http\Helpers
 */
class FcmHelper
{
    protected Messaging $messaging;
    private string      $icon;
    public bool         $validateOnly = false;
    private array       $data         = [];

    public function __construct()
    {
        $this->messaging = app('firebase.messaging');
        //        $this->icon      = asset('notification.png');
        $this->icon      = 'notification.png';
    }

    /**
     * Set validate message only
     * If $validate = true, system will not send notification to devices
     *
     * @param bool $validate
     */
    public function setValidateOnly(bool $validate): void
    {
        $this->validateOnly = $validate;
    }

    /**
     * Push notification to specific device
     *
     * @param string      $deviceToken
     * @param string      $title
     * @param string      $body
     * @param string|null $image
     * @param array       $data
     *
     * @return array
     */
    public function pushToDevice(string $deviceToken, string $title, string $body, string|null $image = null,
                                 array $data = []): array
    {
        if (!$image)
            $image = $this->icon;

        $message = CloudMessage::withTarget('token', $deviceToken)
                               ->withNotification(Messaging\Notification::create($title, $body, $image));

        if ($data)
            $message = $message->withData($this->_handleData($data));

        $message = $this->_configForDevice($message);

        try {
            return $this->messaging->send($message, $this->validateOnly);
        } catch (MessagingException | FirebaseException $e) {
            throw new SystemException(__("system-500"), $e);
        }
    }

    /**
     * Push notification to multiple devices
     *
     * @param array       $deviceTokens
     * @param string      $title
     * @param string      $body
     * @param string|null $link
     * @param string|null $image
     * @param array       $data
     *
     * @return array
     */
    #[ArrayShape(['success' => "mixed", 'fail' => "mixed"])]
    public function pushToDevices(array $deviceTokens, string $title, string $body, string|null $link = null, string|null $image = null,
                                  array $data = []): array
    {
        if (count($deviceTokens) > 500)
            throw new BadRequestException(__("You can send one message to up to 500 devices"), new Exception());

        if (!$image)
            $image = $this->icon;

        $message = CloudMessage:: new()
                               ->withNotification(Messaging\Notification::create($title, $body, $image));

        if ($data)
            $message = $message->withData($this->_handleData($data));

        $message = $this->_configForDevice($message, $link);

        try {
            $sendReport = $this->messaging->sendMulticast($message, $deviceTokens, $this->validateOnly);

            return [
                'success' => $sendReport->successes()->count(),
                'fail'    => $sendReport->failures()->count(),
            ];
        } catch (MessagingException | FirebaseException $e) {
            throw new SystemException(__("system-500"), $e);
        }
    }

    /**
     * Subscribe one or many devices to one or multiple topics
     *
     * @param string|array $topics
     * @param string|array $deviceTokens
     *
     * @return array
     */
    #[ArrayShape(['fail' => "mixed"])]
    public function subscribeToTopics(string|array $topics, string|array $deviceTokens): array
    {
        if (gettype($deviceTokens) == 'array' && count($deviceTokens) > 1000)
            throw new BadRequestException(__("You can subscribe up to 1000 devices"), new Exception());

        if (gettype($topics) == 'string')
            return $this->messaging->subscribeToTopic($topics, $deviceTokens);
        else
            return $this->messaging->subscribeToTopics($topics, $deviceTokens);
    }

    /**
     * @param string|array $topics
     * @param string|array $deviceTokens
     *
     * @return array
     */
    public function unsubscribeFromTopics(string|array $topics, string|array $deviceTokens): array
    {
        if (gettype($deviceTokens) == 'array' && count($deviceTokens) > 1000)
            throw new BadRequestException(__("You can subscribe up to 1000 devices"), new Exception());

        if (gettype($topics) == 'string')
            return $this->messaging->unsubscribeFromTopic($topics, $deviceTokens);
        else
            return $this->messaging->unsubscribeFromTopics($topics, $deviceTokens);
    }

    /**
     * @param string|array $deviceTokens
     *
     * @return array
     */
    public function unsubscribeFromAllTopics(string|array $deviceTokens): array
    {
        if (gettype($deviceTokens) == 'array' && count($deviceTokens) > 1000)
            throw new BadRequestException(__("You can subscribe up to 1000 devices"), new Exception());

        return $this->messaging->unsubscribeFromAllTopics($deviceTokens);
    }

    /**
     * @param string      $topic
     * @param string      $title
     * @param string      $body
     * @param string|null $image
     * @param array       $data
     *
     * @return array
     */
    public function pushToTopic(string $topic, string $title, string $body, string|null $image = null,
                                array $data = []): array
    {
        if (!$image)
            $image = $this->icon;

        $message = CloudMessage::withTarget('topic', $topic)
                               ->withNotification(Messaging\Notification::create($title, $body, $image));

        if ($data)
            $message = $message->withData($this->_handleData($data));

        $message = $this->_configForDevice($message);

        try {
            return $this->messaging->send($message, $this->validateOnly);
        } catch (MessagingException | FirebaseException $e) {
            throw new SystemException(__("system-500"), $e);
        }
    }

    /**
     * Get all subscribed topics of token
     *
     * @param string $token
     *
     * @return array
     */
    public function getSubscribedTopics(string $token): array
    {
        try {
            $appInstance   = $this->messaging->getAppInstance($token);
            $subscriptions = $appInstance->topicSubscriptions();
            $topics        = [];
            foreach ($subscriptions as $subscription) {
                $topics[] = $subscription->topic();
            }

            return $topics;
        } catch (FirebaseException $e) {
            throw new SystemException(__("system-500"), $e);
        }
    }

    /**
     * Handle data before push notification
     *
     * @param array $data
     *
     * @return array
     */
    private function _handleData(array $data): array
    {
        // do something
        return $data;
    }

    /**
     * Config data to push specific device type
     *
     * @param CloudMessage $message
     * @param bool         $web
     * @param bool         $android
     * @param bool         $ios
     *
     * @return CloudMessage
     */
    private function _configForDevice(CloudMessage $message, $link = null, $web = true, $android = true, $ios = true, ): CloudMessage
    {
        if ($web)
            $message = $message->withWebPushConfig(
                ['fcm_options'  => [
                    'link' => $link
                ],
                 'notification' => ['icon' => 'logo.png']
                ]
            );

        if ($android) {
            //do some thing
        }

        if ($ios) {
            //do some thing
        }

        $message = $message->withFcmOptions(['analytics_label' => 'charge-now-fcm']);

        return $message;
    }
}
