<?php

namespace Friendica\Test\src\App;

use Detection\MobileDetect;
use Friendica\App\Mode;
use Friendica\App\Module;
use Friendica\Core\Config;
use Friendica\Database\Database;
use Friendica\Test\MockedTest;
use Friendica\Test\Util\DBAMockTrait;
use Friendica\Test\Util\VFSTrait;
use Friendica\Util\BasePath;
use Mockery\MockInterface;

class ModeTest extends MockedTest
{
	use VFSTrait;
	use DBAMockTrait;

	/**
	 * @var BasePath|MockInterface
	 */
	private $basePathMock;

	/**
	 * @var Database|MockInterface
	 */
	private $databaseMock;

	/**
	 * @var Config\Cache\ConfigCache|MockInterface
	 */
	private $configCacheMock;

	public function setUp()
	{
		parent::setUp();

		$this->setUpVfsDir();

		$this->basePathMock = \Mockery::mock(BasePath::class);
		$this->databaseMock = \Mockery::mock(Database::class);
		$this->configCacheMock = \Mockery::mock(Config\Cache\ConfigCache::class);
	}

	public function testItEmpty()
	{
		$mode = new Mode();
		$this->assertTrue($mode->isInstall());
		$this->assertFalse($mode->isNormal());
	}

	public function testWithoutConfig()
	{
		$this->basePathMock->shouldReceive('getPath')->andReturn($this->root->url())->once();

		$this->assertTrue($this->root->hasChild('config/local.config.php'));

		$this->delConfigFile('local.config.php');

		$this->assertFalse($this->root->hasChild('config/local.config.php'));

		$mode = (new Mode())->determine($this->basePathMock, $this->databaseMock, $this->configCacheMock);

		$this->assertTrue($mode->isInstall());
		$this->assertFalse($mode->isNormal());

		$this->assertFalse($mode->has(Mode::LOCALCONFIGPRESENT));
	}

	public function testWithoutDatabase()
	{
		$this->basePathMock->shouldReceive('getPath')->andReturn($this->root->url())->once();

		$this->databaseMock->shouldReceive('connected')->andReturn(false)->once();

		$mode = (new Mode())->determine($this->basePathMock, $this->databaseMock, $this->configCacheMock);

		$this->assertFalse($mode->isNormal());
		$this->assertTrue($mode->isInstall());

		$this->assertTrue($mode->has(Mode::LOCALCONFIGPRESENT));
		$this->assertFalse($mode->has(Mode::DBAVAILABLE));
	}

	public function testWithoutDatabaseSetup()
	{
		$this->basePathMock->shouldReceive('getPath')->andReturn($this->root->url())->once();

		$this->databaseMock->shouldReceive('connected')->andReturn(true)->once();
		$this->databaseMock->shouldReceive('fetchFirst')
		                   ->with('SHOW TABLES LIKE \'config\'')->andReturn(false)->once();

		$mode = (new Mode())->determine($this->basePathMock, $this->databaseMock, $this->configCacheMock);

		$this->assertFalse($mode->isNormal());
		$this->assertTrue($mode->isInstall());

		$this->assertTrue($mode->has(Mode::LOCALCONFIGPRESENT));
	}

	public function testWithMaintenanceMode()
	{
		$this->basePathMock->shouldReceive('getPath')->andReturn($this->root->url())->once();

		$this->databaseMock->shouldReceive('connected')->andReturn(true)->once();
		$this->databaseMock->shouldReceive('fetchFirst')
		                   ->with('SHOW TABLES LIKE \'config\'')->andReturn(true)->once();
		$this->configCacheMock->shouldReceive('get')->with('system', 'maintenance')
		                      ->andReturn(true)->once();

		$mode = (new Mode())->determine($this->basePathMock, $this->databaseMock, $this->configCacheMock);

		$this->assertFalse($mode->isNormal());
		$this->assertFalse($mode->isInstall());

		$this->assertTrue($mode->has(Mode::DBCONFIGAVAILABLE));
		$this->assertFalse($mode->has(Mode::MAINTENANCEDISABLED));
	}

	public function testNormalMode()
	{
		$this->basePathMock->shouldReceive('getPath')->andReturn($this->root->url())->once();

		$this->databaseMock->shouldReceive('connected')->andReturn(true)->once();
		$this->databaseMock->shouldReceive('fetchFirst')
		                   ->with('SHOW TABLES LIKE \'config\'')->andReturn(true)->once();
		$this->configCacheMock->shouldReceive('get')->with('system', 'maintenance')
		                      ->andReturn(false)->once();
		$this->databaseMock->shouldReceive('selectFirst')
		                   ->with('config', ['v'], ['cat' => 'system', 'k' => 'maintenance'])
		                   ->andReturn(['v' => null])->once();

		$mode = (new Mode())->determine($this->basePathMock, $this->databaseMock, $this->configCacheMock);

		$this->assertTrue($mode->isNormal());
		$this->assertFalse($mode->isInstall());

		$this->assertTrue($mode->has(Mode::DBCONFIGAVAILABLE));
		$this->assertTrue($mode->has(Mode::MAINTENANCEDISABLED));
	}

