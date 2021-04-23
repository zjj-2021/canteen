<?php


namespace app\api\controller\v1;


use app\api\controller\BaseController;
use app\api\service\PunishmentService;
use app\lib\exception\SuccessMessage;
use app\lib\exception\SuccessMessageWithData;
use think\facade\Request;

class Punishment extends BaseController
{
    /**
     * @api {GET} /api/v1/punishment/strategyDetail 惩罚机制PC端-惩罚策略-获取测法策略列表
     * @apiGroup  CMS
     * @apiVersion 3.0.0
     * @apiDescription  惩罚机制PC端-惩罚策略-获取测法策略列表
     * @apiExample {get}  请求样例:
     * xxxx
     * @apiParam (请求参数说明) {int} page 当前页码
     * @apiParam (请求参数说明) {int} size 每页多少条数据
     * @apiParam (请求参数说明) {String} company_id 企业id
     * @apiParam (请求参数说明) {String} canteen_id 饭堂id
     * @apiSuccessExample {json} 返回样例:
     * {"msg":"ok","errorCode":0,"code":200,"data":{"total":1,"per_page":10,"current_page":1,"last_page":1,"data":[{"id":1,"company_id":78,"canteen_id":1,"staff_type_id":1,"detail":[{"id":7,"strategy_id":1,"type":"no_meal 订餐未就餐","count":2,"state":1},{"id":8,"strategy_id":1,"type":"no_booking 未订餐就餐","count":2,"state":1}]}]}}
     * @apiSuccess (返回参数说明) {int} total 数据总数
     * @apiSuccess (返回参数说明) {int} per_page 每页多少条数据
     * @apiSuccess (返回参数说明) {int} current_page 当前页码
     * @apiSuccess (返回参数说明) {int} last_page 最后页码
     * @apiSuccess (返回参数说明) {int} id 惩罚策略id
     * @apiSuccess (返回参数说明) {int} company_id 企业id
     * @apiSuccess (返回参数说明) {int} canteen_id 饭堂id
     * @apiSuccess (返回参数说明) {int} staff_type_id 人员类型id
     *      * @apiParam (请求参数说明) {string} detail  惩罚策略明细json字符串
     * @apiSuccess (返回参数说明) {int} detail|id 惩罚策略明细表id
     * @apiSuccess (返回参数说明) {int} detail|strategy_id 测法策略id
     * @apiSuccess (返回参数说明) {string} detail|type 违规类型：no_meal 订餐未就餐；no_booking  未订餐就餐
     * @apiSuccess (返回参数说明) {int}  detail|state 状态：1 正常；2 删除
     * @apiSuccess (返回参数说明) {int}  detail|count 最大违规数量
     */

    public function  strategyDetail($page = 1, $size = 10)
    {
        $params =Request::param();
        $company_id = $params['company_id'];
        $canteen_id = $params['canteen_id'];
        $menus = (new PunishmentService())->strategyDetails($page, $size, $company_id, $canteen_id);
        return json(new SuccessMessageWithData(['data' => $menus]));
    }
    /**
     * @api {POST} /api/v1/punishment/strategyDetail 惩罚机制PC端-惩罚策略-修改惩罚策略
     * @apiGroup   CMS
     * @apiVersion 3.0.0
     * @apiDescription    惩罚机制PC端-惩罚策略-修改惩罚策略
     * @apiExample {post}  请求样例:
     * [{
     * "id":7,
     * "strategy_id":"1",
     * "type":"no_meal",
     * "count":"2",
     * "state":"1"
     * },{
     * "id":8,
     * "strategy_id":"1",
     * "type":"no_booking",
     * "count":"2",
     * "state":"1"}]
     * @apiParam (请求参数说明) {int} id 惩罚策略明细表id
     * @apiParam (请求参数说明) {int} strategy_id  惩罚策略id
     * @apiParam (请求参数说明) {string} type 违规类型：no_meal 订餐未就餐；no_booking  未订餐就餐
     * @apiParam (请求参数说明) {int}  state 状态：1 正常；2 删除
     * @apiParam (请求参数说明) {int}  count 最大违规数量
     * @apiSuccessExample {json} 返回样例:
     * {"msg":"ok","errorCode":0,"code":200}
     * @apiSuccess (返回参数说明) {int} errorCode 错误码： 0表示操作成功无错误
     * @apiSuccess (返回参数说明) {string} msg 信息描述
     */
    public function updateStrategy()
    {
        $params =Request::param();
        (new PunishmentService())->updateStrategy($params);
        return json(new SuccessMessage());
    }
}