<?php
abstract class SipSession
{
	// 指向本 Session 所属的 module。
	public $module;
	public $transactions = array();
	
	public $role;
	public $state = 0;

	public $local_ip;
	public $local_port;
	public $remote_ip;
	public $remote_port;
	
	public $remote_allow = array();

	protected $expires = 60;
	protected static $reg_timers = array(0, 0.5, 1, 2, 3, 2);
	protected static $call_timers = array(0, 0.5, 1, 2, 2, 2);
	protected static $ring_timers = array(0, 3, 3, 3, 3, 3);
	protected static $now_timers = array(0, 0);
	
	public $call_id; // session id
	// local_cseq remote_cseq
	public $cseq;    // command/transaction seq
	
	public $uri;
	
	public $local;
	public $local_tag;
	public $remote;
	public $remote_tag;
	
	protected $auth;
	
	function __construct(){
	}
	
	abstract function incoming($msg, $trans);
	abstract function outgoing($trans);
	
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
			return 'NONE';
		}
	}
	
	function complete(){
		if($this->state != SIP::COMPLETED && !$this->renew){
			Logger::debug($this->role_name() . " session {$this->call_id} established");
		}
		$this->state = SIP::COMPLETED;
	}
	
	function close(){
		foreach($this->transactions as $trans){
			$trans->close();
			$this->transactions = array($trans);
			return;
		}
	}
	
	function onclose($msg){
		foreach($this->transactions as $trans){
			$trans->onclose();
			$trans->branch = $msg->branch;
			$trans->remote_tag = $msg->from_tag;
			$trans->cseq = $msg->cseq;
			$this->transactions = array($trans);
			return;
		}
	}
	
	function terminate(){
		$this->state = SIP::CLOSED;
		$this->transactions = array();
	}
	
	function new_transaction($state, $timers=array()){
		$this->cseq ++;
		
		$trans = new SipTransaction();
		$trans->state = $state;
		$trans->timers = $timers;
		$trans->branch = $this->branch;
		$trans->local_tag = $this->local_tag;
		$trans->remote_tag = $this->remote_tag;
		$trans->cseq = $this->cseq;
		$trans->branch = SIP::new_branch();
		$this->add_transaction($trans);
		return $trans;
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
}
