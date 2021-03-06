<?php
/**
 * 이 파일은 iModule 게시판모듈의 일부입니다. (https://www.imodule.kr)
 *
 * 게시판과 관련된 모든 기능을 제어한다.
 * 
 * @file /modules/board/ModuleBoard.class.php
 * @author Arzz (arzz@arzz.com)
 * @license MIT License
 * @version 3.0.0.161211
 */
class ModuleBoard {
	/**
	 * iModule 및 Module 코어클래스
	 */
	private $IM;
	private $Module;
	
	/**
	 * DB 관련 변수정의
	 *
	 * @private object $DB DB접속객체
	 * @private string[] $table DB 테이블 별칭 및 원 테이블명을 정의하기 위한 변수
	 */
	private $DB;
	private $table;
	
	/**
	 * 언어셋을 정의한다.
	 * 
	 * @private object $lang 현재 사이트주소에서 설정된 언어셋
	 * @private object $oLang package.json 에 의해 정의된 기본 언어셋
	 */
	private $lang = null;
	private $oLang = null;
	
	/**
	 * DB접근을 줄이기 위해 DB에서 불러온 데이터를 저장할 변수를 정의한다.
	 *
	 * @private $members 회원정보
	 * @private $labels 라벨정보
	 * @private $memberPages 회원관련 컨텍스트를 사용하고 있는 사이트메뉴 정보
	 * @private $logged 현재 로그인한 회원정보
	 */
	private $boards = array();
	private $categorys = array();
	private $posts = array();
	private $ments = array();
	
	/**
	 * class 선언
	 *
	 * @param iModule $IM iModule 코어클래스
	 * @param Module $Module Module 코어클래스
	 * @see /classes/iModule.class.php
	 * @see /classes/Module.class.php
	 */
	function __construct($IM,$Module) {
		/**
		 * iModule 및 Module 코어 선언
		 */
		$this->IM = $IM;
		$this->Module = $Module;
		
		/**
		 * 모듈에서 사용하는 DB 테이블 별칭 정의
		 * @see 모듈폴더의 package.json 의 databases 참고
		 */
		$this->table = new stdClass();
		$this->table->board = 'board_table';
		$this->table->category = 'board_category_table';
		$this->table->post = 'board_post_table';
		$this->table->ment = 'board_ment_table';
		$this->table->ment_depth = 'board_ment_depth_table';
		$this->table->attachment = 'board_attachment_table';
		$this->table->history = 'board_history_table';
	}
	
	/**
	 * 모듈 코어 클래스를 반환한다.
	 * 현재 모듈의 각종 설정값이나 모듈의 package.json 설정값을 모듈 코어 클래스를 통해 확인할 수 있다.
	 *
	 * @return Module $Module
	 */
	function getModule() {
		return $this->Module;
	}
	
	/**
	 * 모듈 설치시 정의된 DB코드를 사용하여 모듈에서 사용할 전용 DB클래스를 반환한다.
	 *
	 * @return DB $DB
	 */
	function db() {
		if ($this->DB == null || $this->DB->ping() === false) $this->DB = $this->IM->db($this->getModule()->getInstalled()->database);
		return $this->DB;
	}
	
	/**
	 * 모듈에서 사용중인 DB테이블 별칭을 이용하여 실제 DB테이블 명을 반환한다.
	 *
	 * @param string $table DB테이블 별칭
	 * @return string $table 실제 DB테이블 명
	 */
	function getTable($table) {
		return empty($this->table->$table) == true ? null : $this->table->$table;
	}
	
	/**
	 * [코어] 사이트 외부에서 현재 모듈의 API를 호출하였을 경우, API 요청을 처리하기 위한 함수로 API 실행결과를 반환한다.
	 * 소스코드 관리를 편하게 하기 위해 각 요쳥별로 별도의 PHP 파일로 관리한다.
	 *
	 * @param string $api API명
	 * @return object $datas API처리후 반환 데이터 (해당 데이터는 /api/index.php 를 통해 API호출자에게 전달된다.)
	 * @see /api/index.php
	 */
	function getApi($api) {
		$data = new stdClass();
		
		/**
		 * 이벤트를 호출한다.
		 */
		$this->IM->fireEvent('beforeGetApi','board',$api,$values,null);
		
		/**
		 * 모듈의 api 폴더에 $api 에 해당하는 파일이 있을 경우 불러온다.
		 */
		if (is_file($this->getModule()->getPath().'/api/'.$api.'.php') == true) {
			INCLUDE $this->getModule()->getPath().'/api/'.$api.'.php';
		}
		
		return $data;
	}
	
