<?php

class cert {

	/*
	 * the insert function creates the ssl cert files
	 */
	function insert($data, $app, $suffix) {

		/*
		 * we can only proceed if openssl did create
		 * the crt and key file
		 */
		if ($data['cert']['crt'] == 1 && $data['cert']['key'] == 1) {

			/*
			 * create the bundled cert file if we have a bundle
			 */
			if ($data['cert']['bundle_check'] == 1) {

				/*
				 * create an empty file to ensure newline between the .crt and .bundle
				 */
				exec('echo "" > /tmp/ispconfig3_newline_fix');

				/*
				 * merge the .crt and .bundle files
				 */
				exec('cat '. $data['cert']['crt'] .' '. $data['cert']['bundle'] .' > '. $data['cert'][$suffix .'_crt']);
				$app->log('Merging ssl cert and bundle file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);

				/*
				 * remove the file we created to fix the newline
				 */
				exec('rm /tmp/ispconfig3_newline_fix');

			} else {

				/*
				 * copy the secrect .crt file
				 */
				exec('cp '. $data['cert']['crt'] .' '. $data['cert'][$suffix .'_crt']);
				$app->log('Copying ssl cert file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);

			}


			/*
			 * copy the secrect .key file
			 */
			exec('cp '. $data['cert']['key'] .' '. $data['cert'][$suffix .'_key']);
			$app->log('Copying ssl key file: '. $data['cert'][$suffix .'_key'], LOGLEVEL_DEBUG);

		} else {

			/*
			 * Report an error
			 */
			$app->log('Creating '. $suffix .' ssl files failed', LOGLEVEL_DEBUG);

		}

	}


	/*
	 * the update function changes the ssl cert files
	 */
	function update($data, $app, $suffix) {

		/*
		 * We make it really simple and remove all files
		 * so we can re-'create' them
		 */
		$this->delete($data, $app, $suffix);
		$this->insert($data, $app, $suffix);

	}


	/*
	 * the delete function removes the ssl cert files
	 */
	function delete($data, $app, $suffix) {

		/*
		 * check if the crt file exists and remove if it does
		 */
		if ($data['cert'][$suffix .'_crt_check'] == 1) {

			unlink($data['cert']['nginx_crt']);
			$app->log('Removing ssl cert file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);

		}


		/*
		 * check if the key file exists and remove if it does
		 */
		if ($data['cert'][$suffix .'_key_check'] == 1) {

			unlink($data['cert'][$suffix .'_key']);
			$app->log('Removing ssl key file: '. $data['cert'][$suffix. '_key'], LOGLEVEL_DEBUG);

		}

	}

}