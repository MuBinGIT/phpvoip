<?php
class SipEngine
{
	private $link;
	public $local_ip;
	public $local_port;
	
	private $modules = array();
	private $router;
	private $mixer;
	
	private $inited = false;
	
	private function __construct(){
		$this->time = microtime(1);
	}
	
	static function create($local_ip='127.0.0.1', $local_port=0){
		$ret = new SipEngine();
		$ret->link = SipLink::listen($local_ip, $local_port);
		$ret->local_ip = $ret->link->local_ip;
		$ret->local_port = $ret->link->local_port;
		return $ret;
	}
	
	function init(){
		$this->router = new SipRouter();
		// TODO:
		$this->router->domain = $this->local_ip;
		
		$this->mixer = new SipMixer();
		$this->add_module($this->mixer, INT_MAX); // Mixer模块放在所有模块的前面

		if($this->inited){
			return;
		}
		$this->inited = true;
		foreach($this->modules as $index=>$mi){
			$mi['module']->init();
			if(!$mi['module']->domain){
				$mi['module']->domain = $this->local_ip;
			}
		}
	}
	
	function add_module($mod, $weight=0){
		$offset = count($this->modules);
		foreach($this->modules as $index=>$mi){
			if($weight > $mi['weight']){
				$offset = $index;
				break;
			}
		}
		$mod->engine = $this;
		$mi = array(
			'weight' => $weight,
			'module' => $mod,
		);
		array_splice($this->modules, $offset, 0, array($mi));
	}

	function loop(){
		if(!$this->inited){
			throw new Exception('not init');
		}

		$read = array($this->link->sock);
		$write = array();
		$except = array();
	
		$ret = @socket_select($read, $write, $except, 0, 20*1000);
		// TESTING: 如下代码实现引擎慢速响应
		$pause = 0.3;
		static $stime = 0;
		$ts = microtime(1) - $stime;
		$sleep = min($pause, max(0, $pause - $ts));
		usleep($sleep * 1000 * 1000);
		#Logger::debug(sprintf("sleep %.3f %.3f %.3f", $sleep, $ts, $stime));
		$stime = microtime(1);
		
		if($ret === false){
			Logger::error(socket_strerror(socket_last_error()));
			return false;
		}
		
		if($read){
			$this->proc_recv();
		}
		
		$this->proc_send();
	}
	
	private function proc_recv(){
		while(1){
			$msg = $this->link->recv();
			if(!$msg){
				break;
			}
			// TODO: 在一次轮次内，相同的两条消息应该丢掉第2条
			$ret = $this->incoming($msg);
			if($ret !== true){
				$this->error_reply($msg);
			}
		}
	}
	
	private function incoming($msg){
		foreach($this->modules as $mi){
			$module = $mi['module'];
			$ret = $module->incoming($msg);
			if($ret === true){
				return true;
			}
		}

		if($msg->method == 'INVITE'){
			if($msg->to->username === $msg->from->username){
				Logger::info("invalid INVITE, from == to, {$msg->from->username}");
				return false;
			}

			$callee = $this->callin($msg);
			if(!$callee){
				return false;
			}
			
			$msg = $this->router->route($msg);
			
			$caller = $this->callout($msg);
			if(!$caller){
				return false;
			}

			$this->mixer->add_dialog($callee, $caller);
			return true;
		}
		return false;
	}
	
	private function callin($msg){
		foreach($this->modules as $mi){
			$module = $mi['module'];
			$sess = $module->callin($msg);
			if($sess){
				Logger::debug("callin " . $sess->brief());
				return $sess;
			}
		}
		
		Logger::debug("403 Forbidden");
		// TODO: send error response
		return null;
	}
	
	private function callout($msg){
		foreach($this->modules as $mi){
			$module = $mi['module'];
			$sess = $module->callout($msg);
			if($sess){
				Logger::debug("callout " . $sess->brief());
				return $sess;
			}
		}

		Logger::debug("404 Not Found");
		// TODO: send error response
		return;
		return null;
	}
	
	private $time = 0;

	function proc_send(){
		$old_time = $this->time;
		$this->time = microtime(1);
		$timespan = max(0, $this->time - $old_time);

		foreach($this->modules as $mi){
			$module = $mi['module'];
			$msgs = $module->outgoing($time, $timespan);
			foreach($msgs as $msg){
				// TODO: 对于模块消息，不通过 socket 发送
				$this->link->send($msg);
			}
		}
	}
	
	private function error_reply($msg){
		Logger::debug("drop msg");
		// TODO: 在此处根据不同的消息类似创建不同的 ErrorReplySession
	}
}
