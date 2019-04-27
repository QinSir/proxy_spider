var request = require('request');

request.post({
	url:'http://e.fhshangcheng.com/welcome/debug',
	proxy:'http://61.189.242.243:55484',
},function(e , r , data){
	console.log(data);
});