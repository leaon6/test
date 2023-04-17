<?php
/**
	Explain:
		摆渡数据管理处理函数
	Author:	wbl
	Date:	2012-04-10
**/

/**
	删除队列的选择的数据内容
**/
function	delSelect()
{
	//进行删除指定队列的处理
	reset($_REQUEST['F_qid']);
	while ( list(, $v) = each($_REQUEST['F_qid']) ) {
//		$cmd = "sudo -u root  /usr/sbin/postmulti -i postfix-out -x postsuper -d  $v";
		$cmd = "sudo -u root  postsuper -c /etc/postfix-in -d  ".trim($v);
//		$cmd = "/usr/sbin/postsuper -c /etc/postfix-in -d  $v";
		shell_exec($cmd);
	}
	header("location: data.php?F_action=export");
	exit;
}

/**
	摆渡数据导出函数
**/
function	exportData()
{
	//导出摆渡数据压缩包处理
	if ( !isEmpty($_REQUEST['do']) ) {
		doExportData();
	}

	//显示导出数据函数
	displayExportDataUI();
}


/**
	导出摆渡数据，让用户下载，记录摆渡的批次
**/
function	doExportData()
{
	global	$IN_CONF_DIR;
	global	$SYSTMP_DIR;
	global	$_SESSION;
	global	$REMOTE_ADDR;

	//得到hold队列的目录
	$cmd = "/usr/sbin/postmulti -i postfix-in -x postconf -h queue_directory";
	$ret_id = @popen($cmd, "r");
    if ( !$ret_id ) {
		die_tpl("EXPORT_DATA_ERR");
    }
    $str = "";
    while ( !feof($ret_id) ) {
		$str .= fread($ret_id, 512);	
    }
    pclose($ret_id);
	$queue_dir = $str;
	if ( empty($queue_dir) || !is_dir(trim($queue_dir)) ) {
		echo "2 \n";
		die_tpl("EXPORT_DATA_ERR");
	}
	
	$hold_dir = trim($queue_dir)."/hold";
	
	$comp_filename = "${SYSTMP_DIR}/".$_SESSION[admin]."_".mktime().".tgz";
	$v_list = array();
	//处理用户的数据导出功能，查看用户时全部导出还是选择导出	
	if ( empty($_REQUEST['type']) ) {
		//遍历hold队列目录得到所有的文件列表
		$dir_id = @opendir($hold_dir);	
		if ( !$dir_id ) {
		echo "3 \n";
			die_tpl("EXPORT_DATA_ERR");
		}
		while ( $file = readdir($dir_id) ) {
			if ( substr($file, 0, 1) == '.' ) {
				continue;
			}
			$v_list[] = "hold/$file";
		}	
		closedir($dir_id);
	} else {
		reset($_REQUEST['F_qid']);
		while ( list(, $v) = each($_REQUEST['F_qid']) ) {
			$v_list[] = "hold/$v";
		}
	}			

	chdir(trim($queue_dir));
	$tar_id = new Archive_Tar($comp_filename, true);	
	$tar_id->create($v_list);	

	//将各项内容记录到批次中
	recordExportBatch($v_list);
	
	//计入到导出的日志中
	recordOptLog('1', $_SESSION['admin'],  $REMOTE_ADDR, filesize($comp_filename), count($v_list));

	//下载本批次内容	
	header("Content-type: application/x-tar");
    header("Content-Disposition: attachment; filename=\"".basename($comp_filename)."\"");
    readfile("$comp_filename");
    @unlink($comp_filename);
	exit;
}

/**
	记录下载批次到数据表中
**/
function	recordExportBatch($queue_arr)
{
	//得到最大的批次数量
	$sql = "SELECT MAX(batch) AS max_batch FROM exportlog WHERE admin = '".$_SESSION['admin']."'";	
	$rs  = db_query($sql);
	if ( !$rs ) {
		$max_batch = 1;
	} else {
		$row = db_fetch($rs);
		$max_batch = intval($row['max_batch']) + 1;
	}
	
	$queue_str = "";
	reset($queue_arr);
	while ( list(, $v) = each($queue_arr) ) {
		$queue_str .= basename($v).",";
	}	
	$sql = "INSERT INTO exportlog SET ".
			"batch = '$max_batch', ".
			"date  = NOW(), ".
			"num   = ".count($queue_arr).", ".
			"admin = '".$_SESSION['admin']."', ".
			"queue = '$queue_str' ";
	writeLog($sql);
	db_query($sql);	
}

