<?php


namespace app\api\controller\v1;


use app\api\controller\BaseController;
use app\api\model\CompanyStaffT;
use app\api\service\ExcelService;
use app\api\service\OrderStatisticService;
use app\api\service\QrcodeService;
use app\api\service\Token;
use app\api\service\Token as TokenService;
use app\lib\enum\CommonEnum;
use app\lib\exception\DeleteException;
use app\lib\exception\SaveException;
use app\lib\exception\UpdateException;
use think\Container;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;
use think\facade\Request;
use app\lib\exception\SuccessMessage;
use app\lib\exception\SuccessMessageWithData;
use app\lib\exception\AuthException;
use think\Db;
use zml\tp_tools\Redis;
use Endroid\QrCode\QrCode;
use app\api\service\OrderService;
use think\Model;
use app\api\model\DinnerT;
use app\lib\exception\ParameterException;


class Reception extends BaseController
{
    /**
     * 新增接待票申请
     */
    public function save()
    {
        //获取微信用户id
        $userID = TokenService::getCurrentUid('user_id');
        //$userID = '86';
        //接收前端传来信息
        $staffID = Request::param('staff_id');
        $canteenID = Request::param('canteen_id');
        $orderDate = Request::param('ordering_date');
        $dinnerID = Request::param('dinner_id');
        $count = Request::param('count');
        $money = Request::param('money');
        $remark = Request::param('remark');
        $date = date('Ymd');
        $array = array();
        $sql = "select approval,state from  canteen_reception_config_t where canteen_id = " . $canteenID;
        $dtResult = Db::query($sql);
        $state = $dtResult[0]['state'];
        $approval = $dtResult[0]['approval'];
        if ($state !== 1) {
            throw  new  AuthException(['msg' => '该饭堂未启用接待票功能']);
        } else {
            if ($approval == 1) {
                $status = 1;
            } else {
                $status = 2;
            }
            $nowdate = date('Y-m-d H:i:s');
            $sql = "SELECT code_number FROM `canteen_reception_t` where substring(code_number,1,8) = " . $date . " order by code_number desc limit 1";
            $dtResult = Db::query($sql);
            if (count($dtResult) > 0) {
                $lastFourNum = substr($dtResult[0]["code_number"], -4);
                $deleteZero = preg_replace('/[0]*/', '', $lastFourNum, 1);
                $array = $this->codeIncreasing($deleteZero, 4, $deleteZero + 1);
                $apply_code = $date . $array[0];
                $sql = "insert into canteen_reception_t (staff_id,user_id,canteen_id,ordering_date,count,dinner_id,remark,status,create_time,update_time,money,code_number,content) values($staffID,$userID,$canteenID,'" . $orderDate . "',$count,$dinnerID,'" . $remark . "',$status,'" . $nowdate . "','" . $nowdate . "',$money,'" . $apply_code . "','')";
                $affRows = Db::execute($sql);
                if ($affRows > 0) {
                    $sql = "SELECT id FROM `canteen_reception_t` where code_number = " . $apply_code;
                    $dtResult = Db::query($sql);
                    $re_id = $dtResult[0]['id'];
                    if ($count > 1) {
                        $array = $this->codeIncreasing(0, 2, $count + 1);
                        for ($x = 0; $x < $count; $x++) {
                            $code = getRandChar(8);
                            $qrcodeUrl = $this->qrCode($code);
                            $reception_code = $apply_code . $array[$x];
                            $sql = "insert into canteen_reception_qrcode_t (re_id,url,code,create_time,update_time,code_number) values($re_id,'" . $qrcodeUrl . "','" . $code . "','" . $nowdate . "','" . $nowdate . "','" . $reception_code . "')";
                            $affRows = Db::execute($sql);
                        }
                        if ($affRows > 0) {
                            $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state, (case when t1.status = 4 then t1.cancel_time else t1.update_time end) as approval_time,t1.content as approval_opinions,GROUP_CONCAT(t7.code_number ) as reception_code from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id left join canteen_reception_qrcode_t t7 on t1.id = t7.re_id where t1.code_number = " . $apply_code . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status, t1.update_time,t1.cancel_time,t1.content";
                            $dtResult = Db::query($sql);
                        } else {
                            throw  new  AuthException(['msg' => '操作失败']);
                        }
                    } else {
                        $code = getRandChar(8);
                        $qrcodeUrl = $this->qrCode($code);
                        $reception_code = $apply_code . "01";
                        $sql = "insert into canteen_reception_qrcode_t (re_id,url,code,create_time,update_time,code_number) values($re_id,'" . $qrcodeUrl . "','" . $code . "','" . $nowdate . "','" . $nowdate . "','" . $reception_code . "')";
                        $affRows = Db::execute($sql);
                        if ($affRows > 0) {
                            $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state, (case when t1.status = 4 then t1.cancel_time else t1.update_time end) as approval_time,t1.content as approval_opinions,GROUP_CONCAT(t7.code_number ) as reception_code from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id left join canteen_reception_qrcode_t t7 on t1.id = t7.re_id where t1.code_number = " . $apply_code . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status, t1.update_time,t1.cancel_time,t1.content";
                            $dtResult = Db::query($sql);
                        } else {
                            throw  new  AuthException(['msg' => '操作失败']);
                        }
                    }
                } else {
                    throw  new  AuthException(['msg' => '操作失败']);
                }
            } else {
                $apply_code = $date . '0001';
                $sql = "insert into canteen_reception_t (staff_id,user_id,canteen_id,ordering_date,count,dinner_id,remark,status,create_time,update_time,money,code_number,content) values($staffID,$userID,$canteenID,'" . $orderDate . "',$count,$dinnerID,'" . $remark . "',$status,'" . $nowdate . "','" . $nowdate . "',$money,'" . $apply_code . "','')";
                $affRows = Db::execute($sql);
                if ($affRows > 0) {
                    $sql = "SELECT id FROM `canteen_reception_t` where code_number = " . $apply_code;
                    $dtResult = Db::query($sql);
                    $re_id = $dtResult[0]['id'];
                    if ($count > 1) {
                        $array = $this->codeIncreasing(0, 2, $count);
                        for ($x = 0; $x < $count; $x++) {
                            $code = getRandChar(8);
                            $qrcodeUrl = $this->qrCode($code);
                            $reception_code = $apply_code . $array[$x];
                            $sql = "insert into canteen_reception_qrcode_t (re_id,url,code,create_time,update_time,code_number) values($re_id,'" . $qrcodeUrl . "','" . $code . "','" . $nowdate . "','" . $nowdate . "','" . $reception_code . "')";
                            $affRows = Db::execute($sql);
                        }
                        if ($affRows > 0) {
                            $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state, (case when t1.status = 4 then t1.cancel_time else t1.update_time end) as approval_time,t1.content as approval_opinions,GROUP_CONCAT(t7.code_number ) as reception_code from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id left join canteen_reception_qrcode_t t7 on t1.id = t7.re_id where t1.code_number = " . $apply_code . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status, t1.update_time,t1.cancel_time,t1.content";
                            $dtResult = Db::query($sql);
                        } else {
                            throw  new  AuthException(['msg' => '操作失败']);
                        }
                    } else {
                        $code = getRandChar(8);
                        $qrcodeUrl = $this->qrCode($code);
                        $reception_code = $apply_code . "01";
                        $sql = "insert into canteen_reception_qrcode_t (re_id,url,code,create_time,update_time,code_number) values($re_id,'" . $qrcodeUrl . "','" . $code . "','" . $nowdate . "','" . $nowdate . "','" . $reception_code . "')";
                        $affRows = Db::execute($sql);
                        if ($affRows > 0) {
                            $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state, (case when t1.status = 4 then t1.cancel_time else t1.update_time end) as approval_time,t1.content as approval_opinions,GROUP_CONCAT(t7.code_number ) as reception_code from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id left join canteen_reception_qrcode_t t7 on t1.id = t7.re_id where t1.code_number = " . $apply_code . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status, t1.update_time,t1.cancel_time,t1.content";
                            $dtResult = Db::query($sql);
                        } else {
                            throw  new  AuthException(['msg' => '操作失败']);
                        }
                    }
                } else {
                    throw  new  AuthException(['msg' => '操作失败']);
                }

            }
        }

        $result = [
            'code' => '200',
            'msg' => '申请成功',
            'data' => $dtResult
        ];
        return json($result);
    }

