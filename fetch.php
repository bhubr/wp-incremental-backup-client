<?php
require realpath(__DIR__ . '/inc/constants.php');
require_once "vendor/autoload.php";

define('BACKUP_ROOT', '/Volumes/Backup/Geek/Sites');
define('KEEPASS_FILE', '/Volumes/NO NAME/sites.kdbx');
define('KEEPASS_DBID', 'sites');
define('KEEPASS_DEBUG', false);
define('WPIB_CLIENT_DEBUG_MODE', true);
define('WPIB_CLIENT_DEBUG_LEN', 15);

define('APPLICATION_NAME', 'Drive API PHP Quickstart');
define('CREDENTIALS_PATH', '~/.credentials/drive-php-upload.json');
define('CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');
define('FOLDER_ID_FILE', __DIR__ . '/folder_id.json');
// If modifying these scopes, delete your previously saved credentials
// at ~/.credentials/drive-php-quickstart.json
define('SCOPES', implode(' ', array(
  Google_Service_Drive::DRIVE_METADATA_READONLY)
));

if (php_sapi_name() != 'cli') {
  throw new Exception('This application must be run on the command line.');
}

use \KeePassPHP\KeePassPHP as KeePassPHP;
use \KeePassPHP\ProtectedXMLReader as ProtectedXMLReader;

$global_fh = null;

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient() {
  $client = new Google_Client();
  $client->setApplicationName(APPLICATION_NAME);
  // $client->setScopes(SCOPES);
  $client->addScope("https://www.googleapis.com/auth/drive");
  $client->setAuthConfigFile(CLIENT_SECRET_PATH);
  $client->setAccessType('offline');

  // Load previously authorized credentials from a file.
  $credentialsPath = expandHomeDirectory(CREDENTIALS_PATH);
  if (file_exists($credentialsPath)) {
    $accessToken = json_decode(file_get_contents($credentialsPath), true);
  } else {
    // Request authorization from the user.
    $authUrl = $client->createAuthUrl();
    printf("Open the following link in your browser:\n%s\n", $authUrl);
    print 'Enter verification code: ';
    $authCode = trim(fgets(STDIN));

    // Exchange authorization code for an access token.
    $accessToken = $client->authenticate($authCode);
// var_dump($accessToken);
    // Store the credentials to disk.
    if(!file_exists(dirname($credentialsPath))) {
      mkdir(dirname($credentialsPath), 0700, true);
    }
    file_put_contents($credentialsPath, json_encode($accessToken));
    printf("Credentials saved to %s\n", $credentialsPath);
  }
  $client->setAccessToken($accessToken);
// var_dump($client->getAccessToken());
  // Refresh the token if it's expired.
  if ($client->isAccessTokenExpired()) {
    $client->refreshToken($client->getRefreshToken());
    file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
  }
  var_dump($client);
  return $client;
}


