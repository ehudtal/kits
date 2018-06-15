<?php
	/**
		Gets user magic field responses

		$magic_field_names is an array btw.

		Need to define CANVAS_TOKEN defined in wp-config.php btw.

		Returns an object with field names as keys, an object with student names as keys and values as, well, values.
	*/
	function bz_get_cohort_magic_fields($lc_email, $magic_field_names) {

		$names_url = "";
		foreach($magic_field_names as $name)
			$names_url .= "&fields[]=" . urlencode($name);

		$ch = curl_init();
		$url = 'https://stagingportal.bebraven.org/bz/magic_fields_for_cohort?email=' . urlencode($lc_email) . '&access_token=' . urlencode(CANVAS_TOKEN) . $names_url;
		// Change stagingportal to portal here when going live!
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$answer = curl_exec($ch);
		curl_close($ch);

		// trim off any cross-site get padding, if present,
		// keeping just the json object
		$answer = substr($answer, strpos($answer, "{"));
		// echo $answer;
		// echo $url;
		$obj = json_decode($answer, TRUE);
		return $obj["answers"];
	}
?>