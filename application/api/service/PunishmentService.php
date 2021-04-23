<?php


namespace app\api\service;


use app\api\model\PunishmentDetailT;
use app\api\model\PunishmentStrategyT;
use app\lib\exception\SaveException;
use app\lib\exception\UpdateException;


class PunishmentService
{

    public function strategyDetails($page, $size, $company_id, $canteen_id)
    {
        $details = PunishmentStrategyT::strategyDetail($page, $size, $company_id, $canteen_id);
        return $details;
    }

    public function updateStrategy($params)
    {
        $detail=json_decode($params['detail'], true);

        $res =(new PunishmentDetailT())->saveAll($detail);
        if (!$res) {
            throw new UpdateException();
        }
    }

}