    /**
     * 接待票状态修改
     */
    public function handel()
    {
        $type = Request::param('type');
        $apply_code = Request::param('apply_code');
        $reception_code = Request::param('reception_code');
        $content = Request::param('content');
        $ordering_date = Request::param('ordering_date');
        $dinner_id = Request::param('dinner_id');
        $receptionUrl = '';
        $result = [];
        $date = date('Y-m-d H:i:s');
        if ($type == "cancelReception") {
            $sql = "update canteen_reception_qrcode_t set status = 3,update_time = '" . $date . "' where code_number =" . $reception_code;
            $affRows = Db::execute($sql);
            if ($affRows > 0) {
                $result = [
                    'code' => '200',
                    'msg' => '取消接待票成功'
                ];
            }
        }
        if ($type == "getQrcode") {
            $sql = "select url from canteen_reception_qrcode_t where code_number = " . $reception_code;
            $dtResult = Db::query($sql);
            if (count($dtResult) > 0) {
                $receptionUrl = $dtResult[0]["url"];
                $url = 'http://' . $_SERVER['HTTP_HOST'];
                $receptionUrl = $url . $receptionUrl;
                $result = [
                    'code' => '200',
                    'msg' => 'ok',
                    'url' => $receptionUrl
                ];
            }
        }
        if ($type == "cancelApply") {
            $sql = "update canteen_reception_t set status = 4,update_time = '" . $date . "',cancel_time = '" . $date . "' where code_number = " . $apply_code;
            $affRows = Db::execute($sql);
            if ($affRows > 0) {
                $result = [
                    'code' => '200',
                    'msg' => '取消申请成功'
                ];
            }
        }
        if ($type == "refuseApply") {
            $sql = "update canteen_reception_t set status = 3,update_time = '" . $date . "',content = '" . $content . "' where code_number = " . $apply_code;
            $affRows = Db::execute($sql);
            if ($affRows > 0) {
                $result = [
                    'code' => '200',
                    'msg' => '拒绝申请成功'
                ];
            }
        }
        if ($type == "agreeApply") {
            $res = (new OrderService())->checkOrderCanHandel($dinner_id, $ordering_date);
            if ($res == true) {
                $sql = "update canteen_reception_t set status = 2,update_time = '" . $date . "',content = '" . $content . "' where code_number = " . $apply_code;
                $affRows = Db::execute($sql);
                if ($affRows > 0) {
                    $result = [
                        'code' => '200',
                        'msg' => '审核成功'
                    ];
                }
            } else {
                $result = [
                    'code' => '200',
                    'msg' => '订餐操作时间已截止,无法审核'
                ];
            }
        }
        return json($result);
    }

