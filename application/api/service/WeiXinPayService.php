<?php


namespace app\api\service;

use EasyWeChat\Factory;

class WeiXinPayService
{


    public function getApp()
    {
        $config = [
            // 必要配置
            'app_id' => 'wx60311f2f47c86a3e',
            'mch_id' => '1555725021',
            'key' => '1234567890qwertyuiopasdfghjklzxc',   // API 密钥

            // 如需使用敏感接口（如退款、发送红包等）需要配置 API 证书路径(登录商户平台下载 API 证书)
            'cert_path' => 'path/to/your/cert.pem', // XXX: 绝对路径！！！！
            'key_path' => 'path/to/your/key',      // XXX: 绝对路径！！！！
            'sub_mch_id' => '',
            'notify_url' => 'http://canteen.tonglingok.com/api/v1/wallet/WXNotifyUrl',     // 你也可以在下单时单独设置来想覆盖它
        ];

        $app = Factory::payment($config);
        return $app;
    }

    public function getPayInfo($data)
    {
        $app = app('wechat.payment');
        $app->setSubMerchant('sub_mch_id', '1563520781');
        $result = $app->order->unify([
            'body' => $data['body'],
            'out_trade_no' => $data['out_trade_no'],
            'total_fee' => $data['total_fee'],
            'trade_type' => 'JSAPI',
            'openid' => $data['openid']
        ]);
        return $result;
    }

}