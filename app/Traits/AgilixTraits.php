<?php


namespace App\Traits;


use Exception;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\LmsSystemConstant;

trait AgilixTraits
{
    use LmsClass;

    /**
     * @param int|string $classId
     *
     * @throws Exception
     */
    function upsertClassToAgilix(int|string $classId)
    {
        $data = $this->mappingLMSData($classId);

        $this->pushToExchange($data, 'AGILIX_BUZZ_UPSERT', AMQPExchangeType::DIRECT, 'course');
    }

    /**
     * @param int    $classAssignmentId
     * @param string $role
     *
     * @throws Exception
     */
    function assignUsersToClassToAgilix(int $classAssignmentId, string $role)
    {
        $body = $this->mappingAssignData($classAssignmentId, $role, LmsSystemConstant::AGILIX);
        $this->pushToExchange($body, 'AGILIX_BUZZ_ENROLL', AMQPExchangeType::DIRECT, 'enroll');
    }

    /**
     * @param int $classAssignmentId
     *
     * @throws Exception
     */
    function unAssignUsersToAgilix(int $classAssignmentId)
    {
        $body = $this->mappingUnAssignData($classAssignmentId, LmsSystemConstant::AGILIX);
        $this->pushToExchange($body, 'AGILIX_BUZZ_ENROLL', AMQPExchangeType::DIRECT, 'withdraw');
    }

    /**
     * @param int    $classAssignmentId
     * @param string $status
     *
     * @throws Exception
     */
    function changeStatusEnrollAgilix(int $classAssignmentId,string $status)
    {
        $body = $this->mappingUpdateStatusEnroll($classAssignmentId, LmsSystemConstant::EDMENTUM,$status);
        $this->pushToExchange($body, 'AGILIX_BUZZ_ENROLL', AMQPExchangeType::DIRECT, 'update_status');
    }
}
