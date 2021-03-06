<?php
/**
 * 이 파일은 iModule 게시판모듈의 일부입니다. (https://www.imodule.kr)
 * 
 * 모든 게시판목록을 불러온다.
 *
 * @file /modules/board/process/@getBoards.php
 * @author Arzz (arzz@arzz.com)
 * @license GPLv3
 * @version 3.0.0.160923
 *
 * @return object $results
 */
if (defined('__IM__') == false) exit;

$start = Request('start');
$limit = Request('limit');
$lists = $this->db()->select($this->table->board);
$total = $lists->copy()->count();
$lists = $lists->limit($start,$limit)->get();

for ($i=0, $loop=count($lists);$i<$loop;$i++) {
	
}

$results->success = true;
$results->lists = $lists;
$results->total = $total;
?>