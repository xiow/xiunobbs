<?php

/*
 * Copyright (C) xiuno.com
 */

!defined('FRAMEWORK_PATH') && exit('FRAMEWORK_PATH not defined.');

include BBS_PATH.'control/common_control.class.php';

class attach_control extends common_control {
	
	function __construct(&$conf) {
		parent::__construct($conf);
		$this->_checked['bbs'] = ' class="checked"';
		
		// 加载积分策略
		$this->conf += $this->kv->xget('conf_ext');
		
		// 检查IP 屏蔽
		$this->check_ip();
	}
	
	// 列表
	public function on_index() {
		//
	}
	
	// ajax 弹出下载对话框内容
	public function on_dialog() {
		$uid = $this->_user['uid'];
		
		$fid = intval(core::gpc('fid'));
		$aid = intval(core::gpc('aid'));
		$attach = $this->attach->read($fid, $aid);
		if(empty($attach)) $this->message('附件不存在。', 0);
		$this->attach->format($attach);
		
		// 权限检测
		$forum = $this->forum->read($fid);
		$this->check_forum_exists($forum);
		$havepriv = ($attach['uid'] == $uid || $this->check_access($forum, 'down'));	// 是否有权限下载。
		
		if($uid) {
			$user = $this->user->read($uid);
			$this->_user['golds'] = $user['golds'];
		} else {
			$this->_user['golds'] = 0;
		}
		
		$this->view->assign('attach', $attach);
		// hook attach_dialog_view_before.php
		$this->view->display('attach_dialog_ajax.htm');
	}
	
