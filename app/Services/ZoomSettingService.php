<?php
/**
 * @Author apple
 * @Date   Jun 09, 2022
 */

namespace App\Services;

use Carbon\Carbon;
use Exception;
use YaangVu\Constant\ZoomMeetingSettingConstant;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Constants\CalendarRepeatTypeConstant;
use YaangVu\SisModel\App\Models\impl\ZoomSettingSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;

class ZoomSettingService extends BaseService
{

    function createModel(): void
    {
        $this->model = new ZoomSettingSQL();
    }

    public function preSetupZoomSetting(object $request, array $roomSettingIds)
    {
        $this->doValidate($request, [
            '*.account'  => 'required|distinct',
            '*.token'    => 'required',
            '*.priority' => 'required|numeric|distinct',
            '*.id'       => 'sometimes|in:' . implode(',', $roomSettingIds),
        ]);
    }

    public function getAllZoomSetting(): \Illuminate\Database\Eloquent\Collection|array
    {
        $data = $this->queryHelper->buildQuery($this->model);
        try {
            return $data->get();

        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    public function setupZoomSetting(object $request): bool
    {
        $roomSettingIds = $this->model::query()
                                      ->where('sc_id', SchoolServiceProvider::$currentSchool->uuid)
                                      ->pluck('id')
                                      ->toArray();

        $this->preSetupZoomSetting($request, $roomSettingIds);

        $schoolUuid = SchoolServiceProvider::$currentSchool->uuid;
        $ids        = [];
        foreach ($request->all() as $key => $req) {
            $id = ($req['id'] ?? null) == "" ? null : $req['id'];

            if (!empty($id))
                $ids[] = $id;

            $this->model::query()->updateOrCreate([
                                                      "id" => $id
                                                  ], [
                                                      'account'  => $req['account'],
                                                      'token'    => $req['token'],
                                                      'sc_id'    => $schoolUuid,
                                                      'priority' => $req['priority']
                                                  ]);
        }

        // delete room setting
        $idsRoomSettingDelete = array_diff($roomSettingIds, $ids);
        $this->deleteZoomSettingByIds($idsRoomSettingDelete);

        return true;
    }

    public function deleteZoomSettingByIds(array $ids)
    {
        return $this->model->whereIn('id', $ids)->delete();
    }
}
