<?php
/**
 * @Author im.phien
 * @Date   Jun 22, 2022
 */

namespace App\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use YaangVu\Exceptions\SystemException;
use YaangVu\LaravelBase\Helpers\QueryHelper;
use YaangVu\LaravelBase\Services\impl\BaseService;
use YaangVu\SisModel\App\Models\impl\ZoomHostSQL;

class ZoomHostService extends BaseService
{

    function createModel(): void
    {
        $this->model = new ZoomHostSQL();
    }

    /**
     * @Description
     *
     * @Author im.phien
     * @Date   Jun 22, 2022
     *
     * @param Request $request
     *
     * @return LengthAwarePaginator
     */
    public function getAllZoomHost(Request $request): LengthAwarePaginator
    {
        $searchKey = $request->search_key ? trim($request->search_key) : null;
        $data      = $this->queryHelper->removeParam('search_key')
                                       ->buildQuery($this->model)->when($searchKey, function ($q) use ($searchKey) {
                                        $q->where('full_name', 'ILIKE', '%' . $searchKey . '%')
                                          ->orWhere('email', 'ILIKE', '%' . $searchKey . '%');
                                    });
        try {
            return $data->paginate(QueryHelper::limit());
        } catch (Exception $e) {
            throw new SystemException($e->getMessage() ?? __('system-500'), $e);
        }
    }
}