    /**
     * 后台获取接待票详情
     */
    public function getReceptionDetails()
    {
        $apply_code = Request::param('apply_code');
        if (!empty($apply_code)) {
            $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,(t1.count*t1.money) as sum,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state, (case when t1.status = 4 then t1.cancel_time else t1.update_time end) as approval_time,t1.content as approval_opinions,GROUP_CONCAT(t7.code_number ) as reception_code from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id left join canteen_reception_qrcode_t t7 on t1.id = t7.re_id where t1.code_number = " . "'$apply_code'" . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status, t1.update_time,t1.cancel_time,t1.content";
            $dtResult = Db::query($sql);
            $result = [
                'code' => '200',
                'msg' => 'ok',
                'data' => $dtResult
            ];
        } else {
            $result = [
                'code' => '200',
                'msg' => '查询失败，申请编号不能为空',
                'data' => ''
            ];
        };

        return json($result);
    }

    /**
     * 后台获取接待票申请列表
     */
    public function receptionsForApply($page = 1, $size = 10, $apply_name = '',
                                       $canteen_id = 0,
                                       $department_id = 0,
                                       $dinner_id = 0)
    {

        $ordering_date = Request::param('ordering_date');
        $apply_code = Request::param('apply_code');
        $company_id = Request::param('company_id');
        $apply_state = Request::param('apply_state');
        $whereStr = '';

        if (!empty($company_id)) {
            if ($company_id !== "All") {
                $whereStr .= 'and t5.id = ' . $company_id . ' ';
            }
        }
        if (!empty($canteen_id)) {
            $whereStr .= 'and t6.id = ' . $canteen_id . ' ';
        }
        if (strlen($ordering_date)) {
            $whereStr .= 'and t1.ordering_date = ' . "'$ordering_date'" . ' ';
        }
        if (!empty($dinner_id)) {
            if ($dinner_id !== "All") {
                $whereStr .= 'and t2.id = ' . $dinner_id . ' ';
            }
        }
        if (!empty($department_id)) {
            if ($department_id !== "All") {
                $whereStr .= 'and t4.id = ' . $department_id . ' ';
            }
        }
        if (strlen($apply_name)) {
            $whereStr .= 'and t3.username like' . '"%' . $apply_name . '%"' . ' ';
        }
        if (strlen($apply_code)) {
            $whereStr .= 'and t1.code_number = ' . "'$apply_code'" . ' ';
        }
        if (!empty($apply_state)) {
            if ($apply_state !== "All") {
                $whereStr .= 'and t1.status = ' . $apply_state . ' ';
            }
        }
        if ($whereStr !== '') {
            $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,sum(t1.count*t1.money) as sum,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id where 1 = 1 and t3.state = 1 " . $whereStr . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status order by t1.create_time desc limit ?,?";
            $count = "select count(*) as count from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id where 1 = 1 and t3.state = 1 " . $whereStr . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status";
        } else {
            $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,sum(t1.count*t1.money) as sum,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id where 1 = 1 and t3.state = 1 group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status order by t1.create_time desc limit ?,?";
            $count = "select count(*) as count from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id where 1 = 1 and t3.state = 1 group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status";
        }
        $dtResult = Db::query($sql, [($page - 1) * $size, $size]);
        $count = DB::query($count);
        $total = $count[0]['count'];
        $result = [
            'code' => '200',
            'msg' => 'ok',
            'total' => $total,
            'per_page' => 10,
            'current_page' => $page,
            'data' => $dtResult
        ];
        return json($result);
    }

