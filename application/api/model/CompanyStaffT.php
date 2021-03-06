<?php


namespace app\api\model;


use app\lib\enum\CommonEnum;
use app\lib\enum\PayEnum;
use think\Db;
use think\Model;

class CompanyStaffT extends Model
{
    public function qrcode()
    {
        return $this->hasOne('StaffQrcodeT', 's_id', 'id');
    }

    public function card()
    {
        return $this->hasOne('StaffCardT', 'staff_id', 'id');
    }

    public function canteen()
    {
        return $this->belongsTo('CanteenT', 'c_id', 'id');

    }

    public function canteens()
    {
        return $this->hasMany('StaffCanteenT', 'staff_id', 'id');

    }

    public function account()
    {
        return $this->hasMany('AccountRecordsT', 'staff_id', 'id');

    }

    public function pay()
    {
        return $this->hasMany('PayT', 'staff_id', 'id');

    }

    public function company()
    {
        return $this->belongsTo('CompanyT', 'company_id', 'id');
    }

    public function department()
    {
        return $this->belongsTo('CompanyDepartmentT', 'd_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo('UserT', 'phone', 'phone');
    }

    public static function staff($phone, $company_id = '')
    {
        return self::where('phone', $phone)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where(function ($query) use ($company_id) {
                if (!empty($company_id)) {
                    $query->where('company_id', $company_id);
                }
            })
            ->with('qrcode')
            ->find();
    }

    public static function staffName($phone, $company_id)
    {
        return self::where('phone', $phone)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where('company_id', $company_id)
            ->find();
    }

    public static function staffWithDepartment($staff_id)
    {
        $staff = self::where('id', $staff_id)
            ->with('department')
            ->find();
        return $staff;
    }

    public static function departmentStaffs($d_ids)
    {
        $staffs = self::where(function ($query) use ($d_ids) {
            if (strpos($d_ids, ',') !== false) {
                $query->whereIn('d_id', $d_ids);
            } else {
                $query->where('d_id', $d_ids);
            }
        })->where('state', CommonEnum::STATE_IS_OK)
            ->field('id,username')->select()->toArray();
        return $staffs;
    }

    public static function getStaffWithPhone($phone, $company_id)
    {
        return self::where('phone', $phone)
            ->where('company_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->find();
    }

    public static function getCompanyStaffCounts($company_id)
    {
        return self::where('company_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->count();
    }

    public static function getStaffCanteens($phone)
    {
        return self::where('phone', $phone)
            ->with([
                'company' => function ($query) {
                    $query->field('id,name');
                },
                'canteens' => function ($query) {
                    $query->with(['info' => function ($query2) {
                        $query2->field('id,name');
                    }])
                        ->field('id,staff_id,canteen_id')
                        ->where('state', '=', CommonEnum::STATE_IS_OK);
                }
            ])
            ->field('id,company_id')
            ->where('state', CommonEnum::STATE_IS_OK)
            ->select();
    }

    public static function staffs($company_id)
    {
        return self::where('company_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->with([
                'card' => function ($query) {
                    $query->field('id,staff_id,card_code')->whereIn('state', '1,2');
                },
                'canteens' => function ($query) {
                    $query->with(['info' => function ($query2) {
                        $query2->field('id,name');
                    }])
                        ->field('id,staff_id,canteen_id')
                        ->where('state', '=', CommonEnum::STATE_IS_OK);
                }
            ])
            ->select();
    }

    public static function staffsType($company_id, $page, $size)
    {
        $types = self::where('company_id', $company_id)
            ->field(' t_id as id')
            ->group('t_id')
            ->paginate($size, false, ['page' => $page])->toArray();
        return $types;

    }

    public static function staffsForBalance($page, $size, $department_id, $user, $phone, $company_id)
    {
        $users = self::where('company_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where(function ($query) use ($department_id) {
                if (!empty($department_id)) {
                    $query->where('d_id', $department_id);
                }
            })
            ->where(function ($query) use ($phone) {
                if (!empty($phone)) {
                    $query->where('phone', $phone);
                }
            })
            ->where(function ($query) use ($user) {
                if (!empty($user)) {
                    $query->where('username|code|card_num', 'like', '%' . $user . '%');
                }
            })
            ->with([
                'department' => function ($query) {
                    $query->field('id,name');
                }
            ])
            ->field('id,d_id,username,code,card_num,phone')
            ->paginate($size, false, ['page' => $page])->toArray();
        return $users;

    }


    public static function staffsForBalanceWithAccount($page, $size, $department_id, $user, $phone, $company_id)
    {
        $users = self::where('company_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where(function ($query) use ($department_id) {
                if (!empty($department_id)) {
                    $query->where('d_id', $department_id);
                }
            })
            ->where(function ($query) use ($phone) {
                if (!empty($phone)) {
                    $query->where('phone', $phone);
                }
            })
            ->where(function ($query) use ($user) {
                if (!empty($user)) {
                    $query->where('username|code|card_num', 'like', '%' . $user . '%');
                }
            })
            ->with([
                'department' => function ($query) {
                    $query->field('id,name');
                },
                'account' => function ($query) {
                    $query->where('state', CommonEnum::STATE_IS_OK)
                        ->field('staff_id,account_id,sum(money) as money')
                        ->group('staff_id,account_id');
                },
                'card' => function ($query) {
                    $query->field('id,staff_id,card_code')->whereIn('state', '1,2');
                }
            ])
            ->field('id,d_id,username,code,phone')
            ->paginate($size, false, ['page' => $page])->toArray();
        return $users;

    }

