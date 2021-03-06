<?php
namespace ebi\Dt;

class Helper{
	public function package_name($p){
		$p = str_replace(array('/','\\'),array('.','.'),$p);
		if(substr($p,0,1) == '.') $p = substr($p,1);
		return $p;
	}
	public function type($class){
		if(preg_match('/[A-Z]/',$class)){
			switch(substr($class,-2)){
				case "{}":
				case "[]": $class = substr($class,0,-2);
			}
			$class = str_replace('\\','.',$class);
			if(substr($class,0,1) == '.') $class = substr($class,1);
			return $class;
		}
		return null;
	}
	public function calc_add($i,$add=1){
		return $i + $add;
	}

	
	
	
	/**
	 * アクセサ
	 * @param Dao $obj
	 * @param string $prop_name
	 * @param string $ac
	 */
	public function acr(\ebi\Dao $obj,$prop_name,$ac='fm'){
		return $obj->{$ac.'_'.$prop_name}();
	}
	/**
	 * プロパティ一覧
	 * @param Dao $obj
	 * @param integer $len 表示数
	 */
	public function props(\ebi\Dao $obj,$all=false){
		$props = array_keys($obj->props());
		return ($all) ? $props : array_slice($props,0,5);
	}
	public function primary_query(\ebi\Dao $obj){
		$result = array();
		foreach($this->props($obj) as $prop){
			if($obj->prop_anon($prop,'primary') === true && $obj->prop_anon($prop,'extra') !== true && $obj->prop_anon($prop,'cond') === null){
				$result[] = "primary[".$prop."]=".$obj->{$prop}();
			}
		}
		return implode("&",$result);
	}
	public function primary_hidden(\ebi\Dao $obj){
		$result = array();		
		foreach(array_keys($obj->props()) as $prop){
			if($obj->prop_anon($prop,'primary') === true && $obj->prop_anon($prop,'extra') !== true && $obj->prop_anon($prop,'cond') === null){
				$result[] = '<input type="hidden" name="primary['.$prop.']" value="'.$obj->{$prop}().'" />';
			}
		}
		return implode("&",$result);
	}
	public function has_primary($obj){
		foreach(array_keys($obj->props()) as $prop){
			if($obj->prop_anon($prop,'primary') === true){
				return true;
			}
		}
		return false;
	}
	public function filter(\ebi\Dao $obj,$name){
		if($obj->prop_anon($name,'master') !== null){
			$options = array();
			$options[] = '<option value=""></option>';
			$master = $obj->prop_anon($name,'master');
			if(!empty($master)){
				$master = str_replace('.',"\\",$master);
				if($master[0] !== "\\") $master = "\\".$master;
				$r = new \ReflectionClass($master);
				$mo = $r->newInstanceArgs();
				$primarys = $mo->primary_columns();
				if(sizeof($primarys) != 1) return sprintf('<input name="%s" type="text" />',$name);
				foreach($primarys as $primary) break;
				$pri = $primary->name();
				foreach($master::find() as $dao){
					$options[] = sprintf('<option value="%s">%s</option>',$dao->{$pri}(),(string)$dao);
				}
			}
			return sprintf('<select name="%s">%s</select>',$name,implode('',$options));
		}else{
			$type = $obj->prop_anon($name,'type');
			switch($type){
				case 'boolean':
					$options = array();
					$options[] = '<option value=""></option>';
					foreach(array('true','false') as $choice) $options[] = sprintf('<option value="%s">%s</option>',$choice,$choice);
					return sprintf('<select name="search_%s_%s">%s</select>',$type,$name,implode('',$options));
				case 'timestamp':
				case 'date':
					return sprintf('<input name="search_%s_from_%s" type="text" class="span2" />',$type,$name).' : '.sprintf('<input name="search_%s_to_%s" type="text" class="span2" />',$type,$name);
				default:
					return sprintf('<input name="search_%s_%s" type="text"　/>',$type,$name);
			}
		}
	}
	
	public function form(\ebi\Dao $obj,$name){
		if(method_exists($obj,'form_'.$name)){
			return $obj->{'form_'.$name}();
		}else if($obj->prop_anon($name,'master') !== null){
			$options = array();
			if(!$obj->prop_anon($name,'require')) $options[] = '<option value=""></option>';
			$master = $obj->prop_anon($name,'master');
			if(!empty($master)){
				$master = str_replace('.',"\\",$master);
				if($master[0] !== "\\") $master = "\\".$master;
	
				try{
					$r = new \ReflectionClass($master);
				}catch(\ReflectionException $e){
					$self = new \ReflectionClass(get_class($obj));
					$r = new \ReflectionClass("\\".$self->getNamespaceName().$master);
				}
				$mo = $r->newInstanceArgs();
				$primarys = $mo->primary_columns();
				if(sizeof($primarys) != 1) return sprintf('<input name="%s" type="text" class="form-control" />',$name);
				foreach($primarys as $primary) break;
				$pri = $primary->name();
				foreach(call_user_func_array(array($mo,'find'),array()) as $dao){
					$options[] = sprintf('<option value="%s">%s</option>',$dao->{$pri}(),(string)$dao);
				}
			}
			return sprintf('<select name="%s" class="form-control">%s</select>',$name,implode('',$options));
		}else if($obj->prop_anon($name,'save',true)){
			switch($obj->prop_anon($name,'type')){
				case 'serial': return sprintf('<input name="%s" type="text" disabled="disabled" class="form-control" /><input name="%s" type="hidden" />',$name,$name);
				case 'text': return sprintf('<textarea name="%s" class="form-control" style="height:10em;"></textarea>',$name);
				case 'boolean':
					$options = array();
					if(!$obj->prop_anon($name,'require')) $options[] = '<option value=""></option>';
					foreach(array('true','false') as $choice){
						$options[] = sprintf('<option value="%s">%s</option>',$choice,$choice);
					}
					return sprintf('<select name="%s" class="form-control">%s</select>',$name,implode('',$options));
				default:
					return sprintf('<input name="%s" type="text" format="%s" class="form-control" />',$name,$obj->prop_anon($name,'type'));
			}
		}
	}

	public function dump($obj){
		$result = [];
		foreach($obj as $k => $v){
			if(isset($obj[$k])){
				if(!is_array($obj[$k]) || !empty($obj[$k])){
					$result[$k] = $v;
				}
			}
		}
		ob_start();
			var_dump($result);
		$value = ob_get_clean();
		$value = str_replace('=>'.PHP_EOL,': ',trim($value));
		$value = preg_replace('/\[\d+\]/','&nbsp;&nbsp;\\0',$value);
		return implode(PHP_EOL,array_slice(explode(PHP_EOL,$value),1,-1));
	}

}