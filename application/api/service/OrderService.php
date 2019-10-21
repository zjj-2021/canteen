<?php
/**
 * Created by PhpStorm.
 * User: 明良
 * Date: 2019/9/5
 * Time: 11:14
 */

namespace app\api\service;


use app\api\model\CanteenAccountT;
use app\api\model\ConsumptionRecordsV;
use app\api\model\DinnerStatisticV;
use app\api\model\DinnerT;
use app\api\model\FoodsStatisticV;
use app\api\model\OrderDetailT;
use app\api\model\OrderHandelT;
use app\api\model\OrderingV;
use app\api\model\OrderT;
use app\api\model\OrderUsersStatisticV;
use app\api\model\ShopOrderingV;
use app\api\model\ShopOrderT;
use app\lib\enum\CommonEnum;
use app\lib\enum\MenuEnum;
use app\lib\enum\OrderEnum;
use app\lib\enum\PayEnum;
use app\lib\exception\AuthException;
use app\lib\exception\ParameterException;
use app\lib\exception\SaveException;
use app\lib\exception\UpdateException;
use think\Db;
use think\Exception;
use think\Request;
use function GuzzleHttp\Psr7\str;

class OrderService extends BaseService
{

    public function personChoice($params)
    {
        try {
            Db::startTrans();
            $dinner_id = $params['dinner_id'];
            $ordering_date = $params['ordering_date'];
            $count = $params['count'];
            $detail = json_decode($params['detail'], true);
            unset($params['detail']);

            $params['ordering_type'] = OrderEnum::ORDERING_CHOICE;
            $u_id = Token::getCurrentUid();
            $canteen_id = Token::getCurrentTokenVar('current_canteen_id');

            //获取餐次信息
            $dinner = DinnerT::dinnerInfo($dinner_id);
            //检测该餐次订餐时间是否允许
            $this->checkDinnerForPersonalChoice($dinner, $ordering_date);
            //检测用户是否可以订餐并返回订单金额
            $orderMoney = $this->checkUserCanOrder($u_id, $dinner, $ordering_date, $canteen_id, $count, $detail);

            $pay_way = $this->checkBalance($u_id, $canteen_id, $orderMoney['money'] * $count + $orderMoney['sub_money'] * $count);
            if (!$pay_way) {
                throw new SaveException(['errorCode' => 49000, 'msg' => '余额不足']);
            }
            //保存订单信息
            $params['order_num'] = makeOrderNo();
            $params['pay_way'] = $pay_way;
            $params['u_id'] = $u_id;
            $params['c_id'] = $canteen_id;
            $params['d_id'] = $dinner_id;
            $params['pay'] = CommonEnum::STATE_IS_OK;
            $params['money'] = $orderMoney['money'] * $count;
            $params['sub_money'] = $orderMoney['sub_money'] * $count;
            $params['consumption_type'] = $orderMoney['consumption_type'];
            $params['pay'] = CommonEnum::STATE_IS_OK;

            $company_id = Token::getCurrentTokenVar('current_company_id');
            $staff = (new UserService())->getUserCompanyInfo(Token::getCurrentPhone(), $company_id);
            $params['staff_type_id'] = $staff->t_id;
            $params['department_id'] = $staff->d_id;
            $params['company_id'] = $staff->company_id;
            $params['staff_id'] = $staff->id;
            $order = OrderT::create($params);
            if (!$order) {
                throw new SaveException(['msg' => '生成订单失败']);
            }
            $this->prefixDetail($detail, $order->id);
            if ($params['type'] == OrderEnum::EAT_OUTSIDER && !empty($params['address_id'])) {
                (new AddressService())->prefixAddressDefault($params['address_id']);
            }
            Db::commit();
            return [
                'id' => $order->id
            ];
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }

    }

    public
    function prefixDetail($detail, $o_id)
    {
        $data_list = [];
        foreach ($detail as $k => $v) {
            $foods = $v['foods'];
            foreach ($foods as $k2 => $v2) {
                $data = [
                    'm_id' => $v['menu_id'],
                    'f_id' => $v2['food_id'],
                    'price' => $v2['price'],
                    'count' => $v2['count'],
                    'name' => $v2['name'],
                    'o_id' => $o_id,
                    'state' => CommonEnum::STATE_IS_OK,
                ];
                array_push($data_list, $data);
            }

        }
        $res = (new OrderDetailT())->saveAll($data_list);
        if (!$res) {
            throw new SaveException(['msg' => '存储订餐明细失败']);
        }
    }

