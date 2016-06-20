<?php
Trait T1z_WPIB_Utils {
	function replace_domain_ext($domain) {
		$domain_bits = explode('.', $domain);
		$extension = array_pop($domain_bits);
		$domain_bits[] = 'clone';
		$new_domain = implode('.', $domain_bits);
		return $new_domain;
	}
}