<?php
namespace TYPO3\CMS\Cal\Updates;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Upgrade wizard which goes through all files referenced in the tx_cal_organizer.image filed
 * and creates sys_file records as well as sys_file_reference records for the individual usages.
 *
 * @author Mario Matzulla <mario@matzullas.de>
 */
class TxCalOrganizerImagesUpdateWizard extends \TYPO3\CMS\Install\Updates\AbstractUpdate {

	const FOLDER_ContentUploads = '_migrated/cal_uploads';

	/**
	 * @var string
	 */
	protected $title = 'Migrate file relations of tx_cal_organizer "image"';

	/**
	 * @var string
	 */
	protected $targetDirectory;

	/**
	 * @var \TYPO3\CMS\Core\Resource\ResourceFactory
	 */
	protected $fileFactory;

	/**
	 * @var \TYPO3\CMS\Core\Resource\Index\FileIndexRepository
	 */
	protected $fileIndexRepository;

	/**
	 * @var \TYPO3\CMS\Core\Resource\ResourceStorage
	 */
	protected $storage;

	/**
	 * Initialize all required repository and factory objects.
	 *
	 * @throws \RuntimeException
	 */
	protected function init() {
		$fileadminDirectory = rtrim($GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'], '/') . '/';
		/** @var $storageRepository \TYPO3\CMS\Core\Resource\StorageRepository */
		$storageRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\StorageRepository');
		$storages = $storageRepository->findAll();
		foreach ($storages as $storage) {
			$storageRecord = $storage->getStorageRecord();
			$configuration = $storage->getConfiguration();
			$isLocalDriver = $storageRecord['driver'] === 'Local';
			$isOnFileadmin = !empty($configuration['basePath']) && \TYPO3\CMS\Core\Utility\GeneralUtility::isFirstPartOfStr($configuration['basePath'], $fileadminDirectory);
			if ($isLocalDriver && $isOnFileadmin) {
				$this->storage = $storage;
				break;
			}
		}
		if (!isset($this->storage)) {
			throw new \RuntimeException('Local default storage could not be initialized - might be due to missing sys_file* tables.');
		}
		$this->fileFactory = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\ResourceFactory');
		$this->fileIndexRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Index\\FileIndexRepository');
		$this->targetDirectory = PATH_site . $fileadminDirectory . self::FOLDER_ContentUploads . '/';
	}

	/**
	 * Checks if an update is needed
	 *
	 * @param string &$description The description for the update
	 * @return boolean TRUE if an update is needed, FALSE otherwise
	 */
	public function checkForUpdate(&$description) {
		$updateNeeded = FALSE;
		// Fetch records where the field media does not contain a plain integer value
		// * check whether media field is not empty
		// * then check whether media field does not contain a reference count (= not integer)
		$mapping = $this->getTableColumnMapping();
		$sql = $GLOBALS['TYPO3_DB']->SELECTquery(
			'COUNT(' . $mapping['mapFieldNames']['uid'] . ')',
			$mapping['mapTableName'],
			'1=1'
		);
		$whereClause = $this->getDbalCompliantUpdateWhereClause();
		$sql = str_replace('WHERE 1=1', $whereClause, $sql);
		$resultSet = $GLOBALS['TYPO3_DB']->sql_query($sql);
		$notMigratedRowsCount = 0;
		if ($resultSet !== FALSE) {
			list($notMigratedRowsCount) = $GLOBALS['TYPO3_DB']->sql_fetch_row($resultSet);
			$notMigratedRowsCount = (int)$notMigratedRowsCount;
			$GLOBALS['TYPO3_DB']->sql_free_result($resultSet);
		}
		if ($notMigratedRowsCount > 0) {
			$description = 'There are calendar organizer with an "image" which are referencing files that are not using ' . ' the File Abstraction Layer. This wizard will move the files to fileadmin/' . self::FOLDER_ContentUploads . ' and index them.';
			$updateNeeded = TRUE;
		}
		return $updateNeeded;
	}

	/**
	 * Performs the database update.
	 *
	 * @param array &$dbQueries Queries done in this update
	 * @param mixed &$customMessages Custom messages
	 * @return boolean TRUE on success, FALSE on error
	 */
	public function performUpdate(array &$dbQueries, &$customMessages) {
		$this->init();
		$records = $this->getRecordsFromTable('tx_cal_organizer');
		$this->checkPrerequisites();
		foreach ($records as $singleRecord) {
			$this->migrateRecord($singleRecord);
		}
		return TRUE;
	}

	/**
	 * Ensures a new folder "fileadmin/cal_upload/" is available.
	 *
	 * @return void
	 */
	protected function checkPrerequisites() {
		if (!$this->storage->hasFolder(self::FOLDER_ContentUploads)) {
			$this->storage->createFolder(self::FOLDER_ContentUploads, $this->storage->getRootLevelFolder());
		}
	}

	/**
	 * Processes the actual transformation from CSV to sys_file_references
	 *
	 * @param array $record
	 * @return void
	 */
	protected function migrateRecord(array $record) {
		$collections = array();

		$files = \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode(',', $record['image'], TRUE);
		$descriptions = \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode('
', $record['imagecaption']);
		$titleText = \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode('
', $record['imagetitletext']);
		$i = 0;
		foreach ($files as $file) {
			if (file_exists(PATH_site . 'uploads/tx_cal/pics/' . $file)) {
				\TYPO3\CMS\Core\Utility\GeneralUtility::upload_copy_move(PATH_site . 'uploads/tx_cal/pics/' . $file, $this->targetDirectory . $file);
				$fileObject = $this->storage->getFile(self::FOLDER_ContentUploads . '/' . $file);
				$this->fileIndexRepository->add($fileObject);
				$dataArray = array(
					'uid_local' => $fileObject->getUid(),
					'tablenames' => 'tx_cal_organizer',
					'uid_foreign' => $record['uid'],
					// the sys_file_reference record should always placed on the same page
					// as the record to link to, see issue #46497
					'pid' => $record['pid'],
					'fieldname' => 'image',
					'sorting_foreign' => $i
				);
				if (isset($descriptions[$i])) {
					$dataArray['description'] = $descriptions[$i];
				}
				if (isset($titleText[$i])) {
					$dataArray['alternative'] = $titleText[$i];
				}
				$GLOBALS['TYPO3_DB']->exec_INSERTquery('sys_file_reference', $dataArray);
				unlink(PATH_site . 'uploads/tx_cal/pics/' . $file);
			}
			$i++;
		}
		$this->cleanRecord($record, $i, $collections);
	}

	/**
	 * Removes the old fields from the database-record
	 *
	 * @param array $record
	 * @param integer $fileCount
	 * @param array $collectionUids
	 * @return void
	 */
	protected function cleanRecord(array $record, $fileCount, array $collectionUids) {
		$GLOBALS['TYPO3_DB']->exec_UPDATEquery('tx_cal_organizer', 'uid = ' . $record['uid'], array(
			'image' => $fileCount,
			'imagecaption' => '',
			'imagetitletext' => ''
		));
	}

	/**
	 * Retrieve every record which needs to be processed
	 *
	 * @return array
	 */
	protected function getRecordsFromTable() {
		$mapping = $this->getTableColumnMapping();
		$reverseFieldMapping = array_flip($mapping['mapFieldNames']);

		$fields = array();
		foreach (array('uid', 'pid', 'image', 'imagecaption', 'imagetitletext') as $columnName) {
			$fields[] = $mapping['mapFieldNames'][$columnName];
		}
		$fields = implode(',', $fields);

		$sql = $GLOBALS['TYPO3_DB']->SELECTquery(
			$fields,
			$mapping['mapTableName'],
			'1=1'
		);
		$whereClause = $this->getDbalCompliantUpdateWhereClause();
		$sql = str_replace('WHERE 1=1', $whereClause, $sql);
		$resultSet = $GLOBALS['TYPO3_DB']->sql_query($sql);
		$records = array();
		if (!$GLOBALS['TYPO3_DB']->sql_error()) {
			while (($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($resultSet)) !== FALSE) {
				// Mapping back column names to native TYPO3 names
				$record = array();
				foreach ($reverseFieldMapping as $columnName => $finalColumnName) {
					$record[$finalColumnName] = $row[$columnName];
				}
				$records[] = $record;
			}
			$GLOBALS['TYPO3_DB']->sql_free_result($resultSet);
		}
		return $records;
	}

	/**
	 * Returns a DBAL-compliant where clause to be used for the update where clause.
	 * We have DBAL-related code here because the SQL parser is not able to properly
	 * parse this complex condition but we know that it is compatible with the DBMS
	 * we support in TYPO3 Core.
	 *
	 * @return string
	 */
	protected function getDbalCompliantUpdateWhereClause() {
		$mapping = $this->getTableColumnMapping();
		$this->quoteIdentifiers($mapping);

		$where = sprintf(
			'WHERE %s <> \'\'',
			$mapping['mapFieldNames']['image']
		). ' AND cast( '.$mapping['mapFieldNames']['image'].' AS decimal ) = 0';

		return $where;
	}

	/**
	 * Returns the table and column mapping.
	 *
	 * @return array
	 */
	protected function getTableColumnMapping() {
		$mapping = array(
			'mapTableName' => 'tx_cal_organizer',
			'mapFieldNames' => array(
				'uid'          => 'uid',
				'pid'          => 'pid',
				'image'        => 'image',
				'imagecaption' => 'imagecaption',
				'imagetitletext' => 'imagetitletext',
			)
		);

		if ($GLOBALS['TYPO3_DB'] instanceof \TYPO3\CMS\Dbal\Database\DatabaseConnection) {
			if (!empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dbal']['mapping']['tx_cal_organizer'])) {
				$mapping = array_merge_recursive($mapping, $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dbal']['mapping']['tx_cal_organizer']);
			}
		}

		return $mapping;
	}

	/**
	 * Quotes identifiers for DBAL-compliant query.
	 *
	 * @param array &$mapping
	 * @return void
	 */
	protected function quoteIdentifiers(array &$mapping) {
		if ($GLOBALS['TYPO3_DB'] instanceof \TYPO3\CMS\Dbal\Database\DatabaseConnection) {
			if (!$GLOBALS['TYPO3_DB']->runningNative() && !$GLOBALS['TYPO3_DB']->runningADOdbDriver('mysql')) {
				$mapping['mapTableName'] = '"' . $mapping['mapTableName'] . '"';
				foreach ($mapping['mapFieldNames'] as $key => &$value) {
					$value = '"' . $value . '"';
				}
			}
		}
	}

}