/**
	导出数据显示界面，显示当前等待摆渡数据
**/
function	displayExportDataUI()
{
	global	$IN_CONF_DIR;
	global	$LANGUAGE, $TPL;
	global	$DISPLAY_IN_NUM;
	
	//得到邮件队列信息数组
	$queue_arr = getQueueInfo();

	$html = new Template("lang/$LANGUAGE/tpl/$TPL");	
	$html->set_file("data_export",	"data_export.tpl");
	$html->set_block("data_export",	"row",	"rows");
	$html->set_var("rows",	"");

	$html->set_var("ct_num",	$queue_arr['hold']['num']);
	$html->set_var("in_num",	$DISPLAY_IN_NUM);
	$html->set_var("ct_size",	formatFilesize($queue_arr['hold']['size']));

	usort($queue_arr['hold']['queue'], "cmp");
	reset($queue_arr['hold']['queue']);
	while ( list(, $v) = each($queue_arr['hold']['queue']) ) {
		$html->set_var("qid",	$v['id']);
		$html->set_var("date",	$v['date']." ".$v['time']);
		$html->set_var("mfrom",	$v['from']);
		$html->set_var("mto",	$v['to']);
		$html->set_var("size",	$v['size']);
		
		$html->parse("rows",	"row",	true);
	}
		
	$html->pparse("out",	"data_export");
	exit;
}

/**
	对于队列文件按照时间排序
**/
function	cmp($a, $b) 
{
	$a_time = $a['date']." ".$a['time'];
	$b_time = $b['date']." ".$b['time'];

	if ( $a_time == $b_time ) {
		return 0;
	}
	return ( $a_time < $b_time ) ? -1 : 1;
}

/**
	摆渡数据导入函数
**/
function	importData()
{
	//进行导出操作的处理
	if ( !empty($_REQUEST['do']) ) {
		doImportData();
	}
	displayImportDataUI();
}

/**
	显示导出操作的界面处理函数
**/
function	displayImportDataUI()
{
	global	$LANGUAGE, $TPL;

	$html = new Template("lang/$LANGUAGE/tpl/$TPL");
	$html->set_file("data_import",	"data_import.tpl");

	$max_upload = ini_get("upload_max_filesize");
	$html->set_var("max_uploadsize",	$max_upload);
	$html->set_var("max_uploadsize_byte",	intval($max_upload)*1024*1024);

	$html->set_var("result",	"");

	$html->pparse("out",	"data_import");
	exit;
}

/**
	进行导入摆渡数据包的处理函数
**/
function	doImportData()
{
	global	$OUT_CONF_DIR;
	global	$MSG;
	global	$REMOTE_ADDR;

	//得到外发服务的队列目录
	$cmd = "/usr/sbin/postmulti   -i postfix-out -x postconf -h queue_directory	";
	$ret_id = @popen($cmd, "r");
    if ( !$ret_id ) {
		die_tpl("EXPORT_DATA_ERR");
    }
    $str = "";
    while ( !feof($ret_id) ) {
		$str .= fread($ret_id, 512);	
    }
    pclose($ret_id);
	$queue_dir = $str;
	if ( empty($queue_dir) || !is_dir(trim($queue_dir)) ) {
		die($MSG["IMPORT_ZERO_ERR"]);
	}
	
	
	//检查上传的数据文件是否正常
	if ( !isset($_FILES['F_file']) || count($_FILES['F_file']) == 0 ) {
		die($MSG["DATA_IMPORT_FILE_EMPTY"]);
	}	
	
	//检查文件是否包括hold队列的文件
	$tar_id = new Archive_Tar($_FILES['F_file']['tmp_name']);
	if ( !$tar_id ) {
		@unlink($_FILES['F_file']['tmp_name']);
		die($MSG["DATA_IMPORT_FORMAT_ERR"]);
	}
	$v_list = $tar_id->listContent();
	if ( $v_list == 0 ) {
		unset($tar_id);
		@unlink($_FILES['F_file']['tmp_name']);
		die($MSG["DATA_IMPORT_FORMAT_ERR"]);
	}
	$flag = true;
	while ( list(, $v) = each($v_list) ) {
		if ( strpos($v['filename'], "hold") === false ) {
			$flag = false; 
			break;
		}
	}	
	if ( $flag == false ) {
		@unlinK($_FILES['F_file']['tmp_name']);
		die($MSG["DATA_IMPORT_FORMAT_ERR"]);
	}
	
	//将摆渡的数据在外发队列中解压缩
	chdir(trim($queue_dir));
	$ret_str = system("/bin/tar xzf \"".$_FILES['F_file']['tmp_name']."\" > /dev/null 2>&1", $ret_val); 
	if ( $ret_val != 0 ) {
		@unlink($_FILES['F_file']['tmp_name']);
		die($MSG["EXT_IMPORT_ERR"]);
	}	
	//计入到导出的日志中
	recordOptLog('0', $_SESSION['admin'],  $REMOTE_ADDR, filesize($_FILES['F_file']['tmp']), count($v_list));
	
	@unlink($_FILES['F_file']['tmp_name']);	

	//设定在hold队列中的邮件进行处于发送状态
	$cmd = "sudo -u root  /usr/sbin/postmulti -i postfix-out -x postsuper -H ALL ";
	system($cmd);	
	$cmd = "sudo -u root  /usr/sbin/postmulti -i postfix-out -x postqueue -f ";
	system($cmd);	

	die($MSG["EXT_IMPORT_OK"]);
}


