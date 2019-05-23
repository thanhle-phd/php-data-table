<?php
/*use ssh chanel to OS, by thanh-le @ tinyray.com
  --------------------------------------------------------
   to have PHP ssh2_connect
  	sudo apt-get install php7.0-cli -y
		sudo apt-get install libssh2-1 php-ssh2 -y
   then restart php-fpm service
  --------------------------------------------------------*/
class lntCommand {
	public function fRead($pathFilename) {
		$stream = $this->fOpen($pathFilename, 'r');
		if ($stream) {
			#NONEED: stream_set_blocking($stream, true);
			$contents = stream_get_contents($stream);
			fclose($stream); return $contents;
		}
		return false;
	}

	public function fOpen($pathFilename, $smode='r') {
		$stream = false;
		if ($this->sftpConn) {
			try {
				$stream = @fopen('ssh2.sftp://' . intval($this->sftpConn) . $pathFilename, $smode);
			} catch(Exception $e) { $stream = false; }
		}
		return $stream;
	}

	public function fCopy($destPath, $attachedData) {
		// $attachedData = array( [file-name => file-content]* )
		$fNo = 0;
		foreach ($attachedData as $atfilename => $atfiledata) {
			$handle = $this->fOpen($destPath.$atfilename, 'wt');
			if ($handle === false) break;
			fwrite($handle, $atfiledata); fclose($handle);
			$fNo++;
		}
		return $fNo;
	}

	function Exec($cmd, &$output=NULL) {
		$stream = null;
		if ( !($stream = @ssh2_exec($this->sshConn, $cmd )) ) { return false; }
		// read remote program output
		stream_set_blocking($stream, true);
		$strResult = "";
		while ( $buf = fread($stream, 4096) ) {
			$strResult .= $buf;
		}
		fclose($stream);
		if (!is_null($output)) {
			$output = $this->Result2Array($strResult);
		}
		return $strResult;
	}
	public function Result2Array($strRs) {
		$strRs = trim($strRs);
		if ($strRs) {
			$startPos = strpos($strRs,"+OK begin\n");
  			if ($startPos !== false) {
  				$startPos = $startPos+10;
				$endPos = strpos($strRs,"\n+OK end", $startPos);
				$strRs = substr($strRs, $startPos, $endPos-$startPos);
  			}
			return explode("\n", $strRs);
		}
		return false;
	}
	public function isReady() {
		return ($this->sshConn) ? true : false;
	}

	private $sshConn, $sftpConn;
	function __construct($sshInfo=null) {
/////////////////////////////////////////
// this is temporarily used until I complete the computing API system
//global $config;
//if ($config['lntweb_userid'] != 7) {
//	$this->lastError = 'Dr. Le is rebuilding cloud computing API.<br/>Our apologies. Please check back later!';
//	return;
//}
/////////////////////////////////////////
		global $config; $this->sshConn = false; $this->sftpConn = false;
		$serverPARTS = explode(':', (is_null($sshInfo) ? $config['lntweb_logprog'] : $sshInfo));
		if (count($serverPARTS) == 3) {
			$sshConn = @ssh2_connect($serverPARTS[1], $serverPARTS[2]);
			if ($sshConn) {
				//authenticate with secure keys
				if ( @ssh2_auth_pubkey_file($sshConn, $serverPARTS[0], "../luutru/sh/sshkey.pub", "../luutru/sh/sshkey") ) {
					$this->sshConn = $sshConn;
					$sftpConn = @ssh2_sftp($sshConn);
					if ($sftpConn) $this->sftpConn = $sftpConn;
				}
				//authenticate with username root, password secretpassword
				//if(!@ssh2_auth_password($sshConn, $serverPARTS[0], "Xxx-xxxx")) {
			}
			if (!$this->sshConn) {
				$this->lastError = 'The backend server may be offline now.<br/>My apologies. Please try again later.';
			}
		} else { $this->lastError = 'Bad backend server configurations'; }
	}
	function __destruct() { $this->Clear(); }
	public function GetLastError() { return $this->lastError; }
	private $lastError = 'OK';
	private function Clear() {
		if ($this->sshConn !== false) { unset($this->sshConn); $this->sshConn = false; }
		if ($this->sftpConn !== false) { unset($this->sftpConn); $this->sftpConn = false; }
	}
}
?>