	/**
	 * [코어] 알림메세지를 구성한다.
	 *
	 * @param string $code 알림코드
	 * @param int $fromcode 알림이 발생한 대상의 고유값
	 * @param array $content 알림데이터
	 * @return string $push 알림메세지
	 */
	function getPush($code,$fromcode,$content) {
		$latest = array_pop($content);
		$count = count($content);
		
		$push = new stdClass();
		$push->image = null;
		$push->link = null;
		if ($count > 0) $push->content = $this->getText('push/'.$code.'s');
		else $push->content = $this->getText('push/'.$code);
		
		if ($code == 'ment') {
			$ment = $this->getMent($latest->idx);
			if ($ment == null) {
				$from = $this->IM->getModule('member')->getMember(0)->nickname;
				$push->image = $this->IM->getModule('member')->getMember(0)->photo;
			} else {
				$from = $ment->name;
				$push->image = $this->IM->getModule('member')->getMember($ment->midx)->photo;
			}
			$post = $this->getPost($fromcode);
			if ($post == null) {
				$title = $this->getText('error/notFound');
				$push->link = null;
			} else {
				$title = GetCutString($post->title,15);
				$page = $this->getPostPage($post->idx);
				$push->link = $this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain);
			}
			$push->content = str_replace(array('{from}','{title}'),array('<b>'.$from.'</b>','<b>'.$title.'</b>'),$push->content);
		}
		
