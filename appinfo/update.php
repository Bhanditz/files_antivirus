<?php
/**
 * Copyright (c) 2014 Victor Dubiniuk <victor.dubiniuk@gmail.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */


$installedVersion = \OCP\Config::getAppValue('files_antivirus', 'installed_version');

if (version_compare($installedVersion, '0.5', '<')) {
	$ruleMapper = new \OCA\Files_Antivirus\Db\RuleMapper();
	$ruleMapper->populate();
}

if (version_compare($installedVersion, '0.6', '<')) {
	// remove the old job with old classname
	$jobList = \OC::$server->getJobList();
	$jobs = $jobList->getAll();
	foreach ($jobs as $job) {
		$jobArg = $job->getArgument();
		if($jobArg[0]=='OC_Files_Antivirus_BackgroundScanner')
			$jobList->remove($job);
	}
}