	/**
	 * Test explicit disabled maintenance (in case you manually disable it)
	 */
	public function testDisabledMaintenance()
	{
		$this->basePathMock->shouldReceive('getPath')->andReturn($this->root->url())->once();

		$this->databaseMock->shouldReceive('connected')->andReturn(true)->once();
		$this->databaseMock->shouldReceive('fetchFirst')
		                   ->with('SHOW TABLES LIKE \'config\'')->andReturn(true)->once();
		$this->configCacheMock->shouldReceive('get')->with('system', 'maintenance')
		                      ->andReturn(false)->once();
		$this->databaseMock->shouldReceive('selectFirst')
		                   ->with('config', ['v'], ['cat' => 'system', 'k' => 'maintenance'])
		                   ->andReturn(['v' => '0'])->once();

		$mode = (new Mode())->determine($this->basePathMock, $this->databaseMock, $this->configCacheMock);

		$this->assertTrue($mode->isNormal());
		$this->assertFalse($mode->isInstall());

		$this->assertTrue($mode->has(Mode::DBCONFIGAVAILABLE));
		$this->assertTrue($mode->has(Mode::MAINTENANCEDISABLED));
	}

	/**
	 * Test that modes are immutable
	 */
	public function testImmutable()
	{
		$this->basePathMock->shouldReceive('getPath')->andReturn(null)->once();

		$mode = new Mode();

		$modeNew = $mode->determine($this->basePathMock, $this->databaseMock, $this->configCacheMock);

		$this->assertNotSame($modeNew, $mode);
	}

	/**
	 * Test if not called by index is backend
	 */
	public function testIsBackendNotIndex()
	{
		$server = ['PHP_SELF' => '/daemon.php'];
		$module = new Module();
		$mobileDetect = new MobileDetect();

		$mode = (new Mode())->determineRunMode($module, $server, $mobileDetect);

		$this->assertTrue($mode->isBackend());
	}

	/**
	 * Test is called by index but module is backend
	 */
	public function testIsBackendButIndex()
	{
		$server = ['PHP_SELF' => '/index.php'];
		$module = new Module(Module::DEFAULT, Module::DEFAULT_CLASS, true);
		$mobileDetect = new MobileDetect();

		$mode = (new Mode())->determineRunMode($module, $server, $mobileDetect);

		$this->assertTrue($mode->isBackend());
	}

	/**
	 * Test is called by index and module is not backend
	 */
	public function testIsNotBackend()
	{
		$server = ['PHP_SELF' => '/index.php'];
		$module = new Module(Module::DEFAULT, Module::DEFAULT_CLASS, false);
		$mobileDetect = new MobileDetect();

		$mode = (new Mode())->determineRunMode($module, $server, $mobileDetect);

		$this->assertFalse($mode->isBackend());
	}

	/**
	 * Test if the call is an ajax call
	 */
	public function testIsAjax()
	{
		// This is the server environment variable to determine ajax calls
		$server = [
			'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest',
		];

		$module = new Module(Module::DEFAULT, Module::DEFAULT_CLASS, false);
		$mobileDetect = new MobileDetect();

		$mode = (new Mode())->determineRunMode($module, $server, $mobileDetect);

		$this->assertTrue($mode->isAjax());
	}

	/**
	 * Test if the call is not nan ajax call
	 */
	public function testIsNotAjax()
	{
		$server = [];
		$module = new Module(Module::DEFAULT, Module::DEFAULT_CLASS, false);
		$mobileDetect = new MobileDetect();

		$mode = (new Mode())->determineRunMode($module, $server, $mobileDetect);

		$this->assertFalse($mode->isAjax());
	}

	/**
	 * Test if the call is a mobile and is a tablet call
	 */
	public function testIsMobileIsTablet()
	{
		$server = [];
		$module = new Module(Module::DEFAULT, Module::DEFAULT_CLASS, false);
		$mobileDetect = \Mockery::mock(MobileDetect::class);
		$mobileDetect->shouldReceive('isMobile')->andReturn(true);
		$mobileDetect->shouldReceive('isTablet')->andReturn(true);

		$mode = (new Mode())->determineRunMode($module, $server, $mobileDetect);

		$this->assertTrue($mode->isMobile());
		$this->assertTrue($mode->isTablet());
	}


	/**
	 * Test if the call is not a mobile and is not a tablet call
	 */
	public function testIsNotMobileIsNotTablet()
	{
		$server = [];
		$module = new Module(Module::DEFAULT, Module::DEFAULT_CLASS, false);
		$mobileDetect = \Mockery::mock(MobileDetect::class);
		$mobileDetect->shouldReceive('isMobile')->andReturn(false);
		$mobileDetect->shouldReceive('isTablet')->andReturn(false);

		$mode = (new Mode())->determineRunMode($module, $server, $mobileDetect);

		$this->assertFalse($mode->isMobile());
		$this->assertFalse($mode->isTablet());
	}
}
