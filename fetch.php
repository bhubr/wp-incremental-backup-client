<?php
/**
 * WP Incremental Backup Client
 * Uses:
 *   - cURL to send requests the Backup Plugin
 *   - Google Drive to stored fetched backup files
 *   - KeePass to store WordPress site credentials
 */

/**
 * Class shorthands
 */
use \KeePassPHP\KeePassPHP as KeePassPHP;
use \KeePassPHP\ProtectedXMLReader as ProtectedXMLReader;

/**
 * Constants and autoloader
 */
require realpath(__DIR__ . '/inc/constants.php');
require_once "vendor/autoload.php";

/**
 * Client constants
 */
define('BACKUP_ROOT', '/Volumes/Backup/Geek/Sites');
define('WPIB_CLIENT_DEBUG_MODE', true);
define('WPIB_CLIENT_DEBUG_LEN', 15);

/**
 * KeePass constants
 */
define('KEEPASS_FILE', '/Volumes/NO NAME/sites.kdbx');
define('KEEPASS_DBID', 'sites');
define('KEEPASS_DEBUG', false);

/**
 * Google Drive constants
 */
define('APPLICATION_NAME', 'Drive API PHP Quickstart');
define('CREDENTIALS_PATH', '~/.credentials/drive-php-upload.json');
define('CLIENT_SECRET_PATH', __DIR__ . '/client_secret.json');
define('FOLDER_ID_FILE', __DIR__ . '/folder_id.json');

/**
 * Global file handler for big downloads
 */
global $global_fh;
$global_fh = null;

/*------------------------
 | Application starts here
 *------------------------*/
if (php_sapi_name() != 'cli') {
  throw new Exception('This application must be run on the command line.');
}

/**
 * Google Drive: returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function get_client() {
	$client = new Google_Client();
	$client->setApplicationName(APPLICATION_NAME);
	$client->addScope("https://www.googleapis.com/auth/drive");
	$client->setAuthConfigFile(CLIENT_SECRET_PATH);
	$client->setAccessType('offline');

  	// Load previously authorized credentials from a file.
	$credentialsPath = expand_home_directory(CREDENTIALS_PATH);
	if (file_exists($credentialsPath)) {
	    $accessToken = file_get_contents($credentialsPath);
	} else {
	    // Request authorization from the user.
	    $authUrl = $client->createAuthUrl();
	    printf("Open the following link in your browser:\n%s\n", $authUrl);
	    print 'Enter verification code: ';
	    $authCode = trim(fgets(STDIN));

	    // Exchange authorization code for an access token.
	    $accessToken = $client->authenticate($authCode);

	    // Store the credentials to disk.
	    if(!file_exists(dirname($credentialsPath))) {
			mkdir(dirname($credentialsPath), 0700, true);
	    }
	    file_put_contents($credentialsPath, json_encode($accessToken));
	    printf("Credentials saved to %s\n", $credentialsPath);
	}
  	$client->setAccessToken($accessToken);

	// Refresh the token if it's expired.
	if ($client->isAccessTokenExpired()) {
	    $client->refreshToken($client->getRefreshToken());
	    file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
	}
	return $client;
}


/**
 * Google Drive: expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expand_home_directory($path) {
  $homeDirectory = getenv('HOME');
  if (empty($homeDirectory)) {
    $homeDirectory = getenv("HOMEDRIVE") . getenv("HOMEPATH");
  }
  return str_replace('~', realpath($homeDirectory), $path);
}

/**
 * Google Drive: read a chunk from file handle
 */
function read_video_chunk ($handle, $chunkSize)
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

/**
 * Prompt a password silently
 * Source: http://stackoverflow.com/questions/187736/command-line-password-prompt-in-php
 */
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


/**
 * Client main class
 */
class T1z_WP_Incremental_Backup_Client {

	/**
	 * cURL handle
	 */
	private $ch;

	/**
	 * Parsed json response
	 */
	private $parsed_response;

	/**
	 * Config data
	 */
	private $config;

	/**
	 * Login URL to WordPres site
	 */
	private $login_url;

	/**
	 * Total number of tarballs to be fetched
	 */
	private $num_archives;

	/**
	 * Array containing the downloaded files
	 */
	private $downloaded_files;

	/**
	 * Per-site root dir
	 */
	private $dest_dir_prefix;

	/**
	 * Destination dir for downloaded backup files
	 */
	private $dest_dir;

	/**
	 * WordPress uncompressed dir
	 */
	private $wp_expanded_dir;

