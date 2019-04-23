<?php

use Swoole\Coroutine\Redis;

class credis{
	private $config = [];
	private $conn;

	function __construct(){
		//载入配置
		global $qxapp;
		$this->config = $qxapp->config['redis'];
		//尝试连接
		$this->conn = new Redis();
		$this->conn->connect($this->config['ip'], $this->config['port']);
	}

	/**
	 * 魔术方法
	 * @param  string $method 调用方法
	 * @param  array  $args   参数项
	 */
	public function __call($method = '' , $args = []){
		//检查方法
		if(!is_string($method) || !preg_match('/^\w+$/', $method)){
			return false;
		}
		//检查是否存在方法
		if(!method_exists($this->conn, $method)){
			return false;
		}
		//参数解析
		$tmp = [];
		foreach ($args as $k => $v) {
			$tmp[] = '$args['.$k.']';
		}
		//开始解析并调用
		eval('$res = $this->conn->'.$method.'('.join(',' , $tmp).');');
		return $res;
	}
}