<?php

class log {

	protected $config = array(
		'log_time_format' => ' c ',
		'log_path'        => '',
	);

	// 实例化并传入参数
	public function __construct($config = array()) {
		if(is_array($config) && !empty($config)){
			$this->config = array_merge($this->config, $config);
		}

		empty($this->config['log_path']) && $this->config['log_path'] = BASE_DIR . 'log/';
	}

	/**
	 * 日志写入接口
	 * @access public
	 * @param string $log 日志信息
	 * @param string $destination  写入目标
	 * @return void
	 */
	public function write($log, $destination = '') {
		$now = date($this->config['log_time_format']);
		if (empty($destination)) {
			$destination = $this->config['log_path'] . date('y_m_d') . '.log';
		}
		// 自动创建日志目录
		$log_dir = dirname($destination);
		if (!is_dir($log_dir)) {
			mkdir($log_dir, 0755, true);
		}

		//记录日志
		error_log('[' . $now . '] ' . PHP_EOL . $log . PHP_EOL . PHP_EOL, 3, $destination);
	}
}