    /**
     * 后台管理获取接待票统计列表
     */
    public function receptionsForCMS($page = 1, $size = 10, $apply_name = '',
                                     $canteen_id = 0,
                                     $department_id = 0,
                                     $dinner_id = 0)
    {
        $ordering_date = Request::param('ordering_date');
        $reception_code = Request::param('reception_code');
        $company_id = Request::param('company_id');
        $reception_state = Request::param('reception_state');
        $whereStr = '';
        if (!empty($company_id)) {
            if ($company_id !== "All") {
                $whereStr .= 'and t7.id = ' . $company_id . ' ';
            }
        }
        if (!empty($canteen_id)) {
            if ($canteen_id !== "All") {
                $whereStr .= 'and t3.id = ' . $canteen_id . ' ';
            }
        }
        if (strlen($ordering_date)) {
            $whereStr .= 'and t2.ordering_date = ' . "'$ordering_date'" . ' ';
        }
        if (!empty($dinner_id)) {
            if ($dinner_id !== "All") {
                $whereStr .= 'and t4.id = ' . $dinner_id . ' ';
            }
        }
        if (!empty($department_id)) {
            if ($department_id !== "All") {
                $whereStr .= 'and t6.id = ' . $department_id . ' ';
            }
        }
        if (strlen($apply_name)) {
            $whereStr .= 'and t5.username like' . '"%' . $apply_name . '%"' . ' ';
        }
        if (strlen($reception_code)) {
            $whereStr .= 'and t1.code_number = ' . "'$reception_code'" . ' ';
        }
        if (!empty($reception_state)) {
            if ($reception_state !== "All") {
                $whereStr .= 'and t1.status = ' . $reception_state . ' ';
            }
        }
        if ($whereStr !== '') {
            $sql = "select t2.code_number as apply_code,t1.code_number as reception_code,t3.name as canteen_name,t2.ordering_date, t4.name as dinner_name,t6.name as department_name,t5.username as apply_name,t2.money,(case when t1.`status` = 1 then '已使用' when t1.`status` = 2 then '未使用' when t1.`status` = 3 then '已取消' when t1.`status` = 4 then '已过期' end) as reception_state,COALESCE(t1.used_time,'') as used_time from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id = t2.id left join canteen_canteen_t t3 on t2.canteen_id = t3.id left join canteen_dinner_t t4 on t2.dinner_id = t4.id left join canteen_company_staff_t t5 on t2.staff_id = t5.id left join canteen_company_department_t t6 on t5.d_id = t6.id left join canteen_company_t t7 on t5.company_id = t7.id where 1 = 1 and t5.state = 1 " . $whereStr . "order by t1.id desc limit ?,?";
            $count = "select count(*) as count from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id = t2.id left join canteen_canteen_t t3 on t2.canteen_id = t3.id left join canteen_dinner_t t4 on t2.dinner_id = t4.id left join canteen_company_staff_t t5 on t2.staff_id = t5.id left join canteen_company_department_t t6 on t5.d_id = t6.id left join canteen_company_t t7 on t5.company_id = t7.id where 1 = 1 and t5.state = 1 " . $whereStr;
        } else {
            $sql = "select t2.code_number as apply_code,t1.code_number as reception_code,t3.name as canteen_name,t2.ordering_date, t4.name as dinner_name,t6.name as department_name,t5.username as apply_name,t2.money,(case when t1.`status` = 1 then '已使用' when t1.`status` = 2 then '未使用' when t1.`status` = 3 then '已取消' when t1.`status` = 4 then '已过期' end) as reception_state,COALESCE(t1.used_time,'') as used_time from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id = t2.id left join canteen_canteen_t t3 on t2.canteen_id = t3.id left join canteen_dinner_t t4 on t2.dinner_id = t4.id left join canteen_company_staff_t t5 on t2.staff_id = t5.id left join canteen_company_department_t t6 on t5.d_id = t6.id left join canteen_company_t t7 on t5.company_id = t7.id where 1 = 1 and t5.state = 1 order by t1.id desc limit ?,?";
            $count = "select count(*) as count from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id = t2.id left join canteen_canteen_t t3 on t2.canteen_id = t3.id left join canteen_dinner_t t4 on t2.dinner_id = t4.id left join canteen_company_staff_t t5 on t2.staff_id = t5.id left join canteen_company_department_t t6 on t5.d_id = t6.id left join canteen_company_t t7 on t5.company_id = t7.id where 1 = 1 and t5.state = 1";
        }
        $dtResult = Db::query($sql, [($page - 1) * $size, $size]);
        $count = DB::query($count);
        $total = $count[0]['count'];
        $result = [
            'code' => '200',
            'msg' => 'ok',
            'total' => $total,
            'per_page' => 10,
            'current_page' => $page,
            'data' => $dtResult
        ];
        return json($result);
    }

