<?php
class SipNullSession extends SipSession
{
	function __construct(){
		$this->role = SIP::NONE;
		$this->set_state(SIP::TRYING);
		
		$this->local = new SipContact();
		$this->remote = new SipContact();
		
		$new = $this->new_request();
		$new->trying();
		$new->timers = array(1, 1, 1, 1, 1, 1);
	}
	
	function incoming($msg, $trans){
		return null;
	}
	
	private $count = 0;

	function outgoing($trans){
		if(++$this->count == 3){
			$this->complete();
			$trans->wait(999);
		}
		return null;
	}
}

