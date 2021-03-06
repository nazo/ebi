<?php
namespace ebi\flow;
/**
 * リクエストやセッションを処理するactionベース
 * @author tokushima
 *
 */
class Request{
	use \ebi\Plugin, \ebi\FlowPlugin;
	
	private $sess;
	private $req;
	private $login_id;
	private $login_anon;
	
	public function __construct(){
		$this->req = new \ebi\Request();
		$d = debug_backtrace(false);
		$d = array_pop($d);
		$sess_name = md5($d['file']);
		$this->sess = new \ebi\Session($sess_name);
		$this->login_id = $sess_name.'_LOGIN_';
		$this->login_anon = \ebi\Annotation::decode($this,'login',__CLASS__);
	}
	public function get_after_vars(){
		return $this->req->ar_vars();
	}
	/**
	 * POSTされたか
	 * @return boolean
	 */
	public function is_post(){
		return $this->req->is_post();
	}
	/**
	 * 添付ファイル情報の取得
	 * @param string $n
	 * @return array
	 */
	public function in_files($n){
		return $this->req->in_files($n);
	}
	/**
	 * 添付されたファイルがあるか
	 * @param array $file_info
	 * @return boolean
	 */
	public function has_file($file_info){
		return $this->req->has_file($file_info);
	}
	/**
	 * 添付ファイルのオリジナルファイル名の取得
	 * @param array $file_info
	 * @return string
	 */
	public function file_original_name($file_info){
		return $this->req->file_original_name($file_info);
	}
	/**
	 * 添付ファイルのファイルパスの取得
	 * @param array $file_info
	 * @return string
	 */
	public function file_path($file_info){
		return $this->req->file_path($file_info);
	}
	/**
	 * 添付ファイルを移動します
	 * @param array $file_info
	 * @param string $newname
	 */
	public function move_file($file_info,$newname){
		$this->req->move_file($file_info,$newname);
	}
	/**
	 * クッキーへの書き出し
	 * @param string $name 書き込む変数名
	 * @param int $expire 有効期限 (+ time)
	 * @param string $path パスの有効範囲
	 * @param boolean $subdomain サブドメインでも有効とするか
	 * @param boolean $secure httpsの場合のみ書き出しを行うか
	 */
	protected function write_cookie($name,$expire=null,$path=null,$subdomain=false,$secure=false){
		$this->req->write_cookie($name,$expire,$path,$subdomain,$secure);
	}
	/**
	 * クッキーから削除
	 * 登録時と同条件のものが削除される
	 * @param string $name クッキー名
	 */
	protected function delete_cookie($name,$path=null,$subdomain=false,$secure=false){
		$this->req->delete_cookie($name,$path,$subdomain,$secure);
	}
	/**
	 * クッキーから呼び出された値か
	 * @param string $name
	 * @return boolean
	 */
	protected function is_cookie($name){
		return $this->req->is_cookie($name);
	}
	/**
	 * pathinfo または argv
	 * @return string
	 */
	protected function args(){
		return $this->req->args();
	}
	/**
	 * 値をセットする
	 * @param string $key
	 * @param mixed $val
	 */
	public function vars($key,$val){
		$this->req->vars($key,$val);
	}
	/**
	 * 定義済みの値から一つ取得する
	 * @param string $n 取得する定義名
	 * @param mixed $d 値が存在しない場合の代理値
	 * @return mixed
	 */
	public function in_vars($n,$d=null){
		return $this->req->in_vars($n,$d);
	}
	/**
	 * 値を削除する
	 * @param string $n 削除する定義名
	 */
	public function rm_vars($name=null){
		call_user_func_array([$this->req,'rm_vars'],func_get_args());
	}
	/**
	 * 指定のキーが存在するか
	 * @param string $n
	 * @return boolean
	 */
	public function is_vars($n){
		return $this->req->is_vars($n);
	}
	/**
	 * 定義済みの一覧を返す
	 * @return array
	 */
	public function ar_vars(){
		return $this->req->ar_vars();
	}
	
	
	/**
	 * セッションにセットする
	 * @param string $key
	 * @param mixed $val
	 */
	public function sessions($key,$val){
		$this->sess->vars($key,$val);
	}
	/**
	 * セッションから取得する
	 * @param string $n 取得する定義名
	 * @param mixed $d セッションが存在しない場合の代理値
	 * @return mixed
	 */
	public function in_sessions($n,$d=null){
		return $this->sess->in_vars($n,$d);
	}
	/**
	 * セッションから削除する
	 * @param string $n 削除する定義名
	 */
	public function rm_sessions($n){
		call_user_func_array([$this->sess,'rm_vars'],func_get_args());
	}
	/**
	 * 指定のキーが存在するか
	 * @param string $n
	 * @return boolean
	 */
	public function is_sessions($n){
		return $this->sess->is_vars($n);
	}
	public function before(){
		if(method_exists($this,'__before__')){
			$this->__before__();
		}
		if($this->has_object_plugin('before_flow_action_request')) {
			$this->call_object_plugin_funcs('before_flow_action_request',$this);
		}
		if(
			!$this->is_login() && 
			((isset($this->login_anon['require'])) || $this->has_object_plugin('login_condition'))
		){
			$this->login_required();
		}
	}
	public function after(){
		if(method_exists($this,'__after__')){
			$this->__after__();
		}
		if($this->has_object_plugin('after_flow_action_request')) {
			$this->call_object_plugin_funcs('after_flow_action_request',$this);
		}
	}
	/**
	 * ログインしていない場合にログイン処理を実行する
	 * @throws \LogicException
	 */
	private function login_required(){
		$selected_pattern = $this->get_selected_pattern();
		if(!$this->is_login() 
			&& isset($selected_pattern['action'])
			&& strpos($selected_pattern['action'],'::do_login') === false
		){
			if($this->has_object_plugin('before_login_required')) {
				$this->call_object_plugin_funcs('before_login_required',$this);
			}
			if(!$this->is_sessions('logined_redirect_to')){
				$this->set_login_redirect(\ebi\Request::current_url().\ebi\Request::request_string(true));
			}
			$req = new \ebi\Request();
			$this->sess->vars(__CLASS__.'_login_vars',array(time(),$req->ar_vars()));
			$this->set_before_redirect('login');
		}
	}	

