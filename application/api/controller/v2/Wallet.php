<?php


namespace app\api\controller\v2;


use app\api\service\v2\DownExcelService;
use app\api\service\WalletService;
use app\lib\exception\ParameterException;
use app\lib\exception\SuccessMessage;
use app\lib\exception\SuccessMessageWithData;
use think\facade\Request;

class Wallet
{
    /**
     * @api {POST}  /api/v2/wallet/recharge/upload CMS管理端--充值管理(分账户)--批量充值
     * @apiGroup  CMS
     * @apiVersion 3.0.0
     * @apiDescription  用file控件上传excel ，文件名称为：cash
     * @apiSuccessExample {json} 返回样例:
     * {"msg":"ok","errorCode":0,"code":200}
     * @apiSuccess (返回参数说明) {int} error_code 错误代码 0 表示没有错误
     * @apiSuccess (返回参数说明) {String} msg 操作结果描述
     */
    public function rechargeCashUpload()
    {
        $cash_excel = request()->file('cash');
        if (is_null($cash_excel)) {
            throw  new ParameterException(['msg' => '缺少excel文件']);
        }
        $res = (new WalletService())->rechargeCashUploadWithAccount($cash_excel);
        return json(new SuccessMessageWithData(['data' => $res]));
    }

    /**
     * @api {GET} /api/v2/wallet/recharges/export CMS管理端-充值管理(分账)-充值记录列表-导出报表
     * @apiGroup  CMS
     * @apiVersion 3.0.0
     * @apiDescription CMS管理端-充值管理-充值记录列表-导出报表
     * @apiExample {get}  请求样例:
     * http://canteen.tonglingok.com/api/v2/wallet/recharges/export?time_begin=2019-09-01&time_end=2019-11-01&admin_id=0&username&type=all
     * @apiParam (请求参数说明) {string} time_begin 查询开始时间
     * @apiParam (请求参数说明) {string} time_end 查询截止时间
     * @apiParam (请求参数说明) {String} username 被充值用户
     * @apiParam (请求参数说明) {int} admin_id 充值人员id，全部传入0
     * @apiParam (请求参数说明) {String} type 充值途径:目前有：cash：现金；1:微信；2:农行；all：全部
     * @apiSuccessExample {json} 返回样例:
     * {"msg":"ok","errorCode":0,"code":200,"data":{"url":"http:\/\/canteen.tonglingok.com\/static\/excel\/download\/材料价格明细_20190817005931.xls"}}
     * @apiSuccess (返回参数说明) {int} error_code 错误代码 0 表示没有错误
     * @apiSuccess (返回参数说明) {string} msg 操作结果描述
     * @apiSuccess (返回参数说明) {string} url 下载地址
     */
    public function exportRechargeRecords($type = 'all', $admin_id = 0, $username = '', $department_id = 0)
    {
        $time_begin = Request::param('time_begin');
        $time_end = Request::param('time_end');
        (new DownExcelService())->exportRechargeRecords($time_begin, $time_end,
            $type, $admin_id, $username, $department_id,
            'rechargeRecordsWithAccount');
        return json(new SuccessMessage());
        /* $records = (new WalletService())->exportRechargeRecordsWithAccount($time_begin, $time_end, $type, $admin_id, $username, $department_id);
         return json(new SuccessMessageWithData(['data' => $records]));*/

    }

    /**
     * @api {GET} /api/v2/wallet/users/balance CMS管理端-充值管理(分账)-饭卡余额查询
     * @apiGroup  CMS
     * @apiVersion 3.0.0
     * @apiDescription CMS管理端-充值管理-饭卡余额查询
     * @apiExample {get}  请求样例:
     * http://canteen.tonglingok.com/api/v2/wallet/users/balance?&department_id=0&user&phone=&page=1&size=10
     * @apiParam (请求参数说明) {int} page 当前页码
     * @apiParam (请求参数说明) {int} size 每页多少条数据
     * @apiParam (请求参数说明) {String} user 人员信息
     * @apiParam (请求参数说明) {String} phone 手机号
     * @apiParam (请求参数说明) {int} department_id 部门id，全部传0
     * @apiSuccessExample {json} 返回样例:
     * {"msg":"ok","errorCode":0,"code":200,"data":{"total":4,"per_page":"1","current_page":1,"last_page":4,"data":[{"id":6699,"d_id":353,"username":"蚊","code":"1000","phone":"15014335935","department":{"id":353,"name":"1部"},"account":[{"account_id":73,"name":"个人账户","type":1,"fixed_type":1,"balance":"89.50"}],"card":{"id":346,"staff_id":6699,"card_code":"680141047"}}]}}
     * @apiSuccess (返回参数说明) {int} total 数据总数
     * @apiSuccess (返回参数说明) {int} per_page 每页多少条数据
     * @apiSuccess (返回参数说明) {int} current_page 当前页码
     * @apiSuccess (返回参数说明) {int} last_page 最后页码
     * @apiSuccess (返回参数说明) {String} username  姓名
     * @apiSuccess (返回参数说明) {int} code 编码
     * @apiSuccess (返回参数说明) {string} card_num 卡号
     * @apiSuccess (返回参数说明) {string} phone 手机号
     * @apiSuccess (返回参数说明) {obj} department 部门信息
     * @apiSuccess (返回参数说明) {string} name 部门名称
     * @apiSuccess (返回参数说明) {obj} account 账户余额信息
     * @apiSuccess (返回参数说明) {string} name 账户名称
     * @apiSuccess (返回参数说明) {float} balance 账户余额
     * @apiSuccess (返回参数说明) {obj}  card 用户卡信息
     * @apiSuccess (返回参数说明) {string}  card_code 卡号
     */
    public function usersBalance($page = 1, $size = 20, $department_id = 0, $user = '', $phone = '')
    {
        $users = (new WalletService())->usersBalanceWithAccount($page, $size, $department_id, $user, $phone);
        return json(new SuccessMessageWithData(['data' => $users]));

    }

