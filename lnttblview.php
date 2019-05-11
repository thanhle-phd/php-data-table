<?php

/*----- USAGE:
   -> Inherits from class 'lntMySqlDB';
1) Prepare an array of which each element is also an array presenting the column layout (title, style, exp),
   where 'exp' is a format string with values that is used to generate the entire content of a cell.
   In exp, field names should be placed between <% and %>.
2) Create an object of class 'lntTableView'. Four parameters are requred by the class constructor method:
   the sql statement, the number of rows in each display page, the array mentioned above, and the table width.
-> Note: All column and table widths are specified absolutely using px mesurement or relatively using percentage.
>>>>> Copyright (c) by THANH LE @tinyray.com <<<<< */

require_once('lntmysql.php');
class lntTableView extends lntMySqlDB {
	function __contruct($servername=null, $username=null, $password=null, $database=null, $port=null) {
		parent::__construct($servername, $username, $password, $database, $port);
	}
	public function Render($tbl_sql, $mrpp, $tblinfo, $tblFormat, $tblPath="/") {
		if (!strpos($tblFormat,':')) $tblFormat = "width:$tblFormat;"; // if $tblFormat is just table width
		if (is_array($tbl_sql)) {
			$this->arrayLoad($tbl_sql);
			$mRecNo = $this->nrow();
			$eContentSql = $this->encrypt('hihi', 'lntajxtable');
		} else {
			$db_auto_open = !($this->db_handle);
			if (!$this->ReadStart($tbl_sql)) {
				print 'ERROR: ' . $this->GetLastError(); return;
			}
			$mRecNo = $this->nrow();
			if ($db_auto_open) $this->ReadEnd();
			$eContentSql = $this->encrypt($tbl_sql, 'lntajxtable');
		}
		if ($mRecNo < 1) {
			// print 'No data found!';
			return;
		}
		// page length option
		$RowNoOpt = array(5,15,25,50);
		if (!in_array($mrpp, $RowNoOpt)) { $RowNoOpt[] = $mrpp; sort($RowNoOpt); }
		// generate the table meta data
		$mTblID = 'lntajxtbl' . rand();
		$mParms = array(
			'lntajxtblID' => $mTblID/*tableID*/,
			'lntajxtblUrl' => $tblPath,
			"cmd_$mTblID" => '?'/*sql*/,
			"cnt_$mTblID" => '?'/*row format*/,
			'lntajxtblRecNo' => $mRecNo,
			'lntajxtblRowNo' => $mrpp,
			'lntajxtblRowOp' => implode(',', $RowNoOpt),
			'lntajxtblColNo' => count($tblinfo),
			'lntajxtblPageIdx' => 1,
			'lntajxmsg' => 'none', /*this stop ajax from refresh the content*/
		);
		if ($mRecNo > $mrpp) {
			// display 'loading...' in pagination
			$mParms['lntajxtle2'] = $mTblID . '2';
		}
		// table headers
		$line = "<tr class=\"bgrd2\">";
		$ncol = 0; $mRowFmt = '';
		foreach ($tblinfo as $field) {
			// for the titles
			if ($field['sort'] !== false) {
				$colHTML = $field['title'];
				$colID = $mTblID . "c" . $ncol;
				$colHTML = "<a id=\"$colID\" title=\"Sort by $colHTML\" href=\"javascript:{}\" onclick=\"lntajxtblCmd('$mTblID',{'lntajxtblSortBy':'{$field['sort']}','lntajxtblSortColID':'$colID'});\"><b>$colHTML</b></a>";
			} else {
				$colHTML = '<b>' . $field['title'] . '</b>';
			}
			$line .= ('<td style="margin:0;padding:5px 0px 0px 0px;' . $field['style'] . '" class="' . (($ncol==0)? 'tblHeadCell1' : 'tblHeadCell2') . "\">$colHTML</td>\n");
			// for the content row
			$mRowFmt .= ('<td class="' . (($ncol==0)? 'tblContentCell1' : 'tblContentCell2') . '" style="' . $field['style'] . '">' . $field['exp'] . '</td>');
			$ncol++;
		}
		$mRowFmt = str_replace("<%#%>","<%lntstt%>", $mRowFmt);
		// directly print out the content
		$eRowFmt = $this->encrypt($mRowFmt, 'lntajxtable');
		print (($mrpp<1000)? ('<script type="text/javascript">var '.$mTblID.'='.$this->jsonEncode($mParms) . ";</script>\n") : '') .
			"<table style=\"border-spacing:0px 0px;table-layout:fixed;$tblFormat\">\n<thead>$line</tr>\n<tr class=\"bgrd2\"><td colspan=\"$ncol\"><hr></td></tr>\n</thead>\n<tbody id=\"$mTblID\">\n" .
			$this->cntRender($mTblID, $tbl_sql, $mRowFmt, $mParms) .
			"\n</tbody>\n</table>\n" . (($mrpp<1000)?
			("<input type=\"hidden\" name=\"cmd_$mTblID\" id=\"cmd_$mTblID\" value=\"$eContentSql\" />\n" .
			"<input type=\"hidden\" name=\"cnt_$mTblID\" id=\"cnt_$mTblID\" value=\"$eRowFmt\" />\n") : '');
		return $mRecNo;
	}
	public function ajaxRequest() {
		if (!isset($_POST['lntajxtblID'])) return false;
		$mTblID = $_POST['lntajxtblID'];
		$mParms = array(
			'lntajxtblID' => $mTblID/*tableID*/,
			'lntajxtblUrl' => $_POST['lntajxtblUrl'],
			"cmd_$mTblID" => '?'/*sql*/,
			"cnt_$mTblID" => '?'/*row format*/,
			'lntajxtblRecNo' => $_POST['lntajxtblRecNo'],
			'lntajxtblRowNo' => $_POST['lntajxtblRowNo'],
			'lntajxtblRowOp' => $_POST['lntajxtblRowOp'],
			'lntajxtblColNo' => $_POST['lntajxtblColNo'],
			'lntajxtblPageIdx' => $_POST['lntajxtblPageIdx'],
			'lntajxmsg' => 'none' /*this stop ajax from refresh the content*/
		);
		if (isset($_POST['lntajxtblSearch']) && $_POST['lntajxtblSearch'] != '*') {
			$mParms['lntajxtblSearch'] = $_POST['lntajxtblSearch'];
		}
		if (isset($_POST['lntajxtle2'])) {
			$mParms['lntajxtle2'] = $_POST['lntajxtle2'];
		}
		if (isset($_POST['lntajxtblSortBy'])) {
			$mParms['lntajxtblSortBy'] = $_POST['lntajxtblSortBy'];
			$mParms['lntajxtblSortIn'] = $_POST['lntajxtblSortIn'];
		}
		$msql = $this->decrypt($_POST["cmd_$mTblID"], 'lntajxtable');
		$mRowFmt = $this->decrypt($_POST["cnt_$mTblID"], 'lntajxtable');
		print $this->cntRender($mTblID, $msql, $mRowFmt, $mParms);
		return true;
	}
	protected function cntRender($mTblID, $m_sql, $mRowFmt, $mParms) {
		// table info
		if (isset($mParms['lntajxtblSortBy'])) {
			$mSortBy = $mParms['lntajxtblSortBy'];
			$mSortIn = (isset($mParms['lntajxtblSortIn'])) ? ((int)($mParms['lntajxtblSortIn'])) : 0;
		}
		$mPageIdx = ((int) $mParms['lntajxtblPageIdx']) - 1;
		$mRecNo = (int) $mParms['lntajxtblRecNo'];
		$mrpp = (int) $mParms['lntajxtblRowNo'];
		$RowNoOpt = explode(',', $mParms['lntajxtblRowOp']);
		$ncol = (int) $mParms['lntajxtblColNo'];
		$mPageNo = ceil($mRecNo / $mrpp); 
		if ($mPageNo <= $mPageIdx) { $mPageIdx = $mPageIdx - 1; }
		// generate the sql statement
		$sql = $m_sql;
		if ($this->isDB) {
			if (isset($mSortBy)) {
				$sql.= (' ORDER BY ' . $mSortBy . (($mSortIn==0) ? ' ASC' : ' DESC'));
				if (isset($mParms['lntajxtblSearch'])) {
					$sql2 = 'SET @rownum:=0 && SELECT rnk FROM (SELECT @rownum:=@rownum+1 AS rnk,funcSoundexMatchAll(\''.
							$mParms['lntajxtblSearch'].'\','.$mSortBy.',\' \') AS val FROM ('.$sql.') q) t WHERE val=1 LIMIT 1';
					$snIdx = $this->ReadValue($sql2);
					if ($snIdx) { $mPageIdx = ceil($snIdx / $mrpp) - 1; }
				}
			}
			if ($mPageNo > 1) { $sql.= (' LIMIT ' . ($mPageIdx * $mrpp) . ',' . $mrpp); }
			$db_auto_open = !($this->db_handle);
		} else {
			$db_auto_open = false;
		}
		$result = "";
		if ($sql && $this->ReadStart($sql)) {
			$this->currowidx += ($mPageIdx * $mrpp); $curRow = 0;
			while ($this->Read()) {
				$rowClass = (($curRow % 2) == 1) ? ' class="bgrd1"' : ''; $curRow++;
				// php5
				//$result .= ("<tr$rowClass style=\"line-height:180%;\">" . preg_replace('/<%([^<%>]+)%>/e', '$this["\\1"]', $mRowFmt) . "</tr>\n");
				// php7
				$result .= ("<tr$rowClass style=\"line-height:180%;\">" . preg_replace_callback('/<%([^<%>]+)%>/', function ($cntInfo) { return $this[$cntInfo[1]]; }, $mRowFmt) . "</tr>\n");
			}
			if ($db_auto_open) { $this->ReadEnd(); }
			// padded with empty rows to retain the height
			if ($mPageNo > 1) {
				while ($curRow < $mrpp) {
					$rowClass = (($curRow % 2) == 1) ? ' class="bgrd2"' : ''; $curRow++;
					$result .= ("<tr$rowClass style=\"line-height:180%;\"><td colspan=\"$ncol\">&nbsp;</td></tr>\n");
				}
			}
		} else {
			$result .= ('<span class="attachbox icon-notification">ERROR: ' . $this->GetLastError() . '</span>');
		}
		// end of table content
		// generate options for page length selection
		$cboInfo = '<ul class="lnttblviewpage" style="margin:0;padding:0;"><li><span title="Table View add-on" onclick="window.open(\'http://blog.tinyray.com/lnttblview\',\'_blank\');" style="display:inline !important;cursor:pointer;" class="icon-members"></span>&emsp;<select class="inputbox small" id="'.$mTblID.'pr" name="'.$mTblID.'pr" onchange="lntajxtblCmd(\''.$mTblID.'\',{\'lntajxtblPageIdx\':1,\'lntajxtblRowNo\':this.value});">';
		for ($rnI = 0; $rnI < count($RowNoOpt); $rnI++) {
			if (($RowNo1 = $RowNoOpt[$rnI]) >= $mRecNo) break;
			$isSelected = ($mrpp == $RowNo1) ? ' selected="selected"' : ''; 
			$cboInfo .= "\n<option value=\"$RowNo1\"$isSelected>$RowNo1</option>";
		}
		$cboInfo .= ('
<option value="'.$mRecNo.'">All</option></select></li>
<li><input type="text" class="inputbox" id="'.$mTblID.'sb" style="width:100px;display:none;" value="" /></li>
<li><a href="javascript:{}" onclick="'.((isset($_POST['lntajxtblSortBy'])) ?
('var sctrl=$(\'#'.$mTblID.'sb\');var sval=sctrl.val();sctrl.slideToggle(\'slow\');if(sval){lntajxtblCmd(\''.$mTblID.'\',{\'lntajxtblSearch\':sval});return true;} else return false;'):
('MessageBox(\'Error\',\'Please select a sort column to search on\');')).
'"><i class="fa fa-search"></i></a></li>
</ul>');
		// print the table footer
		$result .= "<tr class=\"bgrd2\"><td colspan=\"$ncol\" class=\"tblHeadCell1\" style=\"text-align:right;padding:0px 5px 0px 5px;margin:0;line-height:1";
		if ($mPageNo > 1) { // show navigation bar
			$result .= ('"><div class="pagination" style="float:left">'.$cboInfo.'</div><div class="pagination" id="'.$mTblID.'2"><ul class="icon-pages lnttblviewpage"><li>' . ($mPageIdx+1) . '&nbsp;of&nbsp;' . $mPageNo . '&nbsp;&nbsp;</li>');
			$result .= "<li><a title=\"go to the first page\" href=\"javascript:{}\" onclick=\"lntajxtblCmd('$mTblID',{'lntajxtblPageIdx':1});\"><i class=\"fa fa-fast-backward\"></i></a></li>";
			if ($mPageIdx > 0) {
				$result .= "<li><a title=\"go to the previous page\" href=\"javascript:{}\" onclick=\"lntajxtblCmd('$mTblID',{'lntajxtblPageIdx':$mPageIdx});\"><i class=\"fa fa-backward\"></i></a></li>";
			}
			for ($i=$mPageIdx-5; $i<$mPageIdx+5;$i++) {
				if ($i>0 && $i<$mPageNo-1) {
					$gotoI = $i+1;
					$result .= ("<li><a href=\"javascript:{}\" onclick=\"lntajxtblCmd('$mTblID',{'lntajxtblPageIdx':$gotoI});\">$gotoI</a></li>");
				}
			}
			if ($mPageIdx < $mPageNo-1) {
				$gotoI = $mPageIdx + 2;
				$result .= "<li><a href=\"javascript:{}\" title=\"go to the next page\" onclick=\"lntajxtblCmd('$mTblID',{'lntajxtblPageIdx':$gotoI});\"><i class=\"fa fa-forward\"></i></a></li>";
			}
			$result .= ("<li><a title=\"go to the last page\" href=\"javascript:{}\" onclick=\"lntajxtblCmd('$mTblID',{'lntajxtblPageIdx':$mPageNo});\"><i class=\"fa fa-fast-forward\"></i></a></li></ul></div>");
		} else { $result.= ';">'; }
		$result.= ('</td></tr><tr'.(($mPageNo>1)?' class="bgrd2"':'').'><td style="margin:0;padding:0;" colspan="'.$ncol.'"><hr></td></tr>');
		return $result;
	}
}
?>