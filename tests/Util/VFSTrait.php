<?php

namespace Friendica\Test\Util;


use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;

trait VFSTrait
{
	/**
	 * @var vfsStreamDirectory The Stream Directory
	 */
	protected $root;

	/**
	 * Sets up the Virtual File System for Friendica with common files (config, dbstructure)
	 */
	protected function setUpVfsDir() {
		// the used directories inside the App class
		$structure = [
			'config' => [],
			'bin' => [],
			'static' => [],
			'test' => [],
		];

		// create a virtual directory and copy all needed files and folders to it
		$this->root = vfsStream::setup('friendica', 0777, $structure);

		$this->setConfigFile('dbstructure.config.php', true);
		$this->setConfigFile('defaults.config.php', true);
		$this->setConfigFile('settings.config.php', true);
		$this->setConfigFile('local.config.php');
	}

	/**
	 * Copying a config file from the file system to the Virtual File System
	 *
	 * @param string $filename The filename of the config file
	 * @param bool $static True, if the folder `static` instead of `config` should be used
	 */
	protected function setConfigFile($filename, bool $static = false)
	{
		$file = dirname(__DIR__) . DIRECTORY_SEPARATOR .
			'..' . DIRECTORY_SEPARATOR .
			($static ? 'static' : 'config') . DIRECTORY_SEPARATOR .
			$filename;

		if (file_exists($file)) {
			vfsStream::newFile($filename)
				->at($this->root->getChild(($static ? 'static' : 'config')))
				->setContent(file_get_contents($file));
		}
	}

	/**
	 * Delets a config file from the Virtual File System
	 *
	 * @param string $filename The filename of the config file
	 * @param bool $static True, if the folder `static` instead of `config` should be used
	 */
	protected function delConfigFile($filename, bool $static = false)
	{
		if ($this->root->hasChild(($static ? 'static' : 'config') . '/' . $filename)) {
			$this->root->getChild(($static ? 'static' : 'config'))->removeChild($filename);
		}
	}
}
