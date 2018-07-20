<?php

$WP_CONFIG = array();

function bzLoadWpConfig() {
	global $WP_CONFIG;

	$out = array();
	preg_match_all("/define\('([A-Z_0-9]+)', '(.*)'\);/", file_get_contents("wp-config.php"), $out, PREG_SET_ORDER);

	foreach($out as $match) {
		$WP_CONFIG[$match[1]] = $match[2];
	}
}

bzLoadWpConfig();

/*

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
CREATE DATABASE IF NOT EXISTS `braven_attendance` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci;
USE `braven_attendance`;

START TRANSACTION;

SET NAMES utf8mb4;

	CREATE TABLE attendance_events (
		id INTEGER NOT NULL AUTO_INCREMENT,

		course_id INTEGER,
		cohort TEXT NULL, -- if not null, it applies only to this cohort

		name TEXT NOT NULL,
		event_time DATETIME,

		PRIMARY KEY (id)
	) DEFAULT CHARACTER SET=utf8mb4;

	CREATE TABLE attendance_people (
		event_id INTEGER NOT NULL,
		person_name VARCHAR(180) NOT NULL,
		present INTEGER NULL, -- null means unknown, 0 means no, 1 means there, 2 means late
		FOREIGN KEY (event_id) REFERENCES attendance_events(id) ON DELETE CASCADE,
		PRIMARY KEY(event_id, person_name)
	) DEFAULT CHARACTER SET=utf8mb4;

	COMMIT;
*/

session_start();

