<?php


namespace app\api\service;


use app\api\model\CanteenT;
use app\api\model\CompanyAccountT;
use app\api\model\CompanyDepartmentT;
use app\api\model\CompanyStaffT;
use app\api\model\CompanyStaffV;
use app\api\model\DepartmentV;
use app\api\model\StaffCanteenT;
use app\api\model\StaffCardT;
use app\api\model\StaffCardV;
use app\api\model\StaffQrcodeT;
use app\api\model\StaffV;
use app\lib\enum\CommonEnum;
use app\lib\enum\UserEnum;
use app\lib\exception\DeleteException;
use app\lib\exception\ParameterException;
use app\lib\exception\SaveException;
use app\lib\exception\UpdateException;
use http\Params;
use think\Db;
use think\Exception;
use think\Request;
use function GuzzleHttp\Promise\each_limit;
use function GuzzleHttp\Psr7\str;

class DepartmentService
{
    public function save($params)
    {
        if ($this->checkExit($params['c_id'], $params['name'])) {
            throw new SaveException(['msg' => '部门：' . $params['name'] . '已存在']);
        }
        $params['state'] = CommonEnum::STATE_IS_OK;
        $department = CompanyDepartmentT::create($params);
        if (!$department) {
            throw new SaveException();
        }
        return $department->id;
    }