/**
	维护历史导出数据函数
**/
function	exportLog()
{
	global	$LANGUAGE, $TPL;

		
	//删除指定的批次的队列情况
	if ( !empty($_REQUEST['F_del']) ) {
		doDelExportLog();
	}
	//显示历史导出的界面
	displayExportLogUI();
}

/**
	删除指定批次的队列处理
**/
function	doDelExportLog()
{
	global	$IN_CONF_DIR;

	if ( empty($_REQUEST['F_batch']) ) {
		die_tpl("INPUT_VALUE_EMPTY");
	}	
	
	$sql = "SELECT queue FROM exportlog WHERE no = '".$_REQUEST['F_batch']."'";
	$rs  = db_query($sql);
	if ( !$rs || db_num_rows($rs) == 0 ) {
		die_tpl("INPUT_VALUE_EMPTY");
	}
	$row = db_fetch($rs);
	$queue_arr = explode(",", $row['queue']);	

	$cmd = "/usr/sbin/postmulti -i postfix-in -x postsuper ";
	reset($queue_arr);
	while ( list(, $v) = each($queue_arr) ) {
		$ret_str = shell_exec("sudo -u root $cmd -d $v"); 
	}	
	$sql = "UPDATE exportlog SET ".
			"del_flag = '1' ".
			"WHERE no = '".$_REQUEST['F_batch']."'";	
	db_query($sql);

	header("location: data.php?F_action=exportlog");
	exit;
}

/**
	显示历史导出的界面
**/
function	displayExportLogUI()
{
	global	$LANGUAGE, $TPL;
	global	$BATCH_DELSTAT;

	$sql = "SELECT * FROM exportlog ORDER BY date ASC";	
	$rs  = db_query($sql);
	
	$html = new Template("lang/$LANGUAGE/tpl/$TPL");
	$html->set_file("data_exportlog",	"data_exportlog.tpl");
	$html->set_block("data_exportlog",	"row",	"rows");
	$html->set_var("rows",	"");
	$html->set_block("row",	"del_row",	"del_rows");
	$html->set_var("del_rows",	"");

	if ( $rs ) {
		while ( $row = db_fetch($rs) ) {
			$html->set_var("date",	$row['date']);
			$html->set_var("batch",	$row['batch']);
			$html->set_var("admin",	$row['admin']);
			$html->set_var("num",	$row['num']);
			$html->set_var("no",	$row['no']);
			$flag = $row['del_flag'];
			$html->set_var("status",	$BATCH_DELSTAT[$flag]);	

			if ( $flag == '0' ) {
				$html->parse("del_rows",	"del_row",	false);
			} else {
				$html->set_var("del_rows",	"");
			}
			$html->parse("rows",	"row",	true);
			$html->set_var("del_rows",	"");
		}	
	}	

	$html->pparse("out",	"data_exportlog");
	exit;
}

