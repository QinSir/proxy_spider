<?php

defined('BASE_DIR') or define('BASE_DIR' , realpath(dirname(__FILE__).'/..').'/');
//全局app
$qxapp = null;

/**
 * 应用基类
 */
class app{
	public $config = [];

	private $_libs = [];

	public function __construct(){
		$this->config = require_once(BASE_DIR . 'config/config.ini.php');
		//载入当前对象到全局
		$GLOBALS['qxapp'] = &$this;
	}

	public function lib($lib_name = '' , $config = []){
		if(!is_string($lib_name) || !preg_match('/^\w+$/', $lib_name) || $lib_name == 'app'){
			return false;
		}
		if(isset($this->_libs[$lib_name])){
			return $this->_libs[$lib_name];
		}
		if(!file_exists(BASE_DIR . 'libs/'.$lib_name.'.php')){
			return false;
		}
		@require_once(BASE_DIR . 'libs/'.$lib_name.'.php');
		if(!class_exists($lib_name)){
			return false;
		}
		$this->_libs[$lib_name] = (new $lib_name($config));
		return $this->_libs[$lib_name];
	}
}