	public function on_download() {
		$uid = $this->_user['uid'];
		
		$fid = intval(core::gpc('fid'));
		$aid = intval(core::gpc('aid'));
		$attach = $this->attach->read($fid, $aid);
		if(empty($attach)) $this->message('附件不存在。');
		
		// hook attach_download_check_before.php
		
		// 权限检测
		$forum = $this->forum->read($fid);
		
		// 如果不是斑竹，并且不是自己，开始判断权限
		if(!$this->is_mod($forum, $this->_user) && $attach['uid'] != $uid) {
			if($forum['accesson']) {
				$access = $this->forum_access->read($forum['fid'], $this->_user['groupid']);
				if(!$access['allowdown']) {
					$this->message('您所在的用户组不允许在本板块('.$forum['name'].')下载附件。');
				}
			}
			
			if($attach['golds'] > 0) {
				if($uid) {
					$user = $this->user->read($uid);
					$down = $this->attach_download->read($uid, $fid, $aid);
					// 如果没有下载过
					if(empty($down)) {
						if($user['golds'] < $attach['golds']) {
							$this->view->assign('attach', $attach);
							$this->view->assign('user', $user);
							$this->view->display('attach_not_enough_money.htm');
							exit;
						}
						// 扣除金币
						$user['golds'] -= $attach['golds'];
						
						// 如果购买过，可以一直有权下载, uid, aid 为唯一索引
						$this->attach_download->create(array(
							'uid' => $uid,
							'fid' => $fid,
							'aid' => $aid,
							'uploaduid' => $attach['uid'],
							'dateline' => $_SERVER['time'],
							'golds' => $attach['golds'],
						));
						
						// 更新用户金币数
						$this->user->update($user);
						
						// 所有者加金币
						$owner = $this->user->read($attach['uid']);
						$owner['golds'] += $attach['golds'];
						$this->user->update($owner);
					}
				} else {
					$this->message('请登录以后再下载此附件。');
				}
			}
		}
			
		$attachpath = $this->conf['upload_path'].'attach/'.$attach['filename'];
		if(!is_file($attachpath)) {
			$this->message('附件不存在，如果有问题请联系管理员。');
		}
		$filesize = filesize($attachpath);
		
		$attach['downloads']++;
		$this->attach->update($attach);
		
		// 不管是否为收费附件，隐藏附件真实地址！主要是为了安全，盗链，IE图片解析。
		if($attach['golds'] > 0 || 1) {
			
			// 默认开启压缩加快下载速度！
			//$_SERVER['ob_no_gzip'] = 1;
			
			// 头部
			if(stripos($_SERVER["HTTP_USER_AGENT"], 'MSIE') !== FALSE) {
				$attach['orgfilename'] = urlencode($attach['orgfilename']);
				$attach['orgfilename'] = str_replace("+", "%20", $attach['orgfilename']);
			}
			$timefmt = date('D, d M Y H:i:s', $_SERVER['time']).' GMT';
			header('Date: '.$timefmt);
                        header('Last-Modified: '.$timefmt);
			header('Expires: '.$timefmt);
                       // header('Cache-control: max-age=0, must-revalidate, post-check=0, pre-check=0');
                        header('Cache-control: max-age=86400');
			header('Content-Transfer-Encoding: binary');
			header("Pragma: public");
			header('Content-Disposition: attachment; filename="'.$attach['orgfilename'].'"');
			header('Content-Type: application/octet-stream');
			//header("Content-Type: application/force-download");	// 后面的会覆盖前面
			
			// hook attach_download_gold_after.php
			readfile($this->conf['upload_path'].'attach/'.$attach['filename']);
			
			/*if($attach['filetype'] == 'image') {
				// ie6 下会解析图片内容！
				//header('Content-Disposition: inline; filename='.$attach['orgfilename']);
				//header('Content-Type: image/pjpeg');
			} else {
				header('Content-Disposition: attachment; filename='.$attach['orgfilename']);
				header('Content-Type: application/octet-stream');
			}*/
			
			exit;
		} else {
			
			// hook attach_download_free_after.php
			$this->attach->format($attach);
			header('Location: '.$this->conf['upload_url'].'attach/'.$attach['filename']);
			exit;
		}
	}
	
	
	// 接受所有文件 swfupload post ajax
	public function on_uploadimage() {
		$fid = intval(core::gpc('fid'));
		$pid = intval(core::gpc('pid'));
		$uid = $this->_user['uid'];
		$this->check_forbidden_group();
		
		$forum = $this->forum->read($fid);
		$user = $this->user->read($uid);
		
		$this->check_forum_exists($forum);
		$this->check_access($forum, 'attach');
		
		// hook attach_uploadimage_before.php

		if(isset($_FILES['Filedata']['tmp_name']) && is_file($_FILES['Filedata']['tmp_name'])) {
			$file = $_FILES['Filedata'];
			core::htmlspecialchars($file['name']);
			$filetype = $this->attach->get_filetype($file['name']);
			if($filetype != 'image') {
				$allowtypes = $this->attach->get_allow_filetypes();
				$this->message('请选择图片格式的文件，后缀名为：.gif, .jpg, .png, .bmp！', 0);
			}
			
			if(!$this->attach->is_safe_image($file['tmp_name'])) {
				$this->message('系统检测到图片('.$file['name'].')不是安全的，请更换其他图片试试。', 0);
			}
			
			$arr = array (
				'fid'=>$fid,
				'tid'=>0,
				'pid'=>$pid,
				'filesize'=>0,
				'width'=>0,
				'height'=>0,
				'filename'=>'',
				'orgfilename'=>$file['name'],
				'filetype'=>$filetype,
				'dateline'=>$_SERVER['time'],
				'comment'=>'',
				'downloads'=>0,
				'isimage'=>1,
				'golds'=>0,
				'uid'=>$this->_user['uid'],
			);
			$aid = $this->attach->create($arr);
			$this->attach->save_aid_to_tmp($fid, $aid, $uid);
			
			$uploadpath = $this->conf['upload_path'].'attach/';
			$uploadurl = $this->conf['upload_url'].'attach/';
			
			// 处理文件
			$imginfo = getimagesize($file['tmp_name']);
			
			// 如果为 GIF, 直接 copy
			// 判断文件类型，如果为图片文件，缩略，否则直接保存。
			if($imginfo[2] == 1) {
				$fileurl = image::set_dir($aid, $uploadpath).'/'.$aid.'.gif';
				$thumbfile = $uploadpath.$fileurl;
				copy($file['tmp_name'], $thumbfile);
				$r['filesize'] = filesize($file['tmp_name']);
				$r['width'] = $imginfo[0];
				$r['height'] = $imginfo[1];
				$r['fileurl'] = $fileurl;
			} else {
				$r = image::safe_thumb($file['tmp_name'], $aid, '.jpg', $uploadpath, $this->conf['upload_image_max_width'], 240000);	// 1210 800
				$thumb = image::safe_thumb($file['tmp_name'], $aid, '_thumb.jpg', $uploadpath, $this->conf['thread_icon_middle'], 2256);
				$thumbfile = $uploadpath.$thumb['fileurl'];
				image::clip($thumbfile, $thumbfile, 0, 0, $this->conf['thread_icon_middle'], $this->conf['thread_icon_middle']);	// 对付金箍棒图片
			}
			
			$arr['aid'] = $aid;
			$arr['fid'] = $fid;
			$arr['filesize'] = $r['filesize'];
			$arr['width'] = $r['width'];
			$arr['height'] = $r['height'];
			$arr['filename'] = $r['fileurl'];
			$this->attach->update($arr);
			
			// hook attach_uploadimage_after.php
			$this->message('<img src="'.$uploadurl.$r['fileurl'].'" width="'.$arr['width'].'" height="'.$arr['height'].'"/>');
			
		} else {
			if($_FILES['Filedata']['error'] == 1) {
				$this->message('上传文件( '.htmlspecialchars($_FILES['Filedata']['name']).' )太大，超出了 php.ini 的设置：'.ini_get('upload_max_filesize'), 0);
			} else {
				$this->message('上传文件失败，错误编码：'.$_FILES['Filedata']['error'], 0); // .print_r($_FILES, 1)
			}
		}
	}
	