    /**
     * 微信端管理获取接待票列表
     */
    public function receptionsForOfficial($page = 1, $size = 5)
    {
        $user_id = TokenService::getCurrentTokenVar('user_id');
        //$user_id = '43';
        $canteen_id = Request::param('canteen_id');
        $whereStr = '';
        if (empty($user_id)) {
            throw  new  AuthException(['msg' => '用户id不能为空']);
        } else {
            $whereStr .= 'and t2.user_id = ' . $user_id . ' ';
        }
        if (!empty($canteen_id)) {
            if ($canteen_id !== "All") {
                $whereStr .= 'and t3.id = ' . $canteen_id . ' ';
            }
        }
        if ($whereStr !== "") {
            $sql = "select t2.ordering_date,t1.code_number as reception_code,t3.name as canteen_name,t4.name as dinner_name,t2.money,(case when t1.`status` = 1 then '已使用' when t1.`status` = 2 then '未使用' when t1.`status` = 3 then '已取消' when t1.`status` = 4 then '已过期' end) as reception_state,(case when t1.status = 4 then t1.update_time else t1.used_time end) as used_time from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id=t2.id left join canteen_canteen_t t3 on t2.canteen_id=t3.id left join canteen_dinner_t t4 on t2.dinner_id=t4.id left join canteen_company_staff_t t5 on t2.staff_id=t5.id where 1 = 1 and t5.state=1 " . $whereStr . " order by t1.id desc limit ?,?";
            $count = "select count(*) as count from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id=t2.id left join canteen_canteen_t t3 on t2.canteen_id=t3.id left join canteen_dinner_t t4 on t2.dinner_id=t4.id left join canteen_company_staff_t t5 on t2.staff_id=t5.id where 1 = 1 and t5.state=1 " . $whereStr;
        } else {
            $sql = "select t2.ordering_date,t1.code_number as reception_code,t3.name as canteen_name,t4.name as dinner_name,t2.money,(case when t1.`status` = 1 then '已使用' when t1.`status` = 2 then '未使用' when t1.`status` = 3 then '已取消' when t1.`status` = 4 then '已过期' end) as reception_state,(case when t1.status = 4 then t1.update_time else t1.used_time end) as used_time from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id=t2.id left join canteen_canteen_t t3 on t2.canteen_id=t3.id left join canteen_dinner_t t4 on t2.dinner_id=t4.id left join canteen_company_staff_t t5 on t2.staff_id=t5.id where 1 = 1 and t5.state=1 and t2.user_id = " . $user_id . " order by t1.id desc limit ?,?";
            $count = "select count(*) as count from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id=t2.id left join canteen_canteen_t t3 on t2.canteen_id=t3.id left join canteen_dinner_t t4 on t2.dinner_id=t4.id left join canteen_company_staff_t t5 on t2.staff_id=t5.id where 1 = 1 and t5.state=1 and t2.user_id = " . $user_id;
        }
        $dtResult = Db::query($sql, [($page - 1) * $size, $size]);
        $count = DB::query($count);
        $total = $count[0]['count'];
        $result = [
            'code' => '200',
            'msg' => 'ok',
            'total' => $total,
            'per_page' => 5,
            'current_page' => $page,
            'data' => $dtResult
        ];
        return json($result);
    }