/**
 * Expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory($path) {
  $homeDirectory = getenv('HOME');
  if (empty($homeDirectory)) {
    $homeDirectory = getenv("HOMEDRIVE") . getenv("HOMEPATH");
  }
  return str_replace('~', realpath($homeDirectory), $path);
}


function readVideoChunk ($handle, $chunkSize)
{
    $byteCount = 0;
    $giantChunk = "";
    while (!feof($handle)) {
        // fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
        $chunk = fread($handle, 8192);
        $byteCount += strlen($chunk);
        $giantChunk .= $chunk;
        if ($byteCount >= $chunkSize)
        {
            return $giantChunk;
        }
    }
    return $giantChunk;
}

// http://stackoverflow.com/questions/187736/command-line-password-prompt-in-php
function prompt_silent($prompt = "Enter Password:") {
  if (preg_match('/^win/i', PHP_OS)) {
    $vbscript = sys_get_temp_dir() . 'prompt_password.vbs';
    file_put_contents(
      $vbscript, 'wscript.echo(InputBox("'
      . addslashes($prompt)
      . '", "", "password here"))');
    $command = "cscript //nologo " . escapeshellarg($vbscript);
    $password = rtrim(shell_exec($command));
    unlink($vbscript);
    return $password;
  } else {
    $command = "/usr/bin/env bash -c 'echo OK'";
    if (rtrim(shell_exec($command)) !== 'OK') {
      trigger_error("Can't invoke bash");
      return;
    }
    $command = "/usr/bin/env bash -c 'read -s -p \""
      . addslashes($prompt)
      . "\" mypassword && echo \$mypassword'";
    $password = rtrim(shell_exec($command));
    echo "\n";
    return $password;
  }
}



class T1z_WP_Incremental_Backup_Client {

	/**
	 * cURL handle
	 */
	private $ch;

	/**
	 * Second handle for downloading files
	 */
	// private $ch_download;

	/**
	 * Parsed json response
	 */
	private $parsed_response;

	/**
	 * Config data
	 */
	private $config;

	private $login_url;

	private $num_archives;

	/**
	 * Latest ZIP file name
	 */
	private $zip_filename;

	private $downloaded_files;

	private $download_fh;

	private $dest_dir_prefix;
	private $dest_dir;
	private $mode = 'normal';

	private $keepass_db;

	private $keepass_map = [];

	private $keepass_datadir = __DIR__ . "/data";

	/**
	 * Google Drive client&service
	 */
	private $client;
	private $service;

	/**
	 * Constructor: read ini file and setup cURL
	 */
	public function __construct() {
		// if(! is_dir('BACKUP_ROOT')) die(BACKUP_ROOT . " could not be found\n");
		$this->setup_keepass();
		$this->cookie = tempnam ("/tmp", "CURLCOOKIE");
		$this->read_config();
		$this->client = getClient();
		$this->service = new Google_Service_Drive($this->client);
		if (!$this->client->getAccessToken()) {
			die("Une erreur est survenue lors de la connexion à Google Drive");
		}
		if(file_exists(FOLDER_ID_FILE)) {
			$this->gd_folder_id = file_get_contents(FOLDER_ID_FILE);
		}
		else {
			$fileMetadata = new Google_Service_Drive_DriveFile(array(
			  'name' => 'WordPressBackup',
			  'mimeType' => 'application/vnd.google-apps.folder'));
			$file = $this->service->files->create($fileMetadata, array(
			  'fields' => 'id'));
			printf("Folder ID: %s\n", $file->id);
			$this->gd_folder_id = $file->id;
			file_put_contents(FOLDER_ID_FILE, $file->id);
		}

	}

	private function setup_keepass() {

		if(!KeePassPHP::init($this->keepass_datadir, KEEPASS_DEBUG)) {
			die("KeePassPHP Initialization failed.");
		}

		$pwd = prompt_silent();
		$kphpdb_pwd = '';
		if(empty($kphpdb_pwd)) {
			$kphpdb_pwd = KeePassPHP::extractHalfPassword($pwd);
		}

		if(KeePassPHP::existsKphpDB(KEEPASS_DBID))
		{
			if(!KeePassPHP::removeDatabase(KEEPASS_DBID, $kphpdb_pwd))
				printf("Database '" . KEEPASS_DBID .
					"' already exists and cannot be deleted.");
		}


		if(!KeePassPHP::addDatabaseFromFiles(KEEPASS_DBID, KEEPASS_FILE, $pwd, '', $kphpdb_pwd, true)) {
			die("Cannot add database '" . KEEPASS_DBID . "'.");
		}
				
		$this->keepass_db = KeePassPHP::getDatabase(KEEPASS_DBID, $kphpdb_pwd, $pwd, true);
		if($this->keepass_db == null) {
			die("Cannot get database '" . KEEPASS_DBID . "'.");
		}
		$groups = $this->keepass_db->getGroups();
		$root = $groups[0];
		$xmlReader = new ProtectedXMLReader;
		foreach($root->entries as $entry) {
			$this->keypass_map[$entry->title] = [
				'uuid' => $entry->uuid,
				'user' => $entry->username,
				'url'  => $entry->url
			];
		}

	}

	private function get_credentials($site) {
		$entry = $this->keypass_map[$site];
		$user = $entry['user'];
		$pwd = $this->keepass_db->getPassword($entry['uuid']);
		if (empty($user)) {
			die("Empty username for site [$site] !\n");
		}
		if (empty($pwd)) {
			die("Empty password for site [$site] !\n");
		}
		return [
			'user' => $user,
			'pass' => $pwd
		];
	}

	private function get_url($site) {
		$url = $this->keypass_map[$site]['url'];
		if (empty($url)) {
			die("Empty url for site [$site] !\n");
		}
		return $url;
	}

	/**
	 * read ini file
	 */
	private function read_config() {
		$this->config = parse_ini_file(__DIR__ . '/fetch.ini', true);
	}

	/**
	 * cURL base setup
	 */
	private function setup_curl() {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6");
		curl_setopt($ch, CURLOPT_TIMEOUT, 6000);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_COOKIESESSION, true);
		curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		return $ch;
	}


	/**
	 * Run process
	 */
	public function run() {
		foreach ($this->config as $site => $config) {
			$this->downloaded_files = [
				'files' => [],
				'sql' => []
			];
			$this->ch = $this->setup_curl();
			if (! $this->ch) { die ("cURL init failed for 1st handle!!"); }
			$this->setup_curl_for_html();
			// $this->ch_download = $this->setup_curl();
			// if (! $this->ch_download) { die ("cURL init failed for 1st handle!!"); }
			printf ("\n\n *******   Begin process for site: %25s   ********\n", $site);

			if (substr($this->get_url($site), -1) !== '/') {
				$this->keypass_map[$site]['url'] .= '/';
			}

			$this->dest_dir_prefix = $this->get_destination_dir($site);
			$this->dest_dir = $this->dest_dir_prefix . DIRECTORY_SEPARATOR . "wpib";
			$this->wp_expanded_dir = $this->dest_dir_prefix . DIRECTORY_SEPARATOR . "wordpress";

			// $this->get_login($config);
			// foreach (['json' => $this->ch, 'binary' => $this->ch_download] as $type => 	$curl_handle) {
			// 	
				
			// }
			$this->login_to_wordpress($config, $site);

			$this->setup_curl_for_json();
			// die('ok login');
			$this->post_generate_backup($config, $site);
			$this->concat_backups($config, $site);
			curl_close($this->ch);
			exec("rm -rf {$this->keepass_datadir}", $out, $ret);
		}
	}

	/**
	 * Basic log function
	 */
	private function log($label, $str) {
		echo "----- $label -----\n";
		echo substr($str, 0, WPIB_CLIENT_DEBUG_LEN) . "\n\n"; 
	}

	private function login_to_wordpress($ch, $config) {
		$this->get_login($ch, $config);
		$this->post_login($ch, $config);
	}

	/**
	 * GET WordPress login page
	 */
	private function get_login($config, $site) {
		var_dump($config);
		$this->login_url = $this->get_url($site) . (array_key_exists('login_url', $config) ? $config['login_url'] : 'wp-login.php');
		printf("* GET login page [url = %s]\n", $this->login_url);
		curl_setopt($this->ch, CURLOPT_URL,  $this->login_url);
		curl_exec($this->ch);
		// $response = $this->send_request();
		if (! $this->response) die("[get_login] cURL error: " . curl_error($this->ch) . "\n");
		if (WPIB_CLIENT_DEBUG_MODE) $this->log('GET login page', $this->response);
	}

	/**
	 * POST request to sign in to WordPress
	 */
	private function post_login($config, $site) {
		$credentials = $this->get_credentials($site);
		$postdata = "log=". $credentials['user'] ."&pwd=". urlencode($credentials['pass']) ."&wp-submit=Se+connecter&redirect_to=". $this->get_url($site) ."wp-admin/&testcookie=1";
		printf("\n\n * POST login to %\n", $this->login_url);

		// $html_response = $this->send_request();

		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $postdata);
		curl_setopt($this->ch, CURLOPT_POST, 1);
		curl_exec($this->ch);
		// $response = $this->send_request();
		$lines = explode("\n", $this->response);
		if (count($lines) < 2) {
			echo "This doesn't seem like an HTML output:\n";
			var_dump($lines);
			exit;
		}
		if (! preg_match('/.*wp\-toolbar.*/', $lines[2])) {
			echo "There should be 'wp-toolbar' on this line:\n";
			echo $lines[2] . "\n";
			var_dump($lines);
			// exit;
		}
		if (! $this->response) die("[post_login] cURL error: " . curl_error($this->ch) . "\n");
		if (WPIB_CLIENT_DEBUG_MODE) $this->log('POST login credentials', $this->response);
	}

	/**
	 * GET WordPress admin page
	 */
	// private function get_admin($config, $site) {
	// 	curl_setopt($this->ch, CURLOPT_POST, 0);
	// 	curl_setopt($this->ch, CURLOPT_POSTFIELDS, "");
	// 	curl_setopt($this->ch, CURLOPT_URL, $this->get_url($site) . 'wp-admin/');
	// 	// $response = curl_exec ($this->ch);
	// 	if (! $response) die("[get_login] cURL error: " . curl_error($this->ch) . "\n");
	// 	if (WPIB_CLIENT_DEBUG_MODE) $this->log('GET login page', $response);
	// }

	private function check_request_response($data) {

		$this->response = $data;
		// Send request and die on cURL error
		// $response = curl_exec($this->ch);
		$http_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

echo $this->mode . "\n";
		if($this->mode === 'download') return;

		// Die on HTTP error
		if ($http_code !== 200) {
			die(" !!! HTTP error (code = $http_code): $data\n");
		}
		// Die on cURL error
		if (empty($this->response)) {
			die(" !!! [post_generate_backup] cURL error: " . curl_error($this->ch) . "\n");
		}
		
		// return $this->response;
	}

	// private function curl_parse_html_response() {
	// 	$html_response = $this->send_request();
	// }	
	private function curl_get_html_response($ch, $data) {
		echo "cURL write func: " . __FUNCTION__ . "\n";
		$this->check_request_response($data);
		// die($data);
	}

	private function curl_parse_json_response($ch, $data) {
		echo "cURL write func: " . __FUNCTION__ . "\n";
		// $json_response = $this->send_request();
		$this->check_request_response($data);
		printf("\ncurl_parse_json_response: %s\n",substr($this->response, 0, 30));


		// Parse JSON response
		$this->parsed_response = json_decode($this->response);

		// Die on empty parsed response
		if (empty($this->parsed_response)) {
			echo " !!! [post_generate_backup] JSON parse error. Received payload:\n";
			die('[>' . $this->response . "<]\n");
		}

		// return $parsed_response;
	}

	private function progress_nop() {

	}

	private function progress($resource,$download_size, $downloaded, $upload_size, $uploaded)
	{

		echo __FUNCTION__ . "{$downloaded}/{$download_size}\n";
	    // if($download_size > 0)
	    //      echo $downloaded / $download_size  * 100;
	    // ob_flush();
	    // flush();
	    // sleep(1); // just to see effect
	}


	private function setup_curl_for_html() {
		// curl_setopt($this->ch, CURLOPT_BINARYTRANSFER, 0);
		// $this->mode = 'normal';
		curl_setopt($this->ch, CURLOPT_WRITEFUNCTION, [$this, 'curl_get_html_response']);
		// curl_setopt($this->ch, CURLOPT_PROGRESSFUNCTION, [$this, 'progress_nop']);
	}

	private function setup_curl_for_json() {
		// curl_setopt($this->ch, CURLOPT_BINARYTRANSFER, 0);
		// $this->mode = 'normal';
		curl_setopt($this->ch, CURLOPT_WRITEFUNCTION, [$this, 'curl_parse_json_response']);
		// curl_setopt($this->ch, CURLOPT_PROGRESSFUNCTION, [$this, 'progress_nop']);
	}


	private function setup_curl_for_download() {
		echo "SETUP CURL: " . __FUNCTION__ . "\n";
		$this->total_written = 0;
		// die(__FUNCTION__);
		// $this->mode = 'download';
		// die($this->mode);
		// curl_setopt($ch, CURLOPT_POST, 0);
		// curl_setopt($ch, CURLOPT_VERBOSE, 0);
		// curl_setopt($ch, CURLOPT_POSTFIELDS,"");
		// curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		// curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($this->ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($this->ch, CURLOPT_WRITEFUNCTION, [$this, 'curl_write_file']);
		// curl_setopt($this->ch, CURLOPT_PROGRESSFUNCTION, [$this, 'progress']);
	}


	function curl_write_file($cp, $data) {
		global $global_fh;
		
		$http_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		// echo "cURL write func: " . __FUNCTION__ . ", http code: $http_code\n";
	  // global $global_fh;
		var_dump($global_fh);
		var_dump(substr(base64_encode($data), 0, 50));
	  $len = fwrite($global_fh, $data);
	  $this->total_written += $len;
	  // echo "$len bytes written\n";
	  return $len;
	}


	private function download_and_check($site, $file, $dest) {
		global $global_fh;
		$check_md5_url = $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_check_md5";
		$destination = $this->dest_dir . DIRECTORY_SEPARATOR . $file;
// die($destination);
		echo "Preparing download for $file => $destination\n";
		echo "Downloading $destination ... \n";
		// $ch = $this->setup_curl();

		// $this->login_to_wordpress($this->ch_download, $config);
		curl_setopt ($this->ch, CURLOPT_URL, $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_download&filename=$file");
echo "setup for download\n";
		$global_fh = fopen($destination, "w+");
		if (!$global_fh) die("could not open $destination\n");
		// var_dump($global_fh);
		$this->setup_curl_for_download();
		echo "size before: " . filesize($destination) . "\n";
		curl_exec($this->ch);
		fclose($global_fh);
		sleep(1);
		$stat = stat($destination);
		echo "total written: {$this->total_written}\n";
		echo "stat for $destination\n";
		// var_dump($stat);
		echo "size after: " . filesize($destination) . "\n";
		// curl_exec($this->ch);

echo "download call done\n";
		if(! file_exists($destination)) {
			printf("Error while downloading %s\n", $destination);
			exit(1);
		}
		if (!array_key_exists($dest, $this->downloaded_files)) {
			$this->downloaded_files[$dest] = [];
		}
		$this->downloaded_files[$dest][] = $destination;

echo "setup for json and fire md5 check\n";
		$this->setup_curl_for_json();
		$md5 = md5_file($destination);
		// ech
echo "$check_md5_url&file=" . urlencode($file) . "&md5=$md5\n";
		curl_setopt ($this->ch, CURLOPT_URL, "$check_md5_url&file=" . urlencode($file) . "&md5=$md5");
		curl_exec($this->ch);

		if($this->parsed_response->md5_match !== true) {
			printf("md5 differ: srv=%s, cli=%s", $this->parsed_response->md5_server, $md5);
			exit(1);
		}

		
	}


	private function loop_downloads($config, $site) {

		// Setup output dir first
		// $dest_dir_prefix = $this->get_destination_dir($site);
		// $dest_dir = $dest_dir_prefix . DIRECTORY_SEPARATOR . "wpib";
		// $wp_expanded_dir = $dest_dir_prefix . DIRECTORY_SEPARATOR . "wordpress";

		// if (!is_dir($dest_dir)) mkdir($dest_dir, 0777, true);
		// if (!is_dir($wp_expanded_dir)) mkdir($wp_expanded_dir);

		// global $global_fh;
		$download_url = $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_download";
		$list_url = $download_url . "&list=1";
		
		$gen_url = $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_generate&step=build_archives";
		if(isset($config['php_path'])) $gen_url .= '&php_path=' . urlencode($config['php_path']);
		// printf(" * Start step %5s", $step);
		// for ($idx_sub = 0 ; $idx_sub < $num_substeps ; $idx_sub++)  {
		$arc_idx = 0;

		// $json_response = $this->get_parsed_json_response($this->ch);
		
		// var_dump($json_response);
		// if (empty($json_response->files)) {
		// 	echo "*** EMPTY files array\n";
		// 	return;
		// }
		curl_setopt ($this->ch, CURLOPT_URL, $gen_url . "&arc_idx=$arc_idx");
		curl_exec($this->ch);
		

		// var_dump($files);die();
		while($arc_idx < $this->num_archives) {

			do {
				curl_setopt ($this->ch, CURLOPT_URL, $list_url);
				curl_exec($this->ch);
				$json_response = $this->parsed_response;
				$files = $json_response->files;
				var_dump($files);
				sleep(4);
			} while (empty($files));

			$arc_idx++;

			if ($arc_idx < $this->num_archives) {
				curl_setopt ($this->ch, CURLOPT_URL, $gen_url . "&arc_idx=$arc_idx");
				curl_exec($this->ch);
			}


			$file = basename(array_shift($files));
			echo "Downloading arc idx: $arc_idx $file\n";
			$this->download_and_check($site, $file, 'files');
			$this->upload_to_google_drive($site, $file);

			printf("%d/%d DONE with file %s\n", $arc_idx, $this->num_archives, $file);
		}
	

	}

	private function upload_to_google_drive($site, $filename) {
		// Fuck PHP, and I mean it!
		// http://stackoverflow.com/questions/5167313/php-problem-filesize-return-0-with-file-containing-few-data
		clearstatcache();
		$source = $this->dest_dir . DIRECTORY_SEPARATOR . $filename;
		// die($source . ', ' . filesize($source));
		$file = new Google_Service_Drive_DriveFile([
			'name' => $filename,
			'parents' => [$this->gd_folder_id]
		]);
		$file->name = "$filename";
		$chunkSizeBytes = 1 * 1024 * 1024;

		// Call the API with the media upload, defer so it doesn't immediately return.
		$this->client->setDefer(true);
		$request = $this->service->files->create($file);

		// Create a media file upload to represent our upload process.
		$media = new Google_Http_MediaFileUpload(
		    $this->client,
		    $request,
		    'application/bzip',
		    null,
		    true,
		    $chunkSizeBytes
		);
		$media->setFileSize(filesize($source));

		// Upload the various chunks. $status will be false until the process is
		// complete.
		$status = false;
		$handle = fopen($source, "rb");
		while (!$status && !feof($handle)) {
		  // read until you get $chunkSizeBytes from TESTFILE
		  // fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
		  // An example of a read buffered file is when reading from a URL
		  $chunk = readVideoChunk($handle, $chunkSizeBytes);
		  $status = $media->nextChunk($chunk);
		}

		// The final value of $status will be the data from the API for the object
		// that has been uploaded.
		$result = false;
		if ($status != false) {
		  $result = $status;
		}

		fclose($handle);
	}

	/**
	 * POST request to generate backup
	 */
	private function post_generate_backup($config, $site) {

		// clear POST data
		curl_setopt ($this->ch, CURLOPT_POSTFIELDS, "");

		// base URL (step param will be appended later)
		$gen_url = $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_generate";
		$check_url = $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_check_progress";
		

		// various steps of process
		$steps = ['dump_sql', 'list_deleted', 'list_md5']; //, 'build_archives']; //, 'sql']; //, 'zip'];
		

		foreach($steps as $step) {
			$num_substeps = $step === 'build_archives' ? $this->num_archives : 1;
			$url = "$gen_url&step=$step";
			if(isset($config['php_path'])) $url .= '&php_path=' . urlencode($config['php_path']);
			if(isset($config['exclude']) && $step === 'list_md5') $url .= '&exclude=' . urlencode($config['exclude']);
			echo "Send request to: $url\n";
			curl_setopt ($this->ch, CURLOPT_URL, $url);
			printf(" * Start step %5s", $step);

			// for ($idx_sub = 0 ; $idx_sub < $num_substeps ; $idx_sub++)  {
			// $url_sub = $step === 'build_archives' ? "&arc_idx=$idx_sub" : "";
			curl_setopt ($this->ch, CURLOPT_URL, $url);
			curl_exec ($this->ch);
			
			$parsed_response = $this->parsed_response;

			// echo " ($parsed_response->step_of_total)  ==>  .";
			$num_calls = 1;


			// Parse response and die on error
			curl_setopt ($this->ch, CURLOPT_URL, "$check_url&step=$step");
			while($parsed_response->done === false) {
				// echo "$step ==> check ($check_url&step=$step)\n";
				echo ".";
				$num_calls += 1;

				// if($step === 'build_archives') {
				// 	$this->loop_downloads($config, $site);
					
				// }
				curl_setopt ($this->ch, CURLOPT_URL, "$check_url&step=$step");
				curl_exec ($this->ch);
				$parsed_response = $this->parsed_response; //json_decode($json_response);
			}
			$padding = 31 - $num_calls;
			printf("%{$padding}s", "OK *\n");

			if($step === 'list_md5') {
				$this->num_archives = $parsed_response->num_archives;
				echo "NUM ARCHIVES: {$this->num_archives}\n";
				// die("num arc:" . $this->num_archives);
			}

			if($step === 'dump_sql') {
				$this->download_and_check($site, $parsed_response->files[0], 'sql');
				// die();
				// die("num arc:" . $this->num_archives);
			}

			// if($step === 'build_archives') {
			// 	$this->loop_downloads($config, $site);
			// }

		}

		$step = 'build_archives';
		// curl_exec($this->ch);
		$this->loop_downloads($config, $site);
		printf(" * Process done for %25s! *\n", $site);
		
	}

	private function get_destination_dir($site) {
		return BACKUP_ROOT . DIRECTORY_SEPARATOR . $site;
	}

	/**
	 * GET request to fetch backup
	 */
	private function concat_backups($config, $site) {
		// global $global_fh;
		// Setup output dir first

		if (!is_dir($this->dest_dir)) mkdir($this->dest_dir, 0777, true);
		if (!is_dir($this->wp_expanded_dir)) mkdir($this->wp_expanded_dir);

		$count_tarballs = count($this->downloaded_files['files']);
		// Open output file
		var_dump($this->downloaded_files);
		foreach($this->downloaded_files['files'] as $i => $file) {
			$cmd = "cd {$this->wp_expanded_dir} ; tar xvjf $file";
			echo "$cmd\n";
			printf("Unpacking %d of %d: %s\n", $i + 1, $count_tarballs, $cmd);
			exec($cmd, $out, $ret);
			// var_dump($out);
		}

		$sqldump = $this->downloaded_files['sql'][0];
		$cmd = "cd {$this->dest_dir} ; bunzip2 $sqldump";
		exec($cmd, $out, $ret);
		// var_dump($out);


		// $info = pathinfo($destination);
		// $filename_prefix =  basename($destination,'.'.$info['extension']);
		// $tar = "$filename_prefix.tar";
		// $tar_fullpath = $dest_dir . DIRECTORY_SEPARATOR . $tar;

		// $cmd1 = "cd $dest_dir; unzip " . $this->zip_filename;
		// echo $cmd1 . "\n";
		// echo shell_exec($cmd1);

		// if(file_exists($tar_fullpath)) {
		// 	$cmd2 = "cd $wp_expanded_dir; tar xvf $tar_fullpath";
		// 	echo $cmd2 . "\n";
		// 	echo shell_exec($cmd2);

		// 	$to_delete_list_file = $wp_expanded_dir . DIRECTORY_SEPARATOR . FILES_TO_DELETE;
		// 	if(file_exists($to_delete_list_file)) {
		// 		$files_to_delete = file($to_delete_list_file);
		// 		$files_to_delete_escaped = array_map(function($file) {
		// 			return escapeshellarg(trim($file));
		// 		}, $files_to_delete);
		// 		$files_to_delete_str = implode(' ', $files_to_delete_escaped);
		// 		// var_dump($files_to_delete_str);
		// 		$cmd3 = "cd $wp_expanded_dir; rm $files_to_delete_str";
		// 		echo shell_exec($cmd3);

		// 		// unlink($to_delete_list_file);
		// 	}
		// }
	}
}



// // 2- POST




$client = new T1z_WP_Incremental_Backup_Client();
$client->run();
exit;