    /**
     * @api {GET} /api/v2/wallet/users/balance/export CMS管理端-充值管理（分账）-饭卡余额查询-导出报表
     * @apiGroup  CMS
     * @apiVersion 3.0.0
     * @apiDescription CMS管理端-充值管理-饭卡余额查询-导出报表
     * @apiExample {get}  请求样例:
     * http://canteen.tonglingok.com/api/v2/wallet/users/balance/export?&department_id=0&user&phone=
     * @apiParam (请求参数说明) {String} user 人员信息
     * @apiParam (请求参数说明) {String} phone 手机号
     * @apiParam (请求参数说明) {int} department_id 部门id，全部传0
     * @apiSuccessExample {json} 返回样例:
     * {"msg":"ok","errorCode":0,"code":200,"data":{"url":"http:\/\/canteen.tonglingok.com\/static\/excel\/download\/材料价格明细_20190817005931.xls"}}
     * @apiSuccess (返回参数说明) {int} error_code 错误代码 0 表示没有错误
     * @apiSuccess (返回参数说明) {string} msg 操作结果描述
     * @apiSuccess (返回参数说明) {string} url 下载地址
     */
    public function exportUsersBalance($department_id = 0, $user = '', $phone = '')
    {
        (new DownExcelService())->exportUsersBalance($department_id, $user, $phone, 'userBalanceWithAccount');
        return json(new SuccessMessage());
   /*     $users = (new WalletService())->exportUsersBalanceWithAccount($department_id, $user, $phone);
        return json(new SuccessMessageWithData(['data' => $users]));*/

    }

    /**
     * @api {POST}  /api/v2/wallet/supplement/upload CMS管理端--设置--补录管理（账户）--批量充值
     * @apiGroup  CMS
     * @apiVersion 3.0.0
     * @apiDescription  用file控件上传excel ，文件名称为：supplement
     * @apiSuccessExample {json} 返回样例:
     * {"msg":"ok","errorCode":0,"code":200}
     * @apiSuccess (返回参数说明) {int} error_code 错误代码 0 表示没有错误
     * @apiSuccess (返回参数说明) {String} msg 操作结果描述
     */
    public function rechargeSupplementUpload()
    {
        $supplement_excel = request()->file('supplement');
        if (is_null($supplement_excel)) {
            throw  new ParameterException(['msg' => '缺少excel文件']);
        }
        $res = (new WalletService())->rechargeSupplementUploadWithAccount($supplement_excel);
        return json(new SuccessMessageWithData(['data' => $res]));
    }


    /**
     * @api {GET} /api/v2/wallet/pay/getPreOrder  微信端-微信支付-获取支付信息
     * @apiGroup  Official
     * @apiVersion 1.0.1
     * @apiDescription 微信端-微信支付-微信支付获取支付信息
     * @apiExample {get}  请求样例:
     * http://mengant.cn/api/v2/wallet/pay/getPreOrder?order_id=1
     * @apiParam (请求参数说明) {int} order_id 订单id
     * @apiSuccessExample {json} 返回样例:
     * {"msg":"ok","errorCode":0,"code":200,"data":{"return_code":"SUCCESS","return_msg":"OK","appid":"wx60311f2f47c86a3e","mch_id":"1555725021","sub_mch_id":"1563901631","nonce_str":"kU7RuppRQZDrFfwu","sign":"B4B16DDD14C77B5D94FFE9B8CA4A0D50","result_code":"SUCCESS","prepay_id":"wx221520364672093fca904b5d1308980100","trade_type":"JSAPI"}}
     * @apiSuccess (返回参数说明) {String} data 前端支付所需数据
     */
    public function getPreOrder()
    {
        $order_id = Request::param('order_id');
        $info = (new \app\api\service\v2\WalletService())->getPreOrder($order_id);
        return json(new SuccessMessageWithData(['data' => $info]));

    }


}