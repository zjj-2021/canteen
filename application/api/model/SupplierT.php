<?php


namespace app\api\model;


use app\lib\enum\CommonEnum;
use think\Model;

class SupplierT extends Model
{
    public static function companySuppliers($company_id)
    {
        $suppliers = self::where('c_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->field('id,name')
            ->select();
        return $suppliers;
    }
}