/**
	得到邮件队列的信息数组	
**/
function    getQueueInfo()
{
    global  $MONTH_DESCRIPT;
	global	$IN_CONF_DIR;

    $cmd	= "/usr/sbin/postmulti -i postfix-in -x postqueue";

    $queue_arr  = array();	
	$queue_arr['num']	= 0;
	$queue_arr['size']	= "0";

    $queue_arr['active']['num']		= 0;
    $queue_arr['active']['size']	= 0;
    $queue_arr['active']['queue']	= array(); 
	$queue_arr['other']['num']		= 0;
	$queue_arr['other']['size']		= 0;
	$queue_arr['other']['queue']	= array();
	$queue_arr['hold']['num']		= 0;
	$queue_arr['hold']['size']		= 0;
	$queue_arr['hold']['queue']		= array();


    $pid = @popen("$cmd -p", "r");
    if ( !$pid ) {
	    return $queue_arr;
    }
    $str = "";
    while ( !feof($pid) ) {
		$str .= fread($pid, 512);	
    }
    pclose($pid);

    if ( empty($str) || strpos($str, "is empty") !== false ) {
		return $queue_arr;
    }

    list(, $queue) = explode("\n", $str, 2); 
    unset($str);
     
    $pos 	= strrpos($queue, "--"); 
    $info 	= trim(substr($queue, $pos+3)); 

    list($size, $unit, , $num, ) = explode(" ", $info, 5);
    $queue_arr['size']	= "$size $unit";  
    $queue_arr['num']	= $num;
    list($queue_info, ) = explode("\n\n--", $queue, 2); 
    unset($queue); 
    $tmp_arr = explode("\n\n", $queue_info);
    reset($tmp_arr);
    while ( list(, $v) = each($tmp_arr) ) {
		$q_arr = explode("\n", $v);	
		$q_to  = "";
		while ( list($kk, $vv) = each($q_arr) ) {
			if ( $kk == 0 ) {
				list($q_id, $q_size, $q_weekly, $q_month, $q_day, $q_time, $q_from) = preg_split("/(\s|\t)+/", $vv, 7);
				if ( is_int(strpos($q_id, "*")) ) {
					$q_stat = "A";
				} else if ( is_int(strpos($q_id, "!")) ) {
					$q_stat = "H";
				}
			} else if ( $kk == 1 ) {
				if ( substr($vv, 0, 1) != " " ) {
					$q_errstr = $vv;
				} else {
					$q_to = trim($vv).",";
				}	
			} else {
				$q_to .= trim($vv).",";
			}
		}
		if ( empty($q_stat) ) {
			if ( !empty($q_errstr) ) {
				$q_stat = 'D';
			} else {
				$q_stat = 'W';
			}
		}
		if ( $q_stat == "A" ) {
			$queue_arr['active']['queue'][] = array(
					'id'	=> trim($q_id, "*"),
					'size'	=> $q_size,
					'weekly'=> $q_weekly,
					'month'	=> $q_month,
					'day'	=> $q_day,
					'date'	=> $MONTH_DESCRIPT[$q_month].$q_day,
					'time'	=> $q_time,
					'from'	=> $q_from,
					'to'	=> trim($q_to, ","),
					'err'	=> $q_errstr,
					'stat'	=> $q_stat
					);
			$queue_arr['active']['num']++;
			$queue_arr['active']['size']+=intval($q_size);
		} else if ( $q_stat == "H" ) {
			$queue_arr['hold']['queue'][] = array(
					'id'	=> trim($q_id, "!"),
					'size'	=> $q_size,
					'weekly'=> $q_weekly,
					'month'	=> $q_month,
					'day'	=> $q_day,
					'date'	=> $MONTH_DESCRIPT[$q_month].$q_day,
					'time'	=> $q_time,
					'from'	=> $q_from,
					'to'	=> trim($q_to, ","),
					'err'	=> $q_errstr,
					'stat'	=> $q_stat
					);
			$queue_arr['hold']['num']++;
			$queue_arr['hold']['size']+=intval($q_size);
		} else {
			$queue_arr['other']['queue'][] = array(
					'id'	=> trim($q_id, "*!"),
					'size'	=> $q_size,
					'weekly'=> $q_weekly,
					'month'	=> $q_month,
					'day'	=> $q_day,
					'date'	=> $MONTH_DESCRIPT[$q_month].$q_day,
					'time'	=> $q_time,
					'from'	=> $q_from,
					'to'	=> trim($q_to, ","),
					'err'	=> $q_errstr,
					'stat'	=> $q_stat
					);
			$queue_arr['other']['num']++;
			$queue_arr['other']['size']+=intval($q_size);
		}
    }
    
    return $queue_arr;
}
?>
