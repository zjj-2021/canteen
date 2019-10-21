<?php
/**
 * Created by PhpStorm.
 * User: 明良
 * Date: 2019/9/17
 * Time: 9:12
 */

namespace app\api\service;


use app\api\model\CompanyStaffT;
use app\api\model\NoticeT;
use app\api\model\NoticeUserT;
use app\api\model\NoticeUserV;
use app\lib\enum\CommonEnum;
use app\lib\enum\NoticeEnum;
use app\lib\exception\DeleteException;
use app\lib\exception\SaveException;
use think\Db;
use think\Exception;
use zml\tp_tools\Redis;

class NoticeService
{
    public function saveNotice($params)
    {
        $params['type'] = NoticeEnum::NOTICE;
        $params['u_id'] = Token::getCurrentUid();
        $notice = NoticeT::create($params);
        if (!$notice) {
            throw new SaveException();
        }
        $this->prefixSendNotice($notice->id, $params['d_ids'], $params['s_ids']);
    }

    /**
     * 将推送信息进行存入redis进行缓存
     */
    private function prefixSendNotice($n_id, $d_ids, $s_ids)
    {
        //将 部门信息进行缓存：notice_d_send_no;notice_d_send_ing
        //将人员信息进行缓存: notice_s_send_no;notice_s_send_ing
        if (strlen($d_ids)) {
            $d_ids_arr = explode(',', $d_ids);
            foreach ($d_ids_arr as $k => $v) {
                $data = [
                    'n_id' => $n_id,
                    'd_id' => $v
                ];
                Redis::instance()->lPush('notice_d_send_no', json_encode($data));
            }

        }
        if (strlen($s_ids)) {
            $s_ids_arr = explode(',', $s_ids);
            foreach ($s_ids_arr as $k => $v) {
                $data = [
                    'n_id' => $n_id,
                    's_id' => $v
                ];
                Redis::instance()->lPush('notice_s_send_no', json_encode($data));
            }

        }
    }

    private function sendNotice($n_id, $d_ids, $s_ids)
    {
        $staffs = (new DepartmentService())->departmentStaffs($d_ids);
        $data_list = [];
        if (empty($staffs)) {
            return true;
        }
        foreach ($staffs as $k => $v) {
            $data = [
                's_id' => $v['id'],
                'n_id' => $n_id,
                'read' => CommonEnum::STATE_IS_FAIL,
                'state' => CommonEnum::STATE_IS_OK
            ];
            array_push($data_list, $data);
        }
        if (strlen($s_ids)) {
            $ids = explode(',', $s_ids);
            foreach ($ids as $k => $v) {
                $data = [
                    'phone' => $v,
                    'n_id' => $n_id,
                    'read' => CommonEnum::STATE_IS_FAIL,
                    'state' => CommonEnum::STATE_IS_OK
                ];
                array_push($data_list, $data);
            }
        }

        $res = (new NoticeUserT())->saveAll($data_list);
        if (!$res) {
            throw new SaveException(['msg' => '推送指定用户失败']);
        }
    }


    /**
     * 获取管理员发布公告
     */
    public function adminNotices($page, $size)
    {
        $u_id = Token::getCurrentUid();
        $notices = NoticeT::adminNotices($u_id, $page, $size);
        return $notices;

    }

    /**
     * 删除公告
     */
    public function deleteNotice($id)
    {
        $res = NoticeT::update(['state' => CommonEnum::STATE_IS_FAIL], ['id' => $id]);
        if (!$res) {
            throw new DeleteException();
        }
    }

    public function notice($id)
    {
        $notice = NoticeT::where('id', $id)
            ->hidden(['state', 'update_time', 'u_id'])
            ->find();
        return $notice;
    }

    public function userNotices($page, $size)
    {
        $phone = Token::getCurrentPhone();
        $staff = (new DepartmentService())->getStaffWithPhone($phone);
        if (empty($staff)) {
            return [
                'total' => 0,
                'per_page' => 10,
                'current_page' => 1,
                'last_page' => 1,
                'data' => array()
            ];
        }
        return NoticeUserV::userNotices($staff->id, $page, $size);


    }
}