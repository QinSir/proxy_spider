<?php
require_once('libs/app.php');

class HttpServer extends app{
    private $serv;
    private $requset;
    private $response;

    private $cur = 1;
    private $max_page = 10000;

    public function __construct(){
        parent::__construct();

        $this->serv = new swoole_http_server('_', 9501);
        $this->serv->set(array(
            'worker_num' => 8, //一般设置为服务器CPU数的1-4倍
            // 'daemonize' => 1, //以守护进程执行
            'max_conn'  => 1280,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'task_worker_num' => 30, //task进程的数量
            'task_ipc_mode ' => 3, //使用消息队列通信，并设置为争抢模式
        ));
        
        $this->serv->on('Request', array($this, 'onRequest'));
        $this->serv->on('Task', array($this, 'onTask'));
        $this->serv->on('Finish', array($this, 'onFinish'));
        $this->serv->start();
    }

    /**
     * 处理请求
     */
    public function onRequest($request, $response) {
        //存储-request、response
        $this->request = $request;
        $this->response = $response;
        //解析url
        @parse_str($this->request->server['query_string'] , $args);
        if(!isset($args['url']) || empty($args['url']) || !preg_match('/^http[s]?:\/\//', $args['url'])){
            $this->end('invalid url');
            return false;
        }
        //检查是否任务重复
        $cur_task = $this->lib('credis')->get('cur_task');
        if($cur_task == $args['url']){
            $this->end('do not repeat');
            return false;
        }
        //记录当前任务
        $this->lib('credis')->set('cur_task' , $args['url']);
        //开始处理
        $this->end('getting data!');
        //开始处理
        $err = 0;
        do{
            $res = $this->get_proxys($args['url']);
            //计算错误次数
            if($res === false){
                $err ++;
            }
            //达到5次错误则停止
            if($err >= 5){
                break;
            }
            //追加计数
            $this->cur ++;
            //循环条件
            $continue = is_numeric($this->max_page) && $this->max_page > 0 ? $this->cur <= $this->max_page : true;
            //每隔1秒请求1次
            sleep(1);
        }while($continue);
        //记录当前任务
        $this->lib('credis')->delete('cur_task');
        echo "done\n";
        return true;
        
    }

    /**
     * 任务处理
     */
    public function onTask($serv, $task_id, $from_id, $data){
        $res = $this->http('http://www.uxiangc.com/' , [
            'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.103 Safari/537.36',
        ] , [
            'ip'=>$data['ip'],
            'port'=>$data['port'],
        ] , 10);
        //记录日志
        $this->lib('log')->write($data['ip'].':'.$data['port']);
        //记录到数据库
        $this->lib('db')->insert('ips',[
            'proxy_ip'=>$data['ip'],
            'proxy_port'=>$data['port'],
            'is_anony'=>$data['is_anony'],
            'proxy_type'=>$data['type'],
            'position'=>$data['position'],
            'is_enable'=>$res === false ? '0' : '1',
        ]);
        unset($res , $proxy);
        return true;
    }

    public function onFinish($serv, $task_id, $data){
        
    }

    /**
     * 异步通知
     * @param  string $key          标识
     * @param  string $payment_code 支付方式编码
     * @param  string $type         数据类型
     */
    private function notify($key = '',$payment_code = '',$type = 'put'){
        
    }

    private function get_proxys($url = ''){
        $url = preg_replace('/{x}/', $this->cur, $url);
        //开始获取
        $random_ip = mt_rand(1,254).'.'.mt_rand(1,254).'.'.mt_rand(1,254).'.'.mt_rand(1,254);
        $res = $this->http( $url, [
            'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.103 Safari/537.36',
            'x-forwarded-for:'.$random_ip,
            'x-real-ip:'.$random_ip,
        ]);
        if(!is_string($res) || empty($res)){
            echo 'error.' . $url."\n";
            return false;
        }
        //整理正则
        $reg = '/<tr>(\s*<td[^<]*>(.*)<\/td>\s*){7}<\/tr>/';
        $res = preg_match_all($reg, $res, $rows);
        if(!$res){
            unset($res);
            return true;
        }
        unset($url , $res);
        //获取行信息
        foreach ($rows[0] as $row) {
            $r = preg_match_all('/<td[^<]*>(.*)<\/td>/', $row , $out);
            if(!$r){
                unset($r , $row , $out);
                continue;
            }
            //执行任务
            $this->serv->task([
                'ip'=>$out[1][0],//代理IP
                'port'=>$out[1][1],//代理端口
                'is_anony'=>strpos($out[1][2] , '匿') === false ? 0 : 1,
                'type'=>$out[1][3],
                'position'=>$out[1][4],
            ],-1 ,function ($serv,$task_id,$res) use ($out){
                //记录日志
                $this->lib('log')->write($out[1][0].':'.$out[1][1]);
            });
        }
        unset($res , $r , $row , $out);
        return true;
    }

    /**
     * http请求
     * @param  string  $url        请求地址
     * @param  array   $HttpHeader 请求头部
     * @param  integer $timeout    超时时间
     */
    private function http($url = '', $HttpHeader=[],$proxy = [],$timeout = 30){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HEADER, 0); #返回头部信息
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //超时设置
        if(!is_numeric($timeout) || $timeout < 0 || $timeout > 120){
            $timeout = 30;
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        //取消SSL验证
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        curl_setopt($ch, CURLOPT_HTTPGET, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        //代理
        if(is_array($proxy) && isset($proxy['ip']) && isset($proxy['port']) && is_string($proxy['ip']) && preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $proxy['ip']) && is_numeric($proxy['port']) && $proxy['port'] > 0){
            curl_setopt($ch, CURLOPT_PROXY, $proxy['ip']);
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxy['port']);
            if(isset($proxy['pwd']) && is_string($proxy['pwd'])){
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['pwd']);    
            }
        }
        if($HttpHeader){
            curl_setopt($ch, CURLOPT_HTTPHEADER, $HttpHeader);
        }
        $ret = curl_exec($ch);
        $httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if($httpCode != '200'){
            return false;
        }
        curl_close($ch);
        return $ret;
    }

    private function end($str = ''){
        $this->response->write($str);
        $this->response->end();
    }
}

//Http服务器-webhook
new HttpServer();