    public
    function checkBalance($u_id, $canteen_id, $money)
    {
        $balance = 10000;
        if ($balance >= $money) {
            return PayEnum::PAY_BALANCE;
        }
        //获取账户设置，检测是否可预支消费
        $canteenAccount = CanteenAccountT::where('c_id', $canteen_id)->find();
        if (!$canteenAccount) {
            return false;
        }

        if ($canteenAccount->type == OrderEnum::OVERDRAFT_NO) {
            return false;
        }
        if ($canteenAccount->limit_money < ($money - $balance)) {
            return false;
        }
        return PayEnum::PAY_OVERDRAFT;

    }

    public
    function checkUserCanOrder($u_id, $dinner, $day, $canteen_id, $count, $detail, $ordering_type = "person_choice")
    {
        //获取用户指定日期订餐数量
        $consumptionCount = OrderingV::getRecordForDayOrdering($u_id, $day, $dinner->name);

        //检测消费策略
        $phone = Token::getCurrentPhone();
        $t_id = (new UserService())->getUserStaffTypeByPhone($phone);
        //获取指定用户消费策略
        $strategies = (new CanteenService())->getStaffConsumptionStrategy($canteen_id, $dinner->id, $t_id);

        $orderMoneyFixed = $dinner->fixed;
        $strategyMoney = $this->checkConsumptionStrategy($strategies, $count, $consumptionCount);
        if ($ordering_type == "person_choice") {
            //检测菜单数据是否合法并返回订单金额
            $detailMoney = $this->checkMenu($dinner->id, $detail);
            if ($orderMoneyFixed == CommonEnum::STATE_IS_FAIL) {
                $strategyMoney['money'] = $detailMoney;
            }
        }
        return $strategyMoney;

    }

    public
    function checkUserCanOrderForOnline($u_id, $dinner, $day, $count, $strategies)
    {
        //获取用户指定日期订餐数量
        $consumptionCount = OrderingV::getRecordForDayOrdering($u_id, $day, $dinner->name);
        //获取指定用户消费策略
        $strategyMoney = $this->checkConsumptionStrategy($strategies, $count, $consumptionCount);
        return $strategyMoney;

    }


    //检测是否在订餐时间内
    public function checkDinnerForPersonalChoice($dinner, $ordering_date)
    {
        if (!$dinner) {
            throw new ParameterException(['msg' => '指定餐次未设置']);
        }
        $type = $dinner->type;
        if ($type == 'week') {
            throw new ParameterException(['msg' => '当前餐次需批量订餐，请使用线上订餐功能订餐']);
        }
        $limit_time = $dinner->limit_time;
        $type_number = $dinner->type_number;
        $expiryDate = $this->prefixExpiryDate($ordering_date, [$type => $type_number]);
        if (strtotime($limit_time) > strtotime($expiryDate)) {
            throw  new  SaveException(['msg' => '超出订餐时间']);
        }
    }

    //检测是否在订餐时间内
    /*  public function checkDinnerForOnline($dinner, $ordering_date)
      {
          // $dinner = DinnerT::dinnerInfo($dinner_id);
          if (!$dinner) {
              throw new ParameterException(['msg' => '指定餐次未设置']);
          }
          $type = $dinner->type;
          $limit_time = $dinner->limit_time;
          $type_number = $dinner->type_number;
          if ($type == 'week') {

          }

          $expiryDate = $this->prefixExpiryDate($ordering_date, [$type => $type_number]);
          if (strtotime($limit_time) > strtotime($expiryDate)) {
              throw  new  SaveException(['msg' => '超出订餐时间']);
          }
      }*/

