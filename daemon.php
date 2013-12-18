#!/usr/local/bin/php
<?php

set_time_limit (0)

require_once('vendor/autoload.php');
require_once('cli');

if(!defined('STDIN') )
	exit('Script must run from CLI');

$config = json_decode(trim(file_get_contents('config.json')));

if(!$config)
	die('malformed config.json');

$dbuser = (isset($config->dbuser)) ? $config->dbuser : 'root';
$dbpass = (isset($config->dbpass)) ? $config->dbpass : null;
$dbname = (isset($config->dbname)) ? $config->dbname : 'test';
$dbhost = (isset($config->dbhost)) ? $config->dbhost : 'localhost';

/* setup database */

use RedBean_Facade as R;

R::setup("mysql:host={$dbhost};dbname={$dbname}", $dbuser, $dbpass);
R::$writer->setUseCache(false);
R::freeze( true );
RedBean_OODBBean::setFlagBeautifulColumnNames(false);

while($check = true) {

	$projects = R::$f->begin()
		->select('yowsupprojects.id as pid, yowsupgroups.id as dbgid, _id, sharedWith, yowsupprojects.name, wagroupid as wagid')
		->from('yowsupprojects')
		->left_join('yowsupgroups on yowsupprojects._id = yowsupgroups.gid')
		->where('yowsupgroups.id is null')
		->get();

	$tasksDone = array();

	foreach ($projects as $index => $project) {

		$pid = $project['_id'];

		/* get users */

		if(!isset($project['wagid'])) {
			$project['sharedWith'] = json_decode($project['sharedWith']);
			if(is_array($project['sharedWith'])) {
				$phones = array_map(function($email){
					return R::$f->begin()
						-> select('phone')
						-> from('yowsupusermapping')
						-> where('email = ?')
						-> put($email)
						-> get('cell');
				}, $project['sharedWith']);
			} else {
				$phones = [];
			}
			$phones = array_values(array_filter($phones));
			$phones = implode(',', $phones);
			$gid = create_group($project['name'], $phones);
			if($gid) {
				$bean = R::dispense('yowsupgroups');
				$bean->gid = $project['_id'];
				$bean->wagroupid = $projects[$index]['wagid'] = $project['wagid'] = $gid;
				R::store($bean);
			}
		}
		$gid = $project['wagid'];
		// if(is_null($project['wagid'])) {
			// $project['wagid'] =
		// }
	}

	$tasks = R::$f->begin()
		->select('yowsupgroups.wagroupid as gid, yowsuptasks.id as taskId, yowsuptasks.name as taskname, yowsuptasks.state')
		->from('yowsuptasks')
		->join('yowsupgroups on yowsupgroups.gid = yowsuptasks.projectId')
		->get();

	foreach ($tasks as $task) {
		if($task['state'] === 'new') {
			$message = 'A task titled "' . $task['taskname'] . '" has been created.';
		} else if($task['state'] === 'doing') {
			$message = 'The task titled "' . $task['taskname'] . '" has changed state to ' . ucwords($task['state']);
		} else if ($task['state'] === 'done') {
			$message = 'The task titled "' . $task['taskname'] . '" has been marked done.';
		}
		send_to_group($task['gid'], $message);
		$bean = R::findOne('yowsuptasks', 'where id = ?', array($task['taskId']));
		if($bean) {
			R::trash($bean);
		}
		$bean = null;
	}
	sleep(3);
}