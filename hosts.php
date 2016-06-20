<?php
require 'trait-t1z-wpib-utils.php';

// register_shutdown_function( "fatal_handler" );

// function format_error() {
// 	return implode(' ', func_get_args()) . "\n";
// }

// function fatal_handler() {
//   $errfile = "unknown file";
//   $errstr  = "shutdown";
//   $errno   = E_CORE_ERROR;
//   $errline = 0;

//   $error = error_get_last();

//   if( $error !== NULL) {
//     $errno   = $error["type"];
//     $errfile = $error["file"];
//     $errline = $error["line"];
//     $errstr  = $error["message"];

//     echo format_error( $errno, $errstr, $errfile, $errline);
//   }
// }
function exception_error_handler($errno, $errstr, $errfile, $errline ) {
    // see http://php.net/manual/en/class.errorexception.php
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
}

set_error_handler("exception_error_handler");

class T1z_WP_Incremental_Backup_Hosts_Writer {
	use T1z_WPIB_Utils;

	/**
	 * Sites config
	 */
	private $sites;

	/**
	 * Cloned domain names
	 */
	private $local_domain_names;

	/**
	 * Constructor: read ini file and setup cURL
	 */
	public function __construct() {
		$this->read_configs();
		$this->write_hosts_file();
	}

	/**
	 * read ini file
	 */
	private function read_configs() {
		$sites = parse_ini_file(__DIR__ . '/fetch.ini', true);
		$this->local_domain_names = array_keys($sites);
	}

	/**
	 * Write hosts file
	 */
	private function write_hosts_file() {
		$hosts_file = file('/etc/hosts');
		$hosts_trimmed = array_map(function($line) {
			return trim($line);
		}, $hosts_file);
		$wpib_begin_tag = array_search("# WPIB_BEGIN", $hosts_trimmed);
		$wpib_end_tag = array_search("# WPIB_END", $hosts_trimmed);
		if (!$wpib_begin_tag || !$wpib_end_tag) {
			echo "Please insert those lines in your /etc/hosts file:\n# WPIB_BEGIN\n# WPIB_END\n";
		}
		$hosts_lines = array_map(function($domain) {
			$new_domain = $this->replace_domain_ext($domain);
			return "127.0.0.1 $new_domain\n";
		}, $this->local_domain_names);
		$splice_begin = $wpib_begin_tag + 1;
		$splice_len = $wpib_end_tag - $wpib_begin_tag - 1;
		array_splice($hosts_file, $splice_begin, $splice_len, $hosts_lines);
		
		try {
			copy('/etc/hosts', '/etc/hosts.bak');
			file_put_contents('/etc/hosts', implode('', $hosts_file));	
		} catch(Exception $e) {
			die($e->getMessage() . "  (maybe you should run me as root, just saying)\n");
		}
		
		// foreach($this->local_domain_names as $domain)
	}
}

$hosts_writer = new T1z_WP_Incremental_Backup_Hosts_Writer();
// var_dump($nginx_conf_writer);