    private
    function checkConsumptionStrategy($strategies, $orderCount, $consumptionCount)
    {
        if (!$strategies) {
            throw new SaveException(['msg' => '饭堂消费策略没有设置']);
        }
        if ($orderCount > $strategies->ordered_count) {
            throw new SaveException(['msg' => '订餐数量超过最大订餐数量，最大订餐数量为：' . $strategies->ordered_count]);
        }
        if ($consumptionCount >= $strategies->consumption_count) {
            throw new SaveException(['msg' => '消费次数已达到上限，最大消费次数为：' . $strategies->ordered_count]);
        }
        $detail = json_decode($strategies->detail, true);
        if (empty($detail)) {
            throw new ParameterException(['msg' => "消费策略设置异常"]);
        }
        //获取消费策略中：订餐未就餐的标准金额和附加金额
        $returnMoney = [];
        $no_meal_money = 0;
        $no_meal_sub_money = 0;
        $meal_money = 0;
        $meal_sub_money = 0;
        foreach ($detail as $k => $v) {
            if (($consumptionCount + 1) == $v['number']) {
                $strategy = $v['strategy'];
                foreach ($strategy as $k2 => $v2) {
                    if ($v2['status'] == "no_meals_ordered") {
                        $no_meal_money = $v2['money'];
                        $no_meal_sub_money = $v2['sub_money'];
                    } else if ($v2['status'] == "ordering_meals") {
                        $meal_money = $v2['money'];
                        $meal_sub_money = $v2['sub_money'];
                    }
                }

                if (($no_meal_money + $no_meal_sub_money) > ($meal_money + $meal_sub_money)) {
                    $returnMoney = [
                        'consumption_type' => 'no_meals_ordered',
                        'money' => $no_meal_money,
                        'sub_money' => $no_meal_money
                    ];
                } else {
                    $returnMoney = [
                        'consumption_type' => 'ordering_meals',
                        'money' => $meal_money,
                        'sub_money' => $meal_money
                    ];
                }
                break;
            }
        }
        return $returnMoney;
    }

    private
    function checkMenu($dinner_id, $detail)
    {
        if (empty($detail)) {
            throw new ParameterException(['菜品明细数据格式不对']);
        }
        //获取餐次下所有菜品类别
        $menus = (new MenuService())->dinnerMenus($dinner_id);
        if (!count($menus)) {
            throw new ParameterException(['msg' => '指定餐次未设置菜单信息']);
        }

        $detailMoney = 0;
        foreach ($detail as $k => $v) {
            $menu_id = $v['menu_id'];
            $menu = $this->getMenuInfo($menus, $menu_id);
            if (empty($menu)) {
                throw new ParameterException(['msg' => '菜品类别id错误']);
            }
            if (($menu['status'] == MenuEnum::FIXED) && ($menu['count'] < count($v['foods']))) {
                throw new SaveException(['msg' => '选菜失败,菜品类别：<' . $menu['category'] . '> 选菜数量超过最大值：' . $menu['count']]);
            }

            $foods = $v['foods'];
            foreach ($foods as $k2 => $v2) {
                $detailMoney += $v2['price'] * $v2['count'];
            }
        }
        return $detailMoney;
    }

    private
    function getMenuInfo($menus, $menu_id)
    {
        $menu = [];
        foreach ($menus as $k => $v) {
            if ($v->id == $menu_id) {
                $menu['status'] = $v->status;
                $menu['count'] = $v->count;
                $menu['category'] = $v->category;
                break;
            }

        }
        return $menu;
    }