		if ($code == 'replyment') {
			$ment = $this->getMent($latest->idx);
			if ($ment == null) {
				$from = $this->IM->getModule('member')->getMember(0)->nickname;
				$push->image = $this->IM->getModule('member')->getMember(0)->photo;
			} else {
				$from = $ment->name;
				$push->image = $this->IM->getModule('member')->getMember($ment->midx)->photo;
			}
			$post = $this->getPost($fromcode);
			if ($post == null) {
				$title = $this->getText('error/notFound');
				$push->link = null;
			} else {
				$title = GetCutString($post->title,15);
				$page = $this->getPostPage($post->idx);
				$push->link = $this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain);
			}
			$push->content = str_replace(array('{from}','{title}'),array('<b>'.$from.'</b>','<b>'.$title.'</b>'),$push->content);
		}
		
		if ($code == 'post_good' || $code == 'post_bad') {
			$from = $this->IM->getModule('member')->getMember($latest->from)->nickname;
			$push->image = $this->IM->getModule('member')->getMember($latest->from)->photo;
			
			if ($code == 'post_bad') {
				$from = '';
				$push->image = $push->image = $this->IM->getModule('member')->getMember(0)->photo;
			}
			
			$post = $this->getPost($fromcode);
			if ($post == null) {
				$title = $this->getText('error/notFound');
				$push->link = null;
			} else {
				$title = GetCutString($post->title,15);
				$page = $this->getPostPage($post->idx);
				$push->link = $this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain);
			}
			$push->content = str_replace(array('{from}','{title}'),array('<b>'.$from.'</b>','<b>'.$title.'</b>'),$push->content);
		}
		
		if ($code == 'ment_good' || $code == 'ment_bad') {
			$from = $this->IM->getModule('member')->getMember($latest->from)->nickname;
			$push->image = $this->IM->getModule('member')->getMember($latest->from)->photo;
			
			if ($code == 'ment_bad') {
				$from = '';
				$push->image = $push->image = $this->IM->getModule('member')->getMember(0)->photo;
			}
			
			$ment = $this->getMent($fromcode);
			$post = $ment != null ? $this->getPost($ment->parent) : null;
			if ($post == null) {
				$title = $this->getText('error/notFound');
				$push->link = null;
			} else {
				$title = GetCutString($post->title,15);
				$page = $this->getPostPage($post->idx);
				$push->link = $this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain);
			}
			$push->content = str_replace(array('{from}','{title}'),array('<b>'.$from.'</b>','<b>'.$title.'</b>'),$push->content);
		}
		
		if ($code == 'post_modify') {
			$from = $latest->from;
			$push->image = $this->IM->getModule('member')->getMember(0)->photo;
			
			$post = $this->getPost($fromcode);
			if ($post == null) {
				$title = $this->getText('error/notFound');
				$push->link = null;
			} else {
				$title = GetCutString($post->title,15);
				$page = $this->getPostPage($post->idx);
				$push->link = $this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain);
			}
			$push->content = str_replace(array('{from}','{title}'),array('<b>'.$from.'</b>','<b>'.$title.'</b>'),$push->content);
		}
		
		if ($code == 'ment_modify') {
			$from = $latest->from;
			$push->image = $this->IM->getModule('member')->getMember(0)->photo;
			
			$ment = $this->getMent($fromcode);
			$post = $ment != null ? $this->getPost($ment->parent) : null;
			
			if ($post == null) {
				$title = $this->getText('error/notFound');
				$push->link = null;
			} else {
				$title = GetCutString($post->title,15);
				$page = $this->getPostPage($post->idx);
				$push->link = $this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain);
			}
			$push->content = str_replace(array('{from}','{title}'),array('<b>'.$from.'</b>','<b>'.$title.'</b>'),$push->content);
		}
		
		if ($code == 'post_delete' || $code == 'ment_delete') {
			$push->image = $this->IM->getModule('member')->getMember(0)->photo;
			$push->link = null;
			$title = GetCutString($latest->title,15);
			$push->content = str_replace(array('{from}','{title}'),array('<b>'.$from.'</b>','<b>'.$title.'</b>'),$push->content);
		}
		
		$push->content = str_replace('{count}','<b>'.$count.'</b>',$push->content);
		return $push;
	}
	
	/**
	 * [코어] 포인트내역 메세지를 구성한다.
	 *
	 * @param string $code 포인트코드
	 * @param array $content 포인트데이터
	 * @return string $point 포인트메세지
	 */
	function getPoint($code,$content) {
		$point = new stdClass();
		$point = $this->getText('point/'.$code);
		
		if ($code == 'post') {
			$post = $this->getPost($content->idx);
			
			if ($post != null) {
				$page = $this->getPostPage($post->idx);
				$title = '<a href="'.$this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain).'" target="_blank">['.GetCutString($post->title,20).']</a>';
			} else {
				$title = '['.$this->getText('error/notFound').']';
			}
			
			$point = str_replace('{title}','<b>'.$title.'</b>',$point);
		}
		
		if ($code == 'post_delete') {
			$title = GetCutString($content->title,20);
			
			$point = str_replace('{title}','<b>'.$title.'</b>',$point);
		}
		
		if ($code == 'post_good' || $code == 'post_bad') {
			$post = $this->getPost($content->idx);
			
			if ($post != null) {
				$page = $this->getPostPage($post->idx);
				$title = '<a href="'.$this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain).'" target="_blank">['.GetCutString($post->title,20).']</a>';
			} else {
				$title = '['.$this->getText('error/notFound').']';
			}
			
			$point = str_replace('{title}','<b>'.$title.'</b>',$point);
		}
		
		if ($code == 'ment') {
			$ment = $this->getMent($content->idx);
			$post = $ment != null ? $this->getPost($ment->parent) : null;
			
			if ($post != null) {
				$page = $this->getPostPage($post->idx);
				$title = '<a href="'.$this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain).'" target="_blank">['.GetCutString($post->title,20).']</a>';
			} else {
				$title = '['.$this->getText('error/notFound').']';
			}
			
			$point = str_replace('{title}','<b>'.$title.'</b>',$point);
		}
		
		if ($code == 'ment_delete') {
			$title = GetCutString($content->title,20);
			
			$point = str_replace('{title}','<b>'.$title.'</b>',$point);
		}
		
		if ($code == 'ment_good' || $code == 'ment_bad') {
			$ment = $this->getMent($content->idx);
			$post = $ment != null ? $this->getPost($ment->parent) : null;
			
			if ($post != null) {
				$page = $this->getPostPage($post->idx);
				$title = '<a href="'.$this->IM->getUrl($page->menu,$page->page,'view',$post->idx,false,$page->domain).'" target="_blank">['.GetCutString($ment->search,20).']</a>';
			} else {
				$title = '['.$this->getText('error/notFound').']';
			}
			
			$point = str_replace('{title}','<b>'.$title.'</b>',$point);
		}
		
		return $point;
	}
	
	/**
	 * [사이트관리자] 모듈 설정패널을 구성한다.
	 *
	 * @return string $panel 설정패널 HTML
	 *
	function getConfigPanel() {
		/**
		 * 설정패널 PHP에서 iModule 코어클래스와 모듈코어클래스에 접근하기 위한 변수 선언
		 *
		$IM = $this->IM;
		$Module = $this->getModule();
		
		ob_start();
		INCLUDE $this->getModule()->getPath().'/admin/configs.php';
		$panel = ob_get_contents();
		ob_end_clean();
		
		return $panel;
	}*/
	
	/**
	 * [사이트관리자] 모듈 관리자패널 구성한다.
	 *
	 * @return string $panel 관리자패널 HTML
	 */
	function getAdminPanel() {
		/**
		 * 설정패널 PHP에서 iModule 코어클래스와 모듈코어클래스에 접근하기 위한 변수 선언
		 */
		$IM = $this->IM;
		$Module = $this;
		
		ob_start();
		INCLUDE $this->getModule()->getPath().'/admin/index.php';
		$panel = ob_get_contents();
		ob_end_clean();
		
		return $panel;
	}
	
	/**
	 * [사이트관리자] 모듈의 전체 컨텍스트 목록을 반환한다.
	 *
	 * @return object $lists 전체 컨텍스트 목록
	 */
	function getContexts() {
		$lists = $this->db()->select($this->table->board,'bid,title')->get();
		
		for ($i=0,$loop=count($lists);$i<$loop;$i++) {
			$lists[$i] = array('context'=>$lists[$i]->bid,'title'=>$lists[$i]->title);
		}
		
		return $lists;
	}
	
	/**
	 * [사이트관리자] 모듈의 컨텍스트 환경설정을 구성한다.
	 *
	 * @param object $site 설정대상 사이트
	 * @param string $context 설정대상 컨텍스트명
	 * @return object[] $configs 환경설정
	 */
	function getContextConfigs($site,$context) {
		$configs = array();
		
		$templet = new stdClass();
		$templet->title = $this->IM->getText('text/templet');
		$templet->name = 'templet';
		$templet->type = 'select';
		$templet->data = array();
		
		$templet->data[] = array('#',$this->getText('admin/configs/form/default_setting'));
		
		$templets = $this->getModule()->getTemplets();
		for ($i=0, $loop=count($templets);$i<$loop;$i++) {
			$templet->data[] = array($templets[$i]->getName(),$templets[$i]->getTitle().' ('.$templets[$i]->getDir().')');
		}
		
		$templet->value = count($templet->data) > 0 ? $templet->data[0][0] : '#';
		$configs[] = $templet;
		
		$category = new stdClass();
		$category->title = $this->getText('category');
		$category->name = 'category';
		$category->type = 'select';
		$category->data = array();
		$category->data[] = array(0,$this->getText('category_all'));
		$categorys = $this->db()->select($this->table->category,'idx,title')->where('bid',$context)->orderBy('sort','asc')->get();
		for ($i=0, $loop=count($categorys);$i<$loop;$i++) {
			$category->data[] = array($categorys[$i]->idx,$categorys[$i]->title);
		}
		$category->value = 0;
		$configs[] = $category;
		
		return $configs;
	}
	
	/**
	 * 언어셋파일에 정의된 코드를 이용하여 사이트에 설정된 언어별로 텍스트를 반환한다.
	 * 코드에 해당하는 문자열이 없을 경우 1차적으로 package.json 에 정의된 기본언어셋의 텍스트를 반환하고, 기본언어셋 텍스트도 없을 경우에는 코드를 그대로 반환한다.
	 *
	 * @param string $code 언어코드
	 * @param string $replacement 일치하는 언어코드가 없을 경우 반환될 메세지 (기본값 : null, $code 반환)
	 * @return string $language 실제 언어셋 텍스트
	 */
	function getText($code,$replacement=null) {
		if ($this->lang == null) {
			if (is_file($this->getModule()->getPath().'/languages/'.$this->IM->language.'.json') == true) {
				$this->lang = json_decode(file_get_contents($this->getModule()->getPath().'/languages/'.$this->IM->language.'.json'));
				if ($this->IM->language != $this->getModule()->getPackage()->language && is_file($this->getModule()->getPath().'/languages/'.$this->getModule()->getPackage()->language.'.json') == true) {
					$this->oLang = json_decode(file_get_contents($this->getModule()->getPath().'/languages/'.$this->getModule()->getPackage()->language.'.json'));
				}
			} elseif (is_file($this->getModule()->getPath().'/languages/'.$this->getModule()->getPackage()->language.'.json') == true) {
				$this->lang = json_decode(file_get_contents($this->getModule()->getPath().'/languages/'.$this->getModule()->getPackage()->language.'.json'));
				$this->oLang = null;
			}
		}
		
		$returnString = null;
		$temp = explode('/',$code);
		
		$string = $this->lang;
		for ($i=0, $loop=count($temp);$i<$loop;$i++) {
			if (isset($string->{$temp[$i]}) == true) {
				$string = $string->{$temp[$i]};
			} else {
				$string = null;
				break;
			}
		}
		
		if ($string != null) {
			$returnString = $string;
		} elseif ($this->oLang != null) {
			if ($string == null && $this->oLang != null) {
				$string = $this->oLang;
				for ($i=0, $loop=count($temp);$i<$loop;$i++) {
					if (isset($string->{$temp[$i]}) == true) {
						$string = $string->{$temp[$i]};
					} else {
						$string = null;
						break;
					}
				}
			}
			
			if ($string != null) $returnString = $string;
		}
		
		/**
		 * 언어셋 텍스트가 없는경우 iModule 코어에서 불러온다.
		 */
		if ($returnString != null) return $returnString;
		elseif (in_array(reset($temp),array('text','button','action')) == true) return $this->IM->getText($code,$replacement);
		else return $replacement == null ? $code : $replacement;
	}
	
	/**
	 * 상황에 맞게 에러코드를 반환한다.
	 *
	 * @param string $code 에러코드
	 * @param object $value(옵션) 에러와 관련된 데이터
	 * @param boolean $isRawData(옵션) RAW 데이터 반환여부
	 * @return string $message 에러 메세지
	 */
	function getErrorText($code,$value=null,$isRawData=false) {
		$message = $this->getText('error/'.$code,$code);
		if ($message == $code) return $this->IM->getErrorText($code,$value,null,$isRawData);
		
		$description = null;
		switch ($code) {
			case 'NOT_ALLOWED_SIGNUP' :
				if ($value != null && is_object($value) == true) {
					$description = $value->title;
				}
				break;
				
			case 'DISABLED_LOGIN' :
				if ($value != null && is_numeric($value) == true) {
					$description = str_replace('{SECOND}',$value,$this->getText('text/remain_time_second'));
				}
				break;
			
			default :
				if (is_object($value) == false && $value) $description = $value;
		}
		
		$error = new stdClass();
		$error->message = $message;
		$error->description = $description;
		
		if ($isRawData === true) return $error;
		else return $this->IM->getErrorText($error);
	}
	
	/**
	 * 특정 컨텍스트에 대한 제목을 반환한다.
	 *
	 * @param string $context 컨텍스트명
	 * @return string $title 컨텍스트 제목
	 */
	function getContextTitle($context) {
		return $this->getText('admin/contexts/'.$context);
	}
	
	/**
	 * 사이트맵에 나타날 뱃지데이터를 생성한다.
	 *
	 * @param string $context 컨텍스트종류
	 * @param object $configs 사이트맵 관리를 통해 설정된 페이지 컨텍스트 설정
	 * @return object $badge 뱃지데이터 ($badge->count : 뱃지숫자, $badge->latest : 뱃지업데이트 시각(UNIXTIME), $badge->text : 뱃지텍스트)
	 * @todo check count information
	 */
	function getContextBadge($context,$config) {
		/**
		 * null 일 경우 뱃지를 표시하지 않는다.
		 */
		return null;
	}
	
	/**
	 * 템플릿 정보를 가져온다.
	 *
	 * @param string $this->getTemplet($configs) 템플릿명
	 * @return string $package 템플릿 정보
	 */
	function getTemplet($templet=null) {
		$templet = $templet == null ? '#' : $templet;
		
		/**
		 * 사이트맵 관리를 통해 설정된 페이지 컨텍스트 설정일 경우
		 */
		if (is_object($templet) == true) {
			$templet = $templet !== null && isset($templet->templet) == true ? $templet->templet : '#';
		}
		
		/**
		 * 템플릿명이 # 이면 모듈 기본설정에 설정된 템플릿을 사용한다.
		 */
		$templet = $templet == '#' ? $this->getModule()->getConfig('templet') : $templet;
		return $this->getModule()->getTemplet($templet);
	}
	
	/**
	 * 페이지 컨텍스트를 가져온다.
	 *
	 * @param string $context 컨테이너 종류
	 * @param object $configs 사이트맵 관리를 통해 설정된 페이지 컨텍스트 설정
	 * @return string $html 컨텍스트 HTML
	 */
	function getContext($bid,$configs=null) {
		/**
		 * 모듈 기본 스타일 및 자바스크립트
		 */
		$this->IM->addHeadResource('style',$this->getModule()->getDir().'/styles/style.css');
		$this->IM->addHeadResource('script',$this->getModule()->getDir().'/scripts/script.js');
		
		$values = new stdClass();
		
		$view = $this->IM->view == '' ? 'list' : $this->IM->view;
		$board = $this->getBoard($bid);
		if ($board == null) return $this->getTemplet($configs)->getError('NOT_FOUND_PAGE');
		
		if ($configs == null) $configs = new stdClass();
		if (isset($configs->templet) == false) $configs->templet = '#';
		if ($configs->templet == '#') $configs->templet = $board->templet;

		$html = PHP_EOL.'<!-- BOARD MODULE -->'.PHP_EOL.'<div data-role="context" data-type="module" data-module="board" data-bid="'.$bid.'" data-view="'.$view.'">'.PHP_EOL;
		$html.= $this->getHeader($bid,$configs);
		
		switch ($view) {
			case 'list' :
				$html.= $this->getListContext($bid,$configs);
				break;
			
			// post view context
			case 'view' :
				$html.= $this->getViewContext($bid,$configs);
				break;
			
			// write / modify post context
			case 'write' :
				$html.= $this->getWriteContext($bid,$configs);
				break;
		}
		
		$html.= $this->getFooter($bid,$configs);
		
		/**
		 * 컨텍스트 컨테이너를 설정한다.
		 */
		$html.= PHP_EOL.'</div>'.PHP_EOL.'<!--// BOARD MODULE -->'.PHP_EOL;
		
		return $html;
	}
	
	/**
	 * 컨텍스트 헤더를 가져온다.
	 *
	 * @param string $bid 게시판 ID
	 * @param object $configs 사이트맵 관리를 통해 설정된 페이지 컨텍스트 설정
	 * @return string $html 컨텍스트 HTML
	 */
	function getHeader($bid,$configs=null) {
		$board = $this->getBoard($bid);
		
		/**
		 * 템플릿파일을 호출한다.
		 */
		return $this->getTemplet($configs)->getHeader(get_defined_vars());
	}
	
	/**
	 * 컨텍스트 푸터를 가져온다.
	 *
	 * @param string $context 컨테이너 종류
	 * @param object $configs 사이트맵 관리를 통해 설정된 페이지 컨텍스트 설정
	 * @return string $html 컨텍스트 HTML
	 */
	function getFooter($context,$configs=null) {
		/**
		 * 템플릿파일을 호출한다.
		 */
		return $this->getTemplet($configs)->getFooter(get_defined_vars());
	}
	
	/**
	 * 에러메세지를 반환한다.
	 *
	 * @param string $code 에러코드 (에러코드는 iModule 코어에 의해 해석된다.)
	 * @param object $value 에러코드에 따른 에러값
	 * @return $html 에러메세지 HTML
	 */
	function getError($code,$value=null) {
		/**
		 * iModule 코어를 통해 에러메세지를 구성한다.
		 */
		$error = $this->getErrorText($code,$value,true);
		return $this->IM->getError($error);
	}
	
	/**
	 * 게시물 목록 컨텍스트를 가져온다.
	 *
	 * @param string $bid 게시판 ID
	 * @param object $configs 사이트맵 관리를 통해 설정된 페이지 컨텍스트 설정
	 * @return string $html 컨텍스트 HTML
	 */
	function getListContext($bid,$configs=null) {
		if ($this->checkPermission($bid,'list') == false) return $this->getTemplet($configs)->getError('FORBIDDEN');
		
		$this->IM->addHeadResource('meta',array('name'=>'robots','content'=>'noidex,follow'));
		
		$board = $this->getBoard($bid);
		
		$lists = $this->db()->select($this->table->post.' p','p.*')->where('p.bid',$bid);
		$total = $lists->copy()->count();
		
		$idx = Request('idx') ? explode('/',Request('idx')) : array(1);
		$category = null;
		if (count($idx) == 2) list($category,$p) = $idx;
		elseif (count($idx) == 1) list($p) = $idx;
		
		if ($this->IM->view == 'view') $idx = $p;
		else $idx = 0;
		if ($configs != null && isset($configs->p) == true) $p = $configs->p;
		
		$limit = $board->post_limit;
		$start = ($p - 1) * $limit;
		
		$sort = Request('sort') ? Request('sort') : 'idx';
		$dir = Request('dir') ? Request('dir') : 'asc';
		$lists = $lists->orderBy($sort,$dir)->limit($start,$limit)->get();
		
		$loopnum = $total - ($p - 1) * $limit;
		for ($i=0, $loop=count($lists);$i<$loop;$i++) {
			$lists[$i] = $this->getPost($lists[$i]);
			$lists[$i]->loopnum = $loopnum - $i;
			$lists[$i]->link = $this->IM->getUrl(null,null,'view',($category == null ? '' : $category.'/').$lists[$i]->idx).$this->IM->getQueryString();
		}
		
		$pagination = $this->getTemplet($configs)->getPagination($p,ceil($total/$limit),$board->page_limit,$this->IM->getUrl(null,null,'list',($category == null ? '' : $category.'/').'{PAGE}'),$board->page_type);
		
		$link = new stdClass();
		$link->list = $this->IM->getUrl(null,null,'list',($category == null ? '' : $category.'/').$p);
		$link->write = $this->IM->getUrl(null,null,'write',false);
		
		$header = PHP_EOL.'<form id="ModuleBoardListForm">'.PHP_EOL;
		$footer = PHP_EOL.'</form>'.PHP_EOL.'<script>Board.list.init("ModuleBoardListForm");</script>'.PHP_EOL;
		
		/**
		 * 템플릿파일을 호출한다.
		 */
		return $this->getTemplet($configs)->getContext('list',get_defined_vars(),$header,$footer);
	}
	
	/**
	 * 게시물 보기 컨텍스트를 가져온다.
	 *
	 * @param string $bid 게시판 ID
	 * @param object $configs 사이트맵 관리를 통해 설정된 페이지 컨텍스트 설정
	 * @return string $html 컨텍스트 HTML
	 */
	function getViewContext($bid,$configs=null) {
		if ($this->checkPermission($bid,'view') == false) return $this->getTemplet($configs)->getError('FORBIDDEN');
		
		$this->IM->addHeadResource('meta',array('name'=>'robots','content'=>'idx,nofollow'));
		
		$board = $this->getBoard($bid);
		
		$idx = Request('idx') ? explode('/',Request('idx')) : array(0);
		$category = null;
		if (count($idx) == 2) list($category,$idx) = $idx;
		elseif (count($idx) == 1) list($idx) = $idx;
		
		$post = $this->getPost($idx);
		if ($post == null) return $this->getTemplet($configs)->getError('NOT_FOUND_PAGE');
		
		/**
		 * 현재 게시물이 속한 페이지를 구한다.
		 */
		$sort = Request('sort') ? Request('sort') : 'idx';
		$dir = Request('dir') ? Request('dir') : 'asc';
		$previous = $this->db()->select($this->table->post.' p','p.*')->where('p.bid',$post->bid)->where('p.'.$sort,$post->{$sort},$dir == 'desc' ? '>=' : '<=');
		$previous = $previous->count();
		$p = ceil($previous/$board->post_limit);
		
		$configs = $configs == null ? new stdClass() : $configs;
		$configs->p = $p;
		
		$link = new stdClass();
		$link->list = $this->IM->getUrl(null,null,'list',($category == null ? '' : $category.'/').$p);
		$link->write = $this->IM->getUrl(null,null,'write',false);
		
		$header = PHP_EOL.'<form id="ModuleBoardViewForm">'.PHP_EOL;
		$header.= '<input type="hidden" name="idx" value="'.$idx.'">'.PHP_EOL;
		$footer = PHP_EOL.'</form>'.PHP_EOL.'<script>Board.view.init("ModuleBoardViewForm");</script>';
		$footer.= $this->getListContext($bid,$configs);
		
		
		
		/**
		 * 템플릿파일을 호출한다.
		 */
		return $this->getTemplet($configs)->getContext('view',get_defined_vars(),$header,$footer);
	}
	
	/**
	 * 게시물 작성 컨텍스트를 가져온다.
	 *
	 * @param string $bid 게시판 ID
	 * @param object $configs 사이트맵 관리를 통해 설정된 페이지 컨텍스트 설정
	 * @return string $html 컨텍스트 HTML
	 */
	function getWriteContext($bid,$configs=null) {
		if ($this->checkPermission($bid,'post_write') == false) return $this->getTemplet($configs)->getError('FORBIDDEN');
		
		$this->IM->addHeadResource('meta',array('name'=>'robots','content'=>'noidex,nofollow'));
		
		$board = $this->getBoard($bid);
		$idx = Request('idx');
		
		/**
		 * 게시물 수정
		 */
		if ($idx !== null) {
			$post = $this->getPost($idx);
			
			if ($post == null) {
				header("HTTP/1.1 404 Not Found");
				return $this->getError($this->getLanguage('error/notFound'));
			}
			
			if ($this->checkPermission($bid,'post_modify') == false) {
				if ($post->midx != 0 && $post->midx != $this->IM->getModule('member')->getLogged()) {
					header("HTTP/1.1 403 Forbidden");
					return $this->getError($this->getLanguage('error/forbidden'));
				} elseif ($post->midx == 0) {
					$password = Request('password');
					$mHash = new Hash();
					if ($mHash->password_validate($password,$post->password) == false) {
						header("HTTP/1.1 403 Forbidden");
					
						$context = $this->getError($this->getLanguage('error/incorrectPassword'));
						$context.= PHP_EOL.'<script>Board.post.modify('.$idx.');</script>'.PHP_EOL;
						
						return $context;
					}
				}
			}
			
			$post->content = $this->getArticleContent($post->content);
			$post->is_notice = $post->is_notice == 'TRUE' ? true : false;
			$post->is_html_title = $post->is_html_title == 'TRUE' ? true : false;
			$post->is_secret = $post->is_secret == 'TRUE' ? true : false;
			$post->is_hidename = $post->is_hidename == 'TRUE' ? true : false;
			
			$post->attachments = $this->db()->select($this->table->attachment)->where('parent',$idx)->where('type','POST')->get();
			for ($i=0, $loop=count($post->attachments);$i<$loop;$i++) {
				$post->attachments[$i] = $post->attachments[$i]->idx;
			}
		} else {
			$post = null;
		}
		
		$header = PHP_EOL.'<form id="ModuleBoardWriteForm-'.$bid.'" data-autosave="true">'.PHP_EOL;
		$header.= '<input type="hidden" name="bid" value="'.$bid.'">'.PHP_EOL;
		if ($post !== null) $header.= '<input type="hidden" name="idx" value="'.$post->idx.'">'.PHP_EOL;
		$footer = PHP_EOL.'</form>'.PHP_EOL.'<script>Board.write.init("ModuleBoardWriteForm-'.$bid.'");</script>'.PHP_EOL;
		
		$wysiwyg = $this->IM->getModule('wysiwyg')->setRequired(true)->setContent($post == null ? '' : $post->content)->get();
		
		/**
		 * 템플릿파일을 호출한다.
		 */
		return $this->getTemplet($configs)->getContext('write',get_defined_vars(),$header,$footer);
	}
	
	/**
	 * 게시판정보를 가져온다.
	 *
	 * @param string $bid
	 * @return object $board
	 */
	function getBoard($bid) {
		if (isset($this->boards[$bid]) == true) return $this->boards[$bid];
		$board = $this->db()->select($this->table->board)->where('bid',$bid)->getOne();
		if ($board == null) {
			$this->boards[$bid] = null;
		} else {
			$this->boards[$bid] = $board;
		}
		
		return $this->boards[$bid];
	}
	
	/**
	 * 게시물정보를 가져온다.
	 *
	 * @param int $idx 게시물고유번호
	 * @return object $post
	 */
	function getPost($idx) {
		if (is_null($idx) == true) return null;
		
		if (is_numeric($idx) == true) {
			if (isset($this->posts[$idx]) == true) return $this->posts[$idx];
			else return $this->getPost($this->db()->select($this->table->post)->where('idx',$idx)->getOne());
		} else {
			$post = $idx;
			if (isset($post->is_rendered) === true && $post->is_rendered === true) return $post;
			
			if ($post->midx == 0) {
				$post->nicname = $post->name;
			} else {
				$member = $this->IM->getModule('member')->getMember($post->midx);
				$post->name = $member->name;
				$post->nickname = $member->nickname;
			}
			
			
			$post->content = $this->IM->getModule('wysiwyg')->decodeContent($post->content);
			$post->is_rendered = true;
			
			$this->posts[$post->idx] = $post;
			return $this->posts[$post->idx];
		}
	}
	
	/**
	 * 권한을 확인한다.
	 *
	 * @param string $bid 게시판 ID
	 * @param string $type 확인할 권한코드
	 * @return boolean $hasPermssion
	 */
	function checkPermission($bid,$type) {
		$board = $this->getBoard($bid);
		$permission = json_decode($board->permission);
		
		if (isset($permission->{$type}) == false) return false;
		return $this->IM->parsePermissionString($permission->{$type});
	}
	
	/**
	 * 게시판 정보를 업데이트한다.
	 *
	 * @param string $bid 게시판 ID
	 */
	function updateBoard($bid) {
		$status = $this->db()->select($this->table->post,'COUNT(*) as total, MAX(reg_date) as latest')->where('bid',$bid)->getOne();
		$this->db()->update($this->table->board,array('post'=>$status->total,'latest_post'=>($status->latest ? $status->latest : 0)))->where('bid',$bid)->execute();
	}
	
	/**
	 * 게시물 정보를 업데이트한다.
	 *
	 * @param int $idx 게시물고유번호
	 */
	function updatePost($idx) {
		$status = $this->db()->select($this->table->ment,'COUNT(*) as total, MAX(reg_date) as latest')->where('parent',$idx)->where('is_delete','FALSE')->getOne();
		$this->db()->update($this->table->post,array('ment'=>$status->total,'latest_ment'=>($status->latest ? $status->latest : 0)))->where('idx',$idx)->execute();
	}
	
	/**
	 * 카테고리 정보를 업데이트한다.
	 *
	 * @param int $category 카테고리고유번호
	 */
	function updateCategory($category) {
		if ($category == 0) return;
		
		$status = $this->db()->select($this->table->post,'COUNT(*) as total, MAX(reg_date) as latest')->where('category',$category)->getOne();
		$this->db()->update($this->table->category,array('post'=>$status->total,'latest_post'=>($status->latest ? $status->latest : 0)))->where('idx',$category)->execute();
	}
	
	/**
	 * 현재 모듈에서 처리해야하는 요청이 들어왔을 경우 처리하여 결과를 반환한다.
	 * 소스코드 관리를 편하게 하기 위해 각 요쳥별로 별도의 PHP 파일로 관리한다.
	 * 작업코드가 '@' 로 시작할 경우 사이트관리자를 위한 작업으로 최고관리자 권한이 필요하다.
	 *
	 * @param string $action 작업코드
	 * @return object $results 수행결과
	 * @see /process/index.php
	 */
	function doProcess($action) {
		$results = new stdClass();
		
		/**
		 * 모듈의 process 폴더에 $action 에 해당하는 파일이 있을 경우 불러온다.
		 */
		if (is_file($this->getModule()->getPath().'/process/'.$action.'.php') == true) {
			INCLUDE $this->getModule()->getPath().'/process/'.$action.'.php';
		}
		
		$values = (object)get_defined_vars();
		$this->IM->fireEvent('afterDoProcess','member',$action,$values,$results);
		
		return $results;
	}
}
?>