	/**
	 * KeePass database
	 */
	private $keepass_db;

	/**
	 * Map KeePass uuid to sites
	 */
	private $keepass_map = [];

	/**
	 * KeePassPHP data dir
	 */
	private $keepass_datadir = __DIR__ . "/data";

	/**
	 * Google Drive client
	 */
	private $client;

	/**
	 * Google Drive service
	 */
	private $service;

	/**
	 * Constructor: read ini file and setup cURL
	 */
	public function __construct() {
		$this->setup_keepass();
		$this->cookie = tempnam ("/tmp", "CURLCOOKIE");
		$this->read_config();
		$this->client = get_client();
		$this->service = new Google_Service_Drive($this->client);
		if (!$this->client->getAccessToken()) {
			die("Une erreur est survenue lors de la connexion à Google Drive");
		}
		if(file_exists(FOLDER_ID_FILE)) {
			$this->gd_folder_id = file_get_contents(FOLDER_ID_FILE);
		}
		else {
			$file_metadata = new Google_Service_Drive_DriveFile(array(
			  'name' => 'WordPressBackup',
			  'mimeType' => 'application/vnd.google-apps.folder'));
			$file = $this->service->files->create($file_metadata, array(
			  'fields' => 'id'));
			printf("Folder ID: %s\n", $file->id);
			$this->gd_folder_id = $file->id;
			file_put_contents(FOLDER_ID_FILE, $file->id);
		}

	}

	/**
	 * Setup KeePass
	 */
	private function setup_keepass() {
		if(!KeePassPHP::init($this->keepass_datadir, KEEPASS_DEBUG)) {
			die("KeePassPHP Initialization failed.");
		}
		$pwd = prompt_silent();
		$kphpdb_pwd = '';
		if(empty($kphpdb_pwd)) {
			$kphpdb_pwd = KeePassPHP::extractHalfPassword($pwd);
		}
		if(KeePassPHP::existsKphpDB(KEEPASS_DBID)) {
			if(!KeePassPHP::removeDatabase(KEEPASS_DBID, $kphpdb_pwd)) {
				printf("Database '%s' already exists and cannot be deleted.", KEEPASS_DBID);
			}
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

	/**
	 * Get username and password for site
	 */
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

	/**
	 * Get URL for site
	 */
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
			if (! $this->ch) {
				die ("cURL init failed for 1st handle!!");
			}
			$this->setup_curl_for_html();
			printf ("\n\n *******   Begin process for site: %25s   ********\n", $site);

			if (substr($this->get_url($site), -1) !== '/') {
				$this->keypass_map[$site]['url'] .= '/';
			}

			$this->dest_dir_prefix = $this->get_destination_dir($site);
			$this->dest_dir = $this->dest_dir_prefix . DIRECTORY_SEPARATOR . "wpib";
			$this->wp_expanded_dir = $this->dest_dir_prefix . DIRECTORY_SEPARATOR . "wordpress";

			$this->login_to_wordpress($config, $site);
			$this->setup_curl_for_json();
			$this->post_generate_backup($config, $site);
			$this->concat_backups($config, $site);
			curl_close($this->ch);
		}
		exec("rm -rf {$this->keepass_datadir}", $out, $ret);
	}

	/**
	 * Basic log function
	 */
	private function log($label, $str) {
		echo "----- $label -----\n";
		echo substr($str, 0, WPIB_CLIENT_DEBUG_LEN) . "\n\n"; 
	}

	/**
	 * Login to a WordPress site
	 */
	private function login_to_wordpress($ch, $config) {
		$this->get_login($ch, $config);
		$this->post_login($ch, $config);
	}

