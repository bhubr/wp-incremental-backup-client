<?php
require 'trait-t1z-wpib-utils.php';
// require 'sql-utils.php';

function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    // see http://php.net/manual/en/class.errorexception.php
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}

set_error_handler("exception_error_handler");

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
 * Tool to extract db config
 */
class WP_DB_Config_Extractor {
	private static $num = 0;

	function get_sql_config($backup_root, $site) {
		$wp_root = $backup_root . DIRECTORY_SEPARATOR . $site . DIRECTORY_SEPARATOR . 'wordpress';
		if (! file_exists("$wp_root/wp-config.php")) {
			return false;
		}
		$wp_config = file("$wp_root/wp-config.php");
		$db_lines = [];
		$prefix = 'DB' . static::$num++ . '_';
		array_map(function($line) use($prefix, &$db_lines) {
			if (preg_match('/define.*DB_(NAME|USER|PASSWORD|HOST|CHARSET|COLLATE).*/', $line)) {
				$db_lines[] = str_replace('DB_', $prefix, $line);
			}
		}, $wp_config);
		
		$db_config = implode("", $db_lines);
		eval($db_config);
		$this->name     = constant("${prefix}NAME");
		$this->user     = constant("${prefix}USER");
		$this->password = constant("${prefix}PASSWORD");
		$this->host     = constant("${prefix}HOST");
		$this->charset  = constant("${prefix}CHARSET");
		$this->collate  = constant("${prefix}COLLATE");
		return [
			'name'     => $this->name,
			'user'     => $this->user,
			'password' => $this->password,
			'host'     => $this->host,
			'charset'  => $this->charset,
			'collate'  => $this->collate
		];
	}
}




class T1z_WP_Incremental_Backup_SQL_Injector {
	use T1z_WPIB_Utils;

	/**
	 * Sites config
	 */
	private $sites;

	/**
	 * Tool config
	 */
	private $config;

	/**
	 * Cloned domain names
	 */
	private $local_domain_names = [];

	/**
	 * WordPress DB configs per site
	 */
	private $wpdb_configs;

	/**
	 * PDO instance for mysql
	 */
	private $pdo_mysql;

	/**
	 * PDO instance for information_schema
	 */
	private $pdo_is;


	/**
	 * Constructor: read ini file and setup cURL
	 */
	public function __construct($command) {
		$methods = [
			'inject' => 'inject_sql',
			'setup'  => 'setup_dbs'
		];
		if (array_search($command, array_keys($methods)) === false) {
			echo "Wrong command: $command (must be in [inject, setup])\n";
			exit;
		}
		$this->read_configs();
		$this->wpdb_configs = $this->get_wpdb_configs();
		$method = $methods[$command];
		$this->$method();
	}

	/**
	 * read ini file
	 */
	private function read_configs() {
		$this->sites = parse_ini_file(__DIR__ . '/fetch.ini', true);
		$config = parse_ini_file(__DIR__ . '/sqlin.ini', true);
		$this->backup_root = $config['backup_root'];
	}

	public function get_latest_sql_filename($site) {
		$dir = $this->backup_root . DIRECTORY_SEPARATOR . $site . DIRECTORY_SEPARATOR . 'wpib';
	    $files = glob("$dir/*.sql");
	    $filename = array_pop($files);
	    return $filename;
	}

	private function get_wpdb_configs() {
		$configs = [];
		$dbce = new WP_DB_Config_Extractor();
		foreach($this->sites as $site => $config) {
			$db_config = $dbce->get_sql_config($this->backup_root, $site);
			if ($db_config) $configs[$site] = $db_config;
		}
		return $configs;
	}


