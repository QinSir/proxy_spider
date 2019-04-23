<?php
/**
 * 数据库
 */
class db{
    public $_sqls=array();
    public $link;
    public $result;
    private $slave_status=false;
    private $slave_link;

    private $host;
    private $user;
    private $pass;
    private $dbname;
    private $charset = 'UTF8';
    private $pre = '';

    function __construct($db = array()){
        //默认配置项
        if((!is_array($db) || empty($db)) && isset($GLOBALS['qxapp']->config['database'])){
            $db = $GLOBALS['qxapp']->config['database'];
        }
        //配置项赋值
        if(is_array($db) && !empty($db)){
            foreach($db as $k=>$v){
                $this->{$k}=$v;
            }
        }
        //检测mysqli扩展
        if(!extension_loaded('mysqli')){
            die('mysqli extension is not loaded!');
        }
        $this->connect();
    }

    /**
     * 链接数据库
     */
    private function connect(){
        $this->link = mysqli_connect($this->host,$this->user,$this->pass,$this->dbname);
        if($this->link === false){
            var_dump($this->host);die;
            die('Database connect error');
        }
        //设置编码
        @mysqli_query($this->link, 'SET NAMES '.$this->charset);
        if(isset($this->slave_status) && $this->slave_status===true){
            $this->set_slave_link();
        }
    }

    /**
     * 设置从库连接
     */
    private function set_slave_link(){
        if(!isset($this->slave) || !is_array($this->slave) || empty($this->slave)){
            $this->slave_status=false;
            return false;
        }

        //随机抽取从库连接
        $slave_ids='';
        foreach ($this->slave as $k => $v) {
            if(!is_array($v) || empty($v)){
                continue;
            }
            if(!isset($v['weight']) || !is_numeric($v['weight']) || $v['weight']<=0){
                continue;
            }            
            $slave_ids.=str_repeat($k, intval($v['weight']));
        }
        if(empty($slave_ids)){
            $this->slave_status=false;
            return false;
        }
        $slave_id=substr($slave_ids, mt_rand(0,strlen($slave_ids)-1),1);
        $slave_config=$this->slave[$slave_id];

        //设置从库连接                
        $slave_link=mysqli_connect($slave_config['host'],$slave_config['user'],$slave_config['pass'],$slave_config['dbname']);
        if($slave_link===false){
            $this->slave_status=false;
            return false;
        }
        $this->slave_status=true;
        $this->slave_link=$slave_link;
        @mysqli_query($this->slave_link, 'SET NAMES '.$this->charset);
    }

    /**
     * 执行sql
     * @param $sql
     * @return bool|mysqli_result
     */
    function query($sql){
        $sql = preg_replace('/###_/', $this->pre, $sql);

        $sql = preg_replace('/^(\s*)/', '', $sql);
        if ($sql=='') return '';

        $conn_link=$this->link;
        if($this->slave_status===true){
            $sql_test=strtolower(trim($sql));
            if(preg_match('/^select/', $sql_test) || preg_match('/^show/', $sql_test)){
                $conn_link=$this->slave_link;
            }
        }

        if(@!mysqli_ping($conn_link)){
            // @mysqli_close($conn_link);
            $this->connect();
            $conn_link=$this->link;
        }

        $this->_sqls[] = $sql;

        // 记录开始时间
        $time = -microtime(true);

        $this->result = mysqli_query($conn_link, $sql);

        if(is_bool($this->result) && $this->result===false){
            echo 'DB-EXEC-ERR:'.mysqli_error($conn_link).',SQL：'.$sql."\n";
            return false;
        }

        if(preg_match('/^(select|show)/i', $sql)){
            return $this->result_array($this->result);
        }

        return $this->affected_rows();
    }

    public function get($sql){
        //自动追加limit
        if(!preg_match('/\s+limit\s+/', $sql)){
            $sql .= ' limit 0,1';
        }
        $res = $this->query($sql);
        if($res === false){
            return false;
        }
        if(!is_array($res) || empty($res)){
            return [];
        }
        return $res[0];
    }

    /**
     * 插入数据库数据
     * @param $table
     * @param $data
     * @return bool|DB_Result|mysqli_result
     */
    public function insert($table,$data){
        $keys = $vals = [];
        foreach($data as $key=>$val){
            $keys[] = '`' . mysqli_real_escape_string($this->link,$key) . '`';
            $vals[] = '\'' . mysqli_real_escape_string($this->link,$val) . '\'';
        }
        $names = join(',' , $keys);
        $values = join(',' , $vals);

        $res=$this->query("REPLACE INTO `{$table}`(".$names.") VALUES(".$values.")");
        if($res === false){
            return false;
        }

        return $this->insert_id();
    }

    /**
     * 更新数据库数据
     * @param string $table
     * @param string|array $data
     * @param array $where
     * @return bool|mysqli_result
     */
    function update($table,$data,$where){
        $table = $this->pre_table($table);

        if(is_array($data)){
            $up = array();
            foreach($data as $k=>$v){
                $up[] = "`".mysqli_real_escape_string($this->link,$k)."`='".mysqli_real_escape_string($this->link,$v)."'";
            }
            $setParams = implode(',',$up);
        }else{
            $setParams = $data;
        }

        $condition = '';
        if(is_array($where)){
            $conds=array();
            foreach($where as $k=>$v)$conds[] = "`".mysqli_real_escape_string($this->link,$k)."`='".mysqli_real_escape_string($this->link,$v)."'";
            $condition = implode(' AND ',$conds);
        }else{
            $condition = $where;
        }
        return $this->query("UPDATE `$table` SET ".$setParams." WHERE ".$condition);
    }

    /**
     * 返回最后一次插入数据的自增id
     * @return int|string
     */
    public function insert_id(){
        return mysqli_insert_id($this->link);
    }

    /**
     * 影响行数
     * @return int
     */
    public function affected_rows(){
        return mysqli_affected_rows($this->link);
    }

    /**
     * table加前缀
     */
    private function pre_table($table){
        if(!is_string($table) || empty($table)){
            return '';
        }
        $pre = '###_';

        $table = (strpos($table,$pre) !== false)
            ? str_replace($pre,$this->pre,$table)
            : $this->pre.$table;

        return $table;
    }

    /**
     * 解析为数组
     */
    private function result_array($res = null){
        $ret = array();
        if(!is_object($res)){
            return $ret;
        }
        while($a=mysqli_fetch_assoc($res)){
            $ret[]=$a;
        }
        return $ret;
    }
}