    /**
     * 微信端管理获取已提交的申请
     */
    public function applySubmitted($page = 1, $size = 5)
    {
        $user_id = TokenService::getCurrentTokenVar('user_id');
        //$user_id = '43';
        if (empty($user_id)) {
            throw  new  AuthException(['msg' => '用户id不能为空']);
        }

        $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name,t3.username as apply_name,t1.count,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id where 1 = 1 and t3.state = 1 and t1.user_id = " . $user_id . " order by t1.create_time desc limit ?,?";
        $count = "select count(*) as count from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id where 1 = 1 and t3.state = 1 and t1.user_id = " . $user_id . " order by t1.create_time desc";
        $dtResult = Db::query($sql, [($page - 1) * $size, $size]);
        $count = DB::query($count);
        $total = $count[0]['count'];
        $result = [
            'code' => '200',
            'msg' => 'ok',
            'total' => $total,
            'per_page' => 5,
            'current_page' => $page,
            'data' => $dtResult
        ];
        return json($result);
    }

    /**
     * 微信端管理获取接待票申请详情
     */
    public function applyDetails()
    {
        $apply_code = Request::param('apply_code');
        if (!empty($apply_code)) {
            $sql = "select t1.code_number as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state, (case when t1.status = 4 then t1.cancel_time else t1.update_time end) as approval_time,t1.content as approval_opinions,GROUP_CONCAT(t7.code_number ) as reception_code from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id left join canteen_reception_qrcode_t t7 on t1.id = t7.re_id where t1.code_number = " . "'$apply_code'" . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status, t1.update_time,t1.cancel_time,t1.content";
            $dtResult = Db::query($sql);
            $result = [
                'code' => '200',
                'msg' => 'ok',
                'data' => $dtResult
            ];
        } else {
            $result = [
                'code' => '200',
                'msg' => '查询失败，申请编号不能为空',
                'data' => ''
            ];
        };

        return json($result);
    }

