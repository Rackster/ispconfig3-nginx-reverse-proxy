<?php

class nginx_reverse_proxy_plugin {

	var $plugin_name = 'nginx_reverse_proxy_plugin';
	var $class_name = 'nginx_reverse_proxy_plugin';


	/*
	 * private variables
	 */
	var $action = '';


	/*
	 * some nice functions which do things we would have to repeat
	 * if we couldn't call them. they do not load themselve but are
	 * called by other functions like the update() or delete() function
	 */

	/*
	 * the vhost handles the whole creating, updating and deleting
	 * of the nginx vhost files
	 */
	function vhost($action, $data, $tpl = '') {
		global $app;

		/*
		 * we create an empty array we can fill with data
		 * and shorten some $vars
		 */
		$nginx_vhosts = '/etc/nginx/sites-available';
		$nginx_vhosts_enabled = '/etc/nginx/sites-enabled';

		$data['vhost'] = array();


		/*
		 * Create the vhost paths
		 */
		$data['vhost']['file_old'] = escapeshellcmd($nginx_vhosts .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['link_old'] = escapeshellcmd($nginx_vhosts_enabled .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['file_new'] = escapeshellcmd($nginx_vhosts .'/'. $data['new']['domain'] .'.vhost');
		$data['vhost']['link_new'] = escapeshellcmd($nginx_vhosts_enabled .'/'. $data['new']['domain'] .'.vhost');


		/*
		 * check if the vhost file exists in "/etc/nginx/sites-available"
		 * (or the path you defined) and set to '1' if it does
		 */
		if (is_file($data['vhost']['file_old'])) $data['vhost']['file_old_check'] = 1;
		if (is_file($data['vhost']['file_new'])) $data['vhost']['file_new_check'] = 1;


		/*
		 * check if the vhost file is linked in "/etc/nginx/sites-enabled"
		 * (or the path you defined) and set to '1' if it does
		 */
		if (is_link($data['vhost']['link_old'])) $data['vhost']['link_old_check'] = 1;
		if (is_link($data['vhost']['link_new'])) $data['vhost']['link_new_check'] = 1;


		/*
		 * require the vhost class and run the function
		 */
		require_once 'classes/vhost.php';
		$vhost = new vhost;

		return $data['vhost'] = $vhost->$action($data, $app, $tpl);

	}


	/*
	 * the cert function handles the creating, updating and deleting
	 * of ssl certs for nginx
	 */
	function cert($action, $data) {
		global $app;

		/*
		 * we create an empty array we can fill with data
		 * and shorten some $vars
		 */
		$data['cert'] = array();
		$suffix = 'nginx';
		$ssl_dir = $data['new']['document_root'] .'/ssl';

		/*
		 * Create the default apache2 ssl cert paths
		 */
		$data['cert']['crt'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.crt');
		$data['cert']['key'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.key');
		$data['cert']['bundle'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.bundle');
		$data['cert'][$suffix .'_crt'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.'. $suffix .'.crt');
		$data['cert'][$suffix .'_key'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.'. $suffix .'.key');


		/*
		 * check if the ssl cert exists in "/var/www/domain.tld/ssl"
		 * (or the path you defined) and set to '1' if it does
		 */
		if (is_file($data['cert']['crt'])) $data['cert']['crt_check'] = 1;
		if (is_file($data['cert'][$suffix .'_crt'])) $data['cert'][$suffix .'_crt_check'] = 1;


		/*
		 * check if the ssl key exists in "/var/www/domain.tld/ssl"
		 * (or the path you defined) and set to '1' if it does
		 */
		if (is_file($data['cert']['key'])) $data['cert']['key_check'] = 1;
		if (is_file($data['cert'][$suffix .'_key'])) $data['cert'][$suffix .'_key_check'] = 1;


		/*
		 * check if the bundle file exists in "/var/www/domain.tld/ssl"
		 * (or the path you defined) and set to '1' if it does
		 */
		if (is_file($data['cert']['bundle'])) $data['cert']['bundle_check'] = 1;


		/*
		 * require the vhost class and run the function
		 */
		require_once 'classes/cert.php';
		$cert = new cert;

		return $data['cert'] = $cert->$action($data, $app, $suffix);

	}


	/*
	 * The onInstall() function is called during ISPConfig installation.
	 * Based on your input/selection, it decides if a symlink for the plugin
	 * has to be created in /usr/local/ispconfig/server/plugins-enabled
	 *
	 */
	function onInstall() {
		global $conf;

		/*
		 * The check ifself
		 */
		if ($conf['services']['nginx_reverse_proxy_plugin'] == true) {
			return true;
		} else {
			return false;
		}

	}


	/*
	 * The onLoad() function is loaded as soon as our plugin get's loaded
	 * We use it, to register the plugin for some site related events
	 */
	function onLoad() {
		global $app;

		/*
		 * Register for those events, the plugin needs to do something
		 * this fills the $event_name var from the functions
		 */
		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'ssl');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'ssl');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'ssl');

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'insert');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'update');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'delete');

		$app->plugins->registerEvent('client_delete', $this->plugin_name, 'client_delete');

	}


	/*
	 * The ssl function is called every time something in the ssl tab is done
	 */
	function ssl($event_name, $data) {
		global $app, $conf;

		/*
		 * check if we have to delete the ssl files
		 */
		if ($data['new']['ssl_action'] == 'del') {

			$this->cert('delete', $data);

		} else {

			$this->cert('update', $data);

		}

	}


	/*
	 * The insert function is called every time a new site is created
	 */
	function insert($event_name, $data) {
		global $app, $conf;

		/*
		 * Set $action to 'insert' so the plugin knows it should run
		 * code which is defined for the insert function
		 */
		$this->action = 'insert';


		/*
		 * We make it simple and only run the update() function
		 */
		$this->update($event_name, $data);

	}


	/*
	 * The update function is called every time a site gets updated from within ISPConfig
	 * (only on the events we registered above) as well as on creating a new site
	 * (see insert function)
	 */
	function update($event_name, $data) {
		global $app, $conf;

		/*
		 * some $vars we will use within the function
		 */
		$final_command = '/etc/init.d/nginx restart && rm -rf /var/cache/nginx/*';


		/*
		 * If $action is not 'insert', let's set it to update
		 */
		if ($this->action != 'insert') $this->action = 'update';


		/*
		 * load the server configuration options
		 */
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');


		/*
		 * We load the global template engine
		 */
		$app->load('tpl');


		/*
		 * Create a new template and choose which master template to take
		 * the file is located within /usr/local/ispconfig/server/conf/
		 */
		$tpl = new tpl();
		$tpl->newTemplate('nginx_reverse_proxy_plugin.vhost.conf.master');


		/*
		 * Write some values from the array to single variables
		 */
		$vhost_data = $data['new'];
		$vhost_data['web_document_root'] = $data['new']['document_root'].'/web';
		$vhost_data['web_document_root_www'] = $web_config['website_basedir'].'/'.$data['new']['domain'].'/web';
		$vhost_data['web_basedir'] = $web_config['website_basedir'];
		$vhost_data['ssl_domain'] = $data['new']['ssl_domain'];


		/*
		 * To have a better overview we split our update function into several parts,
		 * for sites, aliases and subdomains
		 * -> vhost
		 */
		if ($data['new']['type'] == 'vhost') {

			/*
			 * Enable IPv6 support if we have an IP there
			 */
			if ($data['new']['ipv6_address'] != '') $tpl->setVar('ipv6_enabled', 1);


			/*
			 * Auto-Subdomain handling
			 */
			$server_alias = array();

			switch($data['new']['subdomain']) {

				case 'www':
					$server_alias[] .= 'www.'. $data['new']['domain'] .' ';
					break;

				case '*':
					$server_alias[] .= '*.'. $data['new']['domain'] .' ';
					break;

			}


			/*
			 * Check the DB if there are other alias domains
			 * and save to $alias_domains
			 */
			$alias_result = array();

			$alias_result = $app->dbmaster->queryAllRecords('SELECT domain, subdomain FROM web_domain WHERE parent_domain_id = '. $data['new']['parent_domain_id'] .' AND parent_domain_id > 0 AND active = "y"');

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


			/*
			 * Check if the above function and checks 'returned' an alias domain
			 * so we know if we have to pass something to the 'vhost' master
			 */
			if (count($server_alias) > 0) {
				$server_alias_str = '';

				foreach($server_alias as $tmp_alias) {
					$server_alias_str .= $tmp_alias;
				}

				unset($tmp_alias);

				//$tpl->setVar('alias', trim($server_alias_str));
				$tpl->setVar('alias', $server_alias_str);

			} else {

				$tpl->setVar('alias', '');

			}


			/*
			 * Rewrite rule support for main domain (site)
			 */
			if (!isset($rewrite_rules)) $rewrite_rules = array();

			if ($data['new']['redirect_type'] != '' && $data['new']['redirect_path'] != '') {

				if (substr($data['new']['redirect_path'], -1) != '/') $data['new']['redirect_path'] .= '/';
				if (substr($data['new']['redirect_path'], 0, 8) == '[scheme]') {

					$rewrite_target = 'http'.substr($data['new']['redirect_path'], 8);
					$rewrite_target_ssl = 'https'.substr($data['new']['redirect_path'], 8);

				} else {

					$rewrite_target = $data['new']['redirect_path'];
					$rewrite_target_ssl = $data['new']['redirect_path'];

				}


				/*
				 * We have to check where the redirect points
				 * e.g. if it is to an external URL
				 */
				if (substr($data['new']['redirect_path'], 0, 4) == 'http') {

					/*
					 * Always redirect permanent (e.g. R=301,L)
					 */
					$data['new']['redirect_type'] = 'permanent';

				} else {

					/*
					 * We need to prepare the rewrite types since nginx
					 * uses other ones than apache2
					 */
					switch($data['new']['redirect_type']) {

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


				/*
				 * Now we are ready to put them into an array
				 */
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


			/*
			 * Check if SEO redirect is enabled
			 */
			if ($data['new']['seo_redirect'] != '' && ($data['new']['subdomain'] == 'www' || $data['new']['subdomain'] == '*')) {

				$vhost_data['seo_redirect_enabled'] = 1;


				/*
				 * non-www to www redirect
				 */
				if ($data['new']['seo_redirect'] == 'non_www_to_www') {

					$vhost_data['seo_redirect_origin_domain'] = $data['new']['domain'];
					$vhost_data['seo_redirect_target_domain'] = 'www.'. $data['new']['domain'];

				}


				/*
				 * www to non-www redirect
				 */
				if ($data['new']['seo_redirect'] == 'www_to_non_www') {

					$vhost_data['seo_redirect_origin_domain'] = 'www.'. $data['new']['domain'];
					$vhost_data['seo_redirect_target_domain'] = $data['new']['domain'];

				}

			} else {

				$vhost_data['seo_redirect_enabled'] = 0;

			}


			/*
			 * Custom nginx directives from the
			 * ISPConfig field
			 */
			$final_nginx_directives = array();
			$nginx_directives = $data['new']['nginx_directives'];


			/*
			 *
			 */
			$errordocs = $data['new']['errordocs'];


			/*
			 * Make sure we only have UNIX linebreaks
			 */
			$nginx_directives = str_replace("\r\n", "\n", $nginx_directives);
			$nginx_directives = str_replace("\r", "\n", $nginx_directives);
			$nginx_directive_lines = explode("\n", $nginx_directives);

			if (is_array($nginx_directive_lines) && !empty($nginx_directive_lines)) {

				foreach($nginx_directive_lines as $nginx_directive_line) {

					$final_nginx_directives[] = array('nginx_directive' => $nginx_directive_line);

				}

			}

			$tpl->setLoop('nginx_directives', $final_nginx_directives);

			/*
			 * Check if the site is SSL enabled
			 */
			$crt_file = escapeshellcmd($data['new']['document_root'] .'/ssl/'. $data['new']['ssl_domain'] .'.crt');
			$key_file = escapeshellcmd($data['new']['document_root'] .'/ssl/'. $data['new']['ssl_domain'] .'.key');
			if ($data['new']['ssl_domain'] != '' && $data['new']['ssl'] == 'y' && is_file($crt_file) && is_file($key_file) && (filesize($crt_file) > 0) && (filesize($key_file) > 0)) {

				$http_to_https = 1;

			} else {

				$http_to_https = 0;

			}


			/*
			 * Put the default non-SSL vhost into the loop array
			 */
			if (count($rewrite_rules) > 0) {

				$vhosts[] = array(
					'ip_address' => $data['new']['ip_address'],
					'ipv6_address' => $data['new']['ipv6_address'],
					'ssl_enabled' => 0,
					'http_to_https' => $http_to_https,
					'rewrite_enabled' => 1,
					'redirects' => $rewrite_rules,
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
					'rewrite_enabled' => 0,
					'redirects' => '',
					'errordocs' => $errordocs,
					'port' => 80,
					'apache2_port' => 82
				);

			}


			/*
			 * Check if the site is SSL enabled
			 */
			if ($http_to_https == 1) {

				$vhost_data['web_document_root_ssl'] = $data['new']['document_root'] .'/ssl';


				/*
				 * add the SSL vhost to the loop
				 */
				if (count($rewrite_rules) > 0) {

					$vhosts[] = array(
						'ip_address' => $data['new']['ip_address'],
						'ipv6_address' => $data['new']['ipv6_address'],
						'ssl_enabled' => 1,
						'http_to_https' => 0,
						'rewrite_enabled' => 1,
						'redirects' => $rewrite_rules,
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
						'redirects' => '',
						'errordocs' => $errordocs,
						'port' => 443,
						'apache2_port' => 82
					);

				}

			}


			/*
			 * Our vhost loop should now be ready,
			 * so we set it
			 */
			$tpl->setLoop('vhosts', $vhosts);


			/*
			 * We have collected all data in the $vhost_data array
			 * so we can pass it to the template engine
			 */
			$tpl->setVar('cp_base_url', 'https://cp.rackster.ch');
			$tpl->setVar($vhost_data);


			/*
			 * if this is an 'insert', we have to create the vhost file
			 */
			if ($this->action == 'insert') {

				$this->vhost('insert', $data, $tpl->grab());

			}


			/*
			 * if this is an 'update', we have to update the vhost file
			 */
			if ($this->action == 'update') {

				$vhost_backup = $this->vhost('update', $data, $tpl->grab());

			}

		}


		/*
		 * To have a better overview we split our update function into several parts,
		 * for sites, aliases and subdomains
		 * -> alias
		 */
		if ($data['new']['type'] == 'alias') {

			/*
			 * We will run the update function based on the parent_domain so we
			 * first have to get it
			 */
			$parent_domain = $app->dbmaster->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '. intval($data['new']['parent_domain_id']) .'');


			/*
			 * Set data to $parent_domain but override the parent_domain_id
			 */
			$parent_domain['parent_domain_id'] = $data['new']['parent_domain_id'];
			$data['old'] = $parent_domain;
			$data['new'] = $parent_domain;

			$this->update($event_name, $data);

		}


		/*
		 * To have a better overview we split our update function into several parts,
		 * for sites, aliases and subdomains
		 * -> subdomain
		 */
		if ($data['new']['type'] == 'subdomain') {

			/*
			 * We will run the update function based on the parent_domain so we
			 * first have to get it
			 */
			$parent_domain = $app->dbmaster->queryOneRecord('SELECT * FROM web_domain WHERE domain_id = '. intval($data['new']['parent_domain_id']) .'');


			/*
			 * Set data to $parent_domain but override the parent_domain_id
			 */
			$parent_domain['parent_domain_id'] = $data['new']['parent_domain_id'];
			$data['old'] = $parent_domain;
			$data['new'] = $parent_domain;

			$this->update($event_name, $data);

		}


		/*
		 * Everything done here, so let's restart nginx
		 */
		exec($final_command);


		/*
		 * everything went hopefully well, so we can now
		 * delete the vhosts backup
		 */
		if (isset($vhost_backup)) unlink($vhost_backup['file_new'] .'~');
		unset($vhost_backup);


		/*
		 * Unset 'action' to clean it for next processed vhost
		 */
		$this->action = '';

	}


	/*
	 * The delete() function is called every time, a site get's removed
	 */
	function delete($event_name, $data) {
		global $app, $conf;

		/*
		 * Set $action to 'delete' so the plugin knows it should run
		 * code which is defined for the delete function
		 */
		$this->action = 'delete';


		/*
		 * load the server configuration options
		 */
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');


		/*
		 * We just have to delete the vhost file and link
		 * if we deleted a vhost site
		 */
		if ($data['old']['type'] == 'vhost') $this->vhost('delete', $data);


		/*
		 * Check if we deleted an aliasdomain
		 */
		if ($data['old']['type'] == 'alias') {

			/*
			 * Set $data['new']['type'] to 'alias' so we get into
			 * update()->alias->update()->vhost
			 */
			$data['new']['type'] == 'alias';
			$this->update($event_name, $data);

		}


		/*
		 * Check if we deleted a subdomain
		 */
		if ($data['old']['type'] == 'subdomain') {

			/*
			 * Set $data['new']['type'] to 'subdomain' so we get into
			 * update()->subdomain->update()->vhost
			 */
			$data['new']['type'] == 'subdomain';
			$this->update($event_name, $data);

		}

	}


	/*
	 * The client_delete() function is called every time, a client gets deleted
	 */
	function client_delete($event_name, $data) {
		global $app, $conf;

		/*
		 * load the server configuration options
		 */
		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');


		/*
		 * we run a query to get all domains (not alias- subdomains) which are linked
		 * with the client we want to delete
		 */
		$client_id = intval($data['old']['client_id']);

		$client_vhosts = array();

		$client_vhosts = $app->dbmaster->queryAllRecords('SELECT domain FROM web_domain WHERE sys_userid = '. $client_id .' AND parent_domain_id = 0');

		if (count($client_vhosts) > 0) {

			/*
			 * for every single vhost file the client has,
			 * call the delete function to delete the vhost file and link
			 */
			foreach($client_vhosts as $vhost) {

				$data['old']['domain'] = $vhost['domain'];
				$this->vhost('delete', $data);

				$app->log('Removing vhost file: '. $data['old']['domain'], LOGLEVEL_DEBUG);

			}

		}

	}


	/*
	 * Wrapper for exec function for easier debugging
	 */
	private function _exec($command) {
		global $app;

		$app->log('exec: '. $command, LOGLEVEL_DEBUG);
		exec($command);

	}

}