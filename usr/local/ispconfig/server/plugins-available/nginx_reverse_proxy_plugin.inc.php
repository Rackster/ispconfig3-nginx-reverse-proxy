<?php

class nginx_reverse_proxy_plugin
{

	var $plugin_name = 'nginx_reverse_proxy_plugin';
	var $class_name = 'nginx_reverse_proxy_plugin';

	var $action = '';
	var $ssl_certificate_changed = false;


	/*/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	// # ISPCONFIG FUNCTIONS
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	/* -- ONLOAD - register the plugin for some site related events
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	function onLoad()
	{
		global $app;

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'ssl');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'ssl');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'ssl');

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'insert');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'update');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'delete');

		$app->plugins->registerEvent('client_delete', $this->plugin_name, 'client_delete');
	}


	/* -- SSL - called every time something in the ssl tab is done
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	function ssl($event_name, $data)
	{
		global $app, $conf;

		$app->uses('system');

		//* Only vhosts can have a ssl cert
		if($data["new"]["type"] != "vhost" && $data["new"]["type"] != "vhostsubdomain") return;

		if ($data['new']['ssl_action'] == 'del')
		{
			$this->cert_helper('delete', $data);
		}
		else
		{
			$this->cert_heper('update', $data);
		}
	}


	/* -- INSERT - called every time a new site is created
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	function insert($event_name, $data)
	{
		global $app, $conf;

		$this->action = 'insert';
		$this->update($event_name, $data);
	}


	/* -- UPDATE - called every time a site gets updated from within ISPConfig
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	function update($event_name, $data)
	{
		global $app, $conf;

		//* $VAR: command to run after vhost insert/update/delete
		$final_command = '/etc/init.d/nginx restart && rm -rf /var/cache/nginx/*';

		if ($this->action != 'insert') $this->action = 'update';

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		$app->load('tpl');

		$tpl = new tpl();
		$tpl->newTemplate('nginx_reverse_proxy_plugin.vhost.conf.master');

		$vhost_data = $data['new'];
		$vhost_data['web_document_root'] = $data['new']['document_root'].'/web';
		$vhost_data['web_document_root_www'] = $web_config['website_basedir'].'/'.$data['new']['domain'].'/web';
		$vhost_data['web_basedir'] = $web_config['website_basedir'];
		$vhost_data['ssl_domain'] = $data['new']['ssl_domain'];

		/* __ VHOST & VHOSTSUBDOMAIN - section for vhosts and vhostsubdomains ///////////////////////////////////////*/
		if ($data['new']['type'] == 'vhost' || $data['new']['type'] == 'vhostsubdomain')
		{
			if ($data['new']['ipv6_address'] != '') $tpl->setVar('ipv6_enabled', 1);

			$server_alias = array();
			switch($data['new']['subdomain'])
			{
				case 'www':
					$server_alias[] .= 'www.'. $data['new']['domain'] .' ';
					break;
				case '*':
					$server_alias[] .= '*.'. $data['new']['domain'] .' ';
					break;
			}
			$alias_result = array();
			$alias_result = $app->dbmaster->queryAllRecords('SELECT * FROM web_domain WHERE parent_domain_id = '.$data['new']['domain_id']." AND active = 'y' AND type != 'vhostsubdomain'");
			if (count($alias_result) > 0)
			{
				foreach($alias_result as $alias)
				{
					switch($alias['subdomain'])
					{
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
			if (count($server_alias) > 0)
			{
				$server_alias_str = '';

				foreach($server_alias as $tmp_alias)
				{
					$server_alias_str .= $tmp_alias;
				}

				unset($tmp_alias);
				$tpl->setVar('alias', $server_alias_str);
			}
			else
			{
				$tpl->setVar('alias', '');
			}

			if (!isset($rewrite_rules)) $rewrite_rules = array();
			if ($data['new']['redirect_type'] != '' && $data['new']['redirect_path'] != '')
			{
				if (substr($data['new']['redirect_path'], -1) != '/') $data['new']['redirect_path'] .= '/';
				if (substr($data['new']['redirect_path'], 0, 8) == '[scheme]')
				{
					$rewrite_target = 'http'.substr($data['new']['redirect_path'], 8);
					$rewrite_target_ssl = 'https'.substr($data['new']['redirect_path'], 8);
				}
				else
				{
					$rewrite_target = $data['new']['redirect_path'];
					$rewrite_target_ssl = $data['new']['redirect_path'];
				}

				if (substr($data['new']['redirect_path'], 0, 4) == 'http')
				{
					$data['new']['redirect_type'] = 'permanent';
				}
				else
				{
					switch($data['new']['redirect_type'])
					{
						case 'no':
							$data['new']['redirect_type'] = 'break';
							break;
						case 'L':
							$data['new']['redirect_type'] = 'break';
							break;
						default:
							$data['new']['redirect_type'] = 'permanent';
							break;
					}
				}

				switch($data['new']['subdomain'])
				{
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

			if ($data['new']['seo_redirect'] != '' && ($data['new']['subdomain'] == 'www' || $data['new']['subdomain'] == '*'))
			{
				$vhost_data['seo_redirect_enabled'] = 1;

				if ($data['new']['seo_redirect'] == 'non_www_to_www')
				{
					$vhost_data['seo_redirect_origin_domain'] = $data['new']['domain'];
					$vhost_data['seo_redirect_target_domain'] = 'www.'. $data['new']['domain'];
				}

				if ($data['new']['seo_redirect'] == 'www_to_non_www')
				{
					$vhost_data['seo_redirect_origin_domain'] = 'www.'. $data['new']['domain'];
					$vhost_data['seo_redirect_target_domain'] = $data['new']['domain'];
				}
			}
			else
			{
				$vhost_data['seo_redirect_enabled'] = 0;
			}

			$nginx_directives = $data['new']['nginx_directives'];

			$errordocs = !$data['new']['errordocs'];

			$nginx_directives = str_replace("\r\n", "\n", $nginx_directives);
			$nginx_directives = str_replace("\r", "\n", $nginx_directives);
			//$nginx_directive_lines = explode("\n", $nginx_directives);

			$crt_file = escapeshellcmd($data['new']['document_root'] .'/ssl/'. $data['new']['ssl_domain'] .'.crt');
			$key_file = escapeshellcmd($data['new']['document_root'] .'/ssl/'. $data['new']['ssl_domain'] .'.key');
			if ($data['new']['ssl_domain'] != '' && $data['new']['ssl'] == 'y' && is_file($crt_file) && is_file($key_file) && (filesize($crt_file) > 0) && (filesize($key_file) > 0))
			{
				$http_to_https = 1;
			}
			else
			{
				$http_to_https = 0;
			}

			if (count($rewrite_rules) > 0)
			{
				$vhosts[] = array(
					'ip_address' => $data['new']['ip_address'],
					'ipv6_address' => $data['new']['ipv6_address'],
					'ssl_enabled' => 0,
					'http_to_https' => $http_to_https,
					'rewrite_enabled' => 1,
					'redirects' => $rewrite_rules,
					'nginx_directives' => $nginx_directives,
					'errordocs' => $errordocs,
					'port' => 80,
					'apache2_port' => 82
				);
			}
			else
			{
				$vhosts[] = array(
					'ip_address' => $data['new']['ip_address'],
					'ipv6_address' => $data['new']['ipv6_address'],
					'ssl_enabled' => 0,
					'http_to_https' => $http_to_https,
					'rewrite_enabled' => 0,
					'redirects' => '',
					'nginx_directives' => $nginx_directives,
					'errordocs' => $errordocs,
					'port' => 80,
					'apache2_port' => 82
				);
			}

			if ($http_to_https == 1)
			{
				$vhost_data['web_document_root_ssl'] = $data['new']['document_root'] .'/ssl';

				if (count($rewrite_rules) > 0)
				{
					$vhosts[] = array(
						'ip_address' => $data['new']['ip_address'],
						'ipv6_address' => $data['new']['ipv6_address'],
						'ssl_enabled' => 1,
						'http_to_https' => 0,
						'rewrite_enabled' => 1,
						'redirects' => $rewrite_rules,
						'nginx_directives' => $nginx_directives,
						'errordocs' => $errordocs,
						'port' => 443,
						'apache2_port' => 82
					);
				}
				else
				{
					$vhosts[] = array(
						'ip_address' => $data['new']['ip_address'],
						'ipv6_address' => $data['new']['ipv6_address'],
						'ssl_enabled' => 1,
						'http_to_https' => 0,
						'rewrite_enabled' => 0,
						'redirects' => '',
						'nginx_directives' => $nginx_directives,
						'errordocs' => $errordocs,
						'port' => 443,
						'apache2_port' => 82
					);
				}
			}

			$tpl->setLoop('vhosts', $vhosts);

			//* $VAR: ISPConfig CP URL
			$tpl->setVar('cp_base_url', 'https://cp.rackster.ch:8081');
			$tpl->setVar($vhost_data);

			if ($this->action == 'insert')
			{
				$this->vhost_helper('insert', $data, $tpl->grab());
			}

			if ($this->action == 'update')
			{
				$vhost_backup = $this->vhost_helper('update', $data, $tpl->grab());
			}
		}


		/* __ ALIAS - section for aliasdomains //////////////////////////////////////////////////////////////////////*/
		if ($data['new']['type'] == 'alias')
		{
			$parent_domain = $app->dbmaster->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '. intval($data['new']['parent_domain_id']) .'');

			$parent_domain['parent_domain_id'] = $data['new']['parent_domain_id'];
			$data['old'] = $parent_domain;
			$data['new'] = $parent_domain;

			$this->update($event_name, $data);
		}


		/* __ SUBDOMAIN - section for classic subdomains ////////////////////////////////////////////////////////////*/
		if ($data['new']['type'] == 'subdomain')
		{
			$parent_domain = $app->dbmaster->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '. intval($data['new']['parent_domain_id']) .'');

			$parent_domain['parent_domain_id'] = $data['new']['parent_domain_id'];
			$data['old'] = $parent_domain;
			$data['new'] = $parent_domain;

			$this->update($event_name, $data);
		}

		exec($final_command);

		if (isset($vhost_backup)) unlink($vhost_backup['file_new'] .'~');
		unset($vhost_backup);

		$this->action = '';
	}


	/* -- DELETE - called every time, a site get's removed
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	function delete($event_name, $data)
	{
		global $app, $conf;

		$this->action = 'delete';

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		if ($data['old']['type'] == 'vhost' || $data['old']['type'] == 'vhostsubdomain') $this->vhost_helper('delete', $data);

		if ($data['old']['type'] == 'alias')
		{
			$data['new']['type'] == 'alias';
			$this->update($event_name, $data);
		}

		if ($data['old']['type'] == 'subdomain')
		{
			$data['new']['type'] == 'subdomain';
			$this->update($event_name, $data);
		}
	}


	/* -- CLIENT_DELETE - called every time, a client gets deleted
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	function client_delete($event_name, $data)
	{
		global $app, $conf;

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		$client_id = intval($data['old']['client_id']);
		$client_vhosts = array();
		$client_vhosts = $app->dbmaster->queryAllRecords('SELECT domain FROM web_domain WHERE sys_userid = '. $client_id .' AND parent_domain_id = 0');

		if (count($client_vhosts) > 0)
		{
			foreach($client_vhosts as $vhost)
			{
				$data['old']['domain'] = $vhost['domain'];
				$this->vhost_helper('delete', $data);

				$app->log('Removing vhost file: '. $data['old']['domain'], LOGLEVEL_DEBUG);
			}
		}
	}


	/* -- _EXEC - function for easier debugging
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function _exec($command)
	{
		global $app;

		$app->log('exec: '. $command, LOGLEVEL_DEBUG);
		exec($command);
	}


	/*/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	// # VHOST FUNCTIONS
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	/* -- VHOST_HELPER - handler for the other vhost functions
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function vhost_helper($action, $data, $tpl = '')
	{
		global $app;

		//* $VAR: location of nginx vhost dirs
		$nginx_vhosts = '/etc/nginx/sites-available';
		$nginx_vhosts_enabled = '/etc/nginx/sites-enabled';

		$data['vhost'] = array();

		$data['vhost']['file_old'] = escapeshellcmd($nginx_vhosts .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['link_old'] = escapeshellcmd($nginx_vhosts_enabled .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['file_new'] = escapeshellcmd($nginx_vhosts .'/'. $data['new']['domain'] .'.vhost');
		$data['vhost']['link_new'] = escapeshellcmd($nginx_vhosts_enabled .'/'. $data['new']['domain'] .'.vhost');

		if (is_file($data['vhost']['file_old'])) $data['vhost']['file_old_check'] = 1;
		if (is_file($data['vhost']['file_new'])) $data['vhost']['file_new_check'] = 1;

		if (is_link($data['vhost']['link_old'])) $data['vhost']['link_old_check'] = 1;
		if (is_link($data['vhost']['link_new'])) $data['vhost']['link_new_check'] = 1;

		$method = "vhost_$action";
		return $data['vhost'] = $this->$method($data, $app, $tpl);
	}


	/* -- VHOST_INSERT - creates the vhost file and link
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function vhost_insert($data, $app, $tpl)
	{
		file_put_contents($data['vhost']['file_new'], $tpl);
		$data['vhost']['file_new_check'] = 1;
		$app->log('Creating vhost file: '. $data['vhost']['file_new'], LOGLEVEL_DEBUG);
		unset($tpl);

		if ($data['vhost']['link_new_check'] != 1)
		{
			exec('ln -s '. $data['vhost']['file_new'] .' '. $data['vhost']['link_new']);
			$data['vhost']['link_new_check'] = 1;
			$app->log('Creating vhost symlink: '. $data['vhost']['link_new_check'], LOGLEVEL_DEBUG);
		}

		return $data['vhost'];
	}


	/* -- VHOST_UPDATE - updates the vhost file and link
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function vhost_update($data, $app, $tpl)
	{
		$data['vhost']['link_new_check'] = 0;

		if ($data['new']['active'] == 'n')
		{
			$data['vhost']['link_new_check'] = 1;
		}

		exec('mv '. $data['vhost']['file_new'] .' '. $data['vhost']['file_new'] .'~');
		$data['vhost']['file_new_check'] = 0;
		$data['vhost']['file_old_check'] = 0;

		$this->vhost_delete($data, $app);
		return $this->vhost_insert($data, $app, $tpl);
	}


	/* -- VHOST_DELETE - deletes the vhost file and link
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function vhost_delete($data, $app, $tpl = '')
	{
		if ($data['vhost']['file_old_check'] == 1)
		{
			unlink($data['vhost']['file_old']);
			$data['vhost']['file_old_check'] = 0;
			$app->log('Removing vhost file: '. $data['vhost']['file_old'], LOGLEVEL_DEBUG);
		}

		if ($data['vhost']['link_old_check'] == 1)
		{
			unlink($data['vhost']['link_old']);
			$data['vhost']['link_old_check'] = 0;
			$app->log('Removing vhost symlink: '. $data['vhost']['link_old'], LOGLEVEL_DEBUG);
		}
	}


	/*/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	// # CERT FUNCTIONS
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	/* -- CERT_HELPER - handler for the other cert functions
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function cert_helper($action, $data)
	{
		global $app;

		$data['cert'] = array();
		$suffix = 'nginx';
		$ssl_dir = $data['new']['document_root'] .'/ssl';

		$data['cert']['crt'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.crt');
		$data['cert']['key'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.key');
		$data['cert']['bundle'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.bundle');
		$data['cert'][$suffix .'_crt'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.'. $suffix .'.crt');
		$data['cert'][$suffix .'_key'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.'. $suffix .'.key');

		if (is_file($data['cert']['crt'])) $data['cert']['crt_check'] = 1;
		if (is_file($data['cert'][$suffix .'_crt'])) $data['cert'][$suffix .'_crt_check'] = 1;

		if (is_file($data['cert']['key'])) $data['cert']['key_check'] = 1;
		if (is_file($data['cert'][$suffix .'_key'])) $data['cert'][$suffix .'_key_check'] = 1;
		if (is_file($data['cert']['bundle'])) $data['cert']['bundle_check'] = 1;

		$method = "cert_$action";
		return $data['cert'] = $this->$method($data, $app, $suffix);
	}


	/* -- CERT_INSERT - creates the ssl cert files
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function cert_insert($data, $app, $suffix)
	{
		if ($data['cert']['crt_check'] == 1 && $data['cert']['key_check'] == 1)
		{
			if ($data['cert']['bundle_check'] == 1)
			{
				exec('echo "" > /tmp/ispconfig3_newline_fix');

				exec('cat '. $data['cert']['crt'] .' /tmp/ispconfig3_newline_fix '. $data['cert']['bundle'] .' > '. $data['cert'][$suffix .'_crt']);
				$app->log('Merging ssl cert and bundle file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);

				exec('rm /tmp/ispconfig3_newline_fix');
			}
			else
			{
				exec('cp '. $data['cert']['crt'] .' '. $data['cert'][$suffix .'_crt']);
				$app->log('Copying ssl cert file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);
			}

			exec('cp '. $data['cert']['key'] .' '. $data['cert'][$suffix .'_key']);
			$app->log('Copying ssl key file: '. $data['cert'][$suffix .'_key'], LOGLEVEL_DEBUG);
		}
		else
		{
			$app->log('Creating '. $suffix .' ssl files failed', LOGLEVEL_DEBUG);
		}
	}


	/* -- CERT_UPDATE - changes the ssl cert files
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function cert_update($data, $app, $suffix)
	{
		$this->cert_delete($data, $app, $suffix);
		$this->cert_insert($data, $app, $suffix);
	}


	/* -- CERT_DELETE - removes the ssl cert files
	/////////////////////////////////////////////////////////////////////////////////////////////////////////////////*/

	private function cert_delete($data, $app, $suffix)
	{
		if ($data['cert'][$suffix .'_crt_check'] == 1)
		{
			unlink($data['cert']['nginx_crt']);
			$app->log('Removing ssl cert file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);
		}

		if ($data['cert'][$suffix .'_key_check'] == 1)
		{
			unlink($data['cert'][$suffix .'_key']);
			$app->log('Removing ssl key file: '. $data['cert'][$suffix. '_key'], LOGLEVEL_DEBUG);
		}
	}

}