	// 接受所有文件 swfupload post ajax
	public function on_uploadfile() {
		$fid = intval(core::gpc('fid'));
		$pid = intval(core::gpc('pid'));	// 如果新发帖子，那么 pid 为 0
		$uid = $this->_user['uid'];
		$this->check_forbidden_group();
		
		$forum = $this->forum->read($fid);
		$user = $this->user->read($uid);
		
		$this->check_forum_exists($forum);
		$this->check_access($forum, 'attach');

		// hook attach_uploadfile_before.php
		
		if(isset($_FILES['Filedata']['tmp_name']) && is_file($_FILES['Filedata']['tmp_name'])) {
			$file = $_FILES['Filedata'];
			$file['name'] = htmlspecialchars($file['name']);
			$filetype = $this->attach->get_filetype($file['name']);
			// 多后缀名以最后一个 . 为准。文件名舍弃，避免非法文件名。
			$ext = strrchr($file['name'], '.');
			if($filetype == 'unknown') {
				$ext = $this->attach->safe_ext($ext);
			}
			
			$arr = array (
				'fid'=>$fid,
				'tid'=>0,
				'pid'=>$pid,
				'filesize'=>0,
				'width'=>0,
				'height'=>0,
				'filename'=>'',
				'orgfilename'=>$file['name'],
				'filetype'=>$filetype,
				'dateline'=>$_SERVER['time'],
				'comment'=>'',
				'downloads'=>0,
				'isimage'=>0,
				'golds'=>0,
				'uid'=>$uid,
			);
			$aid = $this->attach->create($arr);
			
			// $aid 保存到临时文件，每个用户一个文件，里面记录 aid。在读取后删除该文件。
			// 如果tmp为内存，则在用户未完成期间，可能会导致垃圾数据产生。可以通过 uid=123 and pid=0，来判断附件归属，不过这个查询未建立索引，可以定期清理，一般不需要。
			$this->attach->save_aid_to_tmp($fid, $aid, $uid);
			
			$uploadpath = $this->conf['upload_path'].'attach/';
			$uploadurl = $this->conf['upload_url'].'attach/';
			
			// 处理文件
			$pathadd = image::set_dir($aid, $uploadpath);
			$filename = md5($aid.'_'.$this->conf['auth_key']).$ext;
			$destfile = $uploadpath.$pathadd.'/'.$filename;
			$desturl = $uploadurl.$pathadd.'/'.$filename;

			$arr['fid'] = $fid;
			$arr['aid'] = $aid;
			$arr['filename'] = $pathadd.'/'.$filename;
			$arr['filesize'] = filesize($file['tmp_name']);
			$this->attach->update($arr);
			
			if(move_uploaded_file($file['tmp_name'], $destfile)) {
				
				// hook attach_uploadfile_after.php
				$arr['desturl'] = $desturl;
				$this->message($arr);
			} else {
				// 回滚
				$this->attach->delete($fid, $aid);
				$this->message('保存失败！', 0);
			}
			
		} else {
			$this->message('上传文件失败，可能文件太大。', 0);
		}
	}