    /**
     * 获取当前用户信息
     */
    public function userInfo()
    {
        $phone = Token::getCurrentTokenVar('phone');
        $company_id = Token::getCurrentTokenVar('current_company_id');
//        $phone = "13686948977";
//        $company_id = "78";

        $staff = db('company_staff_t')->where('phone', $phone)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where('company_id', $company_id)
            ->find();
        $username = $staff['username'];
        $staff_id = $staff['id'];
        $d_id = $staff['d_id'];

        $dept = db('company_department_t')->where('id', $d_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->where('c_id', $company_id)
            ->find();
        $deptmentName = $dept['name'];

        return json(new SuccessMessageWithData(['data' => ['staff_id' => $staff_id, 'username' => $username, 'deptmentName' => $deptmentName]]));
    }

    /**
     * 获取接待票设置金额
     */
    public function getReceptionMoney()
    {
        $canteen_id = Request::param('canteen_id');
        if (empty($canteen_id)) {
            throw  new  AuthException(['msg' => '饭堂不存在']);
        }
        $receptionMoney = db('canteen_reception_config_t')->where('canteen_id', $canteen_id)
            ->where('state', CommonEnum::STATE_IS_OK)
            ->find();
        $money = $receptionMoney['money'];
        if (empty($money)) {
            throw  new  AuthException(['msg' => '该饭堂未配置接待票金额']);
        }
        return json(new SuccessMessageWithData(['data' => ['money' => $money]]));
    }

    /**
     * 后台导出接待票统计列表
     */
    public function receptionsForCMSOutput($apply_name = '',
                                           $canteen_id = 0,
                                           $department_id = 0,
                                           $dinner_id = 0)
    {
        $ordering_date = Request::param('ordering_date');
        $reception_code = Request::param('reception_code');
        $company_id = Request::param('company_id');
        $reception_state = Request::param('reception_state');
        $whereStr = '';
        if (!empty($company_id)) {
            if ($company_id !== "All") {
                $whereStr .= 'and t7.id =' . $company_id . ' ';
            }
        }
        if (!empty($canteen_id)) {
            if ($canteen_id !== "All") {
                $whereStr .= 'and t3.id =' . $canteen_id . ' ';
            }
        }
        if (strlen($ordering_date)) {
            $whereStr .= 'and t2.ordering_date =' . "'$ordering_date'" . ' ';
        }
        if (!empty($dinner_id)) {
            if ($dinner_id !== "All") {
                $whereStr .= 'and t4.id =' . $dinner_id . ' ';
            }
        }
        if (!empty($department_id)) {
            if ($department_id !== "All") {
                $whereStr .= 'and t6.id =' . $department_id . ' ';
            }
        }
        if (strlen($apply_name)) {
            $whereStr .= 'and t5.username like' . '"%' . $apply_name . '%"' . ' ';
        }
        if (strlen($reception_code)) {
            $whereStr .= 'and t1.code_number =' . "'$reception_code'" . ' ';
        }
        if (!empty($reception_state)) {
            if ($reception_state !== "All") {
                $whereStr .= 'and t1.status =' . $reception_state . ' ';
            }
        }
        if ($whereStr !== '') {
            $sql = "select CONCAT(\"\t\", t2.code_number) as apply_code,CONCAT(\"\t\", t1.code_number) as reception_code,t3.name as canteen_name,t2.ordering_date,t4.name as dinner_name,t6.name as department_name,t5.username as apply_name,t2.money,(case when t1.`status` = 1 then '已使用' when t1.`status` = 2 then '未使用' when t1.`status` = 3 then '已取消' when t1.`status` = 4 then '已过期' end) as reception_state,COALESCE(t1.used_time,'') as used_time from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id = t2.id left join canteen_canteen_t t3 on t2.canteen_id = t3.id left join canteen_dinner_t t4 on t2.dinner_id=t4.id left join canteen_company_staff_t t5 on t2.staff_id = t5.id left join canteen_company_department_t t6 on t5.d_id = t6.id left join canteen_company_t t7 on t5.company_id = t7.id where 1=1 and t5.state=1 " . $whereStr . " order by t1.id desc ";
        } else {
            $sql = "select CONCAT(\"\t\", t2.code_number) as apply_code,CONCAT(\"\t\", t1.code_number) as reception_code,t3.name as canteen_name,t2.ordering_date,t4.name as dinner_name,t6.name as department_name,t5.username as apply_name,t2.money,(case when t1.`status` = 1 then '已使用' when t1.`status` = 2 then '未使用' when t1.`status` = 3 then '已取消' when t1.`status` = 4 then '已过期' end) as reception_state,COALESCE(t1.used_time,'') as used_time from canteen_reception_qrcode_t t1 left join canteen_reception_t t2 on t1.re_id = t2.id left join canteen_canteen_t t3 on t2.canteen_id = t3.id left join canteen_dinner_t t4 on t2.dinner_id = t4.id left join canteen_company_staff_t t5 on t2.staff_id = t5.id left join canteen_company_department_t t6 on t5.d_id = t6.id left join canteen_company_t t7 on t5.company_id = t7.id where 1=1 and t5.state =1 order by t1.id desc ";
        }
        $records = Db::query($sql);

        $header = ['申请编号', '接待票编号', '饭堂', '餐次日期', '餐次', '部门', '使用人', '金额', '状态', '消费时间/取消时间'];
        $file_name = "接待票统计表";
        $url = (new ExcelService())->makeExcel($header, $records, $file_name);
        return json(new SuccessMessageWithData(['data' => 'http://' . $_SERVER['HTTP_HOST'] . $url]));
    }

    /**
     * 后台导出接待票申请列表
     */
    public function receptionsForApplyOutput($apply_name = '',
                                             $canteen_id = 0,
                                             $department_id = 0,
                                             $dinner_id = 0)
    {

        $ordering_date = Request::param('ordering_date');
        $apply_code = Request::param('apply_code');
        $company_id = Request::param('company_id');
        $apply_state = Request::param('apply_state');
        $whereStr = '';

        if (!empty($company_id)) {
            if ($company_id !== "All") {
                $whereStr .= 'and t5.id = ' . $company_id . ' ';
            }
        }
        if (!empty($canteen_id)) {
            $whereStr .= 'and t6.id = ' . $canteen_id . ' ';
        }
        if (strlen($ordering_date)) {
            $whereStr .= 'and t1.ordering_date = ' . "'$ordering_date'" . ' ';
        }
        if (!empty($dinner_id)) {
            if ($dinner_id !== "All") {
                $whereStr .= 'and t2.id = ' . $dinner_id . ' ';
            }
        }
        if (!empty($department_id)) {
            if ($department_id !== "All") {
                $whereStr .= 'and t4.id = ' . $department_id . ' ';
            }
        }
        if (strlen($apply_name)) {
            $whereStr .= 'and t3.username like' . '"%' . $apply_name . '%"' . ' ';
        }
        if (strlen($apply_code)) {
            $whereStr .= 'and t1.code_number = ' . "'$apply_code'" . ' ';
        }
        if (!empty($apply_state)) {
            if ($apply_state !== "All") {
                $whereStr .= 'and t1.status = ' . $apply_state . ' ';
            }
        }
        if ($whereStr !== '') {
            $sql = "select CONCAT(\"\t\", t1.code_number) as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,sum(t1.count*t1.money) as sum,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id where 1 = 1 and t3.state = 1 " . $whereStr . " group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status order by t1.create_time desc";
        } else {
            $sql = "select CONCAT(\"\t\", t1.code_number) as apply_code,t1.create_time as apply_time,t1.ordering_date,t2.name as dinner_name, t4.name as department_name,t3.username as apply_name,t1.count,t1.money,sum(t1.count*t1.money) as sum,t1.remark,(case when t1.status = 1 then '审核中' when t1.status = 2 then '已生效' when t1.status = 3 then '审核不通过' when t1.status = 4 then '已撤销' end) as apply_state from canteen_reception_t t1 left join canteen_dinner_t t2 ON t1.dinner_id = t2.id left join canteen_company_staff_t t3 ON t1.staff_id = t3.id left join canteen_company_department_t t4 ON t3.d_id = t4.id left join canteen_company_t t5 ON t3.company_id = t5.id left join canteen_canteen_t t6 ON t1.canteen_id = t6.id where 1 = 1 and t3.state = 1 group by  t1.code_number,t1.create_time,t1.ordering_date,t2.name,t4.name,t3.username,t1.count,t1.money,t1.remark,t1.status order by t1.create_time desc";
        }
        $records = Db::query($sql);

        $header = ['申请编号', '申请时间', '餐次日期', '餐次', '部门', '申请人', '数量', '金额', '合计', '申请原因', '状态'];
        $file_name = "接待票申请表";
        $url = (new ExcelService())->makeExcel($header, $records, $file_name);
        return json(new SuccessMessageWithData(['data' => 'http://' . $_SERVER['HTTP_HOST'] . $url]));
    }

    /**
     * 生成接待票二维码 每张二维码的code是8位数随机码
     * @param  $code
     * @return string
     */
    private function qrCode($code)
    {
        $url = "reception&$code";
        return (new QrcodeService())->qr_code($url);
    }

    /**
     * 接待票编号和申请编号排序方法
     */
    private function codeIncreasing($num, $digit, $count)
    {
        $list = array();
        $num = $num;//起始值
        $digit = $digit;//位数
        $count = $count;//最大数
        while ($num < $count) {
            $num++;
            $num = str_pad($num, $digit, "0", STR_PAD_LEFT);
            $list[] = $num;
        }
        return $list;
    }

    public function returnDemo()
    {


        //遇到异常直接throw 不需要return
        $msg = "错误描述";
        throw  new UpdateException(['msg' => $msg]);
        throw new DeleteException(['msg' => $msg]);
        throw new SaveException(['msg' => $msg]);


        //操作正确返回无数据
        return json(new SuccessMessage());

        //需要返回的数据
        $data = [];
        return json(new SuccessMessageWithData(['data' => $data]));


    }

}