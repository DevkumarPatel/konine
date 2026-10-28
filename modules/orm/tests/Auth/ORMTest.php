<?php

/**
 * Tests the Auth_ORM and Auth_Bcrypt drivers against an in-memory SQLite
 * database
 *
 * @package    Kohana/Auth
 * @group      kohana
 * @group      kohana.auth
 * @group      kohana.orm
 * @category   Test
 * @author     Kohana Team
 * @copyright  (c) Kohana Team
 * @license    https://github.com/hospicedev/konine/blob/master/LICENSE.md
 */

class Kohana_Auth_ORMTest extends Unittest_TestCase
{
	/**
	 * @var Database
	 */
	protected $db;

	/**
	 * Driver configuration shared by the tests
	 * @var array
	 */
	protected $config = [
		'hash_method'  => 'sha256',
		'hash_key'     => 'unittest-key',
		'lifetime'     => 1209600,
		'session_type' => 'unittest_auth',
		'session_key'  => 'auth_user',
		'cost'         => 10,
	];

	public function setUp(): void
	{
		parent::setUp();

		if ( ! extension_loaded('pdo_sqlite'))
		{
			$this->markTestSkipped('The pdo_sqlite extension is not available');
		}

		$this->db = Database::instance('unittest_auth', [
			'type'         => 'PDO',
			'connection'   => [
				'dsn'        => 'sqlite::memory:',
				'persistent' => FALSE,
			],
			'table_prefix' => '',
			'caching'      => FALSE,
		]);

		// Model_User and Model_Role use the default database group, and ORM
		// caches each model's connection and columns. Database_PDO cannot
		// list columns, so they are provided here. Model_Auth_User::filters()
		// calls Auth::instance(), which must not start a native session.
		$this->setEnvironment([
			'Database::$default'         => 'unittest_auth',
			'Kohana_ORM::$_init_cache'   => [],
			'Kohana_ORM::$_column_cache' => [
				'user' => array_fill_keys(['id', 'email', 'username', 'password', 'logins', 'last_login'], ['type' => 'string']),
				'role' => array_fill_keys(['id', 'name', 'description'], ['type' => 'string']),
			],
			'Kohana_Auth::$_instance'    => NULL,
			'Session::$instances'        => ['unittest_auth' => new Session_Auth_Example] + Session::$instances,
			'auth.session_type'          => 'unittest_auth',
		]);

		$statements = [
			'CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, description TEXT NOT NULL)',
			'CREATE TABLE roles_users (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL, PRIMARY KEY (user_id, role_id))',
			"CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, username TEXT NOT NULL DEFAULT '' UNIQUE, password TEXT NOT NULL, logins INTEGER NOT NULL DEFAULT 0, last_login INTEGER)",
			'CREATE TABLE user_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, user_agent TEXT NOT NULL, token TEXT NOT NULL UNIQUE, created INTEGER NOT NULL, expires INTEGER NOT NULL)',
			"INSERT INTO roles (id, name, description) VALUES (1, 'login', 'Login privileges'), (2, 'admin', 'Administrative user')",
		];

		foreach ($statements as $sql)
		{
			$this->db->query(NULL, $sql);
		}
	}

	public function tearDown(): void
	{
		if ($this->db)
		{
			$this->db->disconnect();
			$this->db = NULL;
		}

		unset(Database::$instances['unittest_auth']);

		parent::tearDown();
	}

	/**
	 * Inserts a user with the given password hash and role ids.
	 *
	 * @param  string $username
	 * @param  string $password Stored password hash
	 * @param  array  $roles    Role ids
	 * @return void
	 */
	protected function insert_user($username, $password, array $roles)
	{
		list($id) = DB::insert('users', ['username', 'email', 'password'])
			->values([$username, $username.'@example.com', $password])
			->execute($this->db);

		foreach ($roles as $role)
		{
			DB::insert('roles_users', ['user_id', 'role_id'])
				->values([$id, $role])
				->execute($this->db);
		}
	}

	/**
	 * @test
	 * @covers Auth_ORM::_login
	 * @covers Auth_ORM::logged_in
	 */
	public function test_orm_login_succeeds_for_user_with_login_role()
	{
		$auth = new Auth_ORM($this->config);

		$this->insert_user('alice', $auth->hash('password'), [1]);

		$this->assertFalse($auth->logged_in());
		$this->assertTrue($auth->login('alice', 'password'));

		$this->assertTrue($auth->logged_in());
		$this->assertTrue($auth->logged_in('login'));
		$this->assertFalse($auth->logged_in('admin'));
		$this->assertSame('alice', $auth->get_user()->username);

		$logins = DB::select('logins')
			->from('users')
			->where('username', '=', 'alice')
			->execute($this->db)
			->get('logins');

		$this->assertEquals(1, $logins);
	}

	/**
	 * @test
	 * @covers Auth_ORM::_login
	 */
	public function test_orm_login_fails_for_wrong_password_or_missing_login_role()
	{
		$auth = new Auth_ORM($this->config);

		$this->insert_user('alice', $auth->hash('password'), [1]);
		$this->insert_user('bob', $auth->hash('password'), [2]);

		$this->assertFalse($auth->login('alice', 'wrong-password'));
		$this->assertFalse($auth->login('bob', 'password'));
		$this->assertFalse($auth->logged_in());
	}

	/**
	 * @test
	 * @covers Auth_ORM::logout
	 */
	public function test_orm_logout_clears_logged_in_user()
	{
		$auth = new Auth_ORM($this->config);

		$this->insert_user('alice', $auth->hash('password'), [1]);
		$auth->login('alice', 'password');

		$this->assertTrue($auth->logout());
		$this->assertFalse($auth->logged_in());
		$this->assertNull($auth->get_user());
	}

	/**
	 * @test
	 * @covers Auth_Bcrypt::hash
	 */
	public function test_bcrypt_hash_verifies_with_password_verify()
	{
		$auth = new Auth_Bcrypt($this->config);

		$hash = $auth->hash('password');

		$this->assertTrue(password_verify('password', $hash));
		$this->assertFalse(password_verify('wrong-password', $hash));
		$this->assertSame(10, password_get_info($hash)['options']['cost']);
	}

	/**
	 * @test
	 * @covers Auth_Bcrypt::_login
	 */
	public function test_bcrypt_login_checks_password()
	{
		$auth = new Auth_Bcrypt($this->config);

		$this->insert_user('alice', $auth->hash('password'), [1]);

		$this->assertFalse($auth->login('alice', 'wrong-password'));
		$this->assertFalse($auth->logged_in());

		$this->assertTrue($auth->login('alice', 'password'));
		$this->assertTrue($auth->logged_in());
		$this->assertSame('alice', $auth->get_user()->username);
	}
}

/**
 * Session kept in memory, so the tests never start a native PHP session.
 */
class Session_Auth_Example extends Session
{
	protected function _read($id = NULL)
	{
		return NULL;
	}

	protected function _regenerate()
	{
		return 'unittest';
	}

	protected function _write()
	{
		return TRUE;
	}

	protected function _destroy()
	{
		return TRUE;
	}

	protected function _restart()
	{
		return TRUE;
	}
}
