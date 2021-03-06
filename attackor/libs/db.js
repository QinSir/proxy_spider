var config = require('./config');
var mysql = require('mysql');

var db = {
	conn:{},
	init:function(){
		//已经连接过
		if(typeof this.conn === 'object' && typeof this.conn.connect === 'function'){
			return true;
		}
		//开始连接
		this.conn = mysql.createConnection(config.db);
	},
	ping:function(){
		var that = this;
		this.conn.ping(function (err) {
			if (err){
				that.conn = {};
				that.init();
			}
		});
	},
	query:function(sql , params , cbk){
		if(typeof params === 'function'){
			cbk = params;
			params = null;
		}
		//初始化
		this.init();
		//ping测试
		this.ping();
		//开始查询
		this.conn.query(sql, params , function (e, r) {
			console.log(sql,params);
			//异常
			if(e){
				console.log(e.sqlMessage + ' sql:' + sql);
				return false;
			}
			//输出结果
			cbk && typeof cbk === 'function' && cbk.call({} , r);
		});
	},
}

module.exports = db;