    private function checkExit($company_id, $name)
    {
        $department = CompanyDepartmentT::where('c_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where('name', $name)
            ->count('id');
        return $department;

    }

    public function deleteDepartment($id)
    {
        if ($this->checkDepartmentCanDelete($id)) {
            throw new DeleteException(['msg' => '删除操作失败，该部门有子部门或者有员工']);
        }
        $res = CompanyDepartmentT::update(['state' => CommonEnum::STATE_IS_FAIL], ['id' => $id]);
        if (!$res) {
            throw new DeleteException();
        }
    }

    private function checkDepartmentCanDelete($id)
    {
        $staff = CompanyStaffT::where('d_id', $id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->count('id');
        if ($staff) {
            return true;
        }
        $son = CompanyDepartmentT::where('parent_id', $id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->count('id');
        if ($son) {
            return true;
        }
        return false;

    }

    public function departments($c_id)
    {
        if (empty($c_id)) {
            $c_id = Token::getCurrentTokenVar('company_id');
        }
        $departments = DepartmentV::departments($c_id);
        return getTree($departments);
    }

    public function addStaff($params)
    {
        try {
            Db::startTrans();
            $this->checkStaffExits($params['company_id'], $params['phone'], $params["face_code"]);
            if (!empty($params['card_num']) && StaffCardV::checkCardExits($params['company_id'], $params['card_num'])) {
                throw new ParameterException(['msg' => "卡号已存在，不能重复绑定"]);
            }
            $params['state'] = CommonEnum::STATE_IS_OK;
            $staff = CompanyStaffT::create($params);
            if (!$staff) {
                throw new SaveException();
            }
            //保存用户饭堂绑定关系
            $this->saveStaffCanteen($staff->id, $params['canteens']);
            //保存二维码
            $this->saveQrcode($staff->id);
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    public function checkStaffExits($company_id, $phone, $face_code, $staff_id = 0)
    {
        $staff = CompanyStaffT::where('company_id', $company_id)
            ->where(function ($query) use ($staff_id) {
                if (empty(!$staff_id)) {
                    $query->where('id', '<>', $staff_id);
                }
            })
            ->where(function ($query) use ($phone, $face_code) {
                if (empty($face_code)) {
                    $query->where('phone', $phone);
                } else {
                    $query->whereOr('phone', $phone)
                        ->whereOr('face_code', $face_code);
                }
            })
            ->where('state', CommonEnum::STATE_IS_OK)
            ->count('id');
        if ($staff) {
            if (empty($face_code)) {
                throw  new SaveException(['msg' => '手机号已存在']);
            } else {
                throw  new SaveException(['msg' => '手机号或者人脸识别ID已存在']);

            }
        }

    }

    public function updateStaff($params)
    {
        try {
            Db::startTrans();
            if (key_exists('expiry_date', $params)) {
                $qrcode = StaffQrcodeT::update(['expiry_date' => $params['expiry_date']], ['s_id' => $params['id']]);
                if (!$qrcode) {
                    throw new UpdateException(['msg' => '更新二维码有效期失败']);
                }
            }
            $staff = CompanyStaffT::get($params['id']);
            $companyID = $staff->company_id;
            if (!empty($params['phone'])) {
                $this->checkStaffExits($companyID, $params['phone'], '', $staff->id);

            }

            $update = CompanyStaffT:: update($params);
            if (!$update) {
                throw new UpdateException();
            }
            //更新用户饭堂绑定关系
            $canteens = empty($params['canteens']) ? [] : json_decode($params['canteens'], true);
            $cancel_canteens = empty($params['cancel_canteens']) ? [] : json_decode($params['cancel_canteens'], true);
            $this->updateStaffCanteen($staff->id, $canteens, $cancel_canteens);
            //处理卡号
            if (!empty($params['card_num'])) {

                if (StaffCardV::checkCardExits($companyID, $params['card_num'])) {
                    throw new ParameterException(['msg' => "卡号已存在，不能重复绑定"]);
                }
                $this->updateCard($params['card_num'], $params['id']);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    private
    function updateCard($cardNum, $staffId)
    {
        StaffCardT::destroy(function ($query) use ($staffId) {
            $query->where('staff_id', $staffId);
        });
        StaffCardT::create([
            'staff_id' => $staffId,
            'card_code' => $cardNum,
            'state'
            => CommonEnum::STATE_IS_OK
        ]);
    }

    private
    function saveStaffCanteen($staff_id, $canteens)
    {
        $canteens = json_decode($canteens, true);
        if (empty($canteens)) {
            throw new ParameterException(['msg' => '字段饭堂id，参数格式错误']);
        }
        $data_list = [];
        foreach ($canteens as $k => $v) {
            $data_list[] = [
                'staff_id' => $staff_id,
                'canteen_id' => $v['canteen_id'],
                'state' => CommonEnum::STATE_IS_OK
            ];
        }
        $res = (new StaffCanteenT())->saveAll($data_list);
        if (!$res) {
            throw new SaveException(['msg' => '添加饭堂用户关系失败']);
        }

    }

    private
    function updateStaffCanteen($staff_id, $canteens, $cancel_canteens)
    {
        $data_list = [];
        if (!empty($canteens)) {
            foreach ($canteens as $k => $v) {
                $data_list[] = [
                    'staff_id' => $staff_id,
                    'canteen_id' => $v,
                    'state' => CommonEnum::STATE_IS_OK
                ];
            }
        }
        if (!empty($cancel_canteens)) {
            foreach ($cancel_canteens as $k => $v) {
                $data_list[] = [
                    'id' => $v,
                    'state' => CommonEnum::STATE_IS_FAIL
                ];
            }
        }
        $res = (new StaffCanteenT())->saveAll($data_list);
        if (!$res) {
            throw new SaveException(['msg' => '更新饭堂用户关系失败']);
        }

    }

    public
    function uploadStaffs($company_id, $staffs_excel)
    {
        $date = (new ExcelService())->saveExcel($staffs_excel);
        $res = $this->prefixStaffs($company_id, $date);
        return $res;
    }

    private
    function prefixStaffs($company_id, $data)
    {
        try {
            Db::startTrans();
            $types = (new AdminService())->allTypes();
            $canteens = (new CanteenService())->companyCanteens($company_id);
            $departments = $this->companyDepartments($company_id);
            $staffs = $this->getCompanyStaffs($company_id);
            //获取企业消费方式
            $consumptionType = (new CompanyService())->consumptionType($company_id);
            $consumptionTypeArr = explode(',', $consumptionType['consumptionType']);
            $phones = $staffs['phones'];
            $faceCodes = $staffs['faceCodes'];
            $cardNums = $staffs['cardNums'];
            $fail = array();
            $success = array();
            $param_key = array();
            if (count($data) < 2) {
                return [];
            }

            foreach ($data as $k => $v) {
                if ($k == 2) {
                    $param_key = $data[$k];
                } else if ($k > 2 && !empty($data[$k])) {

                    //检测手机号是否已经存在
                    if (in_array($v[5], $phones)) {
                        $fail[] = "第" . $k . "数据有问题：手机号" . $v[5] . "系统已经存在";
                        break;
                    } else if (!$this->isMobile($v[5])) {
                        $fail[] = "第" . $k . "数据有问题：手机号格式错误";
                        break;
                    } else {
                        array_push($phones, $v[5]);
                    }
                    $faceCode = trim($v[9]);
                    //检测人脸识别id是否存在
                    if (in_array('face', $consumptionTypeArr)) {
                        if (!empty($faceCode) && in_array($faceCode, $faceCodes)) {
                            $fail[] = "第" . $k . "数据有问题：人脸识别ID" . $faceCode . "系统已经存在";
                            break;
                        } else {
                            if (!empty($faceCode)) {
                                array_push($faceCodes, $faceCode);
                            }
                        }

                    }
                    $check = $this->validateParams($company_id, $param_key, $data[$k], $types, $canteens, $departments, $consumptionTypeArr, $cardNums);
                    if (!$check['res']) {
                        $fail[] = "第" . $k . "数据有问题：" . $check['info']['msg'];
                        continue;
                    }
                    if (in_array('card', $consumptionTypeArr) && strlen($v[6])) {
                        array_push($cardNums, $v[6]);
                    }
                    $success[] = $check['info'];
                }

            }
            if (count($fail)) {
                return [
                    'fail' => $fail
                ];
            }

            if (count($success)) {
                $all = (new CompanyStaffT())->saveAll($success);
                if (!$all) {
                    throw  new SaveException();
                }
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }


    }

    private
    function isMobile($value)
    {
        $rule = '^1[0-9][0-9]\d{8}$^';
        $result = preg_match($rule, $value);
        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    public
    function getCompanyStaffs($company_id)
    {
        $staffs = CompanyStaffT::staffs($company_id);
        $staffsPhone = [];
        $staffsFaceCode = [];
        $staffsCardNum = [];
        foreach ($staffs as $k => $v) {
            array_push($staffsPhone, $v['phone']);
            array_push($staffsFaceCode, $v['face_code']);
            if ($v['card']) {
                array_push($staffsCardNum, $v['card']['card_code']);

            }
        }
        return [
            'phones' => $staffsPhone,
            'faceCodes' => $staffsFaceCode,
            'cardNums' => $staffsCardNum
        ];
    }


    private
    function validateParams($company_id, $param_key, $data, $types, $canteens, $departments, $consumptionTypeArr, $cardNums)
    {
        $state = ['启用', '停用'];
        $canteen = trim($data[0]);
        $department = trim($data[1]);
        $staffType = trim($data[2]);
        $code = trim($data[3]);
        $name = trim($data[4]);
        $phone = trim($data[5]);
        $card_num = trim($data[6]);
        $face_code = trim($data[9]);
        $birthday = trim($data[8]);
        $canteen_ids = [];
        if (!in_array($data[7], $state)) {
            $fail = [
                'name' => $name,
                'msg' => '状态错误'
            ];
            return [
                'res' => false,
                'info' => $fail
            ];
        }

        if (!strlen($name)) {
            $fail = [
                'name' => $name,
                'msg' => '姓名为空'
            ];
            return [
                'res' => false,
                'info' => $fail
            ];
        }
        $state = trim($data[7]) == "启用" ? 1 : 2;
        //判断饭堂是否存在
        if (!strlen($canteen)) {
            $fail = [
                'name' => $name,
                'msg' => '饭堂字段为空'
            ];
            return [
                'res' => false,
                'info' => $fail
            ];
        }
        $canteen_arr = explode('|', $canteen);

        foreach ($canteen_arr as $k => $v) {
            if (!strlen($v)) {
                continue;
            }
            $c_id = $this->checkParamExits($canteens, $v);
            if (!$c_id) {
                $fail = [
                    'name' => $name,
                    'msg' => '企业中不存在该饭堂：' . $v
                ];
                return [
                    'res' => false,
                    'info' => $fail
                ];
                break;
            }
            array_push($canteen_ids, $c_id);
        }

        //判断人员类型是否存在
        $t_id = $this->checkParamExits($types, $staffType);
        if (!$t_id) {
            $fail = [
                'name' => $name,
                'msg' => '系统中不存在该人员类型：' . $staffType
            ];
            return [
                'res' => false,
                'info' => $fail
            ];
        }

        if (in_array('card', $consumptionTypeArr)) {
            //判断填写了卡号，生日必填
            if (in_array($card_num, $cardNums)) {
                $fail = [
                    'name' => $name,
                    'msg' => "卡号重复"
                ];
                return [
                    'res' => false,
                    'info' => $fail
                ];
            }
            if (!strlen($birthday)) {
                $fail = [
                    'name' => $name,
                    'msg' => "生日未填写"
                ];
                return [
                    'res' => false,
                    'info' => $fail
                ];
            }
        }

        //检测部门是否存在
        $d_id = $this->checkParamExits($departments, $department);
        if (!$d_id) {
            $fail = [
                'name' => $name,
                'msg' => '企业中不存在该部门：' . $department
            ];
            return [
                'res' => false,
                'info' => $fail
            ];
        }
        $data = [
            'd_id' => $d_id,
            't_id' => $t_id,
            'code' => $code,
            'username' => $name,
            'phone' => $phone,
            'company_id' => $company_id,
            'canteen_ids' => implode(',', $canteen_ids),
            'state' => $state
        ];

        if (in_array('card', $consumptionTypeArr)) {
            $data['card_num'] = $card_num;
            if (count(explode('-', $birthday)) == 3 || count(explode('/', $birthday)) == 3) {
                $data['birthday'] = $birthday;
            } else {
                $data['birthday'] = gmdate("Y-m-d", ($birthday - 25569) * 86400);
            }
        }

        if (in_array('face', $consumptionTypeArr)) {
            $data['face_code'] = $face_code;

        }

        return [
            'res' => true,
            'info' => $data
        ];
    }

    private
    function checkParamExits($list, $current_data)
    {
        if (!count($list)) {
            return 0;
        }
        foreach ($list as $k => $v) {
            if ($v['name'] == $current_data) {
                return $v['id'];
            }
        }
        return 0;

    }

    private
    function companyDepartments($company_id)
    {
        $departs = CompanyDepartmentT::where('c_id', $company_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->field('id,name')
            ->select()->toArray();
        return $departs;
    }

    private
    function getUploadStaffQrcodeAndCanteenInfo($staffs)
    {
        $list = array();
        $staff_canteen_list = array();
        foreach ($staffs as $k => $v) {
            $code = getRandChar(12);
            $url = sprintf(config("setting.qrcode_url"), 'canteen', $code);
            $qrcode_url = (new QrcodeService())->qr_code($url);
            $list[] = [
                'code' => $code,
                's_id' => $v->id,
                'expiry_date' => date('Y-m-d H:i:s', strtotime('+' . config("setting.qrcode_expire_in") . 'minute')),
                'url' => $qrcode_url
            ];

            $canteen_ids = $v->canteen_ids;
            $canteen_arr = explode(',', $canteen_ids);
            if (!empty($canteen_arr)) {
                foreach ($canteen_arr as $k2 => $v2) {
                    $staff_canteen_list[] = [
                        'staff_id' => $v->id,
                        'canteen_id' => $v2,
                        'state' => CommonEnum::STATE_IS_OK
                    ];
                }
            }
        }
        return [
            'qrcode' => $list,
            'canteen' => $staff_canteen_list
        ];

    }

    public
    function saveQrcode($s_id)
    {
        $code = getQrCodeWithStaffId($s_id);
        $url = sprintf(config("setting.qrcode_url"), 'canteen', $code);
        $qrcode_url = (new QrcodeService())->qr_code($url);
        $expiry_date = date('Y-m-d H:i:s', strtotime("+" . config("setting.qrcode_expire_in") . "minute", time()));
        $data = [
            'code' => $code,
            's_id' => $s_id,
            'minute' => config("setting.qrcode_expire_in"),
            'expiry_date' => $expiry_date,
            'url' => $qrcode_url
        ];
        $qrcode = StaffQrcodeT::create($data);
        if (!$qrcode) {
            throw new SaveException();
        }
        return $qrcode_url;
    }


    public
    function saveQrcode2($s_id)
    {
        $code = getQrCodeWithStaffId($s_id);
        $url = sprintf(config("setting.qrcode_url"), 'canteen', $code);
        $qrcode_url = (new QrcodeService())->qr_code($url);
        $expiry_date = date('Y-m-d H:i:s', strtotime("+" . config("setting.qrcode_expire_in") . "minute", time()));
        $data = [
            'code' => $code,
            's_id' => $s_id,
            'minute' => config("setting.qrcode_expire_in"),
            'expiry_date' => $expiry_date,
            'url' => $qrcode_url
        ];
        $qrcode = StaffQrcodeT::create($data);
        if (!$qrcode) {
            throw new SaveException();
        }
        return [
            'url' => $qrcode->url,
            'create_time' => $qrcode->create_time,
            'expiry_date' => $qrcode->expiry_date
        ];
    }


    public
    function updateQrcode2($params)
    {
        $staff_id = $params['id'];
        $code = getQrCodeWithStaffId($staff_id);
        unset($params['id']);
        $url = sprintf(config("setting.qrcode_url"), 'canteen', $code);
        $qrcode_url = (new QrcodeService())->qr_code($url);
        $params['code'] = $code;
        $params['url'] = $qrcode_url;
        $expiry_date = date('Y-m-d H:i:s', time());
        $params['create_time'] = $expiry_date;
        $params['expiry_date'] = $this->prefixQrcodeExpiryDate($expiry_date, $params);
        $staffQRCode = StaffQrcodeT::where('s_id', $staff_id)->find();
        if ($staffQRCode) {
            $qrcode = StaffQrcodeT::update($params, ['s_id' => $staff_id]);
        } else {
            $params['s_id'] = $staff_id;
            $qrcode = StaffQrcodeT::create($params);
        }
        if (!$qrcode) {
            throw new SaveException();
        }
        $staff = CompanyStaffT::get($staff_id);
        return [
            'usernmae' => $staff->username,
            'url' => $qrcode->url,
            'create_time' => $qrcode->create_time,
            'expiry_date' => $qrcode->expiry_date
        ];
    }


    public
    function updateQrcode($params)
    {
        $s_id = $params['id'];
        $code = getQrCodeWithStaffId($s_id);
        $url = sprintf(config("setting.qrcode_url"), 'canteen', $code, $params['s_id']);
        $qrcode_url = (new QrcodeService())->qr_code($url);

        $params['code'] = $code;
        $params['url'] = $qrcode_url;
        $expiry_date = date('Y-m-d H:i:s', time());
        $params['create_time'] = $expiry_date;
        $params['expiry_date'] = $this->prefixQrcodeExpiryDate($expiry_date, $params);
        $qrcode = StaffQrcodeT::update($params);
        if (!$qrcode) {
            throw new SaveException();
        }
        $staff = CompanyStaffT::get($s_id);
        return [
            'usernmae' => $staff->username,
            'url' => $qrcode->url,
            'create_time' => $qrcode->create_time,
            'expiry_date' => $qrcode->expiry_date
        ];
    }

    public
    function updateQrcode3($params)
    {
        $s_id = $params['staff_id'];
        $code = getQrCodeWithStaffId($s_id);
        $url = sprintf(config("setting.qrcode_url"), 'canteen', $code, $params['s_id']);
        $qrcode_url = (new QrcodeService())->qr_code($url);

        $params['code'] = $code;
        $params['url'] = $qrcode_url;
        $expiry_date = date('Y-m-d H:i:s', time());
        $params['update_time'] = $expiry_date;
        $params['expiry_date'] = $this->prefixQrcodeExpiryDate($expiry_date, $params);
        $qrcode = StaffQrcodeT::update($params);
        if (!$qrcode) {
            throw new SaveException();
        }
        $staff = CompanyStaffT::get($s_id);
        return [
            'usernmae' => $staff->username,
            'url' => $qrcode->url,
            'create_time' => $qrcode->update_time,
            'expiry_date' => $qrcode->expiry_date
        ];
    }


    public
    function companyStaffs($page, $size, $c_id, $d_id)
    {
        $staffs = CompanyStaffV::companyStaffs($page, $size, $c_id, $d_id);
        return $staffs;
    }

    public
    function exportStaffs($company_id, $department_id)
    {
        //检测企业是否包含刷卡消费
        $checkCard = (new CompanyService())->checkConsumptionContainsCard($company_id);
        //检测企业是否包含刷脸消费
        $checkFace = (new CompanyService())->checkConsumptionContainsFace($company_id);
        $staffs = CompanyStaffV::exportStaffs($company_id, $department_id);
        $staffs = $this->prefixExportStaff($staffs, $checkCard, $checkFace);
        if ($checkCard && $checkFace) {
            $header = ['企业', '部门', '人员状态', '人员类型', '员工编号', '姓名', '手机号码', '卡号', '出生日期', '人脸识别ID', '归属饭堂'];
        } else
            if ($checkCard) {
                $header = ['企业', '部门', '人员状态', '人员类型', '员工编号', '姓名', '手机号码', '卡号', '出生日期', '归属饭堂'];
            } else
                if ($checkFace) {
                    $header = ['企业', '部门', '人员状态', '人员类型', '员工编号', '姓名', '手机号码', '人脸识别ID', '归属饭堂'];
                } else {

                    $header = ['企业', '部门', '人员状态', '人员类型', '员工编号', '姓名', '手机号码', '归属饭堂'];
                }

        $file_name = "企业员工导出";
        $url = (new ExcelService())->makeExcel($header, $staffs, $file_name);
        return [
            'url' => config('setting.domain') . $url
        ];
    }

    public
    function prefixExportStaff($staffs, $checkCard, $checkFace)
    {
        if (!count($staffs)) {
            return $staffs;
        }
        $dataList = [];
        foreach ($staffs as $k => $v) {
            $data = [];
            $data['company'] = $v['company'];
            $data['department'] = $v['department'];
            $data['state'] = $v['state'] == 1 ? '启用' : '停用';;
            $data['type'] = $v['type'];
            $data['code'] = $v['code'];
            $data['username'] = $v['username'];
            $data['phone'] = $v['phone'];
            if ($checkCard) {
                $data['card_num'] = empty($v['card']['card_code']) ? '' : $v['card']['card_code'];
                $data['birthday'] = $v['birthday'];
            }
            if ($checkFace) {
                $data['face_code'] = $v['face_code'];
            }


            $canteen = [];
            $canteens = $v['canteens'];
            foreach ($canteens as $k2 => $v2) {
                array_push($canteen, $v2['info']['name']);
            }
            $data['canteen'] = implode('|', $canteen);
            array_push($dataList, $data);
        }
        return $dataList;

    }

    private
    function prefixQrcodeExpiryDate($expiry_date, $params)
    {
        $type = ['minute', 'hour', 'day', 'month', 'year'];
        $exit = 0;
        foreach ($type as $k => $v) {
            if (key_exists($v, $params) && !empty($params[$v])) {
                $exit = 1;
                $expiry_date = date('Y-m-d H:i:s', strtotime("+" . $params[$v] . "$v", strtotime($expiry_date)));
            }
        }
        if (!$exit) {
            $expiry_date = date('Y-m-d H:i:s', strtotime("+" . config("setting.qrcode_expire_in") . "minute", strtotime($expiry_date)));

        }
        return $expiry_date;
    }

    public
    function departmentStaffs($d_ids)
    {
        $staffs = CompanyStaffT::departmentStaffs($d_ids);
        return $staffs;
    }

    public
    function getStaffWithPhone($phone, $company_id)
    {
        $staff = CompanyStaffT::getStaffWithPhone($phone, $company_id);
        return $staff;

    }

    public
    function getCompanyStaffCounts($company_id)
    {
        $count = CompanyStaffT::getCompanyStaffCounts($company_id);
        return $count;
    }

    public
    function adminDepartments()
    {
        if (Token::getCurrentTokenVar('type') == 'official') {
            $company_id = Token::getCurrentTokenVar('current_company_id');

        } else {
            $company_id = Token::getCurrentTokenVar('company_id');

        }
        $departments = CompanyDepartmentT::adminDepartments($company_id);
        return $departments;
    }

    public
    function departmentsForRecharge()
    {
        $company_id = Token::getCurrentTokenVar('company_id');
        $departments = CompanyDepartmentT::adminDepartments($company_id);
        return $departments;
    }

    public
    function staffsForRecharge($page, $size, $department_id, $key)
    {
        $company_id = Token::getCurrentTokenVar('company_id');
        $accounts = CompanyAccountT::accountsWithSortsAndDepartmentId($company_id);
        $staffs = CompanyStaffV:: staffsForRecharge($page, $size, $department_id, $key, $company_id);
        $data = $staffs['data'];
        if (count($data)) {
            foreach ($data as $k => $v) {
                $data[$k]['account'] = (new AccountService())->checkStaffAccount($accounts, $v['d_id']);
            }
        }
        $staffs['data'] = $data;
        return $staffs;
    }

    public
    function searchStaff($page, $size, $company_id, $department_id, $key)
    {
        $staffs = CompanyStaffV::searchStaffs($page, $size, $company_id, $department_id, $key);
        return $staffs;
    }

    public function handleStaff($id, $state)
    {
        $staff = CompanyStaffT::update(['state' => $state], ['id' => $id]);
        if (!$staff) {
            throw  new UpdateException();
        }
        if ($state == CommonEnum::STATE_IS_DELETE) {
            //删除用户需要解除卡绑定
            $staffCard = StaffCardT::where('staff_id', $id)
                ->where('state', CommonEnum::STATE_IS_OK)
                ->find();
            if ($staffCard) {
                $staffCard->state = CommonEnum::STATE_IS_DELETE;
                $staffCard->save();
            }
        }
    }


}