	private function inject_sql() {
		$dbce = new WP_DB_Config_Extractor();
		foreach($this->sites as $site => $config) {

			// Get DB params from wp-config.php
			$db_config = $dbce->get_sql_config($this->backup_root, $site);
			if (!$db_config) {
				echo "Skip: $site\n";
				continue;
			}


			$show_tables_cmd = "mysql -u{$dbce->user} -p{$dbce->password} {$dbce->name} -Nse 'show tables'";
			exec($show_tables_cmd, $tables, $ret);
			$drop_statements = array_map(function($table) {
				return "drop table if exists $table; ";
			}, $tables);
			$sql_drop = implode("", $drop_statements);
			$drop_cmd = "mysql -u{$dbce->user} -p'{$dbce->password}' {$dbce->name} --execute \"$sql_drop\"";
			echo "$drop_cmd";
			exec($drop_cmd, $output, $ret);

			$sql_dump = $this->get_latest_sql_filename($site);
			$inject_cmd = "mysql -u{$dbce->user} -p'{$dbce->password}' {$dbce->name} < $sql_dump";
			echo "$inject_cmd\n";
			exec($inject_cmd, $output, $ret);

			
			// Create PDO MySQL instance
			// Truncate all db tables
			// Inject new dump
			// Replace strings
			$has_https = substr($config['url'], 0, 5) === 'https';
			$scheme_len = $has_https ? 8 : 7;
			$url_len = strlen($config['url']);
			$has_tr_slash = $config['url'][$url_len - 1] === '/';
			// echo $has_tr_slash ? 'yes' : 'no';
			$site_url = $has_tr_slash ? substr($config['url'], $scheme_len, $url_len - 1) : $config['url'];
			$wp_dir = $this->backup_root . DIRECTORY_SEPARATOR . $site . DIRECTORY_SEPARATOR . 'wordpress';
			$cmd = "cd $wp_dir; wp search-replace 'http://$site_url' 'http://" . $this->replace_domain_ext($site) . "'" ;
			echo $cmd . "\n";
			exec($cmd, $output, $ret);
			$cmd = "cd $wp_dir; wp search-replace 'https://$site_url' 'http://" . $this->replace_domain_ext($site) . "'" ;
			echo $cmd . "\n";
			exec($cmd, $output, $ret);
		}
	}

	private function get_mysql_users() {
		$user_query = $this->pdo_mysql->query("SELECT Host, User FROM user WHERE host = 'localhost' OR host = '127.0.0.1'");
		$results = $user_query->fetchAll();
		return array_map(function($record) {
			return $record['User'];
		}, $results);
	}

	private function create_mysql_users($my_root_pass) {
		$mysql_existing_users = $this->get_mysql_users();

		$mysql_users_to_create = [];
		$sites = array_keys($this->wpdb_configs);
		$num_sites = count($sites);
		echo "Creating $num_sites databases for sites " . implode(', ', $sites);
		foreach($this->wpdb_configs as $site => $db_config) {
			$name = $db_config['name'];
			$user = $db_config['user'];
			$host = $db_config['host'];
			$pass = $db_config['password'];
		    $sql = "CREATE DATABASE IF NOT EXISTS $name;" .
		        "GRANT ALL ON $name.* TO '$user'@'localhost'; " .
		        "FLUSH PRIVILEGES;";
	        $mysql_cmd = "mysql -uroot -p$my_root_pass --execute \"$sql\"";
	        exec($mysql_cmd, $output, $ret);
	        echo "Output for DB CREATION (code: $ret):\n";
	        var_dump($output);

			if (array_search($user, $mysql_existing_users) !== false) {
				echo "User $user *already exists*\n";
				continue;
			}
			if (array_search($user, $mysql_users_to_create) !== false) {
				echo "User $user *already scheduled to create*\n";
				continue;
			}
			try {
				$sql = "CREATE USER '$user'@'localhost' IDENTIFIED BY '$pass'; ";
		        $mysql_cmd = "mysql -uroot -p$my_root_pass --execute \"$sql\"";
		        exec($mysql_cmd, $output, $ret);
		        echo "Output for USER CREATION (code: $ret):\n";
		        var_dump($output);
			} catch(Exception $e) {
				echo $e->getMessage() . "\n";
				continue;
			}
			echo "User $user created!\n";
		}
	}

	private function setup_dbs() {
		// echo "Please enter MySQL root password:\n";
		$my_root_pass = prompt_silent("Please enter MySQL root password: ");
		try {
			$this->pdo_mysql = new PDO(
			    "mysql:host=127.0.0.1;dbname=mysql",
			    'root',
			    $my_root_pass
		    );
			$this->pdo_is = new PDO(
			    "mysql:host=127.0.0.1;dbname=information_schema",
			    'root',
			    $my_root_pass
		    );
		} catch(PDOException $e) {
			if ($e->getCode() === 1045) {
				die("[1045] Access denied (wrong password?)\n");
			}
			die("[$code] Could not create PDO instance (see code)");
		}
		$this->create_mysql_users($my_root_pass);
	}
}

if($argc < 2) {
	echo "Usage:\n  php sqlin.php <command>   (command in [inject, setup])\n";
	exit;
}
$command = $argv[1];
$sql_injector = new T1z_WP_Incremental_Backup_SQL_Injector($command);