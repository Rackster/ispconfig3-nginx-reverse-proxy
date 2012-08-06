<?php

class vhost {

	/*
	 * the insert function creates the vhost file and link
	 */
	function insert($data, $app, $tpl) {

		/*
		 * the vhost file doesn't exist so we have to create it
		 * and write the template content
		 */
		file_put_contents($data['vhost']['file_new'], $tpl);
		$data['vhost']['file_new_check'] = 1;
		$app->log('Creating vhost file: '. $data['vhost']['file_new'], LOGLEVEL_DEBUG);
		unset($tpl);

		if ($data['vhost']['link_new_check'] != 1) {

			/*
			 * the vhost link doesn't exist so we have to create it
			 */
			exec('ln -s '. $data['vhost']['file_new'] .' '. $data['vhost']['link_new']);
			$data['vhost']['link_new_check'] = 1;
			$app->log('Creating vhost symlink: '. $data['vhost']['link_new_check'], LOGLEVEL_DEBUG);

		}


		/*
		 * return the $data['vhost'] array
		 */
		return $data['vhost'];

	}


	/*
	 * the update function updates the vhost file and link
	 */
	function update($data, $app, $tpl) {

		/*
		 * check if the site is no longer active
		 */
		if ($data['new']['active'] == 'n') {

			/*
			 * it's not longer active, so we have to tell
			 * the delete function to NOT delete the vhost file
			 * and the insert function, to NOT create the vhost link
			 */
			$data['vhost']['link_new_check'] = 1;

		}


		/*
		 * create a backup of the vhost file
		 */
		exec('mv '. $data['vhost']['file_new'] .' '. $data['vhost']['file_new'] .'~');
		$data['vhost']['file_new_check'] = 0;


		/*
		 * The site was renamed, so we have to delete the old vhost and create the new
		 */
		$this->delete($data, $app);
		return $this->insert($data, $app, $tpl);

	}


	/*
	 * the delete function deletes the vhost file and link
	 */
	function delete($data, $app, $tpl = '') {

		if ($data['vhost']['file_old_check'] == 1) {

			/*
			 * the vhost file exists so we have to delete it
			 */
			unlink($data['vhost']['file_old']);
			$data['vhost']['file_old_check'] = 0;
			$app->log('Removing vhost file: '. $data['vhost']['file_old'], LOGLEVEL_DEBUG);

		}

		if ($data['vhost']['link_old_check'] == 1) {

			/*
			 * the vhost link exists so we have to delete it
			 */
			unlink($data['vhost']['link_old']);
			$data['vhost']['link_old_check'] = 0;
			$app->log('Removing vhost symlink: '. $data['vhost']['link_old'], LOGLEVEL_DEBUG);

		}

	}

}