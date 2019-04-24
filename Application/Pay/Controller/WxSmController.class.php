<?php
namespace Pay\Controller;

class WxSmController extends PayController
{
    private $code = '';

    public function __construct()
    {
        parent::__construct();

        $matches = [];
        preg_match('/([\da-zA-Z\_]+)Controller$/', __CLASS__, $matches);
        $this->code = $matches[1];
    }

    public function Pay($array)
    {
        $orderid = I("request.pay_orderid");
        $body = I('request.pay_productname');
//        $notifyUrl = $this->_site . 'Pay_WxSm_notifyurl.html'; //异步通知
//        $callbackurl = $this->_site . 'Pay_WxSm_callbackurl.html'; //跳转通知
        $parameter = array(
            'code' => 'WxSm', // 通道名称
            'title' => '微信扫码支付-官方', //通道名称
            'exchange' => 100, // 金额比例
            'gateway' => '',
            'orderid'=>'',
            'out_trade_id' => $orderid, //外部订单号
            'channel'=>$array,
            'body'=>$body
        );
        $return = $this->orderadd($parameter);

        $site        = trim($return['unlockdomain']) ? $return['unlockdomain'] . '/' : $this->_site;
        $gateway     = "{$site}Pay_{$this->code}_Rpay.html";
        $notifyUrl   = "{$site}Pay_{$this->code}_notifyurl.html";
        $callbackUrl = "{$site}Pay_{$this->code}_callbackurl.html";

        $return['notifyurl']   = $notifyUrl;
        $return['callbackurl'] = $callbackUrl;

        if ($return) {
            $Ip = new \Org\Net\IpLocation('UTFWry.dat'); // 实例化类 参数表示IP地址库文件
            $location = $Ip->getlocation(); // 获取某个IP地址所在的位置
            $ip = $location['ip'];
            $arraystr = array(
                "trade_type" => "NATIVE",
                'appid' => $return["appid"],
                "mch_id" => $return["mch_id"],
                "out_trade_no" => $return["orderid"],
                "body" => "商城-".$body,
                "total_fee" => $return["amount"],
                "spbill_create_ip" => $ip,
                "notify_url" => $notifyUrl,
                "nonce_str" => random_str(),
            );
			if($return['appsecret']) {
				$arraystr['sub_mch_id'] = $return['appsecret'];
			}
            ksort($arraystr);
            $buff = "";
            foreach ($arraystr as $k => $v) {
                if ($k != "sign" && $v != "" && !is_array($v)) {
                    $buff .= $k . "=" . $v . "&";
                }
            }
            $buff = trim($buff, "&");
            $string = $buff . "&key=" . $return["signkey"];
            $string = md5($string);
            $sign = strtoupper($string);
            $arraystr["sign"] = $sign;
            $xml = "<xml>";
            foreach ($arraystr as $key => $val) {
                if (is_numeric($val)) {
                    $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
                } else {
                    $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
                }
            }
            $xml .= "</xml>";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_URL, 'https://api.mch.weixin.qq.com/pay/unifiedorder');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
            $data = curl_exec($ch);
            curl_close($ch);
            libxml_disable_entity_loader(true);
            $dataxml = json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
            if ($dataxml) {
                //print_r($dataxml);
                if ($dataxml['return_code'] == 'SUCCESS') {
//                    import("Vendor.phpqrcode.phpqrcode",'',".php");
//                    $url = urldecode($dataxml['code_url']);
//                    $QR = "Uploads/codepay/". $return["orderid"] . ".png";//已经生成的原始二维码图
//                    //$delqr = $QR;
//                    \QRcode::png($url, $QR, "L", 20);
//					//返回数据
//                    /*$replydata = ['returnCode'=>"00", 'memberid'=>$return['memberid'], 'orderid'=>$return['orderid'], 'amount'=>$return['amount']/100, 'code_url'=>$dataxml['code_url'], 'code_img_url'=>$this->_site.$QR];
//                    ksort($replydata);
//                    $signature = "";
//                    foreach ($replydata as $key => $val) {
//                        $signature = $signature . $key . "=" . $val . "&";
//                    }
//                    $replydata['sign'] = strtoupper(md5($signature . "key=" . $this->userinfo['md5key']));
//                    echo json_encode($replydata,JSON_UNESCAPED_UNICODE);*/
//					//exit();
//
//					$this->assign("imgurl", $this->_site.$QR);
//					$this->assign('params',$return);
//					$this->assign('orderid',$return['orderid']);
//					$this->assign('money',$return['amount']/100);
//                    $this->display("WeiXin/weixin");

                    $url = urldecode($dataxml['code_url']);
					$return['amount'] = $return['amount']/100;
                    echo createForm(
                        $gateway,
                        [
                            'data' => encryptDecrypt(serialize([
                                'url'    => $url,
                                'return' => $this->filterData($return),
                                'view'   => 'weixin',
                            ]), 'lgbya!'),
                        ]
                    );

                } else {
                    $this->showmessage($data);
                }
            }
        }
    }

    /**
     *  过滤敏感信息
     */
    private function filterData($return)
    {
        unset($return['mch_id']);//商户id
        unset($return['signkey']);
        unset($return['appid']);
        unset($return['appsecret']);

        return $return;
    }

    /**
     * 发起支付操作
     */
    public function Rpay()
    {
        //接收传输的数据
        $postData = I('post.', '');
        //将数据解密并反序列化
        $formData = unserialize(encryptDecrypt($postData['data'], 'lgbya!', 1));

        $this->showQRcode($formData['url'], $formData['return'], $formData['view']);
    }

    // 页面通知返回
    public function callbackurl()
    {
        $orderid = I('request.orderid', '', 'string,strip_tags,htmlspecialchars');
        $Order = M("Order");
        $pay_status = $Order->where(["pay_orderid"=>$orderid])->getField("pay_status");
        if($pay_status <> 0){
            $this->EditMoney($orderid, 'WxSm', 1);
        }else{
            exit("error");
        }
    }

    // 服务器点对点返回
    public function notifyurl()
    {
        $xml = isset($GLOBALS['HTTP_RAW_POST_DATA'])? $GLOBALS['HTTP_RAW_POST_DATA'] : '';
        if(empty($xml)){
            $xml = file_get_contents('php://input');
        }
        libxml_disable_entity_loader(true);
        $arraystr = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        logResult($xml);
        ksort($arraystr);
        $buff = "";
        foreach ($arraystr as $k => $v) {
            if ($k != "sign" && $v != "" && !is_array($v)) {
                $buff .= $k . "=" . $v . "&";
            }
        }
        $buff = trim($buff, "&");
		$pkey = getKey($arraystr["out_trade_no"]);
        $string = $buff . "&key=" . $pkey;
        $string = md5($string);
        $sign = strtoupper($string);
        if ($sign == $arraystr["sign"]) {
            $this->EditMoney($arraystr["out_trade_no"], 'WxSm', 0);
            exit("success");
        } else {
            echo 'fail!';
        }
    }
}
?>