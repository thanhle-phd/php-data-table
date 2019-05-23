<?php
require_once('lntcmd.php');
$config = ['lntweb_webprog'=>'/var/usr/prog', 'lntweb_cip'=>$_SERVER['REMOTE_ADDR', 'lntweb_url'=>'/'];
function runRemoteSoftwareAsync($eleRender, $postbackPage, $postbackName, $initMsg, $progCmdLine, $progCmdParms='', $attachedData=false, $Priority=0)
{
	global $mainPage, $config, $db; $asyncode = false;
	$cmd = new lntCommand();
	if (!$cmd->isReady()) {
		echo "<script type=\"text/javascript\">lntajxStop('$eleRender');MessageBox('System error','{$cmd->GetLastError()}');</script>";
		return false;
	}
	// check if there is any pending task
	$cmdPostBack = $mainPage->ajaxPostParms('lntajxasyID0');
	if (!is_null($cmdPostBack)) {
		$lastTASKrs = runRemoteSoftwareAsyncCheck($cmd, $cmdPostBack);
		if ($lastTASKrs['done'] === false) {
			echo "<script type=\"text/javascript\">MessageBox('Pending task','You currently have a pending task.<br />Please wait until the task is finished.');</script>";
			return false;
		}
	}
	// process the request
	if ($attachedData) {
	  $fileNo = count($attachedData);
	  if ($cmd->fCopy($config['lntweb_webprog'].'/data-tmp/', $attachedData) != $fileNo) {
  		echo "<script type=\"text/javascript\">MessageBox('System error','{$cmd->GetLastError()}');</script>";
  		return false;
	  }
	  end($attachedData);
	  $tmpOutFilename = key($attachedData);
	} else {
		$tmpOutFilename = "null-" . str_replace('.','-',$config['lntweb_cip']) . "." . mt_rand();
	}

	$isHPC = false;
	#if (isset($config['lntweb_cpucores'])) { $progCmdParms .= (" -c " . $config['lntweb_cpucores'] ); }
	if ($isHPC) {
		$progCmdParms .= (" -c " . "2" ); # always 2
	}
	if ($isHPC) {
		// try: bpsh -aL  to run on multiple online nodes, update output from nodes, line by line (-L)
		$shellNode = exec("beostat -Cb|awk '{na=0;id=id+1;for(i=1;i<NF;i++){if(int(2*\$i)==0)na=na+1;}if(mn<na&&id<13){mn=na;nn=id;}}BEGIN{mn=0;nn=-1;id=-1;}END{print nn;}'");
		if (!is_numeric($shellNode)) { $shellNode = "1"; }
		$computeMsg = "Parallel mode on cloud $shellNode";
		$computeCmd = "bpsh $shellNode";
	} else {
		$computeMsg = 'SSH cloud';
		$computeCmd = "";
	}
	
	// HPC format the command
	$cmd2run = 'cd '. $config['lntweb_webprog'] . ' && ( nohup';
	if($Priority) {
		$cmd2run .= " nice -n $Priority";
	}

	// NO-HPC
	$cmd2run = 'cd '.$config['lntweb_webprog'].' && (';

	$cmd2run .= " $computeCmd ./2run $progCmdLine";
	// only programs in ds-bioc accept -asym command line parameter
	$asynmode = strpos($progCmdLine,'ds-') ? 1 : 0;
	if ($asynmode) {
		$progCmdParms .= " -asym ./data-tmp/$tmpOutFilename.asym";
	}
	$cmd2run .= (((empty($progCmdParms)) ? ' ' : " '$progCmdParms' ") . "> ./data-tmp/$tmpOutFilename.asy 2>&1 & echo $! )");
	// run the command; die($cmd2run);
	$PID = $cmd->Exec($cmd2run);
	if ($PID !== false) {
		$PID = trim($PID); if (empty($PID) || !is_numeric($PID)) $PID = false;
	}
	if ($PID === false) {
		echo "<script type=\"text/javascript\">MessageBox('System error','Computational back-end problem.<br />Unable to perform your request.');</script>";
		return false;
	}

	$asyncode = $db->encrypt("$tmpOutFilename.asy$PID",'lntremotesrv');
	// SET UP Asynchronous program running entry
	$mainPage->ajaxPostParms([
		'lntajxasyID' => $asyncode,
		'lntajxasyAM' => $asynmode,
		'lntajxasyNO' => 0,
		'lntajxasyPG' => $postbackPage,
		'lntajxasyIT' => $postbackName,
		'lntajxasyST' => time(), /* UNIX start timestamps */
		//'lntajxasyET' => ? /* UNIX end timestamps, set on complete */
		'lntajxmsg' => "$computeMsg - $initMsg",
		'lntajxreset' => 0
	]);
	// update the database
	if ($config['lntweb_authapp']) {
		$sql = "UPDATE webuser SET curjob='" . $asyncode . "' WHERE userid=" . $config['lntweb_userid'];
		$db->Start(); $db->Write($sql); $db->End();
	}
	// send the successful script to client
	$jParms = $mainPage->ajaxPostParms(false);
	echo "<script type=\"text/javascript\">lntajxPlay('$eleRender','{$config['lntweb_url']}',$jParms);</script>";
	return true;
}

// keep checking if the background program is done!
function runRemoteSoftwareAsyncDone() {
	if (!isset($_POST['lntajxasyPG']) || !isset($_POST['lntajxasyIT'])) return false;
	$cmd = new lntCommand(); 
	if (! $cmd->isReady() ) {
		echo "<script type=\"text/javascript\">MessageBox('System error','{$cmd->GetLastError()}');</script>";
		return false;
	}
	global $config, $db, $mainPage;
	$asyncode = $_POST['lntajxasyID'];
	$asynmode = $_POST['lntajxasyAM'];
	// wait for the background program
	$loop = 1; $rs = false;
	while (true) {
		$rs = runRemoteSoftwareAsyncCheck($cmd, $asyncode, $asynmode);
		$loop--; if ($loop < 0 || $rs['done']) break;
		sleep(3);
	}
	unset($cmd);
	if ( $rs['done'] === false || !isset($_POST['lntajxasyET']) ) {
		// if not done yet, rebuild the async-prog-running entry
		$lntajxasyNO = (((int)$_POST['lntajxasyNO']) + 1);
		$mainPage->ajaxPostParms([
			'lntajxasyID' => $asyncode,
			'lntajxasyAM' => $asynmode,
			'lntajxasyNO' => $lntajxasyNO,
			'lntajxasyPG' => $_POST['lntajxasyPG'],
			'lntajxasyIT' => $_POST['lntajxasyIT'],
			'lntajxasyST' => $_POST['lntajxasyST'],
			'lntajxmsg' => (($rs['value']===false) ?
				"Waiting for cloud computing &middot;$lntajxasyNO&middot;&middot;&middot;" :  $rs['value'] ),
			'lntajxreset' => 0,
			'lntajxdelay' => 7000 /* wait for 5 seconds before making a postback */
		]);
		if ($rs['done'] !== false) {
			$edtime = time(); $diff = $edtime - intval($_POST['lntajxasyST']);
			$rts = $diff % 60; $rtm = ($diff-$rts) / 60;
			// background program already completes
			$mainPage->ajaxPostParms([ 'lntajxmsg'=>"Cloud computing done in $rtm:$rts",'lntajxreset'=>1,'lntajxasyET'=>$edtime,'lntajxdelay'=>3000 ]);
		}
		$jParms = $mainPage->ajaxPostParms(false);
		echo "<script>lntajxPlay('".$_POST['lntajxtle']."','{$config['lntweb_url']}',$jParms);</script>";
		return false;
	}
	// clear the TASK in the database
	if ($config['lntweb_authapp']) {
		$sql = "UPDATE webuser SET curjob=NULL WHERE curjob='$asyncode'";
		$db->Start(); $db->Write($sql); $db->End();
	}
	// response the client
	if ($rs['value'] === false) return false;
	if (substr($rs['value'][0],0,5) === '-Fail') {
		$msg = 'The task was successfully accomplished.<br />However, a message from cloud computing:<br /><span class="small error">' . 
		        substr($rs['value'][0],6) . '</span><br />Our apologies. Please check back later!';
		echo "<script type=\"text/javascript\">MessageBox('System Information','$msg');</script>";
		return false;
	}
	$mainPage->ajaxPostParms([ $_POST['lntajxasyIT'] => $rs['value'] ]);
	return true;
}

function runRemoteSoftwareAsyncCheck($cmd, $FILE_PID_E, $asynmode=0) {
	global $config, $db;
	$FILE_PID = $db->decrypt($FILE_PID_E,'lntremotesrv');
	$pidpos = strrpos($FILE_PID, '.asy');
	if (!$cmd->isReady() || $pidpos === false) return array('done'=>true, 'value'=>array(''));
	$PID = substr($FILE_PID, $pidpos+4);
	$OUTFILE = substr($FILE_PID, 0, $pidpos+4);
	$outfilename = $config['lntweb_webprog'] . '/data-tmp/' . $OUTFILE;
	//---- check if job is pending
	$ProcessState = false;
	$cmd->Exec("ps $PID", $ProcessState);
	if (count($ProcessState) > 1) {
		$retval = ($asynmode/*job with .asym output file*/) ? $cmd->fRead($outfilename.'m') : false; 
		return array('done'=>false, 'value'=>((empty($retval)) ? false : $retval));
	}
	//----- job is done
	$retval = $cmd->fRead($outfilename);
	$retArr = $cmd->Result2Array($retval);
	return array( 'done'=>true, 'value'=>$retArr );
}

function runRemoteSoftware($eleRender, $softwareCmd, $softwareParms=false, $attachedData=false) {
	global $config; $cmd = new lntCommand();
	$softwareParms = ($softwareParms) ? "'$softwareParms'" : '';
	// run on remote server?
	if (!$cmd->isReady()) {
		if (!empty($eleRender)) $lntajxClean = "lntajxStop('$eleRender');";
		echo "<script type=\"text/javascript\">MessageBox('System error','{$cmd->GetLastError()}');$lntajxClean</script>";
		return false;
	}
	// submit program's data
	if ($attachedData) {
	  if ($cmd->fCopy($config['lntweb_webprog'].'/data-tmp/', $attachedData) != count($attachedData)) return false;
	}
	// execute the command
	$retResult = false;
	$cmd->Exec('cd '.$config['lntweb_webprog']." && ./2run $softwareCmd $softwareParms", $retResult);
	return $retResult;
}

function isUrlValid($url) {
	$urlpart = parse_url($url);
	if (!isset($urlpart['scheme']) || ($urlpart['scheme'] !== 'http' && $urlpart['scheme'] !== 'ftp')) return false;
	return isDomainName($urlpart['host']);
}
function isDomainName($strName) {
	return preg_match('/^([a-z0-9-_]+\.)+(com|org|net|edu|vn|uk|au|ca|info|me)$/', $strName);
}
// obsolete: please use this function in MySql
function IP2Number($sIP) {
    $vals = explode('.', $sIP);
	return ((int)$vals[3]) + 256 * ((int)$vals[2]) + 65536 * ((int)$vals[1]) + 16777216 * ((int)$vals[0]);
}
function downloadPage($url, $postparams='') {
	//$proxy = "64.130.160.63:2301"; //get free from http://www.hidemyass.com/proxy-list/
	$handle = curl_init();
	curl_setopt($handle,CURLOPT_URL, $url);
	curl_setopt($handle,CURLOPT_FRESH_CONNECT, true);
	curl_setopt($handle,CURLOPT_CONNECTTIMEOUT, 5);
	if ($postparams != '') { curl_setopt($handle, CURLOPT_POSTFIELDS, $postparams); }
	curl_setopt($handle,CURLOPT_RETURNTRANSFER, true);
	//curl_setopt($handle, CURLOPT_PROXY, $proxy);
	curl_setopt($handle,CURLOPT_TIMEOUT,1200);
	$html=curl_exec($handle);
	$httpCode = curl_getinfo($handle,CURLINFO_HTTP_CODE);
	curl_close($handle);
	return ($httpCode != 404)? ($html) : (false);
}
if (!function_exists('http-chunked-decode')) {
	function is_hex($hex) {
		// regex is for weenies
		$hex = strtolower(trim(ltrim($hex,"0")));
		if (empty($hex)) { $hex = 0; };
		$dec = hexdec($hex);
		return ($hex == dechex($dec));
	}
	function http_chunked_decode($chunk) {
        $pos = 0;
        $len = strlen($chunk);
        $dechunk = null;

        while(($pos < $len)
            && ($chunkLenHex = substr($chunk,$pos, ($newlineAt = strpos($chunk,"\n",$pos+1))-$pos)))
        {
            if (! is_hex($chunkLenHex)) {
                trigger_error('Value is not properly chunk encoded', E_USER_WARNING);
                return $chunk;
            }

            $pos = $newlineAt + 1;
            $chunkLen = hexdec(rtrim($chunkLenHex,"\r\n"));
            $dechunk .= substr($chunk, $pos, $chunkLen);
            $pos = strpos($chunk, "\n", $pos + $chunkLen) + 1;
        }
        return $dechunk;
    }
}
// parse an HTTP header content
function parseHttpHeader($httpHeader) {
	$metaResponse = array();
	$metaInfo = explode("\r\n", $httpHeader);
	for ($mi=0; $mi<count($metaInfo); $mi++) {
		$metaI = explode(':', $metaInfo[$mi], 2);
		if (count($metaI) == 2) {
			$metaResponse[ trim($metaI[0]) ] = trim($metaI[1]);
		}
	}
	return $metaResponse;
}
// get remote file using socket
function getRemoteFile($url, $postparams='', $metadata=FALSE, $metadatasent=FALSE)
{
	$fp = getRemoteFileHandle($url, $postparams, $metadatasent);
	if( !$fp ) {
		$response = false;
	} else {
		$response = '';
		// retrieve the response from the remote server
		while ( (!feof($fp)) ) {
			if (($line = fgets( $fp, 8192 )) !== false) {
				$response .= $line;
				if ($metadata && $line=="\r\n") break;
			}
		}
		fclose( $fp );
		// strip the headers
		$pos    = strpos($response, "\r\n\r\n");
		$header = substr($response, 0, $pos+4); // every line in $header ends with "\r\n"
		preg_match('|HTTP/\d\.\d\s+(\d+)\s+.*|',$header,$match);
		$httpError = $match[1];
		if ($httpError == '404') {
			$response = false;
		}
		elseif ($httpError == '301' || $httpError == '302') {
			if (($pos = strpos($header, 'Location: ')) !== false) {
				$pos += 10; $pos2 = strpos($header,"\r\n", $pos);
				$oldURL = parse_url($url);
				$url = substr($header, $pos, $pos2-$pos);
				if (substr($url,0,4) != 'http') {
					$url = $oldURL['scheme'] . '://' . $oldURL['host'] . $url;
				}
				$response = getRemoteFile($url, $postparams);
			}
		} else {
			if ($metadata) {
				$response = parseHttpHeader($header);
			} else {
				$response = substr($response, $pos + 4);
				if (strpos($header,"Content-Encoding: gzip") != false) {
					$response = gzinflate(substr($response,10));
				} elseif (strpos($header,"Transfer-Encoding: chunked") != false) {
					$response = http_chunked_decode($response);
				}
			}
		}
	}
	// return the file content
	return $response;
}
function getRemoteFile2($url, $postparams=FALSE, $metadata=FALSE, $metadatasent=FALSE) {
	$handle = curl_init();
	curl_setopt($handle,CURLOPT_FRESH_CONNECT, true);
	curl_setopt($handle,CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($handle,CURLOPT_TIMEOUT, 1200);
	curl_setopt($handle,CURLOPT_SSL_VERIFYPEER, FALSE);
	curl_setopt($handle,CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($handle,CURLOPT_URL, $url);
	curl_setopt($handle,CURLOPT_FOLLOWLOCATION, TRUE);
	curl_setopt($handle,CURLOPT_HEADER, $metadata);
	curl_setopt($handle,CURLOPT_NOBODY, $metadata);
	$metadataheader = array(
		("User-Agent: " . ((isset($_SERVER['HTTP_USER_AGENT'])) ? $_SERVER['HTTP_USER_AGENT']:'Mozilla/5.0')),
		"Accept: */*",
		"Accept-Language: en-us,en;q=0.5",
		"Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7"
		);
	if ($metadatasent) $metadataheader = array_merge($metadataheader,$metadatasent);
	curl_setopt($handle,CURLOPT_HTTPHEADER, $metadataheader);

	if ($postparams) {
		curl_setopt($handle,CURLOPT_POST, TRUE);
		curl_setopt($handle,CURLOPT_POSTFIELDS, $postparams);
	}
	$urlResponse = curl_exec($handle);
	$retcode = curl_getinfo($handle,CURLINFO_HTTP_CODE);
	curl_close($handle);
	if ($retcode != 200) return false;
	if ($metadata) {
		// get only the meta data
		$urlResponse = parseHttpHeader($urlResponse);
	}
	return $urlResponse;
}
function getRemoteFileHandle($url, $postparams, $metadatasent=FALSE) {
	// get the host name and url path
	$parsedUrl = parse_url($url);
	$host = $parsedUrl['host'];
	if (isset($parsedUrl['path'])) {
		$path = $parsedUrl['path'];
	} else {
		$path = '/'; // the url is pointing to the host like http://www.usewide.com
	}
	if (isset($parsedUrl['query'])) {
		$path .= '?' . $parsedUrl['query'];
	}
	if (isset($parsedUrl['port'])) {
		$port = $parsedUrl['port'];
	} else {
		$port = '80'; // most sites use port 80
	}
	// -------------------------------------------------------------------------------------------------------------
	$isPOST = ($postparams != '');
	$timeout = ini_get('default_socket_timeout'); if ($timeout == 0) $timeout = 60;
	$hmethod = ($isPOST) ? 'POST' : 'GET';
	$postInfo = (empty($metadatasent)) ? '' : implode("\r\n",$metadatasent);
	$postInfo .= (($isPOST) ? ("Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($postparams) . "\r\n") : '');
	$httpprotocol = ( isset($_SERVER['SERVER_PROTOCOL']) ) ? $_SERVER['SERVER_PROTOCOL'] : "HTTP/1.1";
	// connect to the remote server
	$fp = @fsockopen( $host, $port, $errno, $errstr, 13 );
	if (!$fp) { return false; }
	// send the necessary headers to get the file
	fwrite(	$fp,"$hmethod $path $httpprotocol\r\n"
			. "Host: $host\r\n"
			. "User-Agent: ".((isset($_SERVER['HTTP_USER_AGENT'])) ? $_SERVER['HTTP_USER_AGENT']:'Mozilla/5.0')."\r\n"
			. "Accept: */*\r\n"
			. "Accept-Language: en-us,en;q=0.5\r\n"
			. "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7\r\n"
			. "Keep-Alive: $timeout\r\n"
			. "Connection: close\r\n" /*if this set to: keep-alive, then the data transfer will be slow*/
			// . "Referer: http://$host\r\n" /* some servers like esnips do not accept a reference */
			. "$postInfo\r\n$postparams" );
	// make sure read/write timeout on this stream
	stream_set_timeout( $fp, $timeout );
	return $fp;
}
function outFileToFile($ifile, $ofile, $tripHttpHeader) {
	while ( (!feof($ifile)) && ($line = fread( $ifile, 8192 )) !== false ) {
		if ($tripHttpHeader) {
			// strip the headers
			$pos  = strpos($line, "\r\n\r\n");
			if ($pos !== false) {
				$line = substr($line, $pos + 4);
			}
			$tripHttpHeader = ($line)? false : true;
			if ($tripHttpHeader) { continue; }
		}
		if ( fwrite($ofile, $line) == false ) break;
	}
	return (!$tripHttpHeader);
}
function outFileToPage($ifile, $tripHttpHeader) {
	while ( connection_aborted() != 1 && (!feof($ifile)) && ($line = fread($ifile, 8192)) !== false ) {
		if ($tripHttpHeader) {
			// strip the http headers
			$pos  = strpos($line, "\r\n\r\n");
			if ($pos !== false) {
				$contentPos = strpos($line,'Content-Length:');
				if ($contentPos !== false) { // check if the file size is informed?
					$contentPos2 = strpos($line,"\r\n",$contentPos);
					$fileSize = (int)(substr($line, $contentPos+15,$contentPos2-$contentPos-15));
					if ($fileSize == 0) { echo ($line . "\r\nBy: Le Ngoc Thanh"); return false; }
					header( substr($line, $contentPos, 2+$contentPos2-$contentPos) );
				}
				$line = substr($line, $pos + 4);
			}
			$tripHttpHeader = ($line)? false : true;
			if ($tripHttpHeader) { continue; }
		}
		print($line);
	}
	ob_end_flush(); // flush(); ob_flush(); // ob_flush() does not destroy the buffer, hence, not enough memory for large file
	return (!$tripHttpHeader);
}
//function getRemoteFile2Page($url, $postparams='', $tripHttpHeader=false)
//{
//	// connect to the remote server
//	$fp = getRemoteFileHandle($url, $postparams);
//	if (!$fp) {
//		fclose( $fsave );
//		die( "Cannot retrieve $url" );
//	}
//	outFileToPage($fp, $tripHttpHeader);
//	fclose( $fp );
//}
function getRemoteFileSaved($pathfile, $url, $postparams='', $tripheader=true)
{	// local file saved
	$fsave = fopen($pathfile, "wb");
	if(!$fsave) {
		return "Cannot create file $pathfile";
	}
	// connect to the remote server
	$fp = getRemoteFileHandle($url, $postparams);
	if (!$fp) {
		fclose( $fsave );
		return "Cannot retrieve $url";
	}
	$firstTime = outFileToFile($fp, $fsave, $tripheader);
	fclose( $fp ); fclose( $fsave );
	// return the result message
	return ($firstTime)? 'OK' : 'Cloud computing rejected!';
}

//--------------------------------------------------
// dispatch youtube
function GetYoutubeOriginalURL(&$url, &$title)
{
	// get video title from youtube
	$info = getRemoteFile($url);
	//$info = system("wget '$url' -O -");
	$p1 = strpos($info,"<h1");
	if ($p1)
	{
		$p1 = strpos($info,'title="', $p1) + 7;
		$p2 = strpos($info,"\">", $p1);
		$title = substr($info,$p1,$p2-$p1);
	}
	// ask service where to download the video, :)
	$p1 = strpos($info,'img.src = "');
	if ($p1)
	{
		$p1 += 11; $p2 = strpos($info,'";', $p1); $info = substr($info,$p1,$p2-$p1);
		$p1 = strpos($info,"?");
		if ($p1)
		{
			$url = substr($info,$p1); $info = substr($info,0,$p1);
			$p1 = strrpos($info,"/");
			$url = urldecode(substr($info, 0, $p1) . "/videoplayback" . $url);
			$url = str_replace("\\", "", $url);
			return true;
		}
	}
	return false;
}
//ESNIPS
function GetEsnipOriginalURL(&$url, &$title)
{
	$esnips = getRemoteFile($url);
	//$info = system("wget '$url' -O -");
	if (!$esnips) { $title = "Invalid eSnips URL"; return false; }
	$titleKey = '<h2>';
	$flashKey = '<div class="main_conetnt_cover {SLIDESHOW_STYLE}">';
    //int docPos, titlePos;
	if (($titlePos = strpos($esnips,$titleKey)) > 0 && ($docPos = strpos($esnips,$flashKey)) > 0)
    {
        $titlePos += strlen($titleKey);
		$docPos = 1 + strpos($esnips, '"', $docPos + strlen($flashKey));
        $lastIdx = strpos($esnips, "</", $titlePos);
        $mediaTitle = substr($esnips,$titlePos,$lastIdx - $titlePos);
		$lastIdx = strpos($esnips,'"',$docPos+2);
		$url = urldecode( substr($esnips, $docPos, $lastIdx-$docPos) );
        //$mediaFolder = substr($esnips, $docPos, $lastIdx - $docPos);
        //$mediaKeyIdx = $lastIdx + 1;
        //$lastIdx = strpos($esnips, '",',$mediaKeyIdx);
        //$madiaKey = substr($esnips, $mediaKeyIdx, $lastIdx - $mediaKeyIdx);
		//$times = 10000 * microtime(true); // (10.000.000 * seconds of the day) / 1000
		//$url = "http://www.esnips.com" . $mediaFolder . $madiaKey . '/ts_id/' . sprintf('%.0f', $times) . "/ns_flash/file88.mp3";
        $title = str_replace(" ", "_", $mediaTitle);
        return true;
    }
    $title = "unknown"; return false;
}
?>
