<?php

/**
 * ISPConfig Nginx Reverse Proxy Plugin.
 *
 * This class extends ISPConfig's vhost management with the functionality to run
 * Nginx in front of Apache2 as a transparent reverse proxy.
 *
 * @author Rackster Internet Services <open-source@rackster.ch>
 * @link https://open-source.rackster.ch/project/ispconfig3-nginx-reverse-proxy-plugin
 */
class nginx_reverse_proxy_plugin {

	/**
	 * Stores the internal plugin name.
	 *
	 * @var string
	 */
	var $plugin_name = 'nginx_reverse_proxy_plugin';

	/**
	 * Stores the internal class name.
	 *
	 * Needs to be the same as $plugin_name.
	 *
	 * @var string
	 */
	var $class_name = 'nginx_reverse_proxy_plugin';

	/**
	 * Stores the current vhost action.
	 *
	 * When ISPConfig triggers the vhost event, it passes either create,update,delete etc.
	 *
	 * @see onLoad()
	 *
	 * @var string
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

		if($conf['services']['web'] == true) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * ISPConfig onLoad hook.
	 *
	 * Register the plugin for some site related events.
	 */
	function onLoad() {
		global $app;

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'ssl');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'ssl');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'ssl');

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'insert');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'update');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'delete');

		$app->plugins->registerEvent('client_delete', $this->plugin_name, 'client_delete');
	}


	/**
	 * ISPConfig ssl hook.
	 *
	 * Called every time something in the ssl tab is done.
	 *
	 * @see onLoad()
	 * @uses cert_helper()
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 */
	function ssl($event_name, $data) {
		global $app, $conf;

		$app->uses('system');

		//* Only vhosts can have a ssl cert
		if($data["new"]["type"] != "vhost" && $data["new"]["type"] != "vhostsubdomain") {
			return;
		}

		if ($data['new']['ssl_action'] == 'del') {
			$this->cert_helper('delete', $data);
		} else {
			$this->cert_helper('update', $data);
		}
	}

	/**
	 * ISPConfig insert hook.
	 *
	 * Called every time a new site is created.
	 *
	 * @uses update()
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 */
	function insert($event_name, $data)	{
		global $app, $conf;

		$this->action = 'insert';
		$this->update($event_name, $data);
	}

	/**
	 * ISPConfig update hook.
	 *
	 * Called every time a site gets updated from within ISPConfig.
	 *
	 * @see insert()
	 * @see delete()
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 */
	function update($event_name, $data)	{
		global $app, $conf;

		//* $VAR: command to run after vhost insert/update/delete
		$final_command = '/etc/init.d/nginx restart && rm -rf /var/cache/nginx/*';

		if ($this->action != 'insert') {
			$this->action = 'update';
		}

		$app->uses('getconf');
		$app->uses('system');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		$app->load('tpl');

		$tpl = new tpl();
		$tpl->newTemplate('nginx_reverse_proxy_plugin.vhost.conf.master');

		$web_folder = 'web';
		if($data['new']['type'] == 'vhostsubdomain') {
			$tmp = $app->db->queryOneRecord('SELECT `domain` FROM web_domain WHERE domain_id = '.intval($data['new']['parent_domain_id']));
			$subdomain_host = preg_replace('/^(.*)\.' . preg_quote($tmp['domain'], '/') . '$/', '$1', $data['new']['domain']);

			if($subdomain_host == '') {
				$subdomain_host = 'web'.$data['new']['domain_id'];
			}

			$web_folder = $data['new']['web_folder'];
			unset($tmp);
		}

		$vhost_data = $data['new'];
		$vhost_data['web_document_root'] = $data['new']['document_root'].'/'.$web_folder;
		$vhost_data['web_document_root_www'] = $web_config['website_basedir'].'/'.$data['new']['domain'].'/'.$web_folder;
		$vhost_data['web_basedir'] = $web_config['website_basedir'];
		$vhost_data['ssl_domain'] = $data['new']['ssl_domain'];

		/* __ VHOST & VHOSTSUBDOMAIN - section for vhosts and vhostsubdomains //////////////*/
		if ($data['new']['type'] == 'vhost' || $data['new']['type'] == 'vhostsubdomain') {
			if ($data['new']['ipv6_address'] != '') {
				$tpl->setVar('ipv6_enabled', 1);
			}

			$server_alias = array();
			switch($data['new']['subdomain']) {
				case 'www':
					$server_alias[] .= 'www.'. $data['new']['domain'] .' ';
				break;
				case '*':
					$server_alias[] .= '*.'. $data['new']['domain'] .' ';
				break;
			}

			$alias_result = array();
			$alias_result = $app->dbmaster->queryAllRecords('SELECT * FROM web_domain WHERE parent_domain_id = '.$data['new']['domain_id']." AND active = 'y' AND type != 'vhostsubdomain'");

			if (count($alias_result) > 0) {
				foreach($alias_result as $alias) {
					switch($alias['subdomain']) {
						case 'www':
							$server_alias[] .= 'www.'. $alias['domain'] .' '. $alias['domain'] .' ';
						break;
						case '*':
							$server_alias[] .= '*.'. $alias['domain'] .' '. $alias['domain'] .' ';
						break;
						default:
							$server_alias[] .= $alias['domain'] .' ';
					}

					$app->log('Add server alias: '. $alias['domain'], LOGLEVEL_DEBUG);
				}

				unset($alias);
			}

			if (count($server_alias) > 0) {
				$server_alias_str = '';

				foreach($server_alias as $tmp_alias) {
					$server_alias_str .= $tmp_alias;
				}

				unset($tmp_alias);
				$tpl->setVar('alias', $server_alias_str);
			} else {
				$tpl->setVar('alias', '');
			}

			if (!isset($rewrite_rules)) {
				$rewrite_rules = array();
			}

			if ($data['new']['redirect_type'] != '' && $data['new']['redirect_path'] != '')	{
				if (substr($data['new']['redirect_path'], -1) != '/') {
					$data['new']['redirect_path'] .= '/';
				}

				if (substr($data['new']['redirect_path'], 0, 8) == '[scheme]') {
					$rewrite_target = 'http'.substr($data['new']['redirect_path'], 8);
					$rewrite_target_ssl = 'https'.substr($data['new']['redirect_path'], 8);
				} else {
					$rewrite_target = $data['new']['redirect_path'];
					$rewrite_target_ssl = $data['new']['redirect_path'];
				}

				if (substr($data['new']['redirect_path'], 0, 4) == 'http') {
					$data['new']['redirect_type'] = 'permanent';
				} else {
					switch($data['new']['redirect_type']) {
						case 'no':
							$data['new']['redirect_type'] = 'break';
						break;
						case 'L':
							$data['new']['redirect_type'] = 'break';
						break;
						default:
							$data['new']['redirect_type'] = 'permanent';
					}
				}

				switch($data['new']['subdomain']) {
					case 'www':
						$rewrite_rules[] = array(
							'rewrite_domain' => '^'.$data['new']['domain'],
							'rewrite_type' => ($data['new']['redirect_type'] == 'no') ? '' : $data['new']['redirect_type'],
							'rewrite_target' => $rewrite_target,
							'rewrite_target_ssl' => $rewrite_target_ssl
						);
						$rewrite_rules[] = array(
							'rewrite_domain' => '^www.'.$data['new']['domain'],
							'rewrite_type' => ($data['new']['redirect_type'] == 'no') ? '' : $data['new']['redirect_type'],
							'rewrite_target' => $rewrite_target,
							'rewrite_target_ssl' => $rewrite_target_ssl
						);
					break;
					case '*':
						$rewrite_rules[] = array(
							'rewrite_domain' => '(^|\.)'.$data['new']['domain'],
							'rewrite_type' => ($data['new']['redirect_type'] == 'no') ? '' : $data['new']['redirect_type'],
							'rewrite_target' => $rewrite_target,
							'rewrite_target_ssl' => $rewrite_target_ssl
						);
					break;
					default:
						$rewrite_rules[] = array(
							'rewrite_domain' => '^'.$data['new']['domain'],
							'rewrite_type' => ($data['new']['redirect_type'] == 'no') ? '' : $data['new']['redirect_type'],
							'rewrite_target' => $rewrite_target,
							'rewrite_target_ssl' => $rewrite_target_ssl
						);
				}
			}

			if ($data['new']['seo_redirect'] != '' && ($data['new']['subdomain'] == 'www' || $data['new']['subdomain'] == '*')) {
				$vhost_data['seo_redirect_enabled'] = 1;

				if ($data['new']['seo_redirect'] == 'non_www_to_www') {
					$vhost_data['seo_redirect_origin_domain'] = $data['new']['domain'];
					$vhost_data['seo_redirect_target_domain'] = 'www.'. $data['new']['domain'];
				}

				if ($data['new']['seo_redirect'] == 'www_to_non_www') {
					$vhost_data['seo_redirect_origin_domain'] = 'www.'. $data['new']['domain'];
					$vhost_data['seo_redirect_target_domain'] = $data['new']['domain'];
				}
			} else {
				$vhost_data['seo_redirect_enabled'] = 0;
			}

			$nginx_directives = $data['new']['nginx_directives'];

			$errordocs = !$data['new']['errordocs'];

			$nginx_directives = str_replace("\r\n", "\n", $nginx_directives);
			$nginx_directives = str_replace("\r", "\n", $nginx_directives);
			#$nginx_directives = explode("\n", $nginx_directives);

			$crt_file = escapeshellcmd($data['new']['document_root'] .'/ssl/'. $data['new']['ssl_domain'] .'.crt');
			$key_file = escapeshellcmd($data['new']['document_root'] .'/ssl/'. $data['new']['ssl_domain'] .'.key');

			if ($data['new']['ssl_domain'] != '' && $data['new']['ssl'] == 'y' && is_file($crt_file) && is_file($key_file) && (filesize($crt_file) > 0) && (filesize($key_file) > 0)) {
				$http_to_https = 1;
			} else {
				$http_to_https = 0;
			}

			if (count($rewrite_rules) > 0) {
				$vhosts[] = array(
					'ip_address' => $data['new']['ip_address'],
					'ipv6_address' => $data['new']['ipv6_address'],
					'ssl_enabled' => 0,
					'http_to_https' => $http_to_https,
					#'nginx_directives' => $nginx_directives,
					'errordocs' => $errordocs,
					'port' => 80,
					'apache2_port' => 82
				);
			} else {
				$vhosts[] = array(
					'ip_address' => $data['new']['ip_address'],
					'ipv6_address' => $data['new']['ipv6_address'],
					'ssl_enabled' => 0,
					'http_to_https' => $http_to_https,
					#'nginx_directives' => $nginx_directives,
					'errordocs' => $errordocs,
					'port' => 80,
					'apache2_port' => 82
				);
			}

			if ($http_to_https == 1) {
				$vhost_data['web_document_root_ssl'] = $data['new']['document_root'] .'/ssl';

				if (count($rewrite_rules) > 0) {
					$vhosts[] = array(
						'ip_address' => $data['new']['ip_address'],
						'ipv6_address' => $data['new']['ipv6_address'],
						'ssl_enabled' => 1,
						'http_to_https' => 0,
						'rewrite_enabled' => 1,
						#'nginx_directives' => $nginx_directives,
						'errordocs' => $errordocs,
						'port' => 443,
						'apache2_port' => 82
					);
				} else {
					$vhosts[] = array(
						'ip_address' => $data['new']['ip_address'],
						'ipv6_address' => $data['new']['ipv6_address'],
						'ssl_enabled' => 1,
						'http_to_https' => 0,
						'rewrite_enabled' => 0,
						#'nginx_directives' => $nginx_directives,
						'errordocs' => $errordocs,
						'port' => 443,
						'apache2_port' => 82
					);
				}
			}

			$tpl->setLoop('vhosts', $vhosts);
			$tpl->setVar($vhost_data);

			if ($this->action == 'insert') {
				$this->vhost_helper('insert', $data, $tpl->grab());
			}

			if ($this->action == 'update') {
				$vhost_backup = $this->vhost_helper('update', $data, $tpl->grab());
			}
		}


		/* __ ALIAS - section for aliasdomains /////////////////////////////////////////////*/
		if ($data['new']['type'] == 'alias') {
			$parent_domain = $app->dbmaster->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '. intval($data['new']['parent_domain_id']) .'');

			$parent_domain['parent_domain_id'] = $data['new']['parent_domain_id'];
			$data['old'] = $parent_domain;
			$data['new'] = $parent_domain;

			$this->update($event_name, $data);
		}


		/* __ SUBDOMAIN - section for classic subdomains ///////////////////////////////////*/
		if ($data['new']['type'] == 'subdomain') {
			$parent_domain = $app->dbmaster->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '. intval($data['new']['parent_domain_id']) .'');

			$parent_domain['parent_domain_id'] = $data['new']['parent_domain_id'];
			$data['old'] = $parent_domain;
			$data['new'] = $parent_domain;

			$this->update($event_name, $data);
		}

		exec($final_command);

		if (isset($vhost_backup)) {
			$app->system->unlink($vhost_backup['file_new'].'~');
		}

		unset($vhost_backup);
		$this->action = '';
	}

	/**
	 * ISPConfig delete hook.
	 *
	 * Called every time, a site get's removed.
	 *
	 * @uses update()
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 */
	function delete($event_name, $data) {
		global $app, $conf;

		$this->action = 'delete';

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		if ($data['old']['type'] == 'vhost' || $data['old']['type'] == 'vhostsubdomain') {
			$this->vhost_helper('delete', $data);
		}

		if ($data['old']['type'] == 'alias') {
			$data['new']['type'] == 'alias';
			$this->update($event_name, $data);
		}

		if ($data['old']['type'] == 'subdomain') {
			$data['new']['type'] == 'subdomain';
			$this->update($event_name, $data);
		}
	}

	/**
	 * ISPConfig client delete hook.
	 *
	 * Called every time, a client gets deleted.
	 *
	 * @uses vhost_helper()
	 *
	 * @param string $event_name the event/action name
	 * @param array $data the vhost data
	 */
	function client_delete($event_name, $data) {
		global $app, $conf;

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		$client_id = intval($data['old']['client_id']);
		$client_vhosts = array();
		$client_vhosts = $app->dbmaster->queryAllRecords('SELECT domain FROM web_domain WHERE sys_userid = '. $client_id .' AND parent_domain_id = 0');

		if (count($client_vhosts) > 0) {
			foreach($client_vhosts as $vhost) {
				$data['old']['domain'] = $vhost['domain'];
				$this->vhost_helper('delete', $data);

				$app->log('Removing vhost file: '. $data['old']['domain'], LOGLEVEL_DEBUG);
			}
		}
	}


	/**
	 * ISPConfig internal debug function.
	 *
	 * Function for easier debugging.
	 *
	 * @param string $command executable command to debug
	 */
	private function _exec($command) {
		global $app;

		$app->log('exec: '. $command, LOGLEVEL_DEBUG);
		exec($command);
	}


	/**
	 * Helps managing vhost config files.
	 *
	 * This functions helps to create/delete and link/unlink vhost configs on disk.
	 *
	 * @param string $action the event/action name
	 * @param array $data the vhost data
	 * @param mixed $tpl vhost template to proceed
	 * @return $data['vhost'] the vhost data
	 */
	private function vhost_helper($action, $data, $tpl = '') {
		global $app;

		$app->uses('system');

		//* $VAR: location of nginx vhost dirs
		$nginx_vhosts = '/etc/nginx/sites-available';
		$nginx_vhosts_enabled = '/etc/nginx/sites-enabled';

		$data['vhost'] = array();

		$data['vhost']['file_old'] = escapeshellcmd($nginx_vhosts .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['link_old'] = escapeshellcmd($nginx_vhosts_enabled .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['file_new'] = escapeshellcmd($nginx_vhosts .'/'. $data['new']['domain'] .'.vhost');
		$data['vhost']['link_new'] = escapeshellcmd($nginx_vhosts_enabled .'/'. $data['new']['domain'] .'.vhost');

		if (is_file($data['vhost']['file_old'])) {
			$data['vhost']['file_old_check'] = 1;
		}

		if (is_file($data['vhost']['file_new'])) {
			$data['vhost']['file_new_check'] = 1;
		}

		if (is_link($data['vhost']['link_old'])) {
			$data['vhost']['link_old_check'] = 1;
		}

		if (is_link($data['vhost']['link_new'])) {
			$data['vhost']['link_new_check'] = 1;
		}

		return $data['vhost'] = call_user_func(
			array(
				$this,
				"vhost_".$action
			),
			$data,
			$app,
			$tpl
		);
	}

	/**
	 * Creates the vhost file and link.
	 *
	 * @param array $data the vhost data
	 * @param object $app ISPConfig app object
	 * @param mixed $tpl vhost template to proceed
	 * @return $data['vhost'] the vhost data
	 */
	private function vhost_insert($data, $app, $tpl) {
		global $app;

		$app->uses('system');

		$app->system->file_put_contents($data['vhost']['file_new'], $tpl);

		$data['vhost']['file_new_check'] = 1;
		$app->log('Creating vhost file: '. $data['vhost']['file_new'], LOGLEVEL_DEBUG);
		unset($tpl);

		if ($data['vhost']['link_new_check'] != 1) {
			exec('ln -s '. $data['vhost']['file_new'] .' '. $data['vhost']['link_new']);
			$data['vhost']['link_new_check'] = 1;
			$app->log('Creating vhost symlink: '. $data['vhost']['link_new_check'], LOGLEVEL_DEBUG);
		}

		return $data['vhost'];
	}

	/**
	 * Updates the vhost file and link.
	 *
	 * @uses vhost_delete()
	 * @uses vhost_insert()
	 *
	 * @param array $data the vhost data
	 * @param object $app ISPConfig app object
	 * @param mixed $tpl vhost template to proceed
	 * @return
	 */
	private function vhost_update($data, $app, $tpl) {
		global $app;

		$app->uses('system');

		$data['vhost']['link_new_check'] = 0;

		if ($data['new']['active'] == 'n') {
			$data['vhost']['link_new_check'] = 1;
		}

		$app->system->rename($data['vhost']['file_new'], $data['vhost']['file_new'].'~');
		$data['vhost']['file_new_check'] = 0;
		$data['vhost']['file_old_check'] = 0;

		$this->vhost_delete($data, $app);
		return $this->vhost_insert($data, $app, $tpl);
	}

	/**
	 * Deletes the vhost file and link.
	 *
	 * @param array $data the vhost data
	 * @param object $app ISPConfig app object
	 * @param mixed $tpl vhost template to proceed
	 */
	private function vhost_delete($data, $app, $tpl = '') {
		global $app;

		$app->uses('system');

		if ($data['vhost']['file_old_check'] == 1) {
			$app->system->unlink($data['vhost']['file_old']);
			$data['vhost']['file_old_check'] = 0;
			$app->log('Removing vhost file: '. $data['vhost']['file_old'], LOGLEVEL_DEBUG);
		}

		if ($data['vhost']['link_old_check'] == 1) {
			$app->system->unlink($data['vhost']['link_old']);
			$data['vhost']['link_old_check'] = 0;
			$app->log('Removing vhost symlink: '. $data['vhost']['link_old'], LOGLEVEL_DEBUG);
		}
	}


	/**
	 * Helps managing SSL cert files.
	 *
	 * This functions helps to create/delete and link/unlink SSL cert files on disk.
	 *
	 * @param string $action the event/action name
	 * @param array $data the vhost data
	 * @return $data['cert'] the cert data
	 */
	private function cert_helper($action, $data) {
		global $app;

		$app->uses('system');

		$data['cert'] = array();
		$suffix = 'nginx';
		$ssl_dir = $data['new']['document_root'] .'/ssl';

		$data['cert']['crt'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.crt');
		$data['cert']['key'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.key');
		$data['cert']['bundle'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.bundle');
		$data['cert'][$suffix .'_crt'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.'. $suffix .'.crt');
		$data['cert'][$suffix .'_key'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.'. $suffix .'.key');

		if (is_file($data['cert']['crt'])) {
			$data['cert']['crt_check'] = 1;
		}

		if (is_file($data['cert'][$suffix .'_crt'])) {
			$data['cert'][$suffix .'_crt_check'] = 1;
		}

		if (is_file($data['cert']['key'])) {
			$data['cert']['key_check'] = 1;
		}

		if (is_file($data['cert'][$suffix .'_key'])) {
			$data['cert'][$suffix .'_key_check'] = 1;
		}

		if (is_file($data['cert']['bundle'])) {
			$data['cert']['bundle_check'] = 1;
		}

		return $data['cert'] = call_user_func(
			array(
				$this,
				"cert_".$action
			),
			$data,
			$app,
			$suffix
		);
	}

	/**
	 * Creates the ssl cert files.
	 *
	 * @param array $data the vhost data
	 * @param object $app ISPConfig app object
	 * @param string $suffix cert filename suffix
	 */
	private function cert_insert($data, $app, $suffix) {
		global $app;

		$app->uses('system');

		if ($data['cert']['crt_check'] == 1 && $data['cert']['key_check'] == 1)	{
			if ($data['cert']['bundle_check'] == 1)	{
				exec('echo "" > /tmp/ispconfig3_newline_fix');

				exec('cat '. $data['cert']['crt'] .' /tmp/ispconfig3_newline_fix '. $data['cert']['bundle'] .' > '. $data['cert'][$suffix .'_crt']);
				$app->log('Merging ssl cert and bundle file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);

				$app->system->unlink("/tmp/ispconfig3_newline_fix");
			} else {
				$app->system->copy($data['cert']['crt'], $data['cert'][$suffix .'_crt']);
				$app->log('Copying ssl cert file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);
			}

			$app->system->copy($data['cert']['key'], $data['cert'][$suffix .'_key']);
			$app->log('Copying ssl key file: '. $data['cert'][$suffix .'_key'], LOGLEVEL_DEBUG);
		} else {
			$app->log('Creating '. $suffix .' ssl files failed', LOGLEVEL_DEBUG);
		}
	}

	/**
	 * Changes the ssl cert files.
	 *
	 * @uses cert_delete()
	 * @uses cert_insert()
	 *
	 * @param array $data the vhost data
	 * @param object $app ISPConfig app object
	 * @param string $suffix cert filename suffix
	 */
	private function cert_update($data, $app, $suffix) {
		global $app;

		$this->cert_delete($data, $app, $suffix);
		$this->cert_insert($data, $app, $suffix);
	}

	/**
	 * Removes the ssl cert files.
	 *
	 * @param array $data the vhost data
	 * @param object $app ISPConfig app object
	 * @param string $suffix cert filename suffix
	 */
	private function cert_delete($data, $app, $suffix) {
		global $app;

		$app->uses('system');

		if ($data['cert'][$suffix .'_crt_check'] == 1) {
			$app->system->unlink($data['cert']['nginx_crt']);
			$app->log('Removing ssl cert file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);
		}

		if ($data['cert'][$suffix .'_key_check'] == 1) {
			$app->system->unlink($data['cert'][$suffix .'_key']);
			$app->log('Removing ssl key file: '. $data['cert'][$suffix. '_key'], LOGLEVEL_DEBUG);
		}
	}

}