<?php
/**
 * @Author yaangvu
 * @Date   Mar 16, 2022
 */

namespace App\Jobs\SyncAssignment;

class AssignmentDto
{
    private int         $classId;
    private int         $userId;
    private string      $assignment;
    private string      $externalId;
    private string|null $status;

    /**
     * @return string|null
     */
    public function getStatus(): string|null
    {
        return $this->status;
    }

    /**
     * @param string|null $status
     */
    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    /**
     * @return int
     */
    public function getClassId(): int
    {
        return $this->classId;
    }

    /**
     * @param int $classId
     */
    public function setClassId(int $classId): void
    {
        $this->classId = $classId;
    }

    /**
     * @return int
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * @param int $userId
     */
    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }

    /**
     * @return string
     */
    public function getAssignment(): string
    {
        return $this->assignment;
    }

    /**
     * @param string $assignment
     */
    public function setAssignment(string $assignment): void
    {
        $this->assignment = $assignment;
    }

    /**
     * @return string
     */
    public function getExternalId(): string
    {
        return $this->externalId;
    }

    /**
     * @param string $externalId
     */
    public function setExternalId(string $externalId): void
    {
        $this->externalId = $externalId;
    }
}
