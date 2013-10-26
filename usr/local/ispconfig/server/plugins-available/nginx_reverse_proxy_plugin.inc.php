<?php

/*
Copyright (c) 2007 - 2012, Till Brehm, projektfarm Gmbh
All rights reserved.

Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

	* Redistributions of source code must retain the above copyright notice,
	  this list of conditions and the following disclaimer.
	* Redistributions in binary form must reproduce the above copyright notice,
	  this list of conditions and the following disclaimer in the documentation
	  and/or other materials provided with the distribution.
	* Neither the name of ISPConfig nor the names of its contributors
	  may be used to endorse or promote products derived from this software without
	  specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

/**
 * ISPConfig3 Nginx Reverse Proxy.
 *
 * This class extends ISPConfig's vhost management with the functionality to run
 * Nginx in front of Apache2 as a transparent reverse proxy.
 *
 * @author Rackster Internet Services <open-source@rackster.ch>
 * @link https://open-source.rackster.ch/project/ispconfig3-nginx-reverse-proxy
 */
class nginx_reverse_proxy_plugin {

	/**
	 * ISPConfig internals
	 */
	var $plugin_name = 'nginx_reverse_proxy_plugin';
	var $class_name = 'nginx_reverse_proxy_plugin';

	/**
	 * Private variables (temporary stores)
	 */
	var $action = '';


	/**
	 * ISPConfig onInstall hook.
	 *
	 * Called during ISPConfig installation to determine if a symlink shall be created.
	 *
	 * @return bool create symlink if true
	 */
	function onInstall() {
		global $conf;

		return $conf['services']['web'];
	}

	/**
	 * ISPConfig onLoad hook.
	 *
	 * Register the plugin for some site related events.
	 */
	function onLoad() {
		global $app;

		$app->plugins->registerEvent('web_domain_insert',$this->plugin_name,'ssl');
		$app->plugins->registerEvent('web_domain_update',$this->plugin_name,'ssl');
		$app->plugins->registerEvent('web_domain_delete',$this->plugin_name,'ssl');

		$app->plugins->registerEvent('web_domain_insert',$this->plugin_name,'insert');
		$app->plugins->registerEvent('web_domain_update',$this->plugin_name,'update');
		$app->plugins->registerEvent('web_domain_delete',$this->plugin_name,'delete');

		$app->plugins->registerEvent('server_ip_insert',$this->plugin_name,'server_ip');
		$app->plugins->registerEvent('server_ip_update',$this->plugin_name,'server_ip');
		$app->plugins->registerEvent('server_ip_delete',$this->plugin_name,'server_ip');

		$app->plugins->registerEvent('client_delete',$this->plugin_name,'client_delete');

		$app->plugins->registerEvent('web_folder_user_insert',$this->plugin_name,'web_folder_user');
		$app->plugins->registerEvent('web_folder_user_update',$this->plugin_name,'web_folder_user');
		$app->plugins->registerEvent('web_folder_user_delete',$this->plugin_name,'web_folder_user');

		$app->plugins->registerEvent('web_folder_update',$this->plugin_name,'web_folder_update');
		$app->plugins->registerEvent('web_folder_delete',$this->plugin_name,'web_folder_delete');
	}

	/**
	 * ISPConfig ssl hook.
	 *
	 * Called every time something in the ssl tab is done.
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 * @return void
	 */
	function ssl($event_name, $data) {
		global $app, $conf;

		$app->uses('system');

		//* Only vhosts can have a ssl cert
		if ($data["new"]["type"] != "vhost" && $data["new"]["type"] != "vhostsubdomain") {
			return;
		}

		$ssl_dir = $data['new']['document_root'].'/ssl';
		$domain = $data['new']['ssl_domain'];
		$crt_file = $ssl_dir.'/'.$domain.'.nginx.crt';

		//* Ensure SSL dir exists (Apache2 should have created it)
		if (!is_dir($ssl_dir)) {
			$app->system->mkdirpath($ssl_dir);
			$app->log("Creating SSL directory ".$ssl_dir, LOGLEVEL_DEBUG);
		}

		//* Create a SSL Certificate (done by Apache2)
		if ($data['new']['ssl_action'] == 'create' && $conf['mirror_server_id'] == 0) {
			//* Rename files if they exist
			if (file_exists($crt_file)) {
				$app->system->rename($crt_file, $crt_file.'.bak');
				$app->log("Renaming old SSL cert ".$crt_file, LOGLEVEL_DEBUG);
			}
		}

		//* Save a SSL certificate to disk
		if ($data["new"]["ssl_action"] == 'save') {
			$ssl_dir = $data["new"]["document_root"]."/ssl";
			$domain = ($data["new"]["ssl_domain"] != '') ? $data["new"]["ssl_domain"] : $data["new"]["domain"];
			$crt_file = $ssl_dir.'/'.$domain.".nginx.crt";

			//* Backup files
			if (file_exists($crt_file)) {
				$app->system->copy($crt_file, $crt_file.'~');
				$app->log("Copying old SSL cert ".$crt_file, LOGLEVEL_DEBUG);
			}

			//* Write new ssl files
			if (trim($data["new"]["ssl_cert"]) != '') {
				$app->system->file_put_contents($crt_file, $data["new"]["ssl_cert"]);
			}

			// for nginx, bundle files have to be appended to the certificate file
			if (trim($data["new"]["ssl_bundle"]) != '') {
				if (file_exists($crt_file)) {
					$crt_file_contents = trim($app->system->file_get_contents($crt_file));
				} else {
					$crt_file_contents = '';
				}

				if ($crt_file_contents != '') {
					$crt_file_contents .= "\n";
				}

				$crt_file_contents .= $data["new"]["ssl_bundle"];
				$app->system->file_put_contents($crt_file, $app->file->unix_nl($crt_file_contents));
				unset($crt_file_contents);
			}
		}

		//* Delete a SSL certificate
		if ($data['new']['ssl_action'] == 'del') {
			$ssl_dir = $data['new']['document_root'].'/ssl';
			$domain = ($data["new"]["ssl_domain"] != '') ? $data["new"]["ssl_domain"] : $data["new"]["domain"];
			$crt_file = $ssl_dir.'/'.$domain.'.nginx.crt';

			$app->system->unlink($crt_file);
			$app->log("Deleting SSL cert ".$crt_file, LOGLEVEL_DEBUG);
		}
	}

	/**
	 * ISPConfig insert hook.
	 *
	 * Called every time a new site is created.
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 */
	function insert($event_name, $data) {
		global $app, $conf;

		$this->action = 'insert';
		$this->update($event_name, $data);
	}

