<?php
/**
 * Copyright (c) 2014 Viktar Dubiniuk <dubiniuk@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Files_Antivirus;

use OC\Files\Filesystem;
use OC\Files\Storage\Wrapper\Wrapper;
use \OCP\App;
use \OCP\IL10N;
use \OCP\ILogger;
use \OCP\Files\InvalidContentException;
use Icewind\Streams\CallbackWrapper;


class AvirWrapper extends Wrapper{
	
	/**
	 * Modes that are used for writing 
	 * @var array 
	 */
	private $writingModes = array('r+', 'w', 'w+', 'a', 'a+', 'x', 'x+', 'c', 'c+');

	/**
	 * @var AppConfig
	 */
	protected $appConfig;

	/**
	 * @var \OCA\Files_Antivirus\ScannerFactory
	 */
	protected $scannerFactory;
	
	/**
	 * @var IL10N 
	 */
	protected $l10n;
	
	/**
	 * @var ILogger;
	 */
	protected $logger;

	/** @var  RequestHelper */
	protected $requestHelper;

	/**
	 * @param array $parameters
	 */
	public function __construct($parameters) {
		parent::__construct($parameters);
		$this->appConfig = $parameters['appConfig'];
		$this->scannerFactory = $parameters['scannerFactory'];
		$this->l10n = $parameters['l10n'];
		$this->logger = $parameters['logger'];
		$this->requestHelper = $parameters['requestHelper'];
	}
	
	/**
	 * Asynchronously scan data that are written to the file
	 * @param string $path
	 * @param string $mode
	 * @return resource | bool
	 */
	public function fopen($path, $mode){
		$stream = $this->storage->fopen($path, $mode);

		if (
			is_resource($stream)
			&& $this->isWritingMode($mode)
			&& $this->isScannableSize($path)
		) {
			try {
				$scanner = $this->scannerFactory->getScanner();
				$scanner->initScanner();
				return CallBackWrapper::wrap(
					$stream,
					null,
					function ($data) use ($scanner){
						$scanner->onAsyncData($data);
					}, 
					function () use ($scanner, $path) {
						$status = $scanner->completeAsyncScan();
						if (intval($status->getNumericStatus()) === \OCA\Files_Antivirus\Status::SCANRESULT_INFECTED){
							//prevent from going to trashbin
							if (App::isEnabled('files_trashbin')) {
								\OCA\Files_Trashbin\Storage::preRenameHook([
									Filesystem::signal_param_oldpath => '',
									Filesystem::signal_param_newpath => ''
								]);
							}
							
							$owner = $this->getOwner($path);
							$this->unlink($path);

							if (App::isEnabled('files_trashbin')) {
								\OCA\Files_Trashbin\Storage::postRenameHook([]);
							}
							$this->logger->warning(
								'Infected file deleted. ' . $status->getDetails()
								. ' Account: ' . $owner . ' Path: ' . $path,
								['app' => 'files_antivirus']
							);

							\OC::$server->getActivityManager()->publishActivity(
								'files_antivirus',
								Activity::SUBJECT_VIRUS_DETECTED,
								[$path, $status->getDetails()],
								Activity::MESSAGE_FILE_DELETED,
								[],
								$path,
								'',
								$owner,
								Activity::TYPE_VIRUS_DETECTED,
								Activity::PRIORITY_HIGH
							);
											
							throw new InvalidContentException(
								$this->l10n->t(
									'Virus %s is detected in the file. Upload cannot be completed.',
									$status->getDetails()
								)
							);
						}
					}
				);
			} catch (\Exception $e){
				$message = 	implode(' ', [ __CLASS__, __METHOD__, $e->getMessage()]);
				$this->logger->warning($message);
			}
		}
		return $stream;
	}
	
	/**
	 * Checks whether passed mode is suitable for writing 
	 * @param string $mode
	 * @return bool
	 */
	private function isWritingMode($mode){
		// Strip unessential binary/text flags
		$cleanMode = str_replace(
			['t', 'b'],
			['', ''],
			$mode
		);
		return in_array($cleanMode, $this->writingModes);
	}

	/**
	 * Checks upload size against the av_max_file_size config option
	 *
	 * @param string $path
	 * @return bool
	 */
	private function isScannableSize($path) {
		$scanSizeLimit = intval($this->appConfig->getAvMaxFileSize());
		$size = $this->requestHelper->getUploadSize($path);

		// No upload in progress. Skip this file.
		if (is_null($size)){
			$this->logger->debug(
				'No upload in progress or chunk is being uploaded. Scanning is skipped.',
				['app' => 'files_antivirus']
			);
			return false;
		}

		$matchesLimit = $scanSizeLimit === -1 || $scanSizeLimit >= $size;
		$action = $matchesLimit ? 'Scanning is scheduled.' : 'Scanning is skipped.';
		$this->logger->debug(
			'File size is {filesize}. av_max_file_size is {av_max_file_size}. {action}',
			[
				'app' => 'files_antivirus',
				'av_max_file_size' => $scanSizeLimit,
				'filesize' => $size,
				'action' => $action
			]
		);
		return $matchesLimit;
	}
}
