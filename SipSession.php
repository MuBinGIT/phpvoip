<?php
abstract class SipSession
{
	public $role;
	private $state = 0;

	public $local_ip;
	public $local_port;
	public $remote_ip;
	public $remote_port;

	public $call_id;
	public $local;
	public $remote;
	public $contact;
	public $local_cseq;
	public $remote_cseq;
	public $local_branch;
	// public $remote_branch;

	public $remote_allow = array();

	private $callback;
	
	protected $trans;
	protected $transactions = array();
	
	function __construct(){
		$this->set_state(SIP::NONE);
		$this->local_cseq = SIP::new_cseq();
		$this->local_branch = SIP::new_branch();
		$this->trans = new SipTransaction();
		$this->transactions = array($this->trans);
	}
	
	abstract function init();
	abstract function close();
	
	function set_callback($callback){
		$this->callback = $callback;
	}
	
	function state(){
		return $this->state;
	}
	
	function is_state($state){
		return $this->state === $state;
	}
	
	function set_state($new){
		$old = $this->state;
		$this->state = $new;
		if($old !== $new && $this->callback){
			Logger::debug($this->brief() . " state = " . $this->state_text());
			call_user_func($this->callback, $this);
		}
	}
	
	function role_name(){
		if($this->role == SIP::REGISTER){
			return 'REGISTER';
		}else if($this->role == SIP::REGISTRAR){
			return 'REGISTRAR';
		}else if($this->role == SIP::CALLER){
			return 'CALLER';
		}else if($this->role == SIP::CALLEE){
			return 'CALLEE';
		}else{
			return 'NOOP';
		}
	}
	
	function state_text(){
		return SIP::state_text($this->state);
	}
	
	function brief(){
		return $this->role_name() .' '. $this->local->address() .'=>'. $this->remote->address();
	}

	
	function complete(){
		$this->set_state(SIP::COMPLETED);
	}

	function terminate(){
		$this->set_state(SIP::CLOSED);
	}
	
	function add_transaction($trans){
		$this->transactions[] = $trans;
	}
	
	function del_transaction($trans){
		foreach($this->transactions as $index=>$tmp){
			if($tmp === $trans){
				unset($this->transactions[$index]);
				break;
			}
		}
	}
	
	function match_sess($msg){
		if($msg->src_ip !== $this->remote_ip || $msg->src_port !== $this->remote_port){
			return false;
		}
		if($msg->call_id != $this->call_id){
			#Logger::debug($this->role_name() . " {$msg->call_id} != {$this->call_id}");
			return false;
		}
		
		if($msg->is_request()){
			if($msg->from->username != $this->remote->username){
				return false;
			}
			if($msg->to->username != $this->local->username){
				return false;
			}
			if($msg->from->tag() !== $this->remote->tag()){
				#Logger::debug($msg->from->tag() . " != " . $this->remote->tag());
				return false;
			}
		}else{
			if($msg->from->username != $this->local->username){
				return false;
			}
			if($msg->to->username != $this->remote->username){
				return false;
			}
			if($msg->from->tag() !== $this->local->tag()){
				return false;
			}
		}
		return true;
	}
	
	private function match_trans($msg, $trans){
		if($msg->is_response()){
			if($msg->cseq !== $trans->cseq){
				Logger::debug("");
				return false;
			}
			if($msg->cseq_method !== $trans->method){
				Logger::debug("");
				return false;
			}
			if($msg->branch !== $trans->branch){
				Logger::debug("{$msg->branch} != {$trans->branch}");
				return false;
			}
			if($trans->to_tag){
				if($msg->to->tag() !== $trans->to_tag){
					Logger::debug($msg->to->tag() . " != " . $trans->to_tag);
					return false;
				}
			}
			return true;
		}else{
			// ACK 特殊处理
			if($msg->method === 'ACK'){
				if($msg->cseq !== $trans->cseq){
					Logger::debug("");
					return false;
				}
				if($msg->uri !== $trans->uri){
					Logger::debug("{$msg->uri} != {$trans->uri}");
					return false;
				}
				if($msg->to->tag() !== $trans->to_tag){
					Logger::debug($msg->to->tag() . " != " . $trans->to_tag);
					return false;
				}
				return true;
			}

			// 收到重传或者 CANCEL
			if($msg->cseq === $trans->cseq && $msg->branch === $trans->branch && $msg->uri === $trans->uri){
				Logger::debug("recv transaction request " . $msg->method);
				return true;
			}
			// re-INVITE 或者 BYE
			if($msg->cseq === $trans->cseq + 1 && $msg->to->tag() === $this->local->tag()){
				Logger::debug("recv new cseq request " . $msg->method);
				$this->remote_cseq = $msg->cseq;
				return true;
			}
			// 新请求或者 BYE
			if(!$this->remote_cseq && $msg->to->tag() === $this->local->tag()){
				Logger::debug("recv first cseq request " . $msg->method);
				$this->remote_cseq = $msg->cseq;
				return true;
			}
			return false;
		}
	}

