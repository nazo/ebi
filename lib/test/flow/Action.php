<?php
namespace test\flow;

class Action{
	public function abc(){
		$var = isset($_GET['abc']) ? $_GET['abc'] : null;
		return ['abc'=>$var];
	}
	public function raise(){
		throw new \LogicException('raise test');
	}
	public function exceptions(){
		\ebi\Exceptions::add(new \LogicException('raise test'),'newgroup');
		\ebi\Exceptions::throw_over();
	}
	public function get_method(){
		$req = new \ebi\Request();
		
		ob_start();
			var_dump($req->ar_vars());
		$data = ob_get_clean();
		
		return ['method'=>($req->is_post() ? 'POST' : 'GET'),'data'=>$data];
	}
}