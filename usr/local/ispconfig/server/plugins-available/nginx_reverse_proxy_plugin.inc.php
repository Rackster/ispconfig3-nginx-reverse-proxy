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
		if($conf['services']['nginx_reverse_proxy'] == true) {
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
		if($this->action != 'insert') $this->action = 'update';


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
		$tpl->newTemplate('nginx_reverse_proxy.vhost.conf.master');


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
			 * Put the default non-SSL vhost into the loop array
			 */
			$vhosts[] = array(
				'ip_address' => $data['new']['ip_address'],
				'ssl_enabled' => 0,
				'port' => 80,
				'apache2_port' => 82
			);


			/*
			 * Check if the site is SSL enabled
			 */
			$crt_file = escapeshellcmd($data['new']['document_root'] .'/ssl/'. $data['new']['ssl_domain'] .'.crt');
			$key_file = escapeshellcmd($data['new']['document_root'] .'/ssl/'. $data['new']['ssl_domain'] .'.key');
			if ($data['new']['ssl_domain'] != '' && $data['new']['ssl'] == 'y' && is_file($crt_file) && is_file($key_file) && (filesize($crt_file) > 0) && (filesize($key_file) > 0)) {

				$vhost_data['web_document_root_ssl'] = $data['new']['document_root'] .'/ssl';


				/*
				 * add the SSL vhost to the loop
				 */
				$vhosts[] = array(
					'ip_address' => $data['new']['ip_address'],
					'ssl_enabled' => 1,
					'port' => 443,
					'apache2_port' => 82
				);

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
			$tpl->setVar('cp_base_url', 'https://cp.rackster.ch:8080');
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

				$this->vhost('update', $data, $tpl->grab());

			}

		}


		/*
		 * To have a better overview we split our update function into several parts,
		 * for sites, aliases and subdomains
		 * -> alias
		 */
		if($data['new']['type'] == 'alias') {}


		/*
		 * To have a better overview we split our update function into several parts,
		 * for sites, aliases and subdomains
		 * -> subdomain
		 */
		if($data['new']['type'] == 'subdomain') {}


		/*
		 * Everything done here, so let's restart nginx
		 */
		exec($final_command);


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
		 */
		$this->vhost('delete', $data);

	}


	/*
	 * The client_delete() function is called every time, a client gets deleted
	 */
	function client_delete($event_name, $data) {
		global $app, $conf;

		// delete all vhosts from client

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