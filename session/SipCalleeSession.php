<?php
class SipCalleeSession extends SipBaseCallSession
{
	private $remote_branch;
	
	function __construct(){
		parent::__construct();
		$this->role = SIP::CALLEE;
		$this->set_state(SIP::NONE);
	}
	
	function brief(){
		return $this->role_name() .' '. $this->remote->address() .'=>'. $this->local->address();
	}

	function del_transaction($trans){
		parent::del_transaction($trans);
		if($this->is_state(SIP::TRYING) || $this->is_state(SIP::RINGING)){
			$this->close();
		}
	}
	
	function init(){
		$this->set_state(SIP::TRYING);
		$new = $this->new_response($this->remote_branch);
		$new->trying();
	}
	
	function ringing(){
		// 不能在 100 响应中返回 totag，所以这里不生成 local tag
		$this->set_state(SIP::RINGING);
		
		$this->transactions = array();
		$new = $this->new_response($this->remote_branch);
		$new->ringing();
	}
	
	function completing(){
		$this->set_state(SIP::COMPLETING);
		$this->local->set_tag(SIP::new_tag());
		
		$this->transactions = array();
		$new = $this->new_response($this->remote_branch);
		$new->completing();
	}
	
	function incoming($msg, $trans){
		$ret = parent::incoming($msg, $trans);
		if($ret === true){
			return true;
		}
		
		if($trans->state == SIP::COMPLETING){
			if($msg->method == 'ACK'){
				$this->complete();
				
				$this->del_transaction($trans);
				
				$new = $this->new_request($trans->branch);
				$new->keepalive();
				
				return true;
			}else if($msg->method == 'INVITE'){
				Logger::debug("recv duplicated INVITE, resend OK");
				$trans->nowait();
				if($msg->content){
					$this->remote_sdp = $msg->content;
				}
				return true;
			}
		}
	}
	
	function outgoing($trans){
		$msg = parent::outgoing($trans);
		if($msg){
			return $msg;
		}
		
		if($trans->state == SIP::TRYING){
			$msg = new SipMessage();
			$msg->code = 100;
			$msg->cseq_method = 'INVITE';
			return $msg;
		}else if($trans->state == SIP::RINGING){
			$msg = new SipMessage();
			$msg->code = 180;
			$msg->cseq_method = 'INVITE';
			return $msg;
		}else if($trans->state == SIP::COMPLETING){
			$msg = new SipMessage();
			$msg->code = 200;
			$msg->cseq_method = 'INVITE';
			
			$msg->add_header('Content-Type', 'application/sdp');
			if($this->local_sdp){
				$msg->content = $this->local_sdp;
			}
			return $msg;
		}
	}
}
