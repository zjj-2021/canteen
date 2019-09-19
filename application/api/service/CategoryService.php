<?php


namespace app\api\service;


use app\api\model\ShopProductCategoryT;
use app\api\model\ShopProductCategoryV;
use app\lib\enum\CommonEnum;
use app\lib\exception\DeleteException;
use app\lib\exception\SaveException;
use app\lib\exception\UpdateException;

class CategoryService
{
    public function save($params)
    {
        $params['admin_id'] = Token::getCurrentUid();
        $supplier = ShopProductCategoryT::create($params);
        if (!$supplier) {
            throw new SaveException();
        }

    }

    public function update($params)
    {
        $supplier = ShopProductCategoryT::update($params);
        if (!$supplier) {
            throw new UpdateException();
        }

    }

    public function delete($id)
    {
        $supplier = ShopProductCategoryT::update(['state' => CommonEnum::STATE_IS_FAIL], ['id' => $id]);
        if (!$supplier) {
            throw new DeleteException();
        }
    }

    public function categories($page, $size, $c_id)
    {
        $suppliers = ShopProductCategoryV::categories($c_id, $page, $size);
        return $suppliers;
    }


}