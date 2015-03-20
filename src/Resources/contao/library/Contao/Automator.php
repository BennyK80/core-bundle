<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2015 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao;

use Contao\CoreBundle\Command\SymlinksCommand;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\PhpExecutableFinder;


/**
 * Provide methods to run automated jobs.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class Automator extends \System
{

	/**
	 * Make the constuctor public
	 */
	public function __construct()
	{
		parent::__construct();
	}


	/**
	 * Check for new \Contao versions
	 */
	public function checkForUpdates()
	{
		if (!is_numeric(BUILD))
		{
			return;
		}

		$objRequest = new \Request();
		$objRequest->send(\Config::get('liveUpdateBase') . (LONG_TERM_SUPPORT ? 'lts-version.txt' : 'version.txt'));

		if (!$objRequest->hasError())
		{
			\Config::set('latestVersion', $objRequest->response);
			\Config::persist('latestVersion', $objRequest->response);
		}

		// Add a log entry
		$this->log('Checked for Contao updates', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the search tables
	 */
	public function purgeSearchTables()
	{
		$objDatabase = \Database::getInstance();

		// Truncate the tables
		$objDatabase->execute("TRUNCATE TABLE tl_search");
		$objDatabase->execute("TRUNCATE TABLE tl_search_index");

		// Purge the cache folder
		$objFolder = new \Folder('system/cache/search');
		$objFolder->purge();

		// Add a log entry
		$this->log('Purged the search tables', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the undo table
	 */
	public function purgeUndoTable()
	{
		$objDatabase = \Database::getInstance();

		// Truncate the table
		$objDatabase->execute("TRUNCATE TABLE tl_undo");

		// Add a log entry
		$this->log('Purged the undo table', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the version table
	 */
	public function purgeVersionTable()
	{
		$objDatabase = \Database::getInstance();

		// Truncate the table
		$objDatabase->execute("TRUNCATE TABLE tl_version");

		// Add a log entry
		$this->log('Purged the version table', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the system log
	 */
	public function purgeSystemLog()
	{
		$objDatabase = \Database::getInstance();

		// Truncate the table
		$objDatabase->execute("TRUNCATE TABLE tl_log");

		// Add a log entry
		$this->log('Purged the system log', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the image cache
	 */
	public function purgeImageCache()
	{
		// Walk through the subfolders
		foreach (scan(TL_ROOT . '/assets/images') as $dir)
		{
			if (strncmp($dir, '.', 1) !== 0)
			{
				$objFolder = new \Folder('assets/images/' . $dir);
				$objFolder->purge();
			}
		}

		// Also empty the page cache so there are no links to deleted images
		$this->purgePageCache();

		// Add a log entry
		$this->log('Purged the image cache', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the script cache
	 */
	public function purgeScriptCache()
	{
		// assets/js and assets/css
		foreach (array('assets/js', 'assets/css') as $dir)
		{
			// Purge the folder
			$objFolder = new \Folder($dir);
			$objFolder->purge();
		}

		// Recreate the internal style sheets
		$this->import('StyleSheets');
		$this->StyleSheets->updateStylesheets();

		// Also empty the page cache so there are no links to deleted scripts
		$this->purgePageCache();

		// Add a log entry
		$this->log('Purged the script cache', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the page cache
	 */
	public function purgePageCache()
	{
		// Purge the folder
		$objFolder = new \Folder('system/cache/html');
		$objFolder->purge();

		// Add a log entry
		$this->log('Purged the page cache', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the search cache
	 */
	public function purgeSearchCache()
	{
		// Purge the folder
		$objFolder = new \Folder('system/cache/search');
		$objFolder->purge();

		// Add a log entry
		$this->log('Purged the search cache', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the internal cache
	 */
	public function purgeInternalCache()
	{
		// Check whether the cache exists
		if (is_dir(TL_ROOT . '/system/cache/dca'))
		{
			foreach (array('config', 'dca', 'language', 'packages', 'sql') as $dir)
			{
				// Purge the folder
				$objFolder = new \Folder('system/cache/' . $dir);
				$objFolder->delete();
			}
		}

		// Add a log entry
		$this->log('Purged the internal cache', __METHOD__, TL_CRON);
	}


	/**
	 * Purge the temp folder
	 */
	public function purgeTempFolder()
	{
		// Purge the folder
		$objFolder = new \Folder('system/tmp');
		$objFolder->purge();

		// Add a log entry
		$this->log('Purged the temp folder', __METHOD__, TL_CRON);
	}


	/**
	 * Regenerate the XML files
	 */
	public function generateXmlFiles()
	{
		// Sitemaps
		$this->generateSitemap();

		// HOOK: add custom jobs
		if (isset($GLOBALS['TL_HOOKS']['generateXmlFiles']) && is_array($GLOBALS['TL_HOOKS']['generateXmlFiles']))
		{
			foreach ($GLOBALS['TL_HOOKS']['generateXmlFiles'] as $callback)
			{
				$this->import($callback[0]);
				$this->$callback[0]->$callback[1]();
			}
		}

		// Also empty the page cache so there are no links to deleted files
		$this->purgePageCache();

		// Add a log entry
		$this->log('Regenerated the XML files', __METHOD__, TL_CRON);
	}


	/**
	 * Remove old XML files from the share directory
	 *
	 * @param boolean $blnReturn If true, only return the finds and don't delete
	 *
	 * @return array An array of old XML files
	 */
	public function purgeXmlFiles($blnReturn=false)
	{
		$arrFeeds = array();
		$objDatabase = \Database::getInstance();

		// XML sitemaps
		$objFeeds = $objDatabase->execute("SELECT sitemapName FROM tl_page WHERE type='root' AND createSitemap=1 AND sitemapName!=''");

		while ($objFeeds->next())
		{
			$arrFeeds[] = $objFeeds->sitemapName;
		}

		// HOOK: preserve third party feeds
		if (isset($GLOBALS['TL_HOOKS']['removeOldFeeds']) && is_array($GLOBALS['TL_HOOKS']['removeOldFeeds']))
		{
			foreach ($GLOBALS['TL_HOOKS']['removeOldFeeds'] as $callback)
			{
				$this->import($callback[0]);
				$arrFeeds = array_merge($arrFeeds, $this->$callback[0]->$callback[1]());
			}
		}

		// Delete the old files
		if (!$blnReturn)
		{
			foreach (scan(TL_ROOT . '/web/share') as $file)
			{
				if (is_dir(TL_ROOT . '/web/share/' . $file))
				{
					continue; // see #6652
				}

				$objFile = new \File('web/share/' . $file);

				if ($objFile->extension == 'xml' && !in_array($objFile->filename, $arrFeeds))
				{
					$objFile->delete();
				}
			}
		}

		return $arrFeeds;
	}


	/**
	 * Generate the Google XML sitemaps
	 *
	 * @param integer $intId The root page ID
	 */
	public function generateSitemap($intId=0)
	{
		$time = time();
		$objDatabase = \Database::getInstance();

		$this->purgeXmlFiles();

		// Only root pages should have sitemap names
		$objDatabase->execute("UPDATE tl_page SET createSitemap='', sitemapName='' WHERE type!='root'");

		// Get a particular root page
		if ($intId > 0)
		{
			do
			{
				$objRoot = $objDatabase->prepare("SELECT * FROM tl_page WHERE id=?")
									   ->limit(1)
									   ->execute($intId);

				if ($objRoot->numRows < 1)
				{
					break;
				}

				$intId = $objRoot->pid;
			}
			while ($objRoot->type != 'root' && $intId > 0);

			// Make sure the page is published
			if (!$objRoot->published || ($objRoot->start != '' && $objRoot->start > $time) || ($objRoot->stop != '' && $objRoot->stop < $time))
			{
				return;
			}

			// Check the sitemap name
			if (!$objRoot->createSitemap || !$objRoot->sitemapName)
			{
				return;
			}

			$objRoot->reset();
		}

		// Get all published root pages
		else
		{
			$objRoot = $objDatabase->execute("SELECT id, dns, language, useSSL, sitemapName FROM tl_page WHERE type='root' AND createSitemap=1 AND sitemapName!='' AND (start='' OR start<$time) AND (stop='' OR stop>$time) AND published=1");
		}

		// Return if there are no pages
		if ($objRoot->numRows < 1)
		{
			return;
		}

		// Create the XML file
		while ($objRoot->next())
		{
			$objFile = new \File('web/share/' . $objRoot->sitemapName . '.xml');

			$objFile->truncate();
			$objFile->append('<?xml version="1.0" encoding="UTF-8"?>');
			$objFile->append('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">');

			// Set the domain (see #6421)
			$strDomain = ($objRoot->useSSL ? 'https://' : 'http://') . ($objRoot->dns ?: \Environment::get('host')) . \Environment::get('path') . '/';

			// Find the searchable pages
			$arrPages = \Backend::findSearchablePages($objRoot->id, $strDomain, true, $objRoot->language);

			// HOOK: take additional pages
			if (isset($GLOBALS['TL_HOOKS']['getSearchablePages']) && is_array($GLOBALS['TL_HOOKS']['getSearchablePages']))
			{
				foreach ($GLOBALS['TL_HOOKS']['getSearchablePages'] as $callback)
				{
					$this->import($callback[0]);
					$arrPages = $this->$callback[0]->$callback[1]($arrPages, $objRoot->id, true, $objRoot->language);
				}
			}

			// Add pages
			foreach ($arrPages as $strUrl)
			{
				$strUrl = rawurlencode($strUrl);
				$strUrl = str_replace(array('%2F', '%3F', '%3D', '%26', '%3A//'), array('/', '?', '=', '&', '://'), $strUrl);
				$strUrl = ampersand($strUrl, true);

				$objFile->append('  <url><loc>' . $strUrl . '</loc></url>');
			}

			$objFile->append('</urlset>');
			$objFile->close();

			// Add a log entry
			$this->log('Generated sitemap "' . $objRoot->sitemapName . '.xml"', __METHOD__, TL_CRON);
		}
	}


	/**
	 * Rotate the log files
	 */
	public function rotateLogs()
	{
		$arrFiles = preg_grep('/\.log$/', scan(TL_ROOT . '/system/logs'));

		foreach ($arrFiles as $strFile)
		{
			$objFile = new \File('system/logs/' . $strFile . '.9');

			// Delete the oldest file
			if ($objFile->exists())
			{
				$objFile->delete();
			}

			// Rotate the files (e.g. error.log.4 becomes error.log.5)
			for ($i=8; $i>0; $i--)
			{
				$strGzName = 'system/logs/' . $strFile . '.' . $i;

				if (file_exists(TL_ROOT . '/' . $strGzName))
				{
					$objFile = new \File($strGzName);
					$objFile->renameTo('system/logs/' . $strFile . '.' . ($i+1));
				}
			}

			// Add .1 to the latest file
			$objFile = new \File('system/logs/' . $strFile);
			$objFile->renameTo('system/logs/' . $strFile . '.1');
		}
	}


	/**
	 * Generate the symlinks in the web/ folder
	 */
	public function generateSymlinks()
	{
		/** @var KernelInterface $kernel */
		global $kernel;

		$container = $kernel->getContainer();

		$command = new SymlinksCommand();
		$command->setContainer($container);
		$command->generateSymlinks(dirname($container->getParameter('kernel.root_dir')), new NullOutput());
	}


	/**
	 * Build the internal cache
	 */
	public function generateInternalCache()
	{
		// Purge
		$this->purgeInternalCache();

		// Rebuild
		$this->generateConfigCache();
		$this->generateDcaCache();
		$this->generateLanguageCache();
		$this->generateDcaExtracts();
		$this->generatePackageCache();
	}


	/**
	 * Create the config cache files
	 */
	public function generateConfigCache()
	{
		/** @var KernelInterface $kernel */
		global $kernel;

		$resources = $kernel->getContainer()->get('contao.resource_provider');

		// Generate the class/template laoder cache file
		$objCacheFile = new \File('system/cache/config/autoload.php');
		$objCacheFile->write('<?php '); // add one space to prevent the "unexpected $end" error

		foreach ($resources->findFiles('config/autoload.php') as $file)
		{
			$objCacheFile->append(static::readPhpFileWithoutTags($file->getPathname()));
		}

		// Close the file (moves it to its final destination)
		$objCacheFile->close();

		// Generate the config cache file
		$objCacheFile = new \File('system/cache/config/config.php');
		$objCacheFile->write('<?php '); // add one space to prevent the "unexpected $end" error

		foreach ($resources->findFiles('config/config.php') as $file)
		{
			$objCacheFile->append(static::readPhpFileWithoutTags($file->getPathname()));
		}

		// Close the file (moves it to its final destination)
		$objCacheFile->close();

		// Generate the page mapping array
		$arrMapper = array();
		$objPages = \PageModel::findPublishedRootPages();

		if ($objPages !== null)
		{
			while ($objPages->next())
			{
				if ($objPages->dns != '')
				{
					$strBase = $objPages->useSSL ? 'https://' : 'http://';
					$strBase .= $objPages->dns . \Environment::get('path') . '/';
				}
				else
				{
					$strBase = \Environment::get('base');
				}

				if ($objPages->fallback)
				{
					$arrMapper[$strBase . 'empty.fallback'] = $strBase . 'empty.' . $objPages->language;
				}

				$arrMapper[$strBase . 'empty.' . $objPages->language] = $strBase . 'empty.' . $objPages->language;
			}
		}

		// Generate the page mapper file
		$objCacheFile = new \File('system/cache/config/mapping.php', true);
		$objCacheFile->write(sprintf("<?php\n\nreturn %s;\n", var_export($arrMapper, true)));
		$objCacheFile->close();

		// Add a log entry
		$this->log('Generated the config cache', __METHOD__, TL_CRON);
	}


	/**
	 * Create the data container cache files
	 */
	public function generateDcaCache()
	{
		/** @var KernelInterface $kernel */
		global $kernel;

		/** @var \File[] $cacheFiles */
		$cacheFiles = [];

		foreach ($kernel->getContainer()->get('contao.resource_provider')->findFiles('dca/*.php') as $file)
		{
			$fileName = $file->getFilename();

			if (!isset($cacheFiles[$fileName])) {
				$cacheFiles[$fileName] = new \File('system/cache/dca/' . $fileName);
				$cacheFiles[$fileName]->write('<?php '); // add one space to prevent the "unexpected $end" error
			}

			$cacheFiles[$fileName]->append(static::readPhpFileWithoutTags($file->getPathname()));
		}

		foreach ($cacheFiles as $cacheFile) {
			$cacheFile->close();
		}

		// Add a log entry
		$this->log('Generated the DCA cache', __METHOD__, TL_CRON);
	}


	/**
	 * Create the language cache files
	 */
	public function generateLanguageCache()
	{
		/** @var KernelInterface $kernel */
		global $kernel;

		$arrLanguages = array();
		$objLanguages = \Database::getInstance()->query("SELECT language FROM tl_member UNION SELECT language FROM tl_user UNION SELECT REPLACE(language, '-', '_') FROM tl_page WHERE type='root'");

		// Only cache the languages which are in use (see #6013)
		while ($objLanguages->next())
		{
			if ($objLanguages->language == '')
			{
				continue;
			}

			$arrLanguages[] = $objLanguages->language;

			// Also cache "de" if "de-CH" is requested
			if (strlen($objLanguages->language) > 2)
			{
				$arrLanguages[] = substr($objLanguages->language, 0, 2);
			}
		}

		$arrLanguages = array_unique($arrLanguages);
		$arrResourcesPaths = $kernel->getContainer()->get('contao.resource_provider')->getResourcesPaths();

		foreach ($arrLanguages as $strLanguage)
		{
			$arrFiles = array();

			// Parse all active modules
			foreach ($arrResourcesPaths as $strFolder)
			{
				$strDir = $strFolder . '/languages/' . $strLanguage;

				if (!is_dir($strDir))
				{
					continue;
				}

				foreach (scan($strDir) as $strFile)
				{
					if (strncmp($strFile, '.', 1) !== 0 && (substr($strFile, -4) == '.php' || substr($strFile, -4) == '.xlf'))
					{
						$arrFiles[] = substr($strFile, 0, -4);
					}
				}
			}

			$arrFiles = array_values(array_unique($arrFiles));

			// Create one file per table
			foreach ($arrFiles as $strName)
			{
				$strCacheFile = 'system/cache/language/' . $strLanguage . '/' . $strName . '.php';

				// Add a short header with links to transifex.com
				$strHeader = "<?php\n\n"
						   . "/**\n"
						   . " * Contao Open Source CMS\n"
						   . " * \n"
						   . " * Copyright (c) 2005-2015 Leo Feyer\n"
						   . " * \n"
						   . " * Core translations are managed using Transifex. To create a new translation\n"
						   . " * or to help to maintain an existing one, please register at transifex.com.\n"
						   . " * \n"
						   . " * @link http://help.transifex.com/intro/translating.html\n"
						   . " * @link https://www.transifex.com/projects/p/contao/language/%s/\n"
						   . " * \n"
						   . " * @license LGPL-3.0+\n"
						   . " */\n";

				// Generate the cache file
				$objCacheFile = new \File($strCacheFile);
				$objCacheFile->write(sprintf($strHeader, $strLanguage));

				// Parse all active modules and append to the cache file
				foreach ($arrResourcesPaths as $strFolder)
				{
					$strFile = $strFolder . '/languages/' . $strLanguage . '/' . $strName;

					if (file_exists($strFile . '.xlf'))
					{
						$objCacheFile->append(static::convertXlfToPhp($strFile . '.xlf', $strLanguage));
					}
					elseif (file_exists($strFile . '.php'))
					{
						$objCacheFile->append(static::readPhpFileWithoutTags($strFile . '.php'));
					}
				}

				// Close the file (moves it to its final destination)
				$objCacheFile->close();
			}
		}

		// Add a log entry
		$this->log('Generated the language cache', __METHOD__, TL_CRON);
	}


	/**
	 * Create the DCA extract cache files
	 */
	public function generateDcaExtracts()
	{
		/** @var KernelInterface $kernel */
		global $kernel;

		$included = array();
		$arrExtracts = array();

		// Only check the active modules (see #4541)
		foreach ($kernel->getContainer()->get('contao.resource_provider')->getResourcesPaths() as $strFolder)
		{
			$strDir = $strFolder . '/dca';

			if (!is_dir($strDir))
			{
				continue;
			}

			foreach (scan($strDir) as $strFile)
			{
				// Ignore non PHP files and files which have been included before
				if (strncmp($strFile, '.', 1) === 0 || substr($strFile, -4) != '.php' || in_array($strFile, $included))
				{
					continue;
				}

				$strTable = substr($strFile, 0, -4);
				$objExtract = \DcaExtractor::getInstance($strTable);

				if ($objExtract->isDbTable())
				{
					$arrExtracts[$strTable] = $objExtract;
				}

				$included[] = $strFile;
			}
		}

		/** @var \DcaExtractor[] $arrExtracts */
		foreach ($arrExtracts as $strTable=>$objExtract)
		{
			// Create the file
			$objFile = new \File('system/cache/sql/' . $strTable . '.php');
			$objFile->write("<?php\n\n");

			// Meta
			$arrMeta = $objExtract->getMeta();

			$objFile->append("\$this->arrMeta = array\n(");
			$objFile->append("\t'engine' => '{$arrMeta['engine']}',");
			$objFile->append("\t'charset' => '{$arrMeta['charset']}',");
			$objFile->append(');', "\n\n");

			// Fields
			$arrFields = $objExtract->getFields();
			$objFile->append("\$this->arrFields = array\n(");

			foreach ($arrFields as $field=>$sql)
			{
				$sql = str_replace('"', '\"', $sql);
				$objFile->append("\t'$field' => \"$sql\",");
			}

			$objFile->append(');', "\n\n");

			// Order fields
			$arrFields = $objExtract->getOrderFields();
			$objFile->append("\$this->arrOrderFields = array\n(");

			foreach ($arrFields as $field)
			{
				$objFile->append("\t'$field',");
			}

			$objFile->append(');', "\n\n");

			// Keys
			$arrKeys = $objExtract->getKeys();
			$objFile->append("\$this->arrKeys = array\n(");

			foreach ($arrKeys as $field=>$type)
			{
				$objFile->append("\t'$field' => '$type',");
			}

			$objFile->append(');', "\n\n");

			// Relations
			$arrRelations = $objExtract->getRelations();
			$objFile->append("\$this->arrRelations = array\n(");

			foreach ($arrRelations as $field=>$config)
			{
				$objFile->append("\t'$field' => array\n\t(");

				foreach ($config as $k=>$v)
				{
					$objFile->append("\t\t'$k' => '$v',");
				}

				$objFile->append("\t),");
			}

			$objFile->append(');', "\n\n");

			// Set the database table flag
			$objFile->append("\$this->blnIsDbTable = true;", "\n");

			// Close the file (moves it to its final destination)
			$objFile->close();
		}

		// Add a log entry
		$this->log('Generated the DCA extracts', __METHOD__, TL_CRON);
	}


	/**
	 * Create the packages cache
	 */
	public function generatePackageCache()
	{
		$objFile = new \File('system/cache/packages/installed.php');
		$objFile->write("<?php\n\nreturn array\n(\n");

		$objJson = json_decode(file_get_contents(TL_ROOT . '/vendor/composer/installed.json'), true);

		foreach ($objJson as $objPackage)
		{
			$strName = str_replace("'", "\\'", $objPackage['name']);
			$strVersion = substr($objPackage['version_normalized'], 0, strrpos($objPackage['version_normalized'], '.'));

			if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $strVersion))
			{
				$objFile->append("\t'$strName' => '$strVersion',");
			}
			elseif (isset($objPackage['extra']['branch-alias'][$objPackage['version_normalized']]))
			{
				$strVersion = str_replace('x-dev', '9999999', $objPackage['extra']['branch-alias'][$objPackage['version_normalized']]);

				if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $strVersion))
				{
					$objFile->append("\t'$strName' => '$strVersion',");
				}
			}
		}

		$objFile->append(');');
		$objFile->close();
	}
}