	/**
	 * ISPConfig update hook.
	 *
	 * Called every time a site gets updated from within ISPConfig.
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 * @return void
	 */
	function update($event_name, $data) {
		global $app, $conf;

		//* Check if the Nginx plugin is enabled
		if (@is_link('/usr/local/ispconfig/server/plugins-enabled/nginx_plugin.inc.php')) {
			$app->log('The Nginx Reverse Proxy cannot be used together with the Nginx plugin.', LOGLEVEL_WARN);
			return 0;
		}

		if ($this->action != 'insert') {
			$this->action = 'update';
		}

		if ($data['new']['type'] != 'vhost' && $data['new']['type'] != 'vhostsubdomain' && $data['new']['parent_domain_id'] > 0) {
			$old_parent_domain_id = intval($data['old']['parent_domain_id']);
			$new_parent_domain_id = intval($data['new']['parent_domain_id']);

			// If the parent_domain_id has been changed, we will have to update the old site as well.
			if ($this->action == 'update' && $data['new']['parent_domain_id'] != $data['old']['parent_domain_id']) {
				$tmp = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '.$old_parent_domain_id." AND active = 'y'");
				$data['new'] = $tmp;
				$data['old'] = $tmp;

				$this->action = 'update';
				$this->update($event_name, $data);
			}

			// This is not a vhost, so we need to update the parent record instead.
			$tmp = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '.$new_parent_domain_id." AND active = 'y'");
			$data['new'] = $tmp;
			$data['old'] = $tmp;

			$this->action = 'update';
		}

		// load the server configuration options
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		if ($data['new']['document_root'] == '') {
			if ($data['new']['type'] == 'vhost' || $data['new']['type'] == 'vhostsubdomain') {
				$app->log('document_root not set', LOGLEVEL_WARN);
			}

			return 0;
		}

		if ($data['new']['system_user'] == 'root' or $data['new']['system_group'] == 'root') {
			$app->log('Websites cannot be owned by the root user or group.', LOGLEVEL_WARN);
			return 0;
		}

		if (trim($data['new']['domain']) == '') {
			$app->log('domain is empty', LOGLEVEL_WARN);
			return 0;
		}

		$web_folder = 'web';
		if ($data['new']['type'] == 'vhostsubdomain') {
			$web_folder = $data['new']['web_folder'];
		}

		$app->uses('system');
		$app->load('tpl');

		$tpl = new tpl();
		$tpl->newTemplate('nginx_reverse_proxy_plugin.vhost.conf.master');

		$vhost_data = $data['new'];
		$vhost_data['web_document_root'] = $data['new']['document_root'].'/' . $web_folder;
		$vhost_data['web_document_root_www'] = $web_config['website_basedir'].'/'.$data['new']['domain'].'/' . $web_folder;
		$vhost_data['web_basedir'] = $web_config['website_basedir'];

		// IPv6
		if ($data['new']['ipv6_address'] != '') {
			$tpl->setVar('ipv6_enabled', 1);

			if ($conf['serverconfig']['web']['vhost_rewrite_v6'] == 'y') {
				if (isset($conf['serverconfig']['server']['v6_prefix']) && $conf['serverconfig']['server']['v6_prefix'] <> '') {
					$explode_v6prefix = explode(':', $conf['serverconfig']['server']['v6_prefix']);
					$explode_v6 = explode(':', $data['new']['ipv6_address']);

					for ($i = 0; $i <= count($explode_v6prefix) - 3; $i++) {
						$explode_v6[$i] = $explode_v6prefix[$i];
					}

					$data['new']['ipv6_address'] = implode(':', $explode_v6);
					$vhost_data['ipv6_address'] = $data['new']['ipv6_address'];
				}
			}
		}

		// Custom rewrite rules
		$final_rewrite_rules = array();

		if (isset($data['new']['rewrite_rules']) && trim($data['new']['rewrite_rules']) != '') {
			$custom_rewrite_rules = trim($data['new']['rewrite_rules']);
			$custom_rewrites_are_valid = true;
			// use this counter to make sure all curly brackets are properly closed
			$if_level = 0;
			// Make sure we only have Unix linebreaks
			$custom_rewrite_rules = str_replace("\r\n", "\n", $custom_rewrite_rules);
			$custom_rewrite_rules = str_replace("\r", "\n", $custom_rewrite_rules);
			$custom_rewrite_rule_lines = explode("\n", $custom_rewrite_rules);

			if (is_array($custom_rewrite_rule_lines) && !empty($custom_rewrite_rule_lines)) {
				foreach ($custom_rewrite_rule_lines as $custom_rewrite_rule_line) {
					// ignore comments
					if (substr(ltrim($custom_rewrite_rule_line), 0, 1) == '#') {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}

					// empty lines
					if (trim($custom_rewrite_rule_line) == '') {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}

					// rewrite
					if (preg_match('@^\s*rewrite\s+(^/)?\S+(\$)?\s+\S+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $custom_rewrite_rule_line)) {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}

					// if
					if (preg_match('@^\s*if\s+\(\s*\$\S+(\s+(\!?(=|~|~\*))\s+(\S+|\".+\"))?\s*\)\s*\{\s*$@', $custom_rewrite_rule_line)) {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						$if_level += 1;
						continue;
					}

					// if - check for files, directories, etc.
					if (preg_match('@^\s*if\s+\(\s*\!?-(f|d|e|x)\s+\S+\s*\)\s*\{\s*$@', $custom_rewrite_rule_line)) {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						$if_level += 1;
						continue;
					}

					// break
					if (preg_match('@^\s*break\s*;\s*$@', $custom_rewrite_rule_line)) {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}

					// return code [ text ]
					if (preg_match('@^\s*return\s+\d\d\d.*;\s*$@', $custom_rewrite_rule_line)) {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}

					// return code URL
					// return URL
					if (preg_match('@^\s*return(\s+\d\d\d)?\s+(http|https|ftp)\://([a-zA-Z0-9\.\-]+(\:[a-zA-Z0-9\.&%\$\-]+)*\@)*((25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9])\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9]|0)\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9]|0)\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[0-9])|localhost|([a-zA-Z0-9\-]+\.)*[a-zA-Z0-9\-]+\.(com|edu|gov|int|mil|net|org|biz|arpa|info|name|pro|aero|coop|museum|[a-zA-Z]{2}))(\:[0-9]+)*(/($|[a-zA-Z0-9\.\,\?\'\\\+&%\$#\=~_\-]+))*\s*;\s*$@', $custom_rewrite_rule_line)) {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}

					// set
					if (preg_match('@^\s*set\s+\$\S+\s+\S+\s*;\s*$@', $custom_rewrite_rule_line)) {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						continue;
					}

					// closing curly bracket
					if (trim($custom_rewrite_rule_line) == '}') {
						$final_rewrite_rules[] = array('rewrite_rule' => $custom_rewrite_rule_line);
						$if_level -= 1;
						continue;
					}

					$custom_rewrites_are_valid = false;
					break;
				}
			}

			if (!$custom_rewrites_are_valid || $if_level != 0) {
				$final_rewrite_rules = array();
			}
		}

		$tpl->setLoop('rewrite_rules', $final_rewrite_rules);

		// Custom nginx directives
		$final_nginx_directives = array();
		$nginx_directives = $data['new']['nginx_directives'];
		// Make sure we only have Unix linebreaks
		$nginx_directives = str_replace("\r\n", "\n", $nginx_directives);
		$nginx_directives = str_replace("\r", "\n", $nginx_directives);
		$nginx_directive_lines = explode("\n", $nginx_directives);

		if (is_array($nginx_directive_lines) && !empty($nginx_directive_lines)) {
			foreach ($nginx_directive_lines as $nginx_directive_line) {
				$final_nginx_directives[] = array('nginx_directive' => $nginx_directive_line);
			}
		}

		$tpl->setLoop('nginx_directives', $final_nginx_directives);

		// Check if a SSL cert exists
		$ssl_dir = $data['new']['document_root'].'/ssl';
		$domain = $data['new']['ssl_domain'];
		$key_file = $ssl_dir.'/'.$domain.'.key';
		$crt_file = $ssl_dir.'/'.$domain.'.nginx.crt';

		if ($domain != '' && $data['new']['ssl'] == 'y' && @is_file($crt_file) && @is_file($key_file) && (@filesize($crt_file) > 0)  && (@filesize($key_file) > 0)) {
			$vhost_data['ssl_enabled'] = 1;
			$app->log('Enable SSL for: '.$domain, LOGLEVEL_DEBUG);
		} else {
			$vhost_data['ssl_enabled'] = 0;
			$app->log('SSL Disabled. '.$domain, LOGLEVEL_DEBUG);
		}

		// Set SEO Redirect
		if ($data['new']['seo_redirect'] != '') {
			$vhost_data['seo_redirect_enabled'] = 1;
			$tmp_seo_redirects = $this->get_seo_redirects($data['new']);

			if (is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)) {
				foreach ($tmp_seo_redirects as $key => $val) {
					$vhost_data[$key] = $val;
				}
			} else {
				$vhost_data['seo_redirect_enabled'] = 0;
			}
		} else {
			$vhost_data['seo_redirect_enabled'] = 0;
		}

		// Rewrite rules
		$own_rewrite_rules = array();
		$rewrite_rules = array();
		$local_rewrite_rules = array();

		if ($data['new']['redirect_type'] != '' && $data['new']['redirect_path'] != '') {
			if (substr($data['new']['redirect_path'], -1) != '/') {
				$data['new']['redirect_path'] .= '/';
			}

			if (substr($data['new']['redirect_path'], 0, 8) == '[scheme]') {
				if ($data['new']['redirect_type'] != 'proxy') {
					$data['new']['redirect_path'] = '$scheme'.substr($data['new']['redirect_path'], 8);
				}
			}

			switch($data['new']['subdomain']) {
				case 'www':
					$exclude_own_hostname = '';

					if (substr($data['new']['redirect_path'], 0, 1) == '/') { // relative path
						$rewrite_exclude = '(?!/\b('.substr($data['new']['redirect_path'], 1, -1).(substr($data['new']['redirect_path'], 1, -1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').')\b)/';
					} else { // URL - check if URL is local
						$tmp_redirect_path = $data['new']['redirect_path'];

						if (substr($tmp_redirect_path, 0, 7) == '$scheme') {
							$tmp_redirect_path = 'http'.substr($tmp_redirect_path,7);
						}

						$tmp_redirect_path_parts = parse_url($tmp_redirect_path);

						if (($tmp_redirect_path_parts['host'] == $data['new']['domain'] || $tmp_redirect_path_parts['host'] == 'www.'.$data['new']['domain']) && ($tmp_redirect_path_parts['port'] == '80' || $tmp_redirect_path_parts['port'] == '443' || !isset($tmp_redirect_path_parts['port']))) {
							// URL is local
							if (substr($tmp_redirect_path_parts['path'], -1) == '/') {
								$tmp_redirect_path_parts['path'] = substr($tmp_redirect_path_parts['path'], 0, -1);
							}

							if (substr($tmp_redirect_path_parts['path'], 0, 1) != '/') {
								$tmp_redirect_path_parts['path'] = '/'.$tmp_redirect_path_parts['path'];
							}

							//$rewrite_exclude = '((?!'.$tmp_redirect_path_parts['path'].'))';
							if ($data['new']['redirect_type'] != 'proxy') {
								$rewrite_exclude = '(?!/\b('.substr($tmp_redirect_path_parts['path'], 1).(substr($tmp_redirect_path_parts['path'], 1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').')\b)/';
								$exclude_own_hostname = $tmp_redirect_path_parts['host'];
							}
						} else {
							// external URL
							$rewrite_exclude = '(.?)/';
						}

						unset($tmp_redirect_path);
						unset($tmp_redirect_path_parts);
					}

					$own_rewrite_rules[] = array(
						'rewrite_domain'		=> '^'.$this->_rewrite_quote($data['new']['domain']),
						'rewrite_type' 			=> ($data['new']['redirect_type'] == 'no') ? '' : $data['new']['redirect_type'],
						'rewrite_target'		=> $data['new']['redirect_path'],
						'rewrite_exclude'		=> $rewrite_exclude,
						'rewrite_subdir'		=> $rewrite_subdir,
						'exclude_own_hostname'	=> $exclude_own_hostname,
						'use_rewrite'			=> true
					);
				break;
				case '*':
					$exclude_own_hostname = '';

					if (substr($data['new']['redirect_path'], 0, 1) == '/') { // relative path
						$rewrite_exclude = '(?!/\b('.substr($data['new']['redirect_path'], 1, -1).(substr($data['new']['redirect_path'], 1, -1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').')\b)/';
					} else { // URL - check if URL is local
						$tmp_redirect_path = $data['new']['redirect_path'];

						if (substr($tmp_redirect_path, 0, 7) == '$scheme') {
							$tmp_redirect_path = 'http'.substr($tmp_redirect_path, 7);
						}

						$tmp_redirect_path_parts = parse_url($tmp_redirect_path);

						//if ($is_serveralias && ($tmp_redirect_path_parts['port'] == '80' || $tmp_redirect_path_parts['port'] == '443' || !isset($tmp_redirect_path_parts['port']))) {
						if ($this->url_is_local($tmp_redirect_path_parts['host'], $data['new']['domain_id']) && ($tmp_redirect_path_parts['port'] == '80' || $tmp_redirect_path_parts['port'] == '443' || !isset($tmp_redirect_path_parts['port']))) {
							// URL is local
							if (substr($tmp_redirect_path_parts['path'], -1) == '/') {
								$tmp_redirect_path_parts['path'] = substr($tmp_redirect_path_parts['path'], 0, -1);
							}

							if (substr($tmp_redirect_path_parts['path'], 0, 1) != '/') {
								$tmp_redirect_path_parts['path'] = '/'.$tmp_redirect_path_parts['path'];
							}

							//$rewrite_exclude = '((?!'.$tmp_redirect_path_parts['path'].'))';
							if ($data['new']['redirect_type'] != 'proxy') {
								$rewrite_exclude = '(?!/\b('.substr($tmp_redirect_path_parts['path'], 1).(substr($tmp_redirect_path_parts['path'], 1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').')\b)/';
								$exclude_own_hostname = $tmp_redirect_path_parts['host'];
							}
						} else {
							// external URL
							$rewrite_exclude = '(.?)/';
						}

						unset($tmp_redirect_path);
						unset($tmp_redirect_path_parts);
					}

					$own_rewrite_rules[] = array(
						'rewrite_domain' 		=> '(^|\.)'.$this->_rewrite_quote($data['new']['domain']),
						'rewrite_type' 			=> ($data['new']['redirect_type'] == 'no') ? '' : $data['new']['redirect_type'],
						'rewrite_target' 		=> $data['new']['redirect_path'],
						'rewrite_exclude'		=> $rewrite_exclude,
						'rewrite_subdir'		=> $rewrite_subdir,
						'exclude_own_hostname' 	=> $exclude_own_hostname,
						'use_rewrite'			=> true
					);
				break;
				default:
					if (substr($data['new']['redirect_path'], 0, 1) == '/') { // relative path
						$exclude_own_hostname = '';
						$rewrite_exclude = '(?!/\b('.substr($data['new']['redirect_path'], 1, -1).(substr($data['new']['redirect_path'], 1, -1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').')\b)/';
					} else { // URL - check if URL is local
						$tmp_redirect_path = $data['new']['redirect_path'];

						if (substr($tmp_redirect_path, 0, 7) == '$scheme') {
							$tmp_redirect_path = 'http'.substr($tmp_redirect_path, 7);
						}

						$tmp_redirect_path_parts = parse_url($tmp_redirect_path);

						if ($tmp_redirect_path_parts['host'] == $data['new']['domain'] && ($tmp_redirect_path_parts['port'] == '80' || $tmp_redirect_path_parts['port'] == '443' || !isset($tmp_redirect_path_parts['port']))) {
							// URL is local
							if (substr($tmp_redirect_path_parts['path'], -1) == '/') {
								$tmp_redirect_path_parts['path'] = substr($tmp_redirect_path_parts['path'], 0, -1);
							}

							if (substr($tmp_redirect_path_parts['path'], 0, 1) != '/') {
								$tmp_redirect_path_parts['path'] = '/'.$tmp_redirect_path_parts['path'];
							}

							//$rewrite_exclude = '((?!'.$tmp_redirect_path_parts['path'].'))';
							if ($data['new']['redirect_type'] != 'proxy') {
								$rewrite_exclude = '(?!/\b('.substr($tmp_redirect_path_parts['path'], 1).(substr($tmp_redirect_path_parts['path'], 1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').')\b)/';
								$exclude_own_hostname = $tmp_redirect_path_parts['host'];
							}
						} else {
							// external URL
							$rewrite_exclude = '(.?)/';
						}

						unset($tmp_redirect_path);
						unset($tmp_redirect_path_parts);
					}

					$own_rewrite_rules[] = array(
						'rewrite_domain' 		=> '^'.$this->_rewrite_quote($data['new']['domain']),
						'rewrite_type' 			=> ($data['new']['redirect_type'] == 'no') ? '' : $data['new']['redirect_type'],
						'rewrite_target' 		=> $data['new']['redirect_path'],
						'rewrite_exclude'		=> $rewrite_exclude,
						'rewrite_subdir'		=> $rewrite_subdir,
						'exclude_own_hostname' 	=> $exclude_own_hostname,
						'use_rewrite'			=> true
					);
			}
		}

		$tpl->setVar($vhost_data);
		$server_alias = array();
		$auto_alias = $web_config['website_autoalias'];

		if ($auto_alias != '') {
			// get the client username
			$client = $app->db->queryOneRecord("SELECT `username` FROM `client` WHERE `client_id` = '" . intval($client_id) . "'");
			$aa_search = array('[client_id]', '[website_id]', '[client_username]', '[website_domain]');
			$aa_replace = array($client_id, $data['new']['domain_id'], $client['username'], $data['new']['domain']);
			$auto_alias = str_replace($aa_search, $aa_replace, $auto_alias);
			unset($client);
			unset($aa_search);
			unset($aa_replace);
			$server_alias[] .= $auto_alias.' ';
		}

		switch($data['new']['subdomain']) {
			case 'www':
				$server_alias[] = 'www.'.$data['new']['domain'].' ';
			break;
			case '*':
				$server_alias[] = '*.'.$data['new']['domain'].' ';
			break;
		}

		// get alias domains (co-domains and subdomains)
		$aliases = $app->db->queryAllRecords('SELECT * FROM web_domain WHERE parent_domain_id = '.$data['new']['domain_id']." AND active = 'y' AND type != 'vhostsubdomain'");
		$alias_seo_redirects = array();

		if (is_array($aliases)) {
			foreach ($aliases as $alias) {
				if ($alias['redirect_type'] == '' || $alias['redirect_path'] == '' || substr($alias['redirect_path'], 0, 1) == '/') {
					switch($alias['subdomain']) {
						case 'www':
							$server_alias[] = 'www.'.$alias['domain'].' '.$alias['domain'].' ';
						break;
						case '*':
							$server_alias[] = '*.'.$alias['domain'].' '.$alias['domain'].' ';
						break;
						default:
							$server_alias[] = $alias['domain'].' ';
					}

					$app->log('Add server alias: '.$alias['domain'], LOGLEVEL_DEBUG);

					// Add SEO redirects for alias domains
					if ($alias['seo_redirect'] != '' && $data['new']['seo_redirect'] != '*_to_www_domain_tld' && $data['new']['seo_redirect'] != '*_to_domain_tld' && ($alias['type'] == 'alias' || ($alias['type'] == 'subdomain' && $data['new']['seo_redirect'] != '*_domain_tld_to_www_domain_tld' && $data['new']['seo_redirect'] != '*_domain_tld_to_domain_tld'))) {
						$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_');

						if (is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)) {
							$alias_seo_redirects[] = $tmp_seo_redirects;
						}
					}
				}

				// Local Rewriting (inside vhost server {} container)
				if ($alias['redirect_type'] != '' && substr($alias['redirect_path'], 0, 1) == '/' && $alias['redirect_type'] != 'proxy') {  // proxy makes no sense with local path
					if (substr($alias['redirect_path'], -1) != '/') {
						$alias['redirect_path'] .= '/';
					}

					$rewrite_exclude = '(?!/\b('.substr($alias['redirect_path'], 1, -1).(substr($alias['redirect_path'], 1, -1) != ''? '|': '').'stats'.($vhost_data['errordocs'] == 1 ? '|error' : '').')\b)/';
					switch($alias['subdomain']) {
						case 'www':
							// example.com
							$local_rewrite_rules[] = array(
								'local_redirect_origin_domain' 	=> $alias['domain'],
								'local_redirect_operator'		=> '=',
								'local_redirect_exclude' 		=> $rewrite_exclude,
								'local_redirect_target' 		=> $alias['redirect_path'],
								'local_redirect_type' 			=> ($alias['redirect_type'] == 'no') ? '' : $alias['redirect_type']
							);

							// www.example.com
							$local_rewrite_rules[] = array(
								'local_redirect_origin_domain' 	=> 'www.'.$alias['domain'],
								'local_redirect_operator'		=> '=',
								'local_redirect_exclude' 		=> $rewrite_exclude,
								'local_redirect_target' 		=> $alias['redirect_path'],
								'local_redirect_type' 			=> ($alias['redirect_type'] == 'no') ? '' : $alias['redirect_type']
							);
						break;
						case '*':
							$local_rewrite_rules[] = array(
								'local_redirect_origin_domain' 	=> '^('.str_replace('.', '\.', $alias['domain']).'|.+\.'.str_replace('.', '\.', $alias['domain']).')$',
								'local_redirect_operator'		=> '~*',
								'local_redirect_exclude'		=> $rewrite_exclude,
								'local_redirect_target' 		=> $alias['redirect_path'],
								'local_redirect_type' 			=> ($alias['redirect_type'] == 'no') ? '' : $alias['redirect_type']
							);
						break;
						default:
							$local_rewrite_rules[] = array(
								'local_redirect_origin_domain' 	=> $alias['domain'],
								'local_redirect_operator'		=> '=',
								'local_redirect_exclude' 		=> $rewrite_exclude,
								'local_redirect_target' 		=> $alias['redirect_path'],
								'local_redirect_type' 			=> ($alias['redirect_type'] == 'no') ? '' : $alias['redirect_type']
							);
					}
				}

				// External Rewriting (extra server {} containers)
				if ($alias['redirect_type'] != '' && $alias['redirect_path'] != '' && substr($alias['redirect_path'], 0, 1) != '/') {
					if (substr($alias['redirect_path'], -1) != '/') {
						$alias['redirect_path'] .= '/';
					}

					if (substr($alias['redirect_path'], 0, 8) == '[scheme]') {
						if ($alias['redirect_type'] != 'proxy') {
							$alias['redirect_path'] = '$scheme'.substr($alias['redirect_path'], 8);
						}
					}

					switch($alias['subdomain']) {
						case 'www':
							if ($alias['redirect_type'] != 'proxy') {
								if (substr($alias['redirect_path'], -1) == '/') {
									$alias['redirect_path'] = substr($alias['redirect_path'], 0, -1);
								}
							}

							// Add SEO redirects for alias domains
							$alias_seo_redirects2 = array();
							if ($alias['seo_redirect'] != '') {
								$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_', 'none');

								if (is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)) {
									$alias_seo_redirects2[] = $tmp_seo_redirects;
								}
							}

							$rewrite_rules[] = array(
								'rewrite_domain' 	=> $alias['domain'],
								'rewrite_type' 		=> ($alias['redirect_type'] == 'no') ? '' : $alias['redirect_type'],
								'rewrite_target' 	=> $alias['redirect_path'],
								'rewrite_subdir'	=> $rewrite_subdir,
								'use_rewrite'		=> true,
								'alias_seo_redirects2' => (count($alias_seo_redirects2) > 0 ? $alias_seo_redirects2 : false));

							// Add SEO redirects for alias domains
							$alias_seo_redirects2 = array();
							if ($alias['seo_redirect'] != '') {
								$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_', 'www');

								if (is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)) {
									$alias_seo_redirects2[] = $tmp_seo_redirects;
								}
							}

							$rewrite_rules[] = array(	'rewrite_domain' 	=> 'www.'.$alias['domain'],
								'rewrite_type' 		=> ($alias['redirect_type'] == 'no') ? '' : $alias['redirect_type'],
								'rewrite_target' 	=> $alias['redirect_path'],
								'rewrite_subdir'	=> $rewrite_subdir,
								'use_rewrite'		=> true,
								'alias_seo_redirects2' => (count($alias_seo_redirects2) > 0 ? $alias_seo_redirects2 : false)
							);
						break;
						case '*':
							if ($alias['redirect_type'] != 'proxy') {
								if (substr($alias['redirect_path'], -1) == '/') {
									$alias['redirect_path'] = substr($alias['redirect_path'], 0, -1);
								}
							}

							// Add SEO redirects for alias domains
							$alias_seo_redirects2 = array();
							if ($alias['seo_redirect'] != '') {
								$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_');

								if (is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)) {
									$alias_seo_redirects2[] = $tmp_seo_redirects;
								}
							}

							$rewrite_rules[] = array(
								'rewrite_domain' 	=> $alias['domain'].' *.'.$alias['domain'],
								'rewrite_type' 		=> ($alias['redirect_type'] == 'no') ? '' : $alias['redirect_type'],
								'rewrite_target' 	=> $alias['redirect_path'],
								'rewrite_subdir'	=> $rewrite_subdir,
								'use_rewrite'		=> true,
								'alias_seo_redirects2' => (count($alias_seo_redirects2) > 0 ? $alias_seo_redirects2 : false)
							);
						break;
						default:
							if ($alias['redirect_type'] == 'proxy') {
								$tmp_redirect_path = $alias['redirect_path'];
								$tmp_redirect_path_parts = parse_url($tmp_redirect_path);
								$rewrite_subdir = $tmp_redirect_path_parts['path'];

								if (substr($rewrite_subdir,0,1) == '/') {
									$rewrite_subdir = substr($rewrite_subdir,1);
								}

								if (substr($rewrite_subdir,-1) != '/') {
									$rewrite_subdir .= '/';
								}

								if ($rewrite_subdir == '/') {
									$rewrite_subdir = '';
								}
							}

							if ($alias['redirect_type'] != 'proxy') {
								if (substr($alias['redirect_path'],-1) == '/') {
									$alias['redirect_path'] = substr($alias['redirect_path'],0,-1);
								}
							}

							if (substr($alias['domain'], 0, 2) === '*.') {
								$domain_rule = '*.'.substr($alias['domain'], 2);
							} else {
								$domain_rule = $alias['domain'];
							}

							// Add SEO redirects for alias domains
							$alias_seo_redirects2 = array();
							if ($alias['seo_redirect'] != '') {
								if (substr($alias['domain'], 0, 2) === '*.') {
									$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_');
								} else {
									$tmp_seo_redirects = $this->get_seo_redirects($alias, 'alias_', 'none');
								}

								if (is_array($tmp_seo_redirects) && !empty($tmp_seo_redirects)) {
									$alias_seo_redirects2[] = $tmp_seo_redirects;
								}
							}

							$rewrite_rules[] = array(
								'rewrite_domain' 	=> $domain_rule,
								'rewrite_type' 		=> ($alias['redirect_type'] == 'no') ? '' : $alias['redirect_type'],
								'rewrite_target' 	=> $alias['redirect_path'],
								'rewrite_subdir'	=> $rewrite_subdir,
								'use_rewrite'		=> true,
								'alias_seo_redirects2' => (count($alias_seo_redirects2) > 0 ? $alias_seo_redirects2 : false)
							);
					}
				}
			}
		}

		//* If we have some alias records
		if (count($server_alias) > 0) {
			$server_alias_str = '';
			$n = 0;

			foreach ($server_alias as $tmp_alias) {
				$server_alias_str .= $tmp_alias;
			}

			unset($tmp_alias);
			$tpl->setVar('alias', trim($server_alias_str));
		} else {
			$tpl->setVar('alias','');
		}

		if (count($rewrite_rules) > 0) {
			$tpl->setLoop('redirects', $rewrite_rules);
		}

		if (count($own_rewrite_rules) > 0) {
			$tpl->setLoop('own_redirects', $own_rewrite_rules);
		}

		if (count($local_rewrite_rules) > 0) {
			$tpl->setLoop('local_redirects', $local_rewrite_rules);
		}

		if (count($alias_seo_redirects) > 0) {
			$tpl->setLoop('alias_seo_redirects', $alias_seo_redirects);
		}

		//* Create basic http auth for website statistics
		$tpl->setVar('stats_auth_passwd_file', $data['new']['document_root']."/web/stats/.htpasswd_stats");

		// Create basic http auth for other directories
		$basic_auth_locations = $this->_create_web_folder_auth_configuration($data['new']);

		if (is_array($basic_auth_locations) && !empty($basic_auth_locations)) {
			$tpl->setLoop('basic_auth_locations', $basic_auth_locations);
		}

		$vhost_file = escapeshellcmd($web_config['nginx_vhost_conf_dir'].'/'.$data['new']['domain'].'.vhost');

		//* Make a backup copy of vhost file
		if (file_exists($vhost_file)) {
			copy($vhost_file,$vhost_file.'~');
		}

		//* Write vhost file
		$app->system->file_put_contents($vhost_file, $this->nginx_merge_locations($tpl->grab()));
		$app->log('Writing the vhost file: '.$vhost_file, LOGLEVEL_DEBUG);
		unset($tpl);

		//* Set the symlink to enable the vhost
		//* First we check if there is a old type of symlink and remove it
		$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/'.$data['new']['domain'].'.vhost');

		if (is_link($vhost_symlink)) {
			unlink($vhost_symlink);
		}

		//* Remove old or changed symlinks
		if ($data['new']['subdomain'] != $data['old']['subdomain'] || $data['new']['active'] == 'n') {
			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/900-'.$data['new']['domain'].'.vhost');

			if (is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
			}

			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/100-'.$data['new']['domain'].'.vhost');

			if (is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
			}
		}

		//* New symlink
		if ($data['new']['subdomain'] == '*') {
			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/900-'.$data['new']['domain'].'.vhost');
		} else {
			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/100-'.$data['new']['domain'].'.vhost');
		}

		if ($data['new']['active'] == 'y' && !is_link($vhost_symlink)) {
			symlink($vhost_file,$vhost_symlink);
			$app->log('Creating symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
		}

		// remove old symlink and vhost file, if domain name of the site has changed
		if ($this->action == 'update' && $data['old']['domain'] != '' && $data['new']['domain'] != $data['old']['domain']) {
			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/900-'.$data['old']['domain'].'.vhost');

			if (is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
			}

			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/100-'.$data['old']['domain'].'.vhost');

			if (is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
			}

			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/'.$data['old']['domain'].'.vhost');

			if (is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
			}

			$vhost_file = escapeshellcmd($web_config['nginx_vhost_conf_dir'].'/'.$data['old']['domain'].'.vhost');
			$app->system->unlink($vhost_file);
			$app->log('Removing file: '.$vhost_file,LOGLEVEL_DEBUG);
		}

		$app->services->restartServiceDelayed('httpd','reload');

		//* The vhost is written and apache has been restarted, so we
		// can reset the ssl changed var to false and cleanup some files
		$ssl_dir = $data['new']['document_root'].'/ssl';
		$domain = $data['new']['ssl_domain'];
		$crt_file = $ssl_dir.'/'.$domain.'.nginx.crt';

		if (@is_file($crt_file.'~')) {
			$app->system->unlink($crt_file.'~');
		}

		// Remove the backup copy of the config file.
		if (@is_file($vhost_file.'~')) {
			$app->system->unlink($vhost_file.'~');
		}

		$this->action = '';
	}

	/**
	 * ISPConfig delete hook.
	 *
	 * Called every time a site gets deleted from within ISPConfig.
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 * @return void
	 */
	function delete($event_name, $data) {
		global $app, $conf;

		$app->uses('getconf');
		$app->uses('system');

		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		if ($data['old']['type'] != 'vhost' && $data['old']['type'] != 'vhostsubdomain' && $data['old']['parent_domain_id'] > 0) {
			//* This is a alias domain or subdomain, so we have to update the website instead
			$parent_domain_id = intval($data['old']['parent_domain_id']);
			$tmp = $app->db->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '.$parent_domain_id." AND active = 'y'");
			$data['new'] = $tmp;
			$data['old'] = $tmp;

			$this->action = 'update';
			$this->update($event_name, $data);
		} else {
			//* This is a website
			// Deleting the vhost file, symlink and the data directory
			$vhost_file = escapeshellcmd($web_config['nginx_vhost_conf_dir'].'/'.$data['old']['domain'].'.vhost');
			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/'.$data['old']['domain'].'.vhost');

			if (is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
			}

			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/900-'.$data['old']['domain'].'.vhost');

			if (is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
			}

			$vhost_symlink = escapeshellcmd($web_config['nginx_vhost_conf_enabled_dir'].'/100-'.$data['old']['domain'].'.vhost');

			if (is_link($vhost_symlink)) {
				$app->system->unlink($vhost_symlink);
				$app->log('Removing symlink: '.$vhost_symlink.'->'.$vhost_file,LOGLEVEL_DEBUG);
			}

			$app->system->unlink($vhost_file);
			$app->log('Removing vhost file: '.$vhost_file,LOGLEVEL_DEBUG);
		}
	}

	/**
	 * ISPConfig server IP hook.
	 *
	 * This function is called when a IP on the server is inserted, updated or deleted.
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 * @return void
	 */
	function server_ip($event_name, $data) {
		return;
	}

	/**
	 *
	 */
	function web_folder_user($event_name, $data) {
		global $app, $conf;

		$app->uses('system');

		if ($event_name == 'web_folder_user_delete') {
			$folder_id = $data['old']['web_folder_id'];
		} else {
			$folder_id = $data['new']['web_folder_id'];
		}

		$folder = $app->db->queryOneRecord("SELECT * FROM web_folder WHERE web_folder_id = ".intval($folder_id));
		$website = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ".intval($folder['parent_domain_id']));

		if (!is_array($folder) or !is_array($website)) {
			$app->log('Not able to retrieve folder or website record.',LOGLEVEL_DEBUG);
			return false;
		}

		$web_folder = 'web';
		if ($website['type'] == 'vhostsubdomain') {
			$web_folder = $website['web_folder'];
		}

		//* Get the folder path.
		if (substr($folder['path'],0,1) == '/') {
			$folder['path'] = substr($folder['path'],1);
		}

		if (substr($folder['path'],-1) == '/') {
			$folder['path'] = substr($folder['path'],0,-1);
		}

		$folder_path = escapeshellcmd($website['document_root'].'/' . $web_folder . '/'.$folder['path']);

		if (substr($folder_path,-1) != '/') {
			$folder_path .= '/';
		}

		//* Check if the resulting path is inside the docroot
		if (stristr($folder_path,'..') || stristr($folder_path,'./') || stristr($folder_path,'\\')) {
			$app->log('Folder path "'.$folder_path.'" contains .. or ./.',LOGLEVEL_DEBUG);
			return false;
		}

		//* Create the folder path, if it does not exist
		if (!is_dir($folder_path)) {
			$app->system->mkdirpath($folder_path);
			$app->system->chown($folder_path,$website['system_user']);
			$app->system->chgrp($folder_path,$website['system_group']);
		}

		//* Create empty .htpasswd file, if it does not exist
		if (!is_file($folder_path.'.htpasswd')) {
			touch($folder_path.'.htpasswd');
			$app->system->chmod($folder_path.'.htpasswd',0755);
			$app->system->chown($folder_path.'.htpasswd',$website['system_user']);
			$app->system->chgrp($folder_path.'.htpasswd',$website['system_group']);
			$app->log('Created file '.$folder_path.'.htpasswd',LOGLEVEL_DEBUG);
		}

		if (($data['new']['username'] != $data['old']['username'] || $data['new']['active'] == 'n') && $data['old']['username'] != '') {
			$app->system->removeLine($folder_path.'.htpasswd',$data['old']['username'].':');
			$app->log('Removed user: '.$data['old']['username'],LOGLEVEL_DEBUG);
		}

		//* Add or remove the user from .htpasswd file
		if ($event_name == 'web_folder_user_delete') {
			$app->system->removeLine($folder_path.'.htpasswd',$data['old']['username'].':');
			$app->log('Removed user: '.$data['old']['username'],LOGLEVEL_DEBUG);
		} else {
			if ($data['new']['active'] == 'y') {
				$app->system->replaceLine($folder_path.'.htpasswd',$data['new']['username'].':',$data['new']['username'].':'.$data['new']['password'],0,1);
				$app->log('Added or updated user: '.$data['new']['username'],LOGLEVEL_DEBUG);
			}
		}

		// write basic auth configuration to vhost file because nginx does not support .htaccess
		$webdata['new'] = $webdata['old'] = $website;
		$this->update('web_domain_update', $webdata);
	}

	/**
	 *
	 */
	function web_folder_delete($event_name, $data) {
		global $app, $conf;

		$folder_id = $data['old']['web_folder_id'];

		$folder = $data['old'];
		$website = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ".intval($folder['parent_domain_id']));

		if (!is_array($folder) or !is_array($website)) {
			$app->log('Not able to retrieve folder or website record.',LOGLEVEL_DEBUG);
			return false;
		}

		$web_folder = 'web';
		if ($website['type'] == 'vhostsubdomain') {
			$web_folder = $website['web_folder'];
		}

		//* Get the folder path.
		if (substr($folder['path'],0,1) == '/') {
			$folder['path'] = substr($folder['path'],1);
		}

		if (substr($folder['path'],-1) == '/') {
			$folder['path'] = substr($folder['path'],0,-1);
		}

		$folder_path = realpath($website['document_root'].'/' . $web_folder . '/'.$folder['path']);

		if (substr($folder_path,-1) != '/') {
			$folder_path .= '/';
		}

		//* Check if the resulting path is inside the docroot
		if (substr($folder_path,0,strlen($website['document_root'])) != $website['document_root']) {
			$app->log('Folder path is outside of docroot.',LOGLEVEL_DEBUG);
			return false;
		}

		//* Remove .htpasswd file
		if (is_file($folder_path.'.htpasswd')) {
			$app->system->unlink($folder_path.'.htpasswd');
			$app->log('Removed file '.$folder_path.'.htpasswd',LOGLEVEL_DEBUG);
		}

		// write basic auth configuration to vhost file because nginx does not support .htaccess
		$webdata['new'] = $webdata['old'] = $website;
		$this->update('web_domain_update', $webdata);
	}

	/**
	 *
	 */
	function web_folder_update($event_name,$data) {
		global $app, $conf;

		$website = $app->db->queryOneRecord("SELECT * FROM web_domain WHERE domain_id = ".intval($data['new']['parent_domain_id']));

		if (!is_array($website)) {
			$app->log('Not able to retrieve folder or website record.',LOGLEVEL_DEBUG);
			return false;
		}

		$web_folder = 'web';
		if ($website['type'] == 'vhostsubdomain') {
			$web_folder = $website['web_folder'];
		}

		//* Get the folder path.
		if (substr($data['old']['path'],0,1) == '/') {
			$data['old']['path'] = substr($data['old']['path'],1);
		}

		if (substr($data['old']['path'],-1) == '/') {
			$data['old']['path'] = substr($data['old']['path'],0,-1);
		}

		$old_folder_path = realpath($website['document_root'].'/' . $web_folder . '/'.$data['old']['path']);

		if (substr($old_folder_path,-1) != '/') {
			$old_folder_path .= '/';
		}

		if (substr($data['new']['path'],0,1) == '/') {
			$data['new']['path'] = substr($data['new']['path'],1);
		}

		if (substr($data['new']['path'],-1) == '/') {
			$data['new']['path'] = substr($data['new']['path'],0,-1);
		}

		$new_folder_path = escapeshellcmd($website['document_root'].'/' . $web_folder . '/'.$data['new']['path']);

		if (substr($new_folder_path,-1) != '/') {
			$new_folder_path .= '/';
		}

		//* Check if the resulting path is inside the docroot
		if (stristr($new_folder_path,'..') || stristr($new_folder_path,'./') || stristr($new_folder_path,'\\')) {
			$app->log('Folder path "'.$new_folder_path.'" contains .. or ./.',LOGLEVEL_DEBUG);
			return false;
		}

		if (stristr($old_folder_path,'..') || stristr($old_folder_path,'./') || stristr($old_folder_path,'\\')) {
			$app->log('Folder path "'.$old_folder_path.'" contains .. or ./.',LOGLEVEL_DEBUG);
			return false;
		}

		//* Check if the resulting path is inside the docroot
		if (substr($old_folder_path,0,strlen($website['document_root'])) != $website['document_root']) {
			$app->log('Old folder path '.$old_folder_path.' is outside of docroot.',LOGLEVEL_DEBUG);
			return false;
		}

		if (substr($new_folder_path,0,strlen($website['document_root'])) != $website['document_root']) {
			$app->log('New folder path '.$new_folder_path.' is outside of docroot.',LOGLEVEL_DEBUG);
			return false;
		}

		//* Create the folder path, if it does not exist
		if (!is_dir($new_folder_path)) {
			$app->system->mkdirpath($new_folder_path);
		}

		if ($data['old']['path'] != $data['new']['path']) {
			//* move .htpasswd file
			if (is_file($old_folder_path.'.htpasswd')) {
				$app->system->rename($old_folder_path.'.htpasswd',$new_folder_path.'.htpasswd');
				$app->log('Moved file '.$old_folder_path.'.htpasswd to '.$new_folder_path.'.htpasswd',LOGLEVEL_DEBUG);
			}
		}

		// write basic auth configuration to vhost file because nginx does not support .htaccess
		$webdata['new'] = $webdata['old'] = $website;
		$this->update('web_domain_update', $webdata);
	}

	/**
	 *
	 */
	function _create_web_folder_auth_configuration($website) {
		global $app, $conf;

		//* Create the domain.auth file which is included in the vhost configuration file
		$app->uses('getconf');

		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');
		$basic_auth_file = escapeshellcmd($web_config['nginx_vhost_conf_dir'].'/'.$website['domain'].'.auth');

		$website_auth_locations = $app->db->queryAllRecords("SELECT * FROM web_folder WHERE active = 'y' AND parent_domain_id = ".intval($website['domain_id']));
		$basic_auth_locations = array();

		if (is_array($website_auth_locations) && !empty($website_auth_locations)) {
			foreach ($website_auth_locations as $website_auth_location) {
				if (substr($website_auth_location['path'], 0, 1) == '/') {
					$website_auth_location['path'] = substr($website_auth_location['path'], 1);
				}

				if (substr($website_auth_location['path'], -1) == '/') {
					$website_auth_location['path'] = substr($website_auth_location['path'], 0, -1);
				}

				if ($website_auth_location['path'] != '') {
					$website_auth_location['path'] .= '/';
				}

				$basic_auth_locations[] = array(
					'htpasswd_location' => '/'.$website_auth_location['path'],
					'htpasswd_path' => $website['document_root'].'/' . ($website['type'] == 'vhostsubdomain' ? $website['web_folder'] : 'web') . '/'.$website_auth_location['path']
				);
			}
		}

		return $basic_auth_locations;
	}

	/**
	 *
	 */
	private function nginx_replace($matches) {
		$location = 'location'.($matches[1] != '' ? ' '.$matches[1] : '').' '.$matches[2].' '.$matches[3];

		if ($matches[4] == '##merge##' || $matches[7] == '##merge##') {
			$location .= ' ##merge##';
		}

		$location .= "\n";
		$location .= $matches[5]."\n";
		$location .= $matches[6];

		return $location;
	}

	/**
	 *
	 */
	private function nginx_merge_locations($vhost_conf) {
		$lines = explode("\n", $vhost_conf);

		// if whole location block is in one line, split it up into multiple lines
		if (is_array($lines) && !empty($lines)) {
			$linecount = sizeof($lines);

			for ($h = 0; $h < $linecount; $h++) {
				// remove comments
				if (substr(trim($lines[$h]),0,1) == '#') {
					unset($lines[$h]);
					continue;
				}

				$lines[$h] = rtrim($lines[$h]);
				$pattern = '/^[^\S\n]*location[^\S\n]+(?:(.+)[^\S\n]+)?(.+)[^\S\n]*(\{)[^\S\n]*(##merge##)?[^\S\n]*(.+)[^\S\n]*(\})[^\S\n]*(##merge##)?[^\S\n]*$/';
				$lines[$h] = preg_replace_callback($pattern, array($this, 'nginx_replace') ,$lines[$h]);
			}
		}

		$vhost_conf = implode("\n", $lines);
		unset($lines);
		unset($linecount);

		$lines = explode("\n", $vhost_conf);

		if (is_array($lines) && !empty($lines)) {
			$locations = array();
			$islocation = false;
			$linecount = sizeof($lines);
			$server_count = 0;

			for ($i = 0; $i < $linecount; $i++) {
				$l = trim($lines[$i]);

				if (substr($l, 0, 8) == 'server {') {
					$server_count += 1;
				}

				if ($server_count > 1) {
					break;
				}

				if (substr($l, 0, 8) == 'location' && !$islocation) {
					$islocation = true;
					$level = 0;

					// Remove unnecessary whitespace
					$l = preg_replace('/\s\s+/', ' ', $l);

					$loc_parts = explode(' ', $l);
					// see http://wiki.nginx.org/HttpCoreModule#location
					if ($loc_parts[1] == '=' || $loc_parts[1] == '~' || $loc_parts[1] == '~*' || $loc_parts[1] == '^~') {
						$location = $loc_parts[1].' '.$loc_parts[2];
					} else {
						$location = $loc_parts[1];
					}

					unset($loc_parts);

					if (!isset($locations[$location]['action'])) {
						$locations[$location]['action'] = 'replace';
					}

					if (substr($l, -9) == '##merge##') {
						$locations[$location]['action'] = 'merge';
					}

					if (!isset($locations[$location]['open_tag'])) {
						$locations[$location]['open_tag'] = '        location '.$location.' {';
					}

					if (!isset($locations[$location]['location']) || $locations[$location]['action'] == 'replace') {
						$locations[$location]['location'] = '';
					}

					if (!isset($locations[$location]['end_tag'])) {
						$locations[$location]['end_tag'] = '        }';
					}

					if (!isset($locations[$location]['start_line'])) {
						$locations[$location]['start_line'] = $i;
					}

					unset($lines[$i]);
				} else {
					if ($islocation) {
						if (strpos($l, '{') !== false) {
							$level += 1;
						}

						if (strpos($l, '}') !== false && $level > 0) {
							$level -= 1;
							$locations[$location]['location'] .= $lines[$i]."\n";
						} elseif (strpos($l, '}') !== false && $level == 0) {
							$islocation = false;
						} else {
							$locations[$location]['location'] .= $lines[$i]."\n";
						}

						unset($lines[$i]);
					}
				}
			}

			if (is_array($locations) && !empty($locations)) {
				foreach ($locations as $key => $val) {
					$new_location = $val['open_tag']."\n".$val['location'].$val['end_tag'];
					$lines[$val['start_line']] = $new_location;
				}
			}

			ksort($lines);
			$vhost_conf = implode("\n", $lines);
		}

		return trim($vhost_conf);
	}

	/**
	 * ISPConfig client delete hook.
	 *
	 * Called every time, a client gets deleted.
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 * @return void
	 */
	function client_delete($event_name, $data) {
		return;
	}

	/**
	 * ISPConfig internal debug method.
	 *
	 * @param string $command executable command to debug
	 */
	private function _exec($command) {
		global $app;

		$app->log('exec: '.$command, LOGLEVEL_DEBUG);
		exec($command);
	}

	/**
	 *
	 */
	public function create_relative_link($f, $t) {
		global $app;

		$from = realpath($f);

		// realpath requires the traced file to exist - so, lets touch it first, then remove
		@$app->system->unlink($t);
		touch($t);
		$to = realpath($t);
		@$app->system->unlink($t);

		// Remove from the left side matching path elements from $from and $to
		// and get path elements counts
		$a1 = explode('/', $from);
		$a2 = explode('/', $to);

		for ($c = 0; $a1[$c] == $a2[$c]; $c++) {
			unset($a1[$c]);
			unset($a2[$c]);
		}

		$cfrom = implode('/', $a1);

		// Check if a path is fully a subpath of another - no way to create symlink in the case
		if (count($a1) == 0 || count($a2) == 0) {
			return false;
		}

		// Add ($cnt_to-1) number of "../" elements to left side of $cfrom
		for ($c = 0; $c < (count($a2) - 1); $c++) {
			$cfrom = '../'.$cfrom;
		}

		return symlink($cfrom, $to);
	}

	/**
	 *
	 */
	private function _rewrite_quote($string) {
		return str_replace(array('.', '*', '?', '+'), array('\\.', '\\*', '\\?', '\\+'), $string);
	}

	/**
	 *
	 */
	private function get_seo_redirects($web, $prefix = '', $force_subdomain = false) {
		$seo_redirects = array();

		if (substr($web['domain'], 0, 2) === '*.') {
			$web['subdomain'] = '*';
		}

		if (($web['subdomain'] == 'www' || $web['subdomain'] == '*') && $force_subdomain != 'www') {
			if ($web['seo_redirect'] == 'non_www_to_www') {
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '=';
			}

			if ($web['seo_redirect'] == '*_domain_tld_to_www_domain_tld') {
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = '^('.str_replace('.', '\.', $web['domain']).'|((?:\w+(?:-\w+)*\.)*)((?!www\.)\w+(?:-\w+)*)(\.'.str_replace('.', '\.', $web['domain']).'))$';
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '~*';
			}

			if ($web['seo_redirect'] == '*_to_www_domain_tld') {
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '!=';
			}
		}

		if ($force_subdomain != 'none') {
			if ($web['seo_redirect'] == 'www_to_non_www') {
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = 'www.'.$web['domain'];
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '=';
			}

			if ($web['seo_redirect'] == '*_domain_tld_to_domain_tld') {
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = '^(.+)\.'.str_replace('.', '\.', $web['domain']).'$';
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '~*';
			}

			if ($web['seo_redirect'] == '*_to_domain_tld') {
				$seo_redirects[$prefix.'seo_redirect_origin_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_target_domain'] = $web['domain'];
				$seo_redirects[$prefix.'seo_redirect_operator'] = '!=';
			}
		}

		return $seo_redirects;
	}

}
