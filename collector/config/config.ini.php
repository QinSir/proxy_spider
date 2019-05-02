<?php
$config = [
	'database' => [
		'host' => '127.0.0.1',
		'user' => 'root',
		'pass' => 'root',
		'dbname' => 'proxy',
		'pre'	=> 'qx_',
	],
	'redis' => [
		'ip'=>'127.0.0.1',
		'port'=>'6379',
	],
	'gateway' => 'http://uming.com',
	'loop-times' => '20',
];
return $config;