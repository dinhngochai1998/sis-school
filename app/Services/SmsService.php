<?php
/**
 * @Author Admin
 * @Date   Jul 12, 2022
 */

namespace App\Services;

use App\Helpers\RabbitMQHelper;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use YaangVu\Constant\PermissionActionConstant;
use YaangVu\Constant\PermissionConstant;
use YaangVu\Constant\RoleConstant;
use YaangVu\Constant\StatusSmsTemplateConstant;
use YaangVu\Exceptions\ForbiddenException;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\SmsParticipantSQL;
use YaangVu\SisModel\App\Models\impl\SmsSettingSQL;
use YaangVu\SisModel\App\Models\impl\SmsSQL;
use YaangVu\SisModel\App\Providers\SchoolServiceProvider;
use YaangVu\SisModel\App\Traits\RoleAndPermissionTrait;

class SmsService extends BaseService
{
    use RabbitMQHelper, RoleAndPermissionTrait;


    function createModel(): void
    {
        $this->model = new SmsSQL();
    }

    public function getAll(): LengthAwarePaginator
    {
        $isStaff = $this->hasAnyRole(RoleConstant::STAFF);
        if (!$isStaff && !$this->hasPermission(PermissionConstant::communication(PermissionActionConstant::LIST_SMS))) {
            throw new ForbiddenException(__('forbidden.forbidden'), new Exception());
        }

        $request = Request();

        $startDate = isset($request['sent_time'][0]) ? $request['sent_time'][0] : null;
        $endDate   = isset($request['sent_time'][1]) ? $request['sent_time'][1] : null;
        $title     = $request->title ?? null;
        $this->queryHelper->removeParam('sent_time');
        $this->queryHelper->removeParam('title');
        $data = $this->queryHelper
            ->buildQuery($this->model)
            ->with('smsParticipants')
            ->when($title, function ($q) use ($title) {
                $q->where('title', 'like', '%' . $title . '%');
            })
            ->when($startDate, function ($q) use ($startDate) {
                $q->wheredate('created_at', '>=', $startDate);
            })
            ->when($endDate, function ($q) use ($endDate) {
                $q->wheredate('created_at', '<=', $endDate);
            })
            ->where('school_id', SchoolServiceProvider::$currentSchool->id);
        try {
            $response = $data->paginate(QueryHelper::limit());
            $response->getCollection()->transform(function ($object) {
                $collectParticipants = collect($object->smsParticipants);
                $countUsers          = $object->count_user ?? 0;
                $sent                = $collectParticipants->where('status', StatusSmsTemplateConstant::DELIVERED);
                $object->sent        = count($sent) . '/' . $countUsers;
                $object->sent_time   = Carbon::parse($object->created_at)->format('Y-m-d H:i:s');

                return $object;
            });

            $this->postGetAll($response);

            return $response;
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }

    /**
     * @throws Exception
     */
    public function preUpdate(int|string $id, object $request)
    {
        $rules = [
            'user_uuids.*' => 'exists:mongodb.users,uuid',
        ];
        BaseService::doValidate($request, $rules);
        $sms          = SmsSQL::query()->with(['smsParticipants'])->where('id', $id)->first();
        $usersUuids   = $request->user_uuids;
        $participants = SmsParticipantSQL::query()->where('sms_id', $sms->id)
                                         ->where('user_uuid', $usersUuids)
                                         ->get();
        $providerIds  = $participants->pluck('provider_id')->toArray();
        $smsSetting   = SmsSettingSQL::query()->whereIn('id', $providerIds)->first();
        $hash         = Str::random(80);
        foreach ($participants as $smsParticipant) {
            if (!$smsParticipant->phone_number) {
                continue;
            }
            $body                           = [
                "auth"    => [
                    "type"       => "manual",
                    "owner"      => "sis",
                    "provider"   => strtolower($smsSetting->provider),
                    "brand_name" => $smsSetting->phone_number,
                    "username"   => $smsSetting->external_id,
                    "password"   => $smsSetting->token,
                ],
                "content" => $sms->content,
                "to"      => $smsParticipant->phone_number,
                "webhook" => [
                    "url"                => env('URL_PROJECT'),
                    "participant_sms_id" => $smsParticipant->id,
                    "hash"               => $hash,
                    "token"              => null
                ],
            ];
            $smsParticipant->hash_code      = $hash;
            $smsParticipant->sent_date_time = \Illuminate\Support\Carbon::now();
            $smsParticipant->status         = StatusSmsTemplateConstant::QUEUE;
            $smsParticipant->save();
            $this->setVHost(env('RABBITMQ_VHOST_NOTIFICATION_DEV'))
                 ->pushToExchange($body, 'SMS', AMQPExchangeType::DIRECT, strtolower($smsSetting->provider));
        }
        $sms->updated_at = \Illuminate\Support\Carbon::now();
        $sms->save();
        parent::preUpdate($id, $request); // TODO: Change the autogenerated stub
    }
}