	// 更新一个文件，文件名不变！
	public function on_updatefile() {
		$fid = intval(core::gpc('fid'));
		$aid = intval(core::gpc('aid'));
		$uid = $this->_user['uid'];
		
		// 板块权限检查
		$forum = $this->mcache->read('forum', $fid);
		$this->check_forum_exists($forum);
		$this->check_access($forum, 'attach');
		
		$ismod = $this->is_mod($forum, $this->_user);
		$attach = $this->attach->read($fid, $aid);
		if(empty($attach)) $this->message('附件不存在。');
		if($attach['uid'] != $this->_user['uid']) {
			$this->check_access($forum, 'update');
		}
		
		// hook attach_updatefile_before.php
		
		if(isset($_FILES['Filedata']['tmp_name']) && is_file($_FILES['Filedata']['tmp_name'])) {
			$file = $_FILES['Filedata'];
			$attach['filesize'] = filesize($file['tmp_name']);
			$this->attach->update($attach);
			if(move_uploaded_file($file['tmp_name'], $this->conf['upload_path'].'attach/'.$attach['filename'])) {
				
				// hook attach_updatefile_after.php
				$this->message($attach);
			} else {
				$this->message('保存失败！', 0);
			}
		} else {
			$this->message('上传文件失败，可能文件太大。', 0);
		}
	}
	
	// 编辑器弹出层，删除一个附件文件
	public function on_deletefile() {
		$this->check_login();
		
		$fid = intval(core::gpc('fid'));
		$aid = intval(core::gpc('aid'));
		
		// 板块权限检查
		$forum = $this->mcache->read('forum', $fid);
		$this->check_forum_exists($forum);
		$this->check_access($forum, 'attach');
		
		// hook attach_deletefile_before.php
		
		$ismod = $this->is_mod($forum, $this->_user);
		$attach = $this->attach->read($fid, $aid);
		if(empty($attach)) $this->message('附件不存在。');
		if($attach['uid'] != $this->_user['uid']) {
			$this->check_access($forum, 'delete');
		}
		
		// 如果附件没有归属，那么可能存在于 kv.uid_aids.tmp 文件中
		if($attach['pid'] == 0) {
			$this->attach->remove_aid_from_tmp($fid, $aid, $this->_user['uid']);
		} else {
			// 附件数--
			$post = $this->post->read($attach['fid'], $attach['pid']);
			$thread = $this->thread->read($attach['fid'], $attach['tid']);
			$this->check_post_exists($post);
			$this->check_thread_exists($post);
			$post['attachnum']--;
			$this->post->update($post);
			if($thread['firstpid'] == $post['pid']) {
				$thread['attachnum']--;
				$this->thread->update($thread);
			}
		}
		
		// todo: 下载（购买）历史，如果最后一次购买的时间在24小时以内，附件不能被删除。保护购买人的权利，否则还没来得及下载，已经被删除。
		// 清理资源比较重要，不考虑上面情况了。
		$this->attach->unlink($attach);
		$this->attach->delete($fid, $aid);
		$this->attach_download->delete_by_fid_aid($fid, $aid);
		
		// hook attach_deletefile_after.php
		
		$this->message('删除成功');
	}
	
	// 更新附件的售价
	public function on_updategold() {
		$this->check_login();
		$uid = $this->_user['uid'];
		$user = $this->user->read($uid);
		$fid = intval(core::gpc('fid'));
		$forum = $this->forum->read($fid);
		$this->check_forum_exists($forum);
		$gold = core::gpc('gold', 'P');
		foreach($gold as $aid=>$golds) {
			$aid = intval($aid);		
			$golds = intval($golds);
			$attach = $this->attach->read($fid, $aid);
			if(empty($attach)) continue;
			if($attach['uid'] != $uid && !$this->is_mod($forum, $user)) continue;
			if($attach['golds'] != $golds) {
				$attach['golds'] = $golds;
				$this->attach->update($attach);
			}
		}
		
		// hook attach_updategold_after.php
		$this->message('更新附件售价成功。', 1);
	}

	// hook attach_control_after.php
}

?>