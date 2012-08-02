<?php

class cert {

	/*
	 * the insert function creates the ssl cert files
	 */
	function insert($data, $app, $suffix) {

		/*
		 * create the bundled cert file
		 */
		exec('cat '. $data['cert']['crt'] .' '. $data['cert']['bundle'] .' > '. $tet);
		$app->log('Copying nginx-rp ssl cert file: '. $data['cert']['crt'], LOGLEVEL_DEBUG);


		/*
		 * copy the secrect .key file
		 */
		exec('cp '. $data['cert']['key'] .' '. $test);
		$app->log('Copying nginx-rp ssl key file: '. $data['cert']['key'], LOGLEVEL_DEBUG);

	}


	/*
	 * the update function changes the ssl cert files
	 */
	function update($data, $app, $suffix) {

		$this->delete($data, $app);
		$this->insert($data, $app);

	}


	/*
	 * the delete function removes the ssl cert files
	 */
	function delete($data, $app, $suffix) {

		$app->log('Removing nginx-rp ssl cert file: '. $data['cert']['cert'], LOGLEVEL_DEBUG);
		$app->log('Removing nginx-rp ssl key file: '. $data['cert']['key'], LOGLEVEL_DEBUG);

	}

}