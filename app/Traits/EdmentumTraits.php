<?php


namespace App\Traits;


use App\Helpers\RabbitMQHelper;
use Exception;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\LmsSystemConstant;

trait EdmentumTraits
{
    use RabbitMQHelper, LmsClass;

    /**
     * @param int|string $classId
     *
     * @throws Exception
     */
    function upsertClassToEdmentum(int|string $classId)
    {
        $data = $this->mappingLMSData($classId);

        $this->pushToExchange($data, 'EDMENTUM_UPSERT', AMQPExchangeType::DIRECT, 'class');
    }

    /**
     * @param int    $classAssignmentId
     * @param string $role
     *
     * @throws Exception
     */
    function assignUsersToClassToEdmentum(int $classAssignmentId, string $role)
    {
        $body = $this->mappingAssignData($classAssignmentId, $role,LmsSystemConstant::EDMENTUM);
        $this->pushToExchange($body, 'EDMENTUM_ENROLL', AMQPExchangeType::DIRECT, 'enroll');
    }

    /**
     * @param int $classAssignmentId
     *
     * @throws Exception
     */
    function unAssignUsersToEdmentum(int $classAssignmentId)
    {
        $body = $this->mappingUnAssignData($classAssignmentId, LmsSystemConstant::EDMENTUM);
        $this->pushToExchange($body, 'EDMENTUM_ENROLL', AMQPExchangeType::DIRECT, 'withdraw');
    }

    /**
     * @param int    $classAssignmentId
     * @param string $status
     *
     * @throws Exception
     */
    function changeStatusEnrollEdmentum(int $classAssignmentId,string $status)
    {
        $body = $this->mappingUpdateStatusEnroll($classAssignmentId, LmsSystemConstant::EDMENTUM,$status);
        $this->pushToExchange($body, 'EDMENTUM_ENROLL', AMQPExchangeType::DIRECT, 'update_status');
    }
}