    public static function staffsForExportsBalance($department_id, $user, $phone, $company_id)
    {
        $users = self::where('company_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where(function ($query) use ($department_id) {
                if (!empty($department_id)) {
                    $query->where('d_id', $department_id);
                }
            })
            ->where(function ($query) use ($phone) {
                if (!empty($phone)) {
                    $query->where('phone', $phone);
                }
            })
            ->where(function ($query) use ($user) {
                if (!empty($user)) {
                    $query->where('username|code', 'like', '%' . $user . '%');
                }
            })
            ->with([
                'department' => function ($query) {
                    $query->field('id,name');
                },
                'account' => function ($query) {
                    $query->where('state', CommonEnum::STATE_IS_OK)
                        ->field('staff_id,account_id,sum(money) as money')
                        ->group('account_id,staff_id');
                },
                'card' => function ($query) use ($user) {
                    $query->where(function ($query) use ($user) {
                        $query->where('card_code', 'like', '%' . $user . '%');

                    })->field('id,staff_id,card_code')->whereIn('state', '1,2');
                }
            ])
            ->field('id,d_id,username,code,card_num,phone')
            ->select()->toArray();
        return $users;

    }

    public static function staffsForOffLine($companyId)
    {
        return self::where('company_id', $companyId)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->with([
                'card' => function ($query) {
                    $query->field('id,staff_id,card_code,state')
                        ->whereIn('state', '1,2');
                }, 'department' => function ($query) {
                    $query->field('id,name');
                }
            ])
            ->field('id,d_id,username,t_id as staff_type_id,face_code')
            ->order('id')
            ->select();

    }

    public static function staffsForAccount($companyId, $departmentId, $username, $page, $size)
    {
        return self::where('company_id', $companyId)
            ->where(function ($query) use ($departmentId) {
                if ($departmentId) {
                    $query->where('d_id', $departmentId);
                }
            })->where(function ($query) use ($username) {
                if (strlen($username)) {
                    $query->where('username', 'like', '%' . $username . '%');
                }
            })->where('state', CommonEnum::STATE_IS_OK)
            ->with([
                'company' => function ($query) {
                    $query->field('id,name');
                }, 'department' => function ($query) {
                    $query->field('id,name');
                }
            ])
            ->field('id,company_id,d_id,username,phone')
            ->paginate($size, false, ['page' => $page])->toArray();
    }


    public static function staffsForSearch($page, $size, $department_id, $user, $company_id)
    {
        $users = self::where('company_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where(function ($query) use ($department_id) {
                if (!empty($department_id)) {
                    $query->where('d_id', $department_id);
                }
            })
            ->where(function ($query) use ($user) {
                if (!empty($user)) {
                    $query->where('username|code|card_num', 'like', '%' . $user . '%');
                }
            })
            ->with([
                'department' => function ($query) {
                    $query->field('id,name');
                },
                'account' => function ($query) {
                    $query->where('state', CommonEnum::STATE_IS_OK)
                        ->field('staff_id,account_id,sum(money) as money')
                        ->group('staff_id,account_id');
                },
                'card' => function ($query) {
                    $query->field('id,staff_id,card_code')->whereIn('state', '1,2');
                }
            ])
            ->field('id,d_id,username,code,phone')
            ->paginate($size, false, ['page' => $page])->toArray();
        return $users;

    }

    public static function getStaffWithUId($accountId, $companyId, $departmentIds)
    {
        $staffs = self::where(function ($query) use ($companyId, $departmentIds) {
            if ($departmentIds) {
                if (count(explode(',', $departmentIds)) > 1) {
                    $query->whereIn('d_id', $departmentIds);
                } else {
                    $query->where('d_id', $departmentIds);
                }
            } else {
                $query->where('company_id', $companyId);
            }

        })->where('state', CommonEnum::STATE_IS_OK)
            ->with([
            'user' => function ($query) {
                $query->field('phone,openid');

            },
            'account' => function ($query) use ($accountId) {
                $query->where('account_id', $accountId)->where('state', CommonEnum::STATE_IS_OK)
                    ->field('staff_id,account_id,sum(money) as money')
                    ->group('staff_id');
            }

        ]) ->field('id,phone,username')
            ->select()->toArray();

        return $staffs;

    }


}