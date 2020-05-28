<?php


namespace app\api\model;


use app\lib\enum\CommonEnum;
use think\Model;

class OrderStatisticV extends Model
{
    public function getTypeAttr($value)
    {
        $status = [1 => '食堂', 2 => '外卖'];
        return $status[$value];
    }

    public function foods()
    {
        return $this->hasMany('OrderDetailT', 'o_id', 'order_id');
    }
    public static function statistic($time_begin, $time_end, $company_ids, $canteen_id, $page, $size)
    {
       // $time_end = addDay(1, $time_end);
        $list = self::whereBetweenTime('ordering_date', $time_begin, $time_end)
            ->where(function ($query) use ($company_ids, $canteen_id) {
                if (empty($canteen_id)) {
                    if (strpos($company_ids, ',') !== false) {
                        $query->whereIn('company_id', $company_ids);
                    } else {
                        $query->where('company_id', $company_ids);
                    }
                } else {
                    $query->where('canteen_id', $canteen_id);
                }
            })
            ->field('ordering_date,company,canteen,dinner,sum(count) as count')
            ->order('ordering_date DESC')
            ->group('dinner_id')
            ->paginate($size, false, ['page' => $page]);
        return $list;

    }

    public static function exportStatistic($time_begin, $time_end, $company_ids, $canteen_id)
    {
        //$time_end = addDay(1, $time_end);
        $list = self::whereBetweenTime('ordering_date', $time_begin, $time_end)
            ->where(function ($query) use ($company_ids, $canteen_id) {
                if (empty($canteen_id)) {
                    if (strpos($company_ids, ',') !== false) {
                        $query->whereIn('company_id', $company_ids);
                    } else {
                        $query->where('company_id', $company_ids);
                    }
                } else {
                    $query->where('canteen_id', $canteen_id);
                }
            })
            ->field('ordering_date,company,canteen,dinner,sum(count) as count')
            ->order('ordering_date DESC')
            ->group('dinner_id')
            ->select()->toArray();
        return $list;

    }

    public static function detail($company_ids, $time_begin,
                                  $time_end, $page, $size, $name,
                                  $phone, $canteen_id, $department_id,
                                  $dinner_id, $type)
    {
        //$time_end = addDay(1, $time_end);
        $list = self::whereBetweenTime('ordering_date', $time_begin, $time_end)
            ->where(function ($query) use ($name, $phone, $department_id) {
                if (strlen($name)) {
                    $query->where('username', $name);
                }
                if (strlen($phone)) {
                    $query->where('phone', $phone);
                }
                if (!empty($department_id)) {
                    $query->where('department_id', $department_id);
                }
            })
            ->where(function ($query) use ($company_ids, $canteen_id, $dinner_id) {
                if (!empty($dinner_id)) {
                    $query->where('dinner_id', $dinner_id);
                } else {
                    if (!empty($canteen_id)) {
                        $query->where('canteen_id', $canteen_id);
                    } else {
                        if (strpos($company_ids, ',') !== false) {
                            $query->whereIn('company_id', $company_ids);
                        } else {
                            $query->where('company_id', $company_ids);
                        }
                    }
                }
            })
            ->where(function ($query) use ($type) {
                if ($type < 3) {
                    $query->where('type', $type);
                }
            })
            ->field('order_id,ordering_date,username,canteen,department,dinner,type,ordering_type')
            ->order('order_id DESC')
            ->paginate($size, false, ['page' => $page]);
        return $list;

    }

    public static function exportDetail($company_ids, $time_begin,
                                        $time_end, $name,
                                        $phone, $canteen_id, $department_id,
                                        $dinner_id,$type)
    {
        $time_end = addDay(1, $time_end);
        $list = self::whereBetweenTime('ordering_date', $time_begin, $time_end)
            ->where(function ($query) use ($name, $phone, $department_id) {
                if (strlen($name)) {
                    $query->where('username', $name);
                }
                if (strlen($phone)) {
                    $query->where('phone', $phone);
                }
                if (!empty($department_id)) {
                    $query->where('department_id', $department_id);
                }
            })
            ->where(function ($query) use ($company_ids, $canteen_id, $dinner_id) {
                if (!empty($dinner_id)) {
                    $query->where('dinner_id', $dinner_id);
                } else {
                    if (!empty($canteen_id)) {
                        $query->where('canteen_id', $canteen_id);
                    } else {
                        if (strpos($company_ids, ',') !== false) {
                            $query->whereIn('company_id', $company_ids);
                        } else {
                            $query->where('company_id', $company_ids);
                        }
                    }
                }
            })
            ->where(function ($query) use ($type) {
                if ($type < 3) {
                    $query->where('type', $type);
                }
            })
            ->with([
                'foods' => function ($query) {
                    $query->where('state', CommonEnum::STATE_IS_OK)
                        ->field('o_id,count,name');
                }
            ])
            ->field('order_id,ordering_date,canteen,department,username,dinner,type')
            ->order('order_id DESC')
            ->select()->toArray();
        return $list;

    }

}