    /**
     * 线上订餐
     */
    public function orderingOnline($detail)
    {
        try {
            Db::startTrans();
            $detail = json_decode($detail, true);
            if (empty($detail)) {
                throw new ParameterException(['msg' => '订餐数据格式错误']);
            }
            $u_id = Token::getCurrentUid();
            $canteen_id = Token::getCurrentTokenVar('current_canteen_id');
            $data = $this->prefixOnlineOrderingData($u_id, $canteen_id, $detail);
            $money = $data['all_money'];
            $pay_way = $this->checkBalance($u_id, $canteen_id, $money);
            if (!$pay_way) {
                throw new SaveException(['errorCode' => 49000, 'msg' => '余额不足']);
            }
            $list = $this->prefixPayWay($pay_way, $data['list']);
            $ordering = (new OrderT())->saveAll($list);
            if (!$ordering) {
                throw  new SaveException();
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }

    }

    private function prefixPayWay($pay_way, $list)
    {
        foreach ($list as $k => $v) {
            $list[$k]['pay_way'] = $pay_way;
        }
        return $list;
    }

    /**
     * 处理线上订餐信息
     * 计算订单总价格
     */
    private function prefixOnlineOrderingData($u_id, $canteen_id, $detail)
    {

        $data_list = [];
        $all_money = 0;
        $company_id = Token::getCurrentTokenVar('current_company_id');
        $staff = (new UserService())->getUserCompanyInfo(Token::getCurrentPhone(), $company_id);
        $staff_type_id = $staff->t_id;
        $department_id = $staff->d_id;
        $staff_id = $staff->id;

        foreach ($detail as $k => $v) {
            //检测该餐次是否在订餐时间范围内
            $ordering_data = $v['ordering'];
            $dinner = DinnerT::dinnerInfo($v['d_id']);
            $strategies = (new CanteenService())->getStaffConsumptionStrategy($canteen_id, $v['d_id'], $staff_type_id);

            if (!empty($ordering_data)) {
                foreach ($ordering_data as $k2 => $v2) {
                    //检测是否可以订餐
                    $checkOrder = $this->checkUserCanOrderForOnline($u_id, $dinner, $v2['ordering_date'], $v2['count'], $strategies);
                    $data = [];
                    $data['u_id'] = $u_id;
                    $data['c_id'] = $canteen_id;
                    $data['d_id'] = $v['d_id'];
                    $data['staff_type_id'] = $staff_type_id;
                    $data['department_id'] = $department_id;
                    $data['staff_id'] = $staff_id;
                    $data['company_id'] = $company_id;
                    $data['ordering_date'] = $v2['ordering_date'];
                    $data['count'] = $v2['count'];
                    $data['order_num'] = makeOrderNo();
                    $data['ordering_type'] = OrderEnum::ORDERING_ONLINE;
                    $params['money'] = $checkOrder['money'] * $v2['count'];
                    $params['sub_money'] = $checkOrder['sub_money'] * $v2['count'];
                    $params['consumption_type'] = $checkOrder['consumption_type'];
                    $data['pay_way'] = '';
                    $data['pay'] = CommonEnum::STATE_IS_OK;
                    array_push($data_list, $data);
                    $all_money += $params['money'] + $params['sub_money'];
                }

            }

        }
        return [
            'all_money' => $all_money,
            'list' => $data_list
        ];

    }

    public function checkDinnerOrdered($ordering_date, $dinner_id, $records)
    {
        if (empty($records)) {
            return true;
        }
        foreach ($records as $k => $v) {
            if (strtotime($ordering_date) == strtotime($v['ordering_date']) && $dinner_id == $v['d_id']) {
                throw new SaveException(['msg' => '订餐失败，' . '日期：' . $ordering_date . ';餐次：' . $v['dinner'] . ';已在饭堂：' . $v['canteen'] . '预定']);
                break;
            }
        }

    }

    /**
     * 获取消费策略中订餐消费默认金额
     */
    private function getStrategyMoneyForOrderingOnline($c_id, $d_id, $t_id)
    {
        $money = 0;
        $strategy = (new CanteenService())->getStaffConsumptionStrategy($c_id, $d_id, $t_id);
        $strategy = $strategy->toArray();
        $detail = $strategy['detail'];
        if (empty($detail)) {
            throw  new ParameterException(['msg' => '消费策略未设置或参数格式错误']);
        }
        foreach ($detail as $k => $v) {
            $info = $v['strategy'];
            if (empty($info)) {
                throw  new ParameterException(['msg' => '消费策略设置出错']);
            }
            foreach ($info as $k2 => $v2) {
                if ($info['status'] = 'ordering_meals') {
                    $money = $v2['money'];
                    break;
                }
            }

            if ($money) {
                break;
            }

        }
        return $money;
    }

    /**
     * 获取用户的订餐信息
     * 今天及今天以后订餐信息
     */
    public function userOrdering($consumption_time)
    {
        $u_id = Token::getCurrentUid();
        $orderings = OrderingV::userOrdering($u_id, $consumption_time);
        return $orderings;


    }

    /**
     * 线上订餐获取初始化信息
     * 1.餐次信息及订餐时间限制
     * 2.消费策略
     */
    public function infoForOnline()
    {
        $canteen_id = Token::getCurrentTokenVar('current_canteen_id');
        $t_id = Token::getCurrentTokenVar('t_id');
        $dinner = (new CanteenService())->getDinners($canteen_id);
        $strategies = (new CanteenService())->staffStrategy($canteen_id, $t_id);
        foreach ($dinner as $k => $v) {
            foreach ($strategies as $k2 => $v2) {
                if ($v['id'] = $v2['d_id']) {
                    $dinner[$k]['ordered_count'] = $v2['ordered_count'];
                    unset($strategies[$k2]);
                }

            }
        }
        return $dinner;
    }

    /**
     * 取消订单
     */
    public function orderCancel($id)
    {
        //检测取消订餐操作是否可以执行
        $order = OrderT::where('id', $id)->find();
        if (!$order) {
            throw new ParameterException(['msg' => '指定订餐信息不存在']);
        }
        $this->checkOrderCanHandel($order->d_id);
        $order->state = CommonEnum::STATE_IS_FAIL;
        $res = $order->save();
        if (!$res) {
            throw new SaveException();
        }
    }

    private function checkOrderCanHandel($d_id)
    {
        //获取餐次设置
        $dinner = DinnerT::dinnerInfo($d_id);
        $type = $dinner->type;
        $limit_time = $dinner->limit_time;
        $type_number = $dinner->type_number;
        if ($type == 'day') {
            $expiryDate = $this->prefixExpiryDateForOrder($dinner->ordering_date, $type_number, '-');
            if (strtotime(date('Y-m-d H:i:s', time())) > strtotime($expiryDate . ' ' . $limit_time)) {
                throw  new  SaveException(['msg' => '当前时间不可操作订单']);
            }
        } else if ($type == 'week') {
            $ordering_date_week = date('W', strtotime($dinner->ordering_date));
            $now_week = date('W', time());
            if ($ordering_date_week <= $now_week) {
                throw  new  SaveException(['msg' => '当前时间不可操作订单']);
            }
            if (($ordering_date_week - $now_week) === 1) {
                if ($type_number == 0) {
                    //星期天
                    if (strtotime($limit_time) < time()) {
                        throw  new  SaveException(['msg' => '当前时间不可操作订单']);
                    }
                } else {
                    //周一到周六
                    if (date('w', time()) > $type_number) {
                        throw  new  SaveException(['msg' => '当前时间不可操作订单']);
                    } else if (date('w', time()) == $type_number && strtotime($limit_time) < time()) {
                        throw  new  SaveException(['msg' => '当前时间不可操作订单']);
                    }
                }
            }

        }
        return true;
    }

    /**
     * 修改订餐数量
     */
    public function changeOrderCount($id, $count)
    {
        $order = OrderT::where('id', $id)->find();
        if (!$order) {
            throw new ParameterException(['msg' => '指定订餐信息不存在']);
        }
        //检测订单是否可操作
        $this->checkOrderCanHandel($order->d_id);
        //检测订单修改数量是否合法
        $strategy = (new CanteenService())->getStaffConsumptionStrategy($order->c_id, $order->d_id, $order->t_id);
        if (!$strategy) {
            throw new ParameterException(['msg' => '当前用户消费策略不存在']);
        }
        if ($count > $strategy->ordered_count) {
            throw new UpdateException(['msg' => '超出最大订餐数量，不能预定']);
        }
        $old_money = $order->money;
        $old_sub_money = $order->sub_money;
        $old_count = $order->count;
        $new_money = ($old_money / $old_count) * $count;
        $new_sub_money = ($old_sub_money / $old_count) * $count;
        //检测订单金额是否合法
        $check_res = $this->checkBalance($order->u_id, $order->c_id, ($new_money + $new_sub_money - $old_money - $old_sub_money));
        if (!$check_res) {
            throw new UpdateException(['msg' => '当前用户可消费余额不足']);
        }
        //修改数量
        $order->count = $count;
        //处理订单金额
        $order->money = $new_money;
        //处理订单附加金额
        $order->sub_money = $new_sub_money;
        //处理消费方式
        $order->pay_way = $check_res;
        if (!($order->save())) {
            throw new UpdateException();
        }
    }

    public function changeOrderFoods($params)
    {
        try {
            Db::startTrans();
            $id = $params['id'];
            $detail = json_decode($params['detail'], true);
            if (empty($detail)) {
                throw new ParameterException(['msg' => '订单明细为空或者数据格式错误']);
            }
            $order = OrderT::where('id', $id)->find();
            //检测订单是否可操作
            $count = $order->count;
            $this->checkOrderCanHandel($order->d_id);
            if (!empty($params['count']) && ($params['count'] != $count)) {
                //检测订单修改数量是否合法
                $count = $params['count'];
                $strategy = (new CanteenService())->getStaffConsumptionStrategy($order->c_id, $order->d_id, $order->t_id);
                if (!$strategy) {
                    throw new ParameterException(['msg' => '当前用户消费策略不存在']);
                }
                if ($count > $strategy->ordered_count) {
                    throw new UpdateException(['msg' => '超出最大订餐量，不能修改']);
                }
            }


            $check_money = $this->checkOrderUpdateMoney($id, $order->u_id, $order->c_id,
                $order->d_id, $order->pay_way, $order->money, $order->sub_money, $order->count, $count, $detail);
            $order->pay_way = $check_money['pay_way'];
            $order->money = $check_money['new_money'];
            $order->sub_money = $check_money['new_sub_money'];
            $order->count = $count;
            if (!($order->save())) {
                throw new UpdateException();
            }
            //处理订单明细
            $this->prefixUpdateOrderDetail($id, $detail);
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    private function prefixUpdateOrderDetail($o_id, $new_detail)
    {
        $data_list = [];
        foreach ($new_detail as $k => $v) {
            $menu_id = $v['menu_id'];
            $add_foods = $v['add_foods'];
            $update_foods = $v['update_foods'];
            $cancel_foods = $v['cancel_foods'];
            if (!empty($add_foods)) {
                foreach ($add_foods as $k2 => $v2) {
                    $data = [
                        'm_id' => $menu_id,
                        'f_id' => $v2['food_id'],
                        'price' => $v2['price'],
                        'count' => $v2['count'],
                        'name' => $v2['name'],
                        'o_id' => $o_id,
                        'state' => CommonEnum::STATE_IS_OK,
                    ];
                    array_push($data_list, $data);
                }
            }

            if (!empty($update_foods)) {
                foreach ($update_foods as $k3 => $v3) {
                    $data = [
                        'id' => $v3['detail_id'],
                        'count' => $v3['count'],
                    ];
                    array_push($data_list, $data);
                }
            }

            if (strlen($cancel_foods)) {
                $cancel_arr = explode(',', $cancel_foods);
                foreach ($cancel_arr as $k4 => $v4) {
                    $data = [
                        'id' => $v4,
                        'state' => CommonEnum::STATE_IS_FAIL,
                    ];
                    array_push($data_list, $data);
                }
            }


        }
        $res = (new OrderDetailT())->saveAll($data_list);
        if (!$res) {
            throw new UpdateException(['msg' => '更新订单明细失败']);
        }

    }

    private function checkOrderUpdateMoney($o_id, $u_id, $canteen_id, $dinner_id, $pay_way,
                                           $old_money, $old_sub_money, $old_count, $count, $new_detail)
    {
        //获取餐次下所有菜品类别
        $menus = (new MenuService())->dinnerMenus($dinner_id);
        if (!count($menus)) {
            throw new ParameterException(['msg' => '指定餐次未设置菜单信息']);
        }
        $dinner = DinnerT::dinnerInfo($dinner_id);
        $fixed = $dinner->fixed;
        $new_money = 0;
        $new_sub_money = 0;
        foreach ($new_detail as $k => $v) {
            $menu_id = $v['menu_id'];
            $add_foods = $v['add_foods'];
            $update_foods = $v['update_foods'];
            $cancel_foods = $v['cancel_foods'];

            $old_detail = OrderDetailT::orderDetail($o_id, $menu_id);
            $check_data = $this->checkOrderDetailUpdate($update_foods, $old_detail);
            $check_data = $this->checkOrderDetailCancel($cancel_foods, $check_data);
            $check_data = $this->checkOrderDetailAdd($add_foods, $check_data);
            $menu = $this->getMenuInfo($menus, $menu_id);
            if (empty($menu)) {
                throw new ParameterException(['msg' => '菜品类别id错误']);
            }
            if (($menu['status'] == MenuEnum::FIXED) && ($menu['count'] < count($check_data))) {
                throw new SaveException(['msg' => '选菜失败,菜品类别：<' . $menu['category'] . '> 选菜数量超过最大值：' . $menu['count']]);
            }

            if ($fixed == CommonEnum::STATE_IS_FAIL) {
                foreach ($check_data as $k3 => $v3) {
                    $new_money += $v3['price'] * $v3['count'];
                }
            }
        }
        if ($fixed == CommonEnum::STATE_IS_FAIL) {
            $new_money = $new_money * $count;
        } else {
            $new_money = $old_money * $count;
        }
        $new_sub_money = $old_sub_money / $old_count * $count;
        if ($new_money > $old_money) {
            $pay_way = $this->checkBalance($u_id, $canteen_id, $new_money + $new_sub_money - $old_money - $old_sub_money);
        }
        return [
            'new_money' => $new_money,
            'new_sub_money' => $new_sub_money,
            'pay_way' => $pay_way
        ];
    }

    private function checkOrderDetailCancel($cancel_foods, $check_data)
    {

        if (strlen($cancel_foods)) {
            $cancel_arr = explode(',', $cancel_foods);
            foreach ($check_data as $k => $v) {
                if (in_array($v['id'], $cancel_arr)) {
                    unset($check_data[$k]);
                }
            }
        }
        return $check_data;
    }

    private function checkOrderDetailUpdate($update_foods, $check_date)
    {
        if (empty($update_foods)) {
            return $check_date;
        }
        foreach ($check_date as $k => $v) {
            foreach ($update_foods as $k2 => $v2) {
                if ($v['id'] == $v2['detail_id']) {
                    $check_date[$k]['count'] = $v2['count'];
                }
            }
        }
        return $check_date;
    }

    private function checkOrderDetailAdd($add_foods, $check_data)
    {
        if (empty($add_foods)) {
            return $check_data;
        }
        foreach ($add_foods as $k => $v) {
            $data = [
                'f_id' => $v['food_id'],
                'price' => $v['price'],
                'count' => $v['count'],
            ];
            array_push($check_data, $data);
        }
        return $check_data;
    }

    public function personalChoiceInfo($id)
    {
        $info = OrderT:: personalChoiceInfo($id);
        return $info;
    }

    public function userOrders($type, $id, $page, $size)
    {
        $u_id = Token::getCurrentUid();
        if ($type == OrderEnum::USER_ORDER_SHOP) {

            $orders = ShopOrderingV::userOrderings($u_id, $id, $page, $size);

        } else {
            $orders = OrderingV::userOrderings($u_id, $type, $id, $page, $size);
        }
        return $orders;
    }

    public function orderDetail($type, $id)
    {
        $u_id = Token::getCurrentUid();
        if ($type == OrderEnum::USER_ORDER_SHOP) {
            $order = ShopOrderT::orderInfo($id);
        } else {
            $order = OrderT::orderDetail($id);
        }
        if ($order->u_id != $u_id) {
            throw new AuthException();
        }

        return $order;
    }

    //用户查询消费记录
    public function consumptionRecords($consumption_time, $page, $size)
    {
        $u_id = Token::getCurrentUid();
        $canteen_id = Token::getCurrentTokenVar('current_canteen_id');
        $records = ConsumptionRecordsV::records($u_id, $consumption_time, $page, $size);
        $consumptionMoney = ConsumptionRecordsV::monthConsumptionMoney($u_id, $consumption_time);
        return [
            'balance' => $this->getUserBalance($u_id, $canteen_id),
            'consumptionMoney' => $consumptionMoney,
            'records' => $records
        ];
    }

    public function getUserBalance($u_id, $canteen_id)
    {

        $canteenAccount = CanteenAccountT::where('c_id', $canteen_id)
            ->find();
        if (!$canteenAccount) {
            throw new ParameterException(['msg' => '该用户归属饭堂设置异常']);
        }
        $hidden = $canteenAccount->type;
        $money = 0;
        if ($hidden == CommonEnum::STATE_IS_FAIL) {
            //不可透支消费，返回用户在该饭堂余额
            $money = 100;
        }
        return [
            'hidden' => $hidden,
            'money' => $money
        ];

    }

    public function recordsDetail($order_type, $order_id)
    {
        if ($order_type == "shop") {
            $order = ShopOrderT::orderInfo($order_id);
        } else if ($order_type == "canteen") {
            $order = OrderT::orderDetail($order_id);
        }
        return $order;
    }

    public function managerOrders($canteen_id, $consumption_time)
    {
        //获取饭堂餐次信息
        $dinner = (new CanteenService())->getDinnerNames($canteen_id);
        if (!$dinner) {
            throw new ParameterException(['msg' => '参数异常，该饭堂未设置餐次信息']);
        }
        //获取饭堂订餐信息
        $orderInfo = OrderT::statisticToOfficial($canteen_id, $consumption_time);
        $today = date('Y-m-d');
        foreach ($dinner as $k => $v) {
            $all = 0;
            $used = 0;
            $noOrdering = 0;
            $orderingNoMeal = 0;
            if (!empty($orderInfo)) {
                foreach ($orderInfo as $k2 => $v2) {
                    if ($v['id'] == $v2['d_id']) {
                        $all += $v2['count'];
                        if (strtotime($today) < strtotime($consumption_time)) {
                            continue;
                        }
                        if ($v2['used'] == CommonEnum::STATE_IS_OK) {
                            $used += $v2['count'];
                            if ($v2['booking'] == CommonEnum::STATE_IS_FAIL) {
                                $noOrdering += $v2['count'];
                            }
                        } else if ($v2['used'] == CommonEnum::STATE_IS_FAIL) {
                            $orderingNoMeal += $v2['count'];
                        }
                        unset($orderInfo[$k2]);
                    }

                }
            }
            $dinner[$k]['all'] = $all;
            $dinner[$k]['used'] = $used;
            $dinner[$k]['noOrdering'] = $noOrdering;
            $dinner[$k]['orderingNoMeal'] = $orderingNoMeal;
        }
        return $dinner;

    }

    //微信端总订餐查询-点击订餐数量，获取菜品统计信息
    public function managerDinnerStatistic($dinner_id, $consumption_time, $page, $size)
    {
        $statistic = DinnerStatisticV::managerDinnerStatistic($dinner_id, $consumption_time, $page, $size);
        if ($statistic->isEmpty()) {
            return [
                'haveFoods' => CommonEnum::STATE_IS_FAIL,
                // 'statistic' => $this->orderUsersStatistic($dinner_id, $consumption_time, 'all', 1, 20)
            ];
        }
        return [
            'haveFoods' => CommonEnum::STATE_IS_OK,
            'statistic' => $statistic
        ];
    }

    public function orderUsersStatistic($dinner_id, $consumption_time, $consumption_type, $page, $size)
    {
        $statistic = OrderUsersStatisticV::orderUsers($dinner_id, $consumption_time, $consumption_type, $page, $size);
        return $statistic;
    }

    public function foodUsersStatistic($dinner_id, $food_id, $consumption_time, $page, $size)
    {
        $statistic = FoodsStatisticV::foodUsersStatistic($dinner_id, $food_id, $consumption_time, $page, $size);
        return $statistic;
    }

    public function handelOrderedNoMeal($dinner_id, $consumption_time)
    {
        $dinner = DinnerT::where('id', $dinner_id)->find();
        if (!$dinner) {
            throw new ParameterException(['msg' => '餐次信息不存在']);
        }
        $canteen_id = $dinner->c_id;
        $checkCleanTime = false;
        $cleanTime = '';
        $dinnerEndTime = $consumption_time . ' ' . $dinner->meal_time_end;
        $account = CanteenAccountT::where('c_id', $canteen_id)->find();
        if ($account) {
            if ($account->type = CommonEnum::STATE_IS_OK) {
                $cleanTime = date('Y-m', strtotime($consumption_time)) . '-' . $account->clean_day . ' ' . $account->clean_time;
            }
        }
        if (strtotime($dinnerEndTime) > time()) {
            throw new UpdateException(['msg' => '餐次就餐时间未截止，不能一键扣费']);
        }
        if ($checkCleanTime && strtotime($cleanTime) < time()) {
            throw new UpdateException(['msg' => '超出系统扣费时间，不能一键扣费']);
        }
        //将订餐未就餐改为订餐就餐信息进行缓存
        $list = OrderUsersStatisticV::orderUsersNoUsed($dinner_id, $consumption_time);
        $dataList = [];
        foreach ($list as $k => $v) {
            $dataList[] = [
                'order_id' => $v->id,
                'state' => CommonEnum::STATE_IS_FAIL
            ];
        }
        if (count($dataList)) {
            $res = (new OrderHandelT())->saveAll($dataList);
            if (!$res) {
                throw new UpdateException();
            }
        }
    }

    public function orderStateHandel()
    {
        $orders = OrderHandelT::where('state', CommonEnum::STATE_IS_FAIL)
            ->limit(0, 5)->select();
        if (!$orders->isEmpty()) {
            foreach ($orders as $k => $v) {
                $this->prefixOrderState($v->order_id);

            }

        }
    }

    private function prefixOrderState($order_id)
    {
        $order = OrderT::where('id', $order_id)->find();

    }

}