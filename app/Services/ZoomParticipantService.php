<?php
/**
 * @Author apple
 * @Date   Jun 13, 2022
 */

namespace App\Services;

use YaangVu\Constant\ClassAssignmentConstant;
use YaangVu\Constant\GuestObjectInviteToZoomConstant;
use YaangVu\Constant\StatusConstant;
use YaangVu\Constant\UserJoinZoomMeetingConstant;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ClassAssignmentSQL;
use YaangVu\SisModel\App\Models\impl\UserNoSQL;
use YaangVu\SisModel\App\Models\impl\ZoomParticipantSQL;

class ZoomParticipantService extends BaseService
{

    function createModel(): void
    {
        $this->model = new ZoomParticipantSQL();
    }

    /**
     * @Author apple
     * @Date   Jun 14, 2022
     *
     * @param object $request
     * @param        $zoomMeetingId
     *
     * @return bool|array
     */
    public function insertParticipantToZoomMeeting(object $request, $zoomMeetingId): bool|array
    {
        $hostUuid          = $request->host_uuid;
        $typeGuest         = $request->type_guest;
        $studentAttendance = $request->student_attendance;

        if ($request->type_guest == GuestObjectInviteToZoomConstant::USER_INFORMATION) {
            $studentUuids = $request->student_uuid;
            $studentUuids = array_merge($studentUuids, [$hostUuid]);
            $participants = $this->insertUserInformationToZoomMeeting($studentUuids, $zoomMeetingId, $hostUuid,
                                                                      $typeGuest,
                                                                      $studentAttendance);
        } else {
            $participants = $this->insertUserInClassToZoomMeeting($request->class_id, $zoomMeetingId, $hostUuid,
                                                                  $typeGuest,
                                                                  $studentAttendance);
        }

        return $participants;
    }

    /**
     * @Author apple
     * @Date   Jun 14, 2022
     *
     * @param array $studentUuids
     * @param       $zoomMeetingId
     * @param       $hostUuid
     * @param       $typeGuest
     * @param       $studentAttendance
     *
     * @return bool|array
     */
    public function insertUserInformationToZoomMeeting(array $studentUuids, $zoomMeetingId, $hostUuid,
                                                             $typeGuest, $studentAttendance): bool|array
    {
        $participants = [];
        foreach ($studentUuids as $studentUuid) {

            if ($studentUuid == $hostUuid) {
                $userJoinMeeting   = UserJoinZoomMeetingConstant::HOST;
                $studentAttendance = false;
            } else {
                $userJoinMeeting = UserJoinZoomMeetingConstant::STUDENT;
            }

            $participants[] = [
                'zoom_meeting_id'    => $zoomMeetingId,
                'user_uuid'          => $studentUuid,
                'user_join_meeting'  => $userJoinMeeting,
                'student_attendance' => $studentAttendance,
                'type_guest'         => $typeGuest,
            ];
        }

        $this->model::query()->insert($participants);

        return $participants;
    }

    /**
     * @Author apple
     * @Date   Jun 14, 2022
     *
     * @param array $classIds
     * @param       $zoomMeetingId
     * @param       $hostUuid
     * @param       $typeGuest
     * @param       $studentAttendance
     *
     * @return bool|array
     */
    public function insertUserInClassToZoomMeeting(array $classIds, $zoomMeetingId, $hostUuid, $typeGuest,
                                                         $studentAttendance): bool|array
    {
        $users        = ClassAssignmentSQL::query()
                                          ->join('users', 'users.id', 'class_assignments.user_id')
                                          ->whereIn('class_id', $classIds)
                                          ->select('users.uuid as user_uuid', 'class_assignments.*')
                                          ->where('status', StatusConstant::ACTIVE)
                                          ->get();
        $participants = [];
        foreach ($users as $user) {
            $participants[] = [
                'zoom_meeting_id'    => $zoomMeetingId,
                'user_uuid'          => $user->user_uuid,
                'user_join_meeting'  => $user->assignment,
                'student_attendance' => $studentAttendance,
                'type_guest'         => $typeGuest,
                'class_id'           => $user->class_id
            ];

        }

        $participants[] = [
            'zoom_meeting_id'    => $zoomMeetingId,
            'user_uuid'          => $hostUuid,
            'user_join_meeting'  => UserJoinZoomMeetingConstant::HOST,
            'student_attendance' => false,
            'type_guest'         => $typeGuest,
            'class_id'           => null
        ];

        $this->model::query()->insert($participants);

        return $participants;

    }

    public function buildQueryParticipantViaZoomMeetingId(int $zoomMeetingId): UserNoSQL|\Jenssegers\Mongodb\Eloquent\Builder
    {
        $uuidParticipants = (new ZoomParticipantSQL())::query()->where('zoom_meeting_id', $zoomMeetingId)
                                                      ->pluck('user_uuid')->toArray();

        return (new UserNoSQL())::query()->whereIn('uuid', $uuidParticipants);
    }
}
