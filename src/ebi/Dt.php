<?php
namespace ebi;
use \ebi\Q;
/**
 * 開発支援ツール
 * @author tokushima
 *
 */
class Dt{
	use \ebi\FlowPlugin;
	private $flow_output_maps = [];
	
	public function get_flow_plugins(){
		return ['ebi.flow.plugin.TwitterBootstrap3Helper'];
	}
	public function get_after_vars(){
		return [
				'f'=>new \ebi\Dt\Helper(),
				'appmode'=>(defined('APPMODE') ? constant('APPMODE') : ''),
				'has_coverage'=>function_exists('xdebug_get_code_coverage'),
				];
	}
	private function get_flow_output_maps(){
		if(empty($this->flow_output_maps)){
			$entry = null;
			$trace = debug_backtrace(false);
			krsort($trace);
			foreach($trace as $t){
				if(isset($t['class']) && $t['class'] == 'ebi\Flow'){
					$entry = $t;
					break;					
				}
			}
			if(isset($entry['file'])){
				$self_class = str_replace('\\','.',__CLASS__);
				$src = file_get_contents($entry['file']);
				
				if(strpos($src,'Flow') !== false){
					foreach(\ebi\Flow::get_map($entry['file']) as $k => $m){
						if(!isset($m['deprecated'])) $m['deprecated'] = false;
						if(!isset($m['mode'])) $m['mode'] = null;
						if(!isset($m['summary'])) $m['summary'] = null;
						if(!isset($m['template'])) $m['template'] = null;
						
						if(isset($m['action']) && is_string($m['action'])){
							list($m['class'],$m['method']) = explode('::',$m['action']);
							if(substr($m['class'],0,1) == '\\') $m['class'] = substr($m['class'],1);
							$m['class'] = str_replace('\\','.',$m['class']);
						}
						if(!isset($m['class']) || $m['class'] != $self_class){
							try{
								$m['error'] = null;
								$m['url'] = $k;
								
								if(isset($m['method'])){
									$info = \ebi\Dt\Man::method_info($m['class'],$m['method']);
									list($summary) = explode(PHP_EOL,$info['description']);
									$m['summary'] = empty($summary) ? null : $summary;
								}
							}catch(\Exception $e){
								$m['error'] = $e->getMessage();
							}
							$this->flow_output_maps[$m['name']] = $m;
						}
					}
				}
			}
		}
		return $this->flow_output_maps;	
	}
	/**
	 * @automap
	 */
	public function phpinfo(){
		ob_start();
			phpinfo();
		$info = ob_get_clean();
		$info = \ebi\Xml::extract($info,'body')->escape(false)->value();
		$info = preg_replace('/<table .+>/','<table class="table table-striped table-bordered table-condensed">',$info);
		return ['phpinfo'=>$info];
	}
	/**
	 * @automap
	 */
	public function index(){
		return array('map_list'=>$this->get_flow_output_maps());
	}
	/**
	 * ライブラリの一覧
	 * @automap
	 */
	public function class_list(){
		$libs = array();
		foreach(self::classes() as $package => $info){
			$r = new \ReflectionClass($info['class']);
			$class_doc = $r->getDocComment();
			$document = trim(preg_replace("/@.+/",'',preg_replace("/^[\s]*\*[\s]{0,1}/m",'',str_replace(array('/'.'**','*'.'/'),'',$class_doc))));
			list($summary) = explode("\n",$document);
			$libs[$package] = $summary;
		}
		ksort($libs);
		return ['class_list'=>$libs];
	}
	/**
	 * クラスのドキュメント
	 * @param string $class
	 * @automap
	 */
	public function class_doc($class){
		$info = \ebi\Dt\Man::class_info($class);
		return $info;
	}
	/**
	 * クラスのソース表示
	 * @param string $class
	 * @automap
	 */
	public function class_src($class){
		$info = \ebi\Dt\Man::class_info($class);
		return ['class_src'=>explode(PHP_EOL,str_replace(["\r\n","\r","\n","\t"],[PHP_EOL,PHP_EOL,PHP_EOL,'  '],file_get_contents($info['filename'])))];
	}
	/**
	 * クラスドメソッドのキュメント
	 * @param string $class
	 * @param string $method
	 * @automap
	 */
	public function method_doc($class,$method){
		$info = \ebi\Dt\Man::method_info($class,$method,true);
		return $info;
	}
	/**
	 * Daoモデルの一覧
	 * @automap
	 */
	public function model_list(){
		$errors = $error_query = $model_list = $con = array();
		
		foreach(self::classes('\ebi\Dao') as $class_info){
			$class = $class_info['class'];
			$r = new \ReflectionClass($class);
			if((!$r->isInterface() && !$r->isAbstract()) && is_subclass_of($class,'\ebi\Dao')){
				$class_doc = $r->getDocComment();
				$package = str_replace('\\','.',substr($class,1));
				$document = trim(preg_replace('/@.+/','',preg_replace("/^[\s]*\*[\s]{0,1}/m",'',str_replace(array('/'.'**','*'.'/'),'',$class_doc))));
				list($summary) = explode("\n",$document);
				$errors[$package] = null;
				$con[$package] = true;

				try{
					\ebi\Dao::start_record();
						$class::find_get();
					\ebi\Dao::stop_record();
				}catch(\ebi\exception\NotFoundException $e){
				}catch(\ebi\exception\ConnectionException $e){
					$errors[$package] = $e->getMessage();
					$con[$package] = false;
				}catch(\Exception $e){
					$errors[$package] = $e->getMessage();
					$error_query[$package] = print_r(\ebi\Dao::recorded_query(),true);
				}
				$model_list[$package] = $summary;
			}
		}
		return [
				'dao_models'=>$model_list,
				'dao_model_errors'=>$errors,
				'dao_model_error_query'=>$error_query,
				'dao_model_con'=>$con,
				];
	}
	private function get_model($name,$sync=true){
		$req = new \ebi\Request();
		$r = new \ReflectionClass('\\'.str_replace('.','\\',$name));
		$obj = $r->newInstance();
		if(is_array($req->in_vars('primary'))){
			foreach($req->in_vars('primary') as $k => $v) $obj->{$k}($v);
		}
		return ($sync) ? $obj->sync() : $obj;
	}
	/**
	 * 検索
	 *
	 * @param string $name モデル名
	 * @automap
	 *
	 * @request string $order ソート順
	 * @request int $page ページ番号
	 * @request string $query 検索文字列
	 * @request string $porder 直前のソート順
	 *
	 * @context array $object_list 結果配列
	 * @context Paginator $paginator ページ情報
	 * @context string $porder 直前のソート順
	 * @context Dao $model 検索対象のモデルオブジェクト
	 * @context string $model_name 検索対象のモデルの名前
	 */
	public function do_find($package){
		$req = new \ebi\Request();
		$class = '\\'.str_replace('.','\\',$package);
		$order = \ebi\Sorter::order($req->in_vars('order'),$req->in_vars('porder'));
	
		if(empty($order)){
			$dao = new $class();
			foreach($dao->props() as $n => $v){
				if($dao->prop_anon($n,'primary')){
					$order = '-'.$n;
					break;
				}
			}
		}
		$object_list = array();
		$paginator = new \ebi\Paginator(20,$req->in_vars('page',1));
		$paginator->cp(array('order'=>$order));
		
		if($req->is_vars('search')){
			$q = new Q();
			foreach($req->ar_vars() as $k => $v){
				if($v !== '' && strpos($k,'search_') === 0){
					list(,$type,$key) = explode('_',$k,3);
					switch($type){
						case 'timestamp':
						case 'date':
							list($fromto,$key) = explode('_',$key);
							$q->add(($fromto == 'to') ? Q::lte($key,$v) : Q::gte($key,$v));
							break;
						default:
							$q->add(Q::contains($key,$v));
					}
					$paginator->vars($k,$v);
				}
				$paginator->vars('search',true);
			}
			$object_list = $class::find_all($q,$paginator,Q::select_order($order,$req->in_vars('porder')));
			$req->rm_vars('q');
		}else{
			$object_list = $class::find_all(Q::match($req->in_vars('q')),$paginator,Q::select_order($order,$req->in_vars('porder')));
			$paginator->vars('q',$req->in_vars('q'));
		}		
		$result = $req->ar_vars();
		$result['object_list'] = $object_list;
		$result['paginator'] = $paginator;
		$result['model'] = new $class();
		$result['package'] = $package;
		return $result;
	}
	/**
	 * 詳細
	 * @param string $package モデル名
	 * @automap
	 */
	public function do_detail($package){
		$obj = $this->get_model($package);
		
		return ['object'=>$obj,
				'model'=>$obj,
				'package'=>$package,
				];
	}
	/**
	 * 削除
	 * @param string $package モデル名
	 * @automap @['post_after'=>'']
	 */
	public function do_drop($package){
		$req = new \ebi\Request();
		if($req->is_post()){
			$this->get_model($package)->delete();
		}
	}
	/**
	 * 更新
	 * @param string $package モデル名
	 * @automap @['post_after'=>['save_and_add_another'=>['do_create','package'],'save'=>['do_find','package']]]
	 */
	public function do_update($package){
		$result = [];
		$req = new \ebi\Request();
		if($req->is_post()){
			$obj = $this->get_model($package,false);
			$obj->set_props($req->ar_vars());
			$obj->save();

			$result[($req->is_vars('save_and_add_another') ? 'save_and_add_another' : 'save')] = true;
		}else{
			$obj = $this->get_model($package);
		}
		foreach(array_keys($obj->props(false)) as $k){
			$fm = 'fm_'.$k;
			$result[$k] = $obj->{$fm}();
		}
		$result['model'] = $obj;
		$result['package'] = $package;
		
		return $result;
	}
	/**
	 * 作成
	 * @param string $package モデル名
	 * @automap @['post_after'=>['save_and_add_another'=>['do_create','package'],'save'=>['do_find','package']]]
	 */
	public function do_create($package){
		$result = [];
		$req = new \ebi\Request();
		
		if($req->is_post()){
			$obj = $this->get_model($package,false);
			$obj->set_props($req->ar_vars());
			$obj->save();
			
			$result[($req->is_vars('save_and_add_another') ? 'save_and_add_another' : 'save')] = true;
		}else{
			$obj = $this->get_model($package,false);
		}
		$result['model'] = $obj;
		$result['package'] = $package;
		
		return $result;
	}
	public static function get_dao_connection($package){
		if(!is_object($package)){
			$r = new \ReflectionClass('\\'.str_replace('.','\\',$package));
			$package = $r->newInstance();
		}
		if(!is_subclass_of($package,'\ebi\Dao')) throw new \RuntimeException('not Dao class');
	
		$connections = \ebi\Dao::connections();
		$conf = explode("\\",get_class($package));
		while(!isset($connections[implode('.',$conf)]) && !empty($conf)) array_pop($conf);
		if(empty($conf)){
			if(!isset($connections['*'])) throw new \RuntimeException(get_class($package).' connection not found');
			$conf = array('*');
		}
		$conf = implode('.',$conf);
		foreach($connections as $k => $con){
			if($k == $conf) return $con;
		}
	}
	/**
	 * SQLを実行する
	 * @param string $package
	 * @automap
	 */
	public function do_sql($package){
		$req = new \ebi\Request();
		$result_list = $keys = array();
		$sql = $req->in_vars('sql');
		$count = 0;

		$con = self::get_dao_connection($package);

		if($req->is_vars('create_sql')){
			$r = new \ReflectionClass('\\'.str_replace('.','\\',$package));
			$dao = $r->newInstance();
			$sql = $con->connector()->create_table_sql($dao);
			$req->rm_vars('create_sql');
			$req->vars('sql',$sql);
		}
		if($req->is_post() && !empty($sql)){
			$excute_sql = array();
			$sql = str_replace(array('\\r\\n','\\r','\\n','\;'),array("\n","\n","\n",'{SEMICOLON}'),$sql);
			foreach(explode(';',$sql) as $q){
				$q = trim(str_replace('{SEMICOLON}',';',$q));
				$excute_sql[] = $q;
				if(!empty($q)) $con->query($q);
			}
			foreach($con as $v){
				if(empty($keys)) $keys = array_keys($v);
				$result_list[] = $v;
				$count++;
					
				if($count >= 100) break;
			}
			$req->vars('excute_sql',implode(';'.PHP_EOL,$excute_sql));
		}
		$req->vars('result_keys',$keys);
		$req->vars('result_list',$result_list);
		$req->vars('package',$package);
		$req->vars('maximum',($count >= 100));
		
		return $req->ar_vars();
	}
	/**
	 * エントリのURL群
	 * @param string $dir
	 * @return array
	 */
	public static function get_urls($dir=null){
		if(empty($dir)) $dir = getcwd();
		
		$urls = array();
		foreach(new \RecursiveDirectoryIterator(
				$dir,
				\FilesystemIterator::CURRENT_AS_FILEINFO|\FilesystemIterator::SKIP_DOTS|\FilesystemIterator::UNIX_PATHS
		) as $f){
			if(substr($f->getFilename(),-4) == '.php' && !preg_match('/\/[\._]/',$f->getPathname())){
				$entry_name = substr($f->getFilename(),0,-4);
				$src = file_get_contents($f->getPathname());
	
				if(strpos($src,'Flow') !== false){
					$entry_name = substr($f->getFilename(),0,-4);
					foreach(\ebi\Flow::get_map($f->getPathname()) as $m){
						$urls[$entry_name.'::'.$m['name']] = $m['format'];
					}
				}
			}
		}
		return $urls;
	}
	/**
	 * ライブラリ一覧
	 * composerの場合はcomposer.jsonで定義しているPSR-0のもののみ
	 * @return array
	 */
	public static function classes($parent_class=null){
		$result = array();
		$include_path = array();
		if(is_dir(getcwd().'/lib')){
			$include_path[] = realpath(getcwd().'/lib');
		}
		if(class_exists('Composer\Autoload\ClassLoader')){
			$r = new \ReflectionClass('Composer\Autoload\ClassLoader');
			$composer_dir = dirname($r->getFileName());
			$json_file = dirname(dirname($composer_dir)).'/composer.json';
				
			if(is_file($json_file)){
				$json = json_decode(file_get_contents($json_file),true);
				if(isset($json['autoload']['psr-0'])){
					foreach($json['autoload']['psr-0'] as $path){
						$p = realpath(dirname($json_file).'/'.$path);
						if($p !== false) $include_path[] = $p;
					}
				}
			}
		}
		foreach($include_path as $libdir){
			if($libdir !== '.'){
				foreach(new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator(
								$libdir,
								\FilesystemIterator::CURRENT_AS_FILEINFO|\FilesystemIterator::SKIP_DOTS|\FilesystemIterator::UNIX_PATHS
						),\RecursiveIteratorIterator::SELF_FIRST
				) as $e){
					if(strpos($e->getPathname(),'/.') === false
							&& strpos($e->getPathname(),'/_') === false
							&& ctype_upper(substr($e->getFilename(),0,1))
							&& substr($e->getFilename(),-4) == '.php'
					){
						try{
							include_once($e->getPathname());
						}catch(\Exeption $ex){
						}
					}
				}
			}
		}
		$set = function(&$result,$r,$include_path,$parent_class){
			if(!$r->isInterface() 
				&& !$r->isAbstract() 
				&& (empty($parent_class) || is_subclass_of($r->getName(),$parent_class)) 
				&& $r->getFileName() !== false
			){
				$bool = empty($include_path);
				if(!$bool){
					foreach($include_path as $libdir){
						if(strpos($r->getFileName(),$libdir) === 0){
							$bool = true;
							break;
						}
					}
				}
				if($bool){
					$n = str_replace('\\','/',$r->getName());
					$result[str_replace('/','.',$n)] = array('filename'=>$r->getFileName(),'class'=>'\\'.$r->getName());
				}
			}
		};
		foreach(get_declared_classes() as $class){
			$set($result,new \ReflectionClass($class),$include_path,$parent_class);
		}
		$add = \ebi\Conf::get('use_vendor',array());
		if(is_string($add)){
			$add = array($add);
		}
		foreach($add as $class){
			$class = str_replace('.','\\',$class);
			if(substr($class,0,1) != '\\') $class = '\\'.$class;
			$ref = new \ReflectionClass($class);
			$set($result,new \ReflectionClass($class),array(),$parent_class);
		}
		ksort($result);
		return $result;
	}
	/**
	 * モデルからtableを作成する
	 * @param string $model
	 * @param boolean $drop
	 * @reutrn array 処理されたモデル
	 * @throws \Exception
	 */
	public static function create_table($model=null,$drop=false){
		$model_list = array();
		$result = array();
	
		if(!empty($model)){
			$model = str_replace('.','\\',$model);
			if(substr($model,0,1) !== '\\') $model = '\\'.$model;
			$model_list = array($model=>array('class'=>$model));
		}else{
			foreach(self::classes('\ebi\Dao') as $class_info){
				$r = new \ReflectionClass($class_info['class']);
				if($r->getParentClass()->getName() == 'ebi\Dao'){
					$model_list[] = $class_info;
				}
			}
		}
		foreach($model_list as $class_info){
			$r = new \ReflectionClass($class_info['class']);
			
			if($r->getParentClass() === false || $r->getParentClass()->getName() != 'ebi\Dao'){
				throw new \InvalidArgumentException('not inherit the \ebi\Dao');
			}			
			if($drop && call_user_func(array($r->getName(),'drop_table'))){
				$result[] = array(-1,$r->getName());
			}
			if(call_user_func(array($r->getName(),'create_table'))){
				$result[] = array(1,$r->getName());
			}
		}
		return $result;
	}
	/**
	 * SmtpBlackholeDaoから送信されたメールの一番新しいものを返す
	 * @param string $to
	 * @param string $subject
	 * @param number $late_time sec
	 * @throws \LogicException
	 * @return \ebi\SmtpBlackholeDao
	 */
	public static function find_mail($to,$keyword=null,$late_time=60){
		if(empty($to)) throw new \LogicException('`to` not found');
	
		$q = new Q();
		$q->add(Q::eq('to',$to));
		$q->add(Q::gte('create_date',time()-$late_time));
		if(!empty($subject)) $q->add(Q::contains('subject',$subject));
	
		foreach(\ebi\SmtpBlackholeDao::find($q,Q::order('-id')) as $mail){
			$value = $mail->subject().$mail->message();
				
			if(empty($keyword) || mb_strpos($value,$keyword) !== false){
				return $mail;
			}
		}
		throw new \LogicException('指定のメールが飛んでいない > ['.$to.'] '.$keyword);
	}
	
	private function file_list($dir,$reg){
		$result = [];
		if(is_dir($dir)){
			$it = new \RecursiveDirectoryIterator($dir,
					\FilesystemIterator::CURRENT_AS_FILEINFO|\FilesystemIterator::SKIP_DOTS|\FilesystemIterator::UNIX_PATHS
			);
			$it = new \RecursiveIteratorIterator($it,\RecursiveIteratorIterator::SELF_FIRST);
			$it = new \RegexIterator($it,$reg);
	
			foreach($it as $f){
				$result[$f->getPathname()] = $f;
			}
		}
		return $result;
	}
	/**
	 * @automap
	 */
	public function coverage(){
		$req = new \ebi\Request();
		$dir = \ebi\Conf::get('test_result_dir',\ebi\Conf::work_path('test_output'));
		$target_list = $this->file_list($dir,'/\.coverage\.xml$/');
		
		usort($target_list,function($a,$b){
			return ($a->getMTime() > $b->getMTime()) ? -1 : 1;
		});
		$covered_list = [];
		$covered_status_list = [];
		$total_covered = 0;
		$create_date = null;
		$target = (!$req->is_vars('target') && !empty($target_list)) ? $target_list[0] : $req->in_vars('target');		

		if(!empty($target) && is_file($target)){
			try{
				$xml = \ebi\Xml::extract(file_get_contents($target),'coverage');
				$covered_total = 0;
				$create_date = $xml->in_attr('create_date');
				$total_covered = $xml->in_attr('covered');
		
				foreach($xml->find('file') as $file){
					$name = $file->in_attr('name');
					$covered = $file->in_attr('covered');
					$covered_list[$name] = array('covered'=>$covered,'status'=>($covered == 100 ? 'perfect' : (($covered >= 50) ? 'more' : (($covered == 0) ? 'zero' : 'bad'))));
					
					if(!isset($covered_status_list[$covered_list[$name]['status']])){
						$covered_status_list[$covered_list[$name]['status']] = 0;
					}
					$covered_total += $covered;
					$covered_status_list[$covered_list[$name]['status']] += $covered;
				}
				foreach($covered_status_list as $st => $v){
					$covered_status_list[$st] = round($v / $covered_total * 100);
				}
			}catch(\ebi\exception\NotFoundException $e){
			}
		}
		return [
			'target'=>$target,
			'target_list'=>$target_list,
			'covered_list'=>$covered_list,
			'covered_status_list'=>$covered_status_list,
			'covered'=>$total_covered,
			'create_date'=>$create_date,
		];
	}
	/**
	 * @automap
	 */
	public function covered(){
		$req = new \ebi\Request();
		$target = $req->in_vars('target');
		$filename = $req->in_vars('filename');
		$source = explode(PHP_EOL,file_get_contents($filename));
		$modify_date = date('Y/m/d H:i:s',filemtime($filename));
		$status = array();
		$coverage_modify_date = null;
		$covered = 0;
		
		for($i=1;$i<count($source);$i++){
			$status[$i] = 'ignore';
		}
		if(is_file($target)){
			try{
				foreach(\ebi\Xml::extract(file_get_contents($target),'coverage')->find('file') as $file){
					if($file->in_attr('name') == $filename){
						foreach(explode(',',$file->find_get('covered_lines')->value()) as $line){
							if(isset($status[$line])) $status[$line] = 'covered';
						}
						foreach(explode(',',$file->find_get('uncovered_lines')->value()) as $line){
							if(isset($status[$line])) $status[$line] = 'uncovered';
						}
						$coverage_modify_date = $file->in_attr('modify_date');
						$covered = $file->in_attr('covered');
						break;
					}
				}
			}catch(\ebi\exception\NotFoundException $e){
			}
		}
		return [
				'source'=>$source,
				'filename'=>basename($filename),
				'dir'=>dirname($filename),
				'status'=>$status,
				'modify_date'=>$modify_date,
				'coverage_modify_date'=>$coverage_modify_date,
				'covered'=>$covered,
				];
	}
	/**
	 * entryを探しhtaccessを生成する
	 * @param string $base
	 */
	public static function htaccess($base){
		if(substr($base,0,1) !== '/') $base = '/'.$base;
		$rules = "RewriteEngine On\nRewriteBase ".$base."\n\n";
		foreach(new \DirectoryIterator(getcwd()) as $f){
			if($f->isFile() && substr($f->getPathname(),-4) == '.php' && substr($f->getFilename(),0,1) != '_' && $f->getFilename() != 'index.php'){
				$src = file_get_contents($f->getPathname());
				if(strpos($src,'Flo'.'w::app(') !== false){
					$app = substr($f->getFilename(),0,-4);
					$rules .= "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^".$app."[/]{0,1}(.*)\$ ".$app.".php/\$1?%{QUERY_STRING} [L]\n\n";
				}
			}
		}
		if(is_file(getcwd().'/index.php')){
			$rules .= "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^(.*)\$ index.php/\$1?%{QUERY_STRING} [L]\n\n";
		}
		file_put_contents('.htaccess',$rules);

		return [realpath('.htaccess'),$rules];
	}
	/**
	 * アプリケーションモードに従い初期処理を行うファイルのパス
	 * @return string
	 */
	public static function setup_file(){
		$dir = defined('COMMONDIR') ? dirname(constant('COMMONDIR')) : getcwd();
		return $dir.'/setup/'.(defined('APPMODE') ? constant('APPMODE') : 'local').'.php';
	}
	/**
	 * アプリケーションモードに従い初期処理を実行する
	 * setup/[APPMODE].phpの実行
	 */
	public static function setup(){
		if(is_file($f=self::setup_file())){
			include($f);
			return true;
		}
		return false;
	}
}
