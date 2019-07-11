<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\Exception\ResponseException;

/**
 * Provide methods to handle versioning.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class Versions extends Controller
{

	/**
	 * Table
	 * @var string
	 */
	protected $strTable;

	/**
	 * Parent ID
	 * @var integer
	 */
	protected $intPid;

	/**
	 * Edit URL
	 * @var string
	 */
	protected $strEditUrl;

	/**
	 * Username
	 * @var string
	 */
	protected $strUsername;

	/**
	 * User ID
	 * @var integer
	 */
	protected $intUserId;

	/**
	 * Initialize the object
	 *
	 * @param string  $strTable
	 * @param integer $intPid
	 *
	 * @throws \InvalidArgumentException
	 */
	public function __construct($strTable, $intPid)
	{
		$this->import(Database::class, 'Database');
		parent::__construct();

		$this->loadDataContainer($strTable);

		if (!isset($GLOBALS['TL_DCA'][$strTable])) {
			throw new \InvalidArgumentException(sprintf('"%s" is not a valid table', StringUtil::specialchars($strTable)));
		}

		$this->strTable = $strTable;
		$this->intPid = (int) $intPid;
	}

	/**
	 * Set the edit URL
	 *
	 * @param string $strEditUrl
	 */
	public function setEditUrl($strEditUrl)
	{
		$this->strEditUrl = $strEditUrl;
	}

	/**
	 * Set the username
	 *
	 * @param string $strUsername
	 */
	public function setUsername($strUsername)
	{
		$this->strUsername = $strUsername;
	}

	/**
	 * Set the user ID
	 *
	 * @param integer $intUserId
	 */
	public function setUserId($intUserId)
	{
		$this->intUserId = $intUserId;
	}

	/**
	 * Returns the latest version
	 *
	 * @return integer|null
	 */
	public function getLatestVersion()
	{
		if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'])
		{
			return null;
		}

		$objVersion = $this->Database->prepare("SELECT MAX(version) AS version FROM tl_version WHERE fromTable=? AND pid=?")
									 ->limit(1)
									 ->execute($this->strTable, $this->intPid);

		return (int) $objVersion->version;
	}

	/**
	 * Create the initial version of a record
	 */
	public function initialize()
	{
		if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'])
		{
			return;
		}

		$objVersion = $this->Database->prepare("SELECT COUNT(*) AS count FROM tl_version WHERE fromTable=? AND pid=?")
									 ->limit(1)
									 ->execute($this->strTable, $this->intPid);

		if ($objVersion->count > 0)
		{
			return;
		}

		$this->create();
	}

	/**
	 * Create a new version of a record
	 */
	public function create()
	{
		if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'])
		{
			return;
		}

		// Delete old versions from the database
		$tstamp = time() - (int) Config::get('versionPeriod');
		$this->Database->query("DELETE FROM tl_version WHERE tstamp<$tstamp");

		// Get the new record
		$objRecord = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
									->limit(1)
									->execute($this->intPid);

		if ($objRecord->numRows < 1 || $objRecord->tstamp < 1)
		{
			return;
		}

		// Store the content if it is an editable file
		if ($this->strTable == 'tl_files')
		{
			$objModel = FilesModel::findByPk($this->intPid);

			if ($objModel !== null && \in_array($objModel->extension, StringUtil::trimsplit(',', strtolower(Config::get('editableFiles')))))
			{
				$objFile = new File($objModel->path);

				if ($objFile->extension == 'svgz')
				{
					$objRecord->content = gzdecode($objFile->getContent());
				}
				else
				{
					$objRecord->content = $objFile->getContent();
				}
			}
		}

		$intVersion = 1;

		$objVersion = $this->Database->prepare("SELECT MAX(version) AS version FROM tl_version WHERE pid=? AND fromTable=?")
									 ->execute($this->intPid, $this->strTable);

		if ($objVersion->version !== null)
		{
			$intVersion = $objVersion->version + 1;
		}

		$strDescription = '';

		if (!empty($objRecord->title))
		{
			$strDescription = $objRecord->title;
		}
		elseif (!empty($objRecord->name))
		{
			$strDescription = $objRecord->name;
		}
		elseif (!empty($objRecord->firstname))
		{
			$strDescription = $objRecord->firstname . ' ' . $objRecord->lastname;
		}
		elseif (!empty($objRecord->headline))
		{
			$chunks = StringUtil::deserialize($objRecord->headline);

			if (\is_array($chunks) && isset($chunks['value']))
			{
				$strDescription = $chunks['value'];
			}
			else
			{
				$strDescription = $objRecord->headline;
			}
		}
		elseif (!empty($objRecord->selector))
		{
			$strDescription = $objRecord->selector;
		}
		elseif (!empty($objRecord->subject))
		{
			$strDescription = $objRecord->subject;
		}

		$this->Database->prepare("UPDATE tl_version SET active='' WHERE pid=? AND fromTable=?")
					   ->execute($this->intPid, $this->strTable);

		$this->Database->prepare("INSERT INTO tl_version (pid, tstamp, version, fromTable, username, userid, description, editUrl, active, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)")
					   ->execute($this->intPid, time(), $intVersion, $this->strTable, $this->getUsername(), $this->getUserId(), $strDescription, $this->getEditUrl(), serialize($objRecord->row()));

		// Trigger the oncreate_version_callback
		if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['oncreate_version_callback']))
		{
			foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['oncreate_version_callback'] as $callback)
			{
				if (\is_array($callback))
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($this->strTable, $this->intPid, $intVersion, $objRecord->row());
				}
				elseif (\is_callable($callback))
				{
					$callback($this->strTable, $this->intPid, $intVersion, $objRecord->row());
				}
			}
		}

		$this->log('Version '.$intVersion.' of record "'.$this->strTable.'.id='.$this->intPid.'" has been created'.$this->getParentEntries($this->strTable, $this->intPid), __METHOD__, TL_GENERAL);
	}

	/**
	 * Restore a version
	 *
	 * @param integer $intVersion
	 */
	public function restore($intVersion)
	{
		if (!$GLOBALS['TL_DCA'][$this->strTable]['config']['enableVersioning'])
		{
			return;
		}

		$objData = $this->Database->prepare("SELECT * FROM tl_version WHERE fromTable=? AND pid=? AND version=?")
								  ->limit(1)
								  ->execute($this->strTable, $this->intPid, $intVersion);

		if ($objData->numRows < 1)
		{
			return;
		}

		$data = StringUtil::deserialize($objData->data);

		if (!\is_array($data))
		{
			return;
		}

		// Restore the content if it is an editable file
		if ($this->strTable == 'tl_files')
		{
			$objModel = FilesModel::findByPk($this->intPid);

			if ($objModel !== null && \in_array($objModel->extension, StringUtil::trimsplit(',', strtolower(Config::get('editableFiles')))))
			{
				$objFile = new File($objModel->path);

				if ($objFile->extension == 'svgz')
				{
					$objFile->write(gzencode($data['content']));
				}
				else
				{
					$objFile->write($data['content']);
				}

				$objFile->close();
			}
		}

		// Get the currently available fields
		$arrFields = array_flip($this->Database->getFieldNames($this->strTable));

		// Unset fields that do not exist (see #5219)
		$data = array_intersect_key($data, $arrFields);

		// Reset fields added after storing the version to their default value (see #7755)
		foreach (array_diff_key($arrFields, $data) as $k=>$v)
		{
			$data[$k] = Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['sql']);
		}

		$this->Database->prepare("UPDATE " . $this->strTable . " %s WHERE id=?")
					   ->set($data)
					   ->execute($this->intPid);

		$this->Database->prepare("UPDATE tl_version SET active='' WHERE fromTable=? AND pid=?")
					   ->execute($this->strTable, $this->intPid);

		$this->Database->prepare("UPDATE tl_version SET active=1 WHERE fromTable=? AND pid=? AND version=?")
					   ->execute($this->strTable, $this->intPid, $intVersion);

		// Trigger the onrestore_version_callback
		if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onrestore_version_callback']))
		{
			foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onrestore_version_callback'] as $callback)
			{
				if (\is_array($callback))
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($this->strTable, $this->intPid, $intVersion, $data);
				}
				elseif (\is_callable($callback))
				{
					$callback($this->strTable, $this->intPid, $intVersion, $data);
				}
			}
		}

		// Trigger the deprecated onrestore_callback
		if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onrestore_callback']))
		{
			@trigger_error('Using the "onrestore_callback" has been deprecated and will no longer work in Contao 5.0. Use the "onrestore_version_callback" instead.', E_USER_DEPRECATED);

			foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onrestore_callback'] as $callback)
			{
				if (\is_array($callback))
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($this->intPid, $this->strTable, $data, $intVersion);
				}
				elseif (\is_callable($callback))
				{
					$callback($this->intPid, $this->strTable, $data, $intVersion);
				}
			}
		}

		$this->log('Version '.$intVersion.' of record "'.$this->strTable.'.id='.$this->intPid.'" has been restored'.$this->getParentEntries($this->strTable, $this->intPid), __METHOD__, TL_GENERAL);
	}

	/**
	 * Compare versions
	 *
	 * @param bool $blnReturnBuffer
	 *
	 * @return string
	 *
	 * @throws ResponseException
	 */
	public function compare($blnReturnBuffer=false)
	{
		$strBuffer = '';
		$arrVersions = array();
		$intTo = 0;
		$intFrom = 0;

		$objVersions = $this->Database->prepare("SELECT * FROM tl_version WHERE pid=? AND fromTable=? ORDER BY version DESC")
									  ->execute($this->intPid, $this->strTable);

		if ($objVersions->numRows < 2)
		{
			$strBuffer = '<p>There are no versions of ' . $this->strTable . '.id=' . $this->intPid . '</p>';
		}
		else
		{
			$intIndex = 0;
			$from = array();

			// Store the versions and mark the active one
			while ($objVersions->next())
			{
				if ($objVersions->active)
				{
					$intIndex = $objVersions->version;
				}

				$arrVersions[$objVersions->version] = $objVersions->row();
				$arrVersions[$objVersions->version]['info'] = $GLOBALS['TL_LANG']['MSC']['version'].' '.$objVersions->version.' ('.Date::parse(Config::get('datimFormat'), $objVersions->tstamp).') '.$objVersions->username;
			}

			// To
			if (Input::post('to') && isset($arrVersions[Input::post('to')]))
			{
				$intTo = Input::post('to');
				$to = StringUtil::deserialize($arrVersions[Input::post('to')]['data']);
			}
			elseif (Input::get('to') && isset($arrVersions[Input::get('to')]))
			{
				$intTo = Input::get('to');
				$to = StringUtil::deserialize($arrVersions[Input::get('to')]['data']);
			}
			else
			{
				$intTo = $intIndex;
				$to = StringUtil::deserialize($arrVersions[$intTo]['data']);
			}

			// From
			if (Input::post('from') && isset($arrVersions[Input::post('from')]))
			{
				$intFrom = Input::post('from');
				$from = StringUtil::deserialize($arrVersions[Input::post('from')]['data']);
			}
			elseif (Input::get('from') && isset($arrVersions[Input::get('from')]))
			{
				$intFrom = Input::get('from');
				$from = StringUtil::deserialize($arrVersions[Input::get('from')]['data']);
			}
			elseif ($objVersions->numRows > $intIndex)
			{
				$intFrom = $objVersions->first()->version;
				$from = StringUtil::deserialize($arrVersions[$intFrom]['data']);
			}
			elseif ($intIndex > 1)
			{
				$intFrom = $intIndex - 1;
				$from = StringUtil::deserialize($arrVersions[$intFrom]['data']);
			}

			// Only continue if both version numbers are set
			if ($intTo > 0 && $intFrom > 0)
			{
				System::loadLanguageFile($this->strTable);

				// Get the order fields
				$objDcaExtractor = DcaExtractor::getInstance($this->strTable);
				$arrOrder = $objDcaExtractor->getOrderFields();

				// Find the changed fields and highlight the changes
				foreach ($to as $k=>$v)
				{
					if ($from[$k] != $to[$k])
					{
						if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['doNotShow'] || $GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['hideInput'])
						{
							continue;
						}

						$blnIsBinary = ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['inputType'] == 'fileTree' || \in_array($k, $arrOrder));

						// Decrypt the values
						if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['encrypt'])
						{
							$to[$k] = Encryption::decrypt($to[$k]);
							$from[$k] = Encryption::decrypt($from[$k]);
						}

						if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['multiple'])
						{
							if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['csv']))
							{
								$delimiter = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['csv'];

								if (isset($to[$k]))
								{
									$to[$k] = preg_replace('/' . preg_quote($delimiter, ' ?/') . '/', $delimiter . ' ', $to[$k]);
								}
								if (isset($from[$k]))
								{
									$from[$k] = preg_replace('/' . preg_quote($delimiter, ' ?/') . '/', $delimiter . ' ', $from[$k]);
								}
							}
							else
							{
								// Convert serialized arrays into strings
								if (\is_array(($tmp = StringUtil::deserialize($to[$k]))) && !\is_array($to[$k]))
								{
									$to[$k] = $this->implodeRecursive($tmp, $blnIsBinary);
								}
								if (\is_array(($tmp = StringUtil::deserialize($from[$k]))) && !\is_array($from[$k]))
								{
									$from[$k] = $this->implodeRecursive($tmp, $blnIsBinary);
								}
							}
						}

						unset($tmp);

						// Convert binary UUIDs to their hex equivalents (see #6365)
						if ($blnIsBinary)
						{
							if (Validator::isBinaryUuid($to[$k]))
							{
								$to[$k] = StringUtil::binToUuid($to[$k]);
							}
							if (Validator::isBinaryUuid($from[$k]))
							{
								$to[$k] = StringUtil::binToUuid($from[$k]);
							}
						}

						// Convert date fields
						if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['rgxp'] == 'date')
						{
							$to[$k] = Date::parse(Config::get('dateFormat'), $to[$k] ?: '');
							$from[$k] = Date::parse(Config::get('dateFormat'), $from[$k] ?: '');
						}
						elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['rgxp'] == 'time')
						{
							$to[$k] = Date::parse(Config::get('timeFormat'), $to[$k] ?: '');
							$from[$k] = Date::parse(Config::get('timeFormat'), $from[$k] ?: '');
						}
						elseif ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['rgxp'] == 'datim' || $k == 'tstamp')
						{
							$to[$k] = Date::parse(Config::get('datimFormat'), $to[$k] ?: '');
							$from[$k] = Date::parse(Config::get('datimFormat'), $from[$k] ?: '');
						}

						// Decode entities if the "decodeEntities" flag is not set (see #360)
						if (empty($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['eval']['decodeEntities']))
						{
							$to[$k] = StringUtil::decodeEntities($to[$k]);
							$from[$k] = StringUtil::decodeEntities($from[$k]);
						}

						// Convert strings into arrays
						if (!\is_array($to[$k]))
						{
							$to[$k] = explode("\n", $to[$k]);
						}
						if (!\is_array($from[$k]))
						{
							$from[$k] = explode("\n", $from[$k]);
						}

						$objDiff = new \Diff($from[$k], $to[$k]);
						$strBuffer .= $objDiff->render(new DiffRenderer(array('field'=>($GLOBALS['TL_DCA'][$this->strTable]['fields'][$k]['label'][0] ?: (isset($GLOBALS['TL_LANG']['MSC'][$k]) ? (\is_array($GLOBALS['TL_LANG']['MSC'][$k]) ? $GLOBALS['TL_LANG']['MSC'][$k][0] : $GLOBALS['TL_LANG']['MSC'][$k]) : $k)))));
					}
				}
			}
		}

		// Identical versions
		if ($strBuffer == '')
		{
			$strBuffer = '<p>'.$GLOBALS['TL_LANG']['MSC']['identicalVersions'].'</p>';
		}

		if ($blnReturnBuffer)
		{
			return $strBuffer;
		}

		$objTemplate = new BackendTemplate('be_diff');
		$objTemplate->content = $strBuffer;
		$objTemplate->versions = $arrVersions;
		$objTemplate->to = $intTo;
		$objTemplate->from = $intFrom;
		$objTemplate->showLabel = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['showDifferences']);
		$objTemplate->theme = Backend::getTheme();
		$objTemplate->base = Environment::get('base');
		$objTemplate->language = $GLOBALS['TL_LANGUAGE'];
		$objTemplate->title = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['showDifferences']);
		$objTemplate->charset = Config::get('characterSet');
		$objTemplate->action = ampersand(Environment::get('request'));

		throw new ResponseException($objTemplate->getResponse());
	}

	/**
	 * Render the versions dropdown menu
	 *
	 * @return string
	 */
	public function renderDropdown()
	{
		$objVersion = $this->Database->prepare("SELECT tstamp, version, username, active FROM tl_version WHERE fromTable=? AND pid=? ORDER BY version DESC")
								     ->execute($this->strTable, $this->intPid);

		if ($objVersion->numRows < 2)
		{
			return '';
		}

		$versions = '';

		while ($objVersion->next())
		{
			$versions .= '
  <option value="'.$objVersion->version.'"'.($objVersion->active ? ' selected="selected"' : '').'>'.$GLOBALS['TL_LANG']['MSC']['version'].' '.$objVersion->version.' ('.Date::parse(Config::get('datimFormat'), $objVersion->tstamp).') '.$objVersion->username.'</option>';
		}

		return '
<div class="tl_version_panel">

<form action="'.ampersand(Environment::get('request')).'" id="tl_version" class="tl_form" method="post" aria-label="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['versioning']).'">
<div class="tl_formbody">
<input type="hidden" name="FORM_SUBMIT" value="tl_version">
<input type="hidden" name="REQUEST_TOKEN" value="'.REQUEST_TOKEN.'">
<select name="version" class="tl_select">'.$versions.'
</select>
<button type="submit" name="showVersion" id="showVersion" class="tl_submit">'.$GLOBALS['TL_LANG']['MSC']['restore'].'</button>
<a href="'.Backend::addToUrl('versions=1&amp;popup=1').'" title="'.StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['showDifferences']).'" onclick="Backend.openModalIframe({\'title\':\''.StringUtil::specialchars(str_replace("'", "\\'", sprintf($GLOBALS['TL_LANG']['MSC']['recordOfTable'], $this->intPid, $this->strTable))).'\',\'url\':this.href});return false">'.Image::getHtml('diff.svg').'</a>
</div>
</form>

</div>
';
	}

	/**
	 * Add a list of versions to a template
	 *
	 * @param BackendTemplate $objTemplate
	 */
	public static function addToTemplate(BackendTemplate $objTemplate)
	{
		$arrVersions = array();

		$objUser = BackendUser::getInstance();
		$objDatabase = Database::getInstance();

		// Get the total number of versions
		$objTotal = $objDatabase->prepare("SELECT COUNT(*) AS count FROM tl_version WHERE version>1 AND editUrl IS NOT NULL" . (!$objUser->isAdmin ? " AND userid=?" : ""))
								->execute($objUser->id);

		$intLast   = ceil($objTotal->count / 30);
		$intPage   = Input::get('vp') ?? 1;
		$intOffset = ($intPage - 1) * 30;

		// Validate the page number
		if ($intPage < 1 || ($intLast > 0 && $intPage > $intLast))
		{
			header('HTTP/1.1 404 Not Found');
		}

		// Create the pagination menu
		$objPagination = new Pagination($objTotal->count, 30, 7, 'vp', new BackendTemplate('be_pagination'));
		$objTemplate->pagination = $objPagination->generate();

		// Get the versions
		$objVersions = $objDatabase->prepare("SELECT pid, tstamp, version, fromTable, username, userid, description, editUrl, active FROM tl_version WHERE editUrl IS NOT NULL" . (!$objUser->isAdmin ? " AND userid=?" : "") . " ORDER BY tstamp DESC, pid, version DESC")
								   ->limit(30, $intOffset)
								   ->execute($objUser->id);

		while ($objVersions->next())
		{
			// Hide profile changes if the user does not have access to the "user" module (see #1309)
			if (!$objUser->isAdmin && $objVersions->fromTable == 'tl_user' && !$objUser->hasAccess('user', 'modules'))
			{
				continue;
			}

			$arrRow = $objVersions->row();

			// Add some parameters
			$arrRow['from'] = max(($objVersions->version - 1), 1); // see #4828
			$arrRow['to'] = $objVersions->version;
			$arrRow['date'] = date(Config::get('datimFormat'), $objVersions->tstamp);
			$arrRow['description'] = StringUtil::substr($arrRow['description'], 32);
			$arrRow['shortTable'] = StringUtil::substr($arrRow['fromTable'], 18); // see #5769

			if ($arrRow['editUrl'] != '')
			{
				$arrRow['editUrl'] = preg_replace(array('/&(amp;)?popup=1/', '/&(amp;)?rt=[^&]+/'), array('', '&amp;rt=' . REQUEST_TOKEN), ampersand($arrRow['editUrl']));
			}

			$arrVersions[] = $arrRow;
		}

		$intCount = -1;
		$arrVersions = array_values($arrVersions);

		// Add the "even" and "odd" classes
		foreach ($arrVersions as $k=>$v)
		{
			$arrVersions[$k]['class'] = (++$intCount % 2 == 0) ? 'even' : 'odd';

			try
			{
				// Mark deleted versions (see #4336)
				$objDeleted = $objDatabase->prepare("SELECT COUNT(*) AS count FROM " . $v['fromTable'] . " WHERE id=?")
										  ->execute($v['pid']);

				$arrVersions[$k]['deleted'] = ($objDeleted->count < 1);
			}
			catch (\Exception $e)
			{
				// Probably a disabled module
				--$intCount;
				unset($arrVersions[$k]);
			}

			// Skip deleted files (see #8480)
			if ($v['fromTable'] == 'tl_files' && $arrVersions[$k]['deleted'])
			{
				--$intCount;
				unset($arrVersions[$k]);
			}
		}

		$objTemplate->versions = $arrVersions;
	}

	/**
	 * Return the edit URL
	 *
	 * @return string
	 */
	protected function getEditUrl()
	{
		if ($this->strEditUrl !== null)
		{
			return sprintf($this->strEditUrl, $this->intPid);
		}

		$strUrl = Environment::get('request');

		// Save the real edit URL if the visibility is toggled via Ajax
		if (preg_match('/&(amp;)?state=/', $strUrl))
		{
			$strUrl = preg_replace
			(
				array('/&(amp;)?id=[^&]+/', '/(&(amp;)?)t(id=[^&]+)/', '/(&(amp;)?)state=[^&]*/'),
				array('', '$1$3', '$1act=edit'), $strUrl
			);
		}

		// Adjust the URL of the "personal data" module (see #7987)
		if (preg_match('/do=login(&|$)/', $strUrl))
		{
			$strUrl = preg_replace('/do=login(&|$)/', 'do=user$1', $strUrl);
			$strUrl .= '&amp;act=edit&amp;id=' . $this->User->id . '&amp;rt=' . REQUEST_TOKEN;
		}

		// Correct the URL in "edit|override multiple" mode (see #7745)
		$strUrl = preg_replace('/act=(edit|override)All/', 'act=edit&id=' . $this->intPid, $strUrl);

		return $strUrl;
	}

	/**
	 * Return the username
	 *
	 * @return string
	 */
	protected function getUsername()
	{
		if ($this->strUsername !== null)
		{
			return $this->strUsername;
		}

		$this->import(BackendUser::class, 'User');

		return $this->User->username;
	}

	/**
	 * Return the user ID
	 *
	 * @return string
	 */
	protected function getUserId()
	{
		if ($this->intUserId !== null)
		{
			return $this->intUserId;
		}

		$this->import(BackendUser::class, 'User');

		return $this->User->id;
	}

	/**
	 * Implode a multi-dimensional array recursively
	 *
	 * @param mixed   $var
	 * @param boolean $binary
	 *
	 * @return string
	 */
	protected function implodeRecursive($var, $binary=false)
	{
		if (!\is_array($var))
		{
			return $binary ? StringUtil::binToUuid($var) : $var;
		}
		elseif (!\is_array(current($var)))
		{
			if ($binary)
			{
				$var = array_map(static function ($v) { return $v ? StringUtil::binToUuid($v) : ''; }, $var);
			}

			return implode(', ', $var);
		}
		else
		{
			$buffer = '';

			foreach ($var as $k=>$v)
			{
				$buffer .= $k . ": " . $this->implodeRecursive($v) . "\n";
			}

			return trim($buffer);
		}
	}
}

class_alias(Versions::class, 'Versions');