	function proc_incoming($msg){
		foreach($this->transactions as $trans){
			if($this->match_trans($msg, $trans)){
				return $this->incoming($msg, $trans);
			}
		}
		return false;
	}

	function proc_outgoing($time, $timespan){
		$ret = array();
		foreach($this->transactions as $trans){
			if(!$trans->timers){
				$this->del_transaction($trans);
				continue;
			}
			
			$trans->timers[0] -= $timespan;
			if($trans->timers[0] <= 0){
				array_shift($trans->timers);
				if(count($trans->timers) > 0){
					$msg = $this->outgoing($trans);
					if($msg){
						$ret[] = $msg;
					}
				}
			}
		}
		if(!$this->transactions){
			Logger::debug($this->role_name() . " terminated for empty transactions");
			$this->terminate();
		}
		return $ret;
	}
		
	protected function incoming($msg, $trans){
		if($msg->method === 'BYE'){
			$this->transactions = array();
			// if($this->is_state(SIP::TRYING) || $this->is_state(SIP::RINGING)){
			if($trans->code > 0 && $trans->code < 200){
				$trans->code = 487; // Request Terminated
				$trans->to_tag = $this->local->tag();
				$trans->timers = array(0, 0);
				$this->transactions[] = $trans;
			}else{
				//
			}
			$this->set_state(SIP::CLOSING);

			// response OK
			$new = new SipTransaction();
			$new->code = 200;
			$new->method = $msg->method;
			$new->cseq = $msg->cseq;
			$new->branch = $msg->branch; // 原 branch
			$new->to_tag = $msg->to->tag();
			$new->timers = array(0, 0);
			$this->transactions[] = $new;
			return true;
		}

		if($msg->code === 200){
			if($trans->method === 'BYE'){
				Logger::debug("recv 200 for BYE, terminate");
				$this->terminate();
				return true;
			}
		}
		if($msg->code === 481 || $msg->code === 486 || $msg->code === 487){
			Logger::debug("recv {$msg->code} for {$trans->method}, closing");
			$this->set_state(SIP::CLOSING);
			$this->remote->set_tag($msg->to->tag());
			
			$trans->method = 'ACK';
			$trans->timers = array(0, 0);
			$this->transactions = array($trans);
			return true;
		}

		if($msg->method === 'ACK'){
			if($trans->code >= 300){
				Logger::debug("recv ACK for {$trans->code}, terminate");
				$this->terminate();
				return true;
			}
		}
		return false;
	}
	
	protected function outgoing($trans){
		$msg = new SipMessage();
		$msg->src_ip = $this->local_ip;
		$msg->src_port = $this->local_port;
		$msg->dst_ip = $this->remote_ip;
		$msg->dst_port = $this->remote_port;

		if($trans->code){
			$msg->code = $trans->code;
			$msg->cseq_method = $trans->method;
		}else{
			$msg->method = $trans->method;
		}
		if($msg->is_request()){
			$msg->from = clone $this->local;
			$msg->to = clone $this->remote;
		}else{
			$msg->from = clone $this->remote;
			$msg->to = clone $this->local;
		}
		if($trans->to_tag){
			$msg->to->set_tag($trans->to_tag);
		}
		if($msg->code === 200 || ($msg->is_request() && ($msg->method === 'INVITE' || $msg->method === 'REGISTER'))){
			$msg->contact = clone $this->contact;
		}
		$msg->call_id = $this->call_id;
		$msg->uri = $trans->uri;
		$msg->branch = $trans->branch;
		$msg->cseq = $trans->cseq;
		$msg->expires = $trans->expires;
		if($trans->auth){
			$msg->auth = SIP::encode_www_auth($trans->auth);
		}
		$msg->content = $trans->content;
		$msg->content_type = $trans->content_type;
		
		return $msg;
	}
}