	/**
	 * GET WordPress login page
	 */
	private function get_login($config, $site) {
		$this->login_url = $this->get_url($site) . (array_key_exists('login_url', $config) ? $config['login_url'] : 'wp-login.php');
		printf("* GET login page [url = %s]\n", $this->login_url);
		curl_setopt($this->ch, CURLOPT_URL,  $this->login_url);
		curl_exec($this->ch);
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

		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $postdata);
		curl_setopt($this->ch, CURLOPT_POST, 1);
		curl_exec($this->ch);
		$lines = explode("\n", $this->response);
		if (count($lines) < 2) {
			echo "This doesn't seem like an HTML output:\n";
			var_dump($lines);
			exit;
		}
		if (! preg_match('/.*wp\-toolbar.*/', $lines[2])) {
			echo "There should be 'wp-toolbar' on this line:\n";
			echo $lines[2] . "\n";
		}
		if (! $this->response) die("[post_login] cURL error: " . curl_error($this->ch) . "\n");
		if (WPIB_CLIENT_DEBUG_MODE) $this->log('POST login credentials', $this->response);
	}

	/**
	 * Get response and check errors
	 */
	private function check_request_response($data) {
		// Store response
		$this->response = $data;
		// Send request and die on cURL error
		$http_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);

		// if($this->mode === 'download') return;

		// Die on HTTP error
		if ($http_code !== 200) {
			die(" !!! HTTP error (code = $http_code): $data\n");
		}
		// Die on cURL error
		if (empty($this->response)) {
			die(" !!! [post_generate_backup] cURL error: " . curl_error($this->ch) . "\n");
		}
	}

	/**
	 * Get HTML response
	 */
	private function curl_get_html_response($ch, $data) {
		$this->check_request_response($data);
	}

	/**
	 * Get JSON response
	 */
	private function curl_parse_json_response($ch, $data) {
		$this->check_request_response($data);
		// Parse JSON response
		$this->parsed_response = json_decode($this->response);

		// Die on empty parsed response
		if (empty($this->parsed_response)) {
			echo " !!! [post_generate_backup] JSON parse error. Received payload:\n";
			die('[>' . $this->response . "<]\n");
		}
	}


	/**
	 * Setup cURL for HTML
	 */
	private function setup_curl_for_html() {
		curl_setopt($this->ch, CURLOPT_BINARYTRANSFER, 0);
		curl_setopt($this->ch, CURLOPT_WRITEFUNCTION, [$this, 'curl_get_html_response']);
	}

	/**
	 * Setup cURL for JSON
	 */
	private function setup_curl_for_json() {
		curl_setopt($this->ch, CURLOPT_BINARYTRANSFER, 0);
		curl_setopt($this->ch, CURLOPT_WRITEFUNCTION, [$this, 'curl_parse_json_response']);
	}

	/**
	 * Setup cURL for file download
	 */
	private function setup_curl_for_download() {
		$this->total_written = 0;
		curl_setopt($this->ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($this->ch, CURLOPT_WRITEFUNCTION, [$this, 'curl_write_file']);
	}

	/**
	 * Write to file handle (for downloads)
	 */
	function curl_write_file($cp, $data) {
		global $global_fh;
  	    $len = fwrite($global_fh, $data);
	    $this->total_written += $len;
	    return $len;
	}

	/**
	 * Download a file and check its md5 sum against that of the remote file
	 */
	private function download_and_check($site, $file, $dest) {
		global $global_fh;
		$check_md5_url = $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_check_md5";
		$destination = $this->dest_dir . DIRECTORY_SEPARATOR . $file;
		echo "Download $file => $destination\n";

		curl_setopt ($this->ch, CURLOPT_URL, $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_download&filename=$file");
		$global_fh = fopen($destination, "w+");
		if (!$global_fh) die("could not open $destination\n");
		$this->setup_curl_for_download();
		curl_exec($this->ch);
		fclose($global_fh);

		if(! file_exists($destination)) {
			printf("Error while downloading %s\n", $destination);
			exit(1);
		}
		if (!array_key_exists($dest, $this->downloaded_files)) {
			$this->downloaded_files[$dest] = [];
		}
		$this->downloaded_files[$dest][] = $destination;

		// Setup again for JSON mode and send md5 check request
		$this->setup_curl_for_json();
		$md5 = md5_file($destination);
		curl_setopt ($this->ch, CURLOPT_URL, "$check_md5_url&file=" . urlencode($file) . "&md5=$md5");
		curl_exec($this->ch);

		if($this->parsed_response->md5_match !== true) {
			printf("md5 differ: srv=%s, cli=%s", $this->parsed_response->md5_server, $md5);
			exit(1);
		}
	}


	/**
	 * Perform download loop for site
	 */
	private function loop_downloads($config, $site) {
		$download_url = $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_download";
		$list_url = $download_url . "&list=1";
		$gen_url = $this->get_url($site) . "wp-admin/admin-ajax.php?action=wpib_generate&step=build_archives";
		if(isset($config['php_path'])) $gen_url .= '&php_path=' . urlencode($config['php_path']);

		// Send request for first file
		$arc_idx = 0;
		curl_setopt ($this->ch, CURLOPT_URL, $gen_url . "&arc_idx=$arc_idx");
		curl_exec($this->ch);
		
		// Continue as long as there are archives to be fetched
		while($arc_idx < $this->num_archives) {
			do {
				curl_setopt ($this->ch, CURLOPT_URL, $list_url);
				curl_exec($this->ch);
				$json_response = $this->parsed_response;
				$files = $json_response->files;
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

	/**
	 * Upload file to Google Drive
	 */
	private function upload_to_google_drive($site, $filename) {
		// Fuck PHP, and I mean it!
		// http://stackoverflow.com/questions/5167313/php-problem-filesize-return-0-with-file-containing-few-data
		clearstatcache();
		$source = $this->dest_dir . DIRECTORY_SEPARATOR . $filename;
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
		  // read until you get $chunkSizeBytes from $filename
		  // fread will never return more than 8192 bytes if the stream is read buffered and it does not represent a plain file
		  // An example of a read buffered file is when reading from a URL
		  	$chunk = read_video_chunk($handle, $chunkSizeBytes);
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

			curl_setopt ($this->ch, CURLOPT_URL, $url);
			curl_exec ($this->ch);
			$parsed_response = $this->parsed_response;
			$num_calls = 1;

			// Parse response and die on error
			curl_setopt ($this->ch, CURLOPT_URL, "$check_url&step=$step");
			while($parsed_response->done === false) {
				echo ".";
				$num_calls += 1;
				curl_setopt ($this->ch, CURLOPT_URL, "$check_url&step=$step");
				curl_exec ($this->ch);
				$parsed_response = $this->parsed_response; //json_decode($json_response);
			}
			$padding = 31 - $num_calls;
			printf("%{$padding}s", "OK *\n");

			if($step === 'list_md5') {
				$this->num_archives = $parsed_response->num_archives;
				echo "NUM ARCHIVES: {$this->num_archives}\n";
			}

			if($step === 'dump_sql') {
				$this->download_and_check($site, $parsed_response->files[0], 'sql');
			}

		}

		$step = 'build_archives';
		$this->loop_downloads($config, $site);
		printf(" * Process done for %25s! *\n", $site);
	}

	/**
	 * Compute per-site destination dir from backup root
	 */
	private function get_destination_dir($site) {
		return BACKUP_ROOT . DIRECTORY_SEPARATOR . $site;
	}

	/**
	 * GET request to fetch backup
	 */
	private function concat_backups($config, $site) {
		// Setup output dir first
		if (!is_dir($this->dest_dir)) mkdir($this->dest_dir, 0777, true);
		if (!is_dir($this->wp_expanded_dir)) mkdir($this->wp_expanded_dir);

		$count_tarballs = count($this->downloaded_files['files']);
		// Open output file
		foreach($this->downloaded_files['files'] as $i => $file) {
			$cmd = "cd {$this->wp_expanded_dir} ; tar xvjf $file";
			echo "$cmd\n";
			printf("Unpacking %d of %d: %s\n", $i + 1, $count_tarballs, $cmd);
			exec($cmd, $out, $ret);
		}

		$sqldump = $this->downloaded_files['sql'][0];
		$cmd = "cd {$this->dest_dir} ; bunzip2 $sqldump";
		exec($cmd, $out, $ret);

		 	$to_delete_list_file = $this->wp_expanded_dir . DIRECTORY_SEPARATOR .'wp-content/uploads/'. FILE_LIST_TO_DELETE;
echo $to_delete_list_file . "\n";
			echo file_exists($to_delete_list_file) ? "there are files to delete...\n" : "no files to delete \n";
		 	if(file_exists($to_delete_list_file)) {
		 		$files_to_delete = file($to_delete_list_file);
				var_dump($files_to_delete);
		 		$files_to_delete_escaped = array_map(function($file) {
		 			return trim($file);
		 		}, $files_to_delete);
		 		// $files_to_delete_str = implode(' ', $files_to_delete_escaped);
		// 		// var_dump($files_to_delete_str);
		 		// $cmd3 = "cd $wp_expanded_dir; rm $files_to_delete_str";
		 		foreach($files_to_delete_escaped as $ftd) {
		 			$fullpath = $this->wp_expanded_dir . DIRECTORY_SEPARATOR . $ftd;
		 			echo "$fullpath must be deleted\n";
		 			if(file_exists($fullpath)) {
		 				echo "deleting $fullpath\n";
		 				unlink($fullpath);
	 				}
		 		}
		 		// echo shell_exec($cmd3);

		 		// unlink($to_delete_list_file);
		 	}
		// }
	}
}



// // 2- POST




$client = new T1z_WP_Incremental_Backup_Client();
$client->run();
exit;