function set_attendance($event_id, $person_name, $present) {
	global $pdo;

	$statement = $pdo->prepare("
		INSERT INTO attendance_people
			(event_id, person_name, present)
		VALUES
			(?, ?, ?)
		ON DUPLICATE KEY UPDATE
			present = ?
	");

	$statement->execute(array(
		$event_id,
		$person_name,
		$present,
		$present
	));
}

function get_event_info($event_id) {
	global $pdo;

	$statement = $pdo->prepare("
		SELECT
			id, name, event_time, course_id
		FROM
			attendance_events
		WHERE
			id = ?
	");

	$statement->execute(array($event_id));
	while($row = $statement->fetch(PDO::FETCH_ASSOC)) {
		return $row;
	}

	return null;
}

function get_event_info_by_name($course_id, $event_name) {
	global $pdo;

	$statement = $pdo->prepare("
		SELECT
			id, name, event_time, course_id
		FROM
			attendance_events
		WHERE
			course_id = ?
			AND
			name = ?
	");

	$statement->execute(array($course_id, $event_name));
	while($row = $statement->fetch(PDO::FETCH_ASSOC)) {
		return $row;
	}

	create_event($course_id, $event_name);

	return get_event_info_by_name($course_id, $event_name);
}

function create_event($course_id, $event_name) {
	global $pdo;

	$statement = $pdo->prepare("
		INSERT INTO attendance_events
			(course_id, name)
		VALUES
			(?, ?)
	");

	$statement->execute(array(
		$course_id,
		$event_name
	));
}


function load_student_status($event_id, $students) {
	global $pdo;

	$statement = $pdo->prepare("
		SELECT
			person_name, present
		FROM
			attendance_people
		WHERE
			event_id = ?
			AND
			person_name IN  (".str_repeat('?,', count($students) - 1)."?)

	");

	$args = array($event_id);
	$args = array_merge($args, $students);

	$result = array();

	foreach($students as $student)
		$result[$student] = 0;

	$statement->execute($args);
	while($row = $statement->fetch(PDO::FETCH_ASSOC)) {
		$result[$row["person_name"]] = $row["present"];
	}

	return $result;
}

function bz_current_full_url() {
	$url = "http";
	if(isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on")
		$url .= "s";
	$url .= "://";
	$url .= $_SERVER["HTTP_HOST"];
	$url .= $_SERVER["PHP_SELF"];
	$url .= "?";
	$url .= $_SERVER["QUERY_STRING"];
	return $url;
}

function sso() {
	global $WP_CONFIG;

	if(isset($_SESSION["sso_service"]) && isset($_SESSION["coming_from"]) && isset($_GET["ticket"])) {
		// validate ticket from the SSO server

		$ticket = $_GET["ticket"];
		$service = $_SESSION["sso_service"];
		$coming_from = $_SESSION["coming_from"];
		unset($_SESSION["sso_service"]);
		unset($_SESSION["coming_from"]);

		$content = file_get_contents("https://{$WP_CONFIG["BRAVEN_SSO_DOMAIN"]}/serviceValidate?ticket=".urlencode($ticket)."&service=".urlencode($service));

		$xml = new DOMDocument();
		$xml->loadXML($content);
		$user = $xml->getElementsByTagNameNS("*", "user")->item(0)->textContent;

		if($user == "admin@beyondz.org" || strpos($user, "@bebraven.org") !== FALSE) {
			// login successful
			$_SESSION["user"] = $user;
		} else {
			echo "User " . htmlentities($user) . " is not authorized. Try logging out of SSO first.";
			exit;
		}

		header("Location: " . $coming_from);
		exit;
	} else if(isset($_SESSION["coming_from"]) && !isset($_SESSION["sso_service"])) {
		$ssoService = bz_current_full_url() . "&dosso";
		$_SESSION["sso_service"] = $ssoService;
		header("Location: https://{$WP_CONFIG["BRAVEN_SSO_DOMAIN"]}/login?service=" . urlencode($ssoService));
		exit;
	} // otherwise it is just an api thing for other uses
}

// returns the currently logged in user, or redirects+exits to SSO
function requireLogin() {
	if(!isset($_SESSION["user"])) {
		if(!isset($_GET["dosso"])) {
			$_SESSION["coming_from"] = bz_current_full_url();
			unset($_SESSION["sso_service"]);
		}
		sso();
		exit;
	}
	return $_SESSION["user"];
}

requireLogin();

$pdo_opt = [
	PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	PDO::ATTR_EMULATE_PREPARES   => false,
];

$pdo = new PDO("mysql:host={$WP_CONFIG["DB_HOST"]};dbname={$WP_CONFIG["DB_ATTENDANCE_NAME"]};charset=utf8mb4", $WP_CONFIG["DB_USER"], $WP_CONFIG["DB_PASSWORD"], $pdo_opt);

	/**
		Gets user magic field responses

		$magic_field_names is an array btw.

		Need to define CANVAS_TOKEN defined in wp-config.php btw.

		Returns an object with field names as keys, an object with student names
		as keys and values as, well, values.
	*/
	function get_cohort_magic_fields($course_id, $lc_email, $magic_field_names) {
		global $WP_CONFIG;

		$names_url = "";
		foreach($magic_field_names as $name)
			$names_url .= "&fields[]=" . urlencode($name);

		$ch = curl_init();
		$url = 'https://stagingportal.bebraven.org/bz/magic_fields_for_cohort?course_id='.((int) $course_id).'&email=' . urlencode($lc_email) . '&access_token=' . urlencode($WP_CONFIG["CANVAS_TOKEN"]) . $names_url;
		// Change stagingportal to portal here when going live!
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$answer = curl_exec($ch);
		curl_close($ch);

		// trim off any cross-site get padding, if present,
		// keeping just the json object
		$answer = substr($answer, strpos($answer, "{"));
		$obj = json_decode($answer, TRUE);
		return $obj["answers"];
	}

	if(isset($_POST["operation"])) {
		set_attendance($_POST["event_id"], $_POST["student_name"], $_POST["present"]);
		exit;
	}


	$lc_email = $_SESSION["user"];
	$course_id = 0;
	if(isset($_GET["course_id"]))
		$course_id = $_GET["course_id"];
	else if(isset($_GET["course_name"])) {
		switch($_GET["course_name"]) {
			case "sjsu":
				$course_id = 40;
			break;
			case "run":
				$course_id = 41;
			break;
			case "nlu":
				$course_id = 39;
			break;
		}
	}

	$event_id = 0;
	$event_name = "";
	if(isset($_GET["event_id"])) {
		$event_id = $_GET["event_id"];
		$event_name = get_event_info($event_id)["name"];
	} else if(isset($_GET["event_name"])) {
		$event_name = $_GET["event_name"];
		$event_id = get_event_info_by_name($course_id, $event_name)["id"];

	}

	$student_list = array_keys(get_cohort_magic_fields($course_id, $lc_email, ['student_list'])["student_list"]);
	$student_status = load_student_status($event_id, $student_list);
?><!DOCTYPE html>
<html>
<head>
<title>Attendance Tracker</title>
<style>
	body {
		font-family: sans-serif;
		font-size: 20px;
	}

	ol {
		list-style: none;
	}

	li {
		margin: 8px 0px;
	}

	label, input {
		vertical-align: middle;
	}
</style>
<script>
	function recordChange(ele, event_id, student_name, present) {
		ele.parentNode.classList.add("saving");
		ele.parentNode.classList.remove("error-saving");
		var http = new XMLHttpRequest();
		http.open("POST", location.href, true);

		var data = "";
		data += "operation=" + encodeURIComponent("save");
		data += "&";
		data += "event_id=" + encodeURIComponent(event_id);
		data += "&";
		data += "student_name=" + encodeURIComponent(student_name);
		data += "&";
		data += "present=" + encodeURIComponent(present);

		http.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		http.onerror = function() {
			ele.parentNode.classList.remove("saving");
			ele.parentNode.classList.add("error-saving");
		};
		http.onload = function() {
			ele.parentNode.classList.remove("saving");
			ele.parentNode.classList.add("saved");
			setTimeout(function() {
				ele.parentNode.classList.remove("saved");
			}, 1000);
		};
		http.send(data);
	}
</script>
<style>
	.saving {
		transition: all ease-out 1s;
		background-color: #666;
	}

	.saved {

		transition: all ease-out 1s;
		background-color: #0f0;
	}

	.error-saving {
		transition: all ease-out 1s;
		background-color: #f00;
	}
</style>
</head>
<body>
	<!--
	Cohort: Course 41, lc@bebraven.org
	Event: LL 1, June 21

	So they display for any given cohort lists
		Event       Event      Event 	Percentage
	Name     [x]         [x]        [x]
	Name
	Name
	Percentage

	It can also display just one column at a time.
	-->

	Attendance for <?php echo htmlentities($event_name); ?>
	<ol>
		<?php
			foreach($student_list as $student) {
		?>
			<li><label><input
				onchange="recordChange(this, this.getAttribute('data-event-id'), this.getAttribute('data-student-name'), this.checked ? 1 : 0);"
				type="checkbox"
				data-event-id="<?php echo $event_id; ?>"
				data-student-name="<?php echo htmlentities($student); ?>"
				<?php if($student_status[$student]) echo "checked=\"checked\""; ?>
			/> <?php echo htmlentities($student); ?></label></li>
		<?php
			}
		?>
	</ol>
</body>
</html>
