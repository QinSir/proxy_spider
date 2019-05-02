<?php
require_once('libs/app.php');
require_once('libs/phpQuery.php');

/**
 * 采集器
 * url https://www.xxx.com/{x} {x}会被替换为 1,2,3~
 * order 1,2,4,5,3 ip,port,anony,type,position
 */
class HttpServer extends app{
    private $serv;
    private $requset;
    private $response;

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
        //解析代理信息顺序
        $proxy_order = [0 , 1 , 2 , 3 , 4];
        if(isset($args['order']) && is_string($args['order']) && preg_match('/^\d+(\,\d+){3,}/', $args['order'])){
            $proxy_order = explode(',', $args['order']);
        }
        //开始处理
        $this->end('getting data!');
        //开始处理
        $err = 0;$cur = 1;
        do{
            $url = preg_replace('/{x}/', $cur++, $args['url']);
            $res = $this->get_proxys($url , $proxy_order);
            //计算错误次数
            if($res === false){
                $err ++;
            }
            //达到5次错误则停止
            if($err >= 5){
                break;
            }
            //循环条件
            $continue = is_numeric($this->max_page) && $this->max_page > 0 ? $cur <= $this->max_page : true;
            //每隔1秒请求1次
            sleep(1);
        }while($continue);
        //取消当前任务
        echo "done\n";
        return true;
        
    }

    /**
     * 任务处理
     */
    public function onTask($serv, $task_id, $from_id, $data){
        //数据整理
        if(!is_array($data) || empty($data)){
            return false;
        }
        foreach ($data as $k => $v) {
            if(!is_string($v)){
                continue;
            }
            $data[$k] = preg_replace('/\s*/', '', $v);
        }
        unset($k , $v);
        //检查代理类型
        if(!preg_match('/^http[s]?$/i', $data['type'])){
            echo 'not support type:'.$data['type']."\n";
            return false;
        }
        //开始尝试请求百度
        $res = $this->http('http://www.baidu.com/' , [
            'User-Agent:Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.103 Safari/537.36',
        ] , [
            'ip'=>$data['ip'],
            'port'=>$data['port'],
            'type'=>$data['type'],
        ] , 30);
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

    public function get_proxys($url = '' , $proxy_order = []){
        echo $url."\n";
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
        //整理数据
        phpQuery::newDocument($res);
        $res = pq('tr');
        if($res->length() <= 0){
            return false;
        }
        unset($url);
        //获取行信息
        foreach ($res as $row) {
            $out = pq($row)->find('td');
            if($out->length() <= 4){
                unset($row , $out);
                continue;
            }
            //执行任务
            $this->serv->task([
                'ip'=>$out->eq($proxy_order[0])->text(),//代理IP
                'port'=>$out->eq($proxy_order[1])->text(),//代理端口
                'is_anony'=>strpos($out->eq($proxy_order[2])->text() , '匿') === false ? 0 : 1,
                'type'=>$out->eq($proxy_order[3])->text(),
                'position'=>@($out->eq($proxy_order[4])->text() ?: ''),
            ],-1);
        }
        unset($res , $row , $out);
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
            if(isset($proxy['type']) && $proxy['type'] == 'https'){
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTPS);
            }else{
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            }
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxy['port']);
            if(isset($proxy['pwd']) && is_string($proxy['pwd'])){
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['pwd']);    
            }
        }
        if($HttpHeader){
            curl_setopt($ch, CURLOPT_HTTPHEADER, $HttpHeader);
        }
        $ret = curl_exec($ch);
        /*$httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE);
        if($httpCode != '200'){
            return false;
        }*/
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