	/**
	 * ログインしているユーザのモデル
	 * @throws \LogicException
	 * @return mixed
	 */
	public function user(){
		if(func_num_args() > 0){
			$user = func_get_arg(0);
			if(isset($this->login_anon['require']) && !empty($this->login_anon['require'])){
				$class = str_replace('.',"\\",$this->login_anon['require']);
				if($class[0] != "\\") $class= "\\".$class;
				if(!($user instanceof $class)){
					throw new \ebi\exception\UnauthorizedTypeException();
				}
			}
			$this->sessions($this->login_id.'USER',$user);
		}
		return $this->in_sessions($this->login_id.'USER');
	}
	/**
	 * ログイン済みか
	 * @return boolean
	 */
	public function is_login(){
		return ($this->in_sessions($this->login_id) !== null);
	}
	/**
	 * ログイン後のリダイレクト先設定
	 * @param string $url
	 */
	public function set_login_redirect($url){
		$this->sessions('logined_redirect_to',$url);
	}
	/**
	 * ログイン
	 * @arg string $login_redirect ログイン後にリダイレクトされるマップ名
	 * @automap
	 */
	public function do_login(){
		if($this->sess->is_vars(__CLASS__.'_login_vars')){
			$data = $this->sess->in_vars(__CLASS__.'_login_vars');
			if(($data[0] + 5) > time()){
				foreach($data[1] as $k => $v){
					if(!$this->is_vars($k)) $this->vars($k,$v);
				}
			}
			$this->sess->rm_vars(__CLASS__.'_login_vars');
		}
		if($this->is_login()){
			// direct access
			if($this->map_arg('login_redirect') != null){
				$this->sessions('logined_redirect_to',$this->map_arg('login_redirect'));
			}
		}else{
			if(!$this->is_sessions('logined_redirect_to') && $this->map_arg('login_redirect') != null){
				$this->sessions('logined_redirect_to',$this->map_arg('login_redirect'));
			}
			if(!$this->has_object_plugin('login_condition') || $this->call_object_plugin_funcs('login_condition',$this) === false){
				$this->call_object_plugin_funcs('login_invalid',$this);
			}else{
				$this->sessions($this->login_id,$this->login_id);
				session_regenerate_id(true);
				$this->call_object_plugin_funcs('after_login',$this);
			}
		}		
		if($this->is_login()){
			$redirect_to = $this->in_sessions('logined_redirect_to');
			$this->rm_sessions('logined_redirect_to');

			$this->call_object_plugin_funcs('after_do_login',$this);
			
			if(!empty($redirect_to)){
				$this->set_after_redirect($redirect_to);
			}
		}else{
			\ebi\HttpHeader::send_status(401);
			$pattern = $this->get_selected_pattern();
			if(!isset($pattern['template'])){
				throw new \ebi\exception\UnauthorizedException();
			}
		}
	}
	/**
	 * ログアウト
	 * @automap
	 */
	public function do_logout(){
		$this->rm_sessions('logined_redirect_to');
		$this->rm_sessions($this->login_id.'USER');
		$this->rm_sessions($this->login_id);
		session_regenerate_id(true);
		
		if($this->map_arg('logout_redirect') != null){
			$this->set_after_redirect($this->map_arg('logout_redirect'));
		}
		$this->vars('login',$this->is_login());
	}
	public function noop(){
		return $this->ar_vars();
	}
}