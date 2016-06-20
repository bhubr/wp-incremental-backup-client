<?php
require 'trait-t1z-wpib-utils.php';

class T1z_WP_Incremental_Backup_Nginx_Conf_Writer {
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
	 * Virtual host config template
	 */
	private $template;

	/**
	 * Cloned domain names
	 */
	private $local_domain_names = [];

	/**
	 * Constructor: read ini file and setup cURL
	 */
	public function __construct() {
		$this->read_configs();
		$this->template = file_get_contents(__DIR__ . '/nginx_vhost.txt');
		$this->write_nginx_vhosts();
	}

	/**
	 * read ini file
	 */
	private function read_configs() {
		$this->sites = parse_ini_file(__DIR__ . '/fetch.ini', true);
		$config = parse_ini_file(__DIR__ . '/nginx.ini', true);
		$this->doc_root = $config['doc_root'];
		$this->nginx_sites = $config['nginx_sites'];
		$this->nginx_conf_dir = $config['nginx_conf_dir'];
		$this->nginx_sites_enabled = str_replace('sites-available', 'sites-enabled', $this->nginx_sites);
	}

	/**
	 * Prepare patterns for replacing {{k}} with relevant value
	 */
	private function prepare_patterns($vars) {
		$patterns = [];
		foreach ($vars as $k) {
			$patterns[] = '/\{\{' . $k . '\}\}/';
		}
		return $patterns;
	}

	/**
	 * Write nginx vhost configs
	 */
	private function write_nginx_vhosts() {
		echo "[WP Incremental Backup plugin] nginx virtual host writer\n";
		foreach($this->sites as $domain => $config) {

			$new_domain = $this->replace_domain_ext($domain);
			// Replace domain.ext with domain.clone
			$this->local_domain_names[] = $new_domain;

			// Replace variables in vhost conf template
			$patterns = $this->prepare_patterns(['domain', 'new_domain', 'doc_root', 'nginx_conf_dir']);
			$vars = [$domain, $new_domain, $this->doc_root, $this->nginx_conf_dir];
			$vhost_conf = preg_replace($patterns, $vars, $this->template);

			// Backup template if exists
			$vhost_file = $this->nginx_sites . DIRECTORY_SEPARATOR . $new_domain;
			$vhost_enabled_link = $this->nginx_sites_enabled . DIRECTORY_SEPARATOR . $new_domain;
			$vhost_exists = file_exists($vhost_file);
			if ($vhost_exists) {
				copy($vhost_file, $vhost_file . '.bak');
			}
			echo "Wrote: $vhost_enabled_link\n";
			file_put_contents($vhost_file, $vhost_conf);

			if (!is_link($vhost_enabled_link)) {
				symlink($vhost_file, $vhost_enabled_link);	
			}
		}
	}
}

$nginx_conf_writer = new T1z_WP_Incremental_Backup_Nginx_Conf_Writer();
