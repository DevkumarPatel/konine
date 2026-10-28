<?php

/**
 * Test case for Kohana_Encrypt with the OpenSSL engine
 *
 * @package    Kohana/Encrypt
 * @group      kohana
 * @group      kohana.encrypt
 * @category   Test
 * @author     Kohana Team
 * @copyright  (c) Kohana Team
 * @license    https://github.com/hospicedev/konine/blob/master/LICENSE.md
 */

class Kohana_EncryptTest extends Unittest_TestCase
{
	/**
	 * 32 byte key for the default AES-256-CBC cipher
	 * @var string
	 */
	protected $key = 'test-key-0123456789-test-key-012';

	/**
	 * Provides test data for test_decode_returns_encoded_text()
	 *
	 * @return array
	 */
	public function provider_decode_returns_encoded_text()
	{
		return [
			// [cipher, key, text]
			['AES-256-CBC', 'test-key-0123456789-test-key-012', 'test text'],
			['AES-256-CBC', 'test-key-0123456789-test-key-012', ''],
			['AES-256-CBC', 'test-key-0123456789-test-key-012', "binary \x00\xff data and ünïcödé"],
			['AES-128-CBC', 'test-key-0123456', str_repeat('more than one block ', 10)],
		];
	}

	/**
	 * @test
	 * @dataProvider provider_decode_returns_encoded_text
	 * @covers Encrypt::encode
	 * @covers Encrypt::decode
	 */
	public function test_decode_returns_encoded_text($cipher, $key, $text)
	{
		$encrypt = new Encrypt(['key' => $key, 'cipher' => $cipher]);

		$encoded = $encrypt->encode($text);

		$this->assertNotSame($text, $encoded);
		$this->assertSame($text, $encrypt->decode($encoded));
	}

	/**
	 * Each encode uses a random IV, so the same text must not produce the
	 * same output twice.
	 *
	 * @test
	 * @covers Encrypt::encode
	 */
	public function test_encode_is_not_deterministic()
	{
		$encrypt = new Encrypt(['key' => $this->key]);

		$this->assertNotSame($encrypt->encode('test text'), $encrypt->encode('test text'));
	}

	/**
	 * @test
	 * @covers Encrypt::decode
	 */
	public function test_decode_with_different_key_returns_false()
	{
		$encoded = (new Encrypt(['key' => $this->key]))->encode('test text');

		$encrypt = new Encrypt(['key' => 'other-key-0123456789-other-key-0']);

		$this->assertFalse($encrypt->decode($encoded));
	}

	/**
	 * @test
	 * @covers Encrypt::decode
	 */
	public function test_decode_tampered_data_returns_false()
	{
		$encrypt = new Encrypt(['key' => $this->key]);

		$payload = json_decode(base64_decode($encrypt->encode('test text')), TRUE);

		// Swap in the ciphertext of another text but keep the original MAC
		$other = json_decode(base64_decode($encrypt->encode('evil text')), TRUE);
		$payload['value'] = $other['value'];

		$this->assertFalse($encrypt->decode(base64_encode(json_encode($payload))));
		$this->assertFalse($encrypt->decode('not encoded data'));
	}

	/**
	 * @test
	 * @covers Encrypt_Engine_Openssl::__construct
	 */
	public function test_key_must_match_cipher_length()
	{
		$this->expectException('Kohana_Exception');

		new Encrypt(['key' => 'test-key-0123456', 'cipher' => 'AES-256-CBC']);
	}

	/**
	 * @test
	 * @covers Encrypt::instance
	 */
	public function test_instance_uses_config_and_is_singleton()
	{
		$this->setEnvironment([
			'Encrypt::$instances' => [],
			'encrypt.test' => ['type' => 'openssl', 'key' => $this->key],
		]);

		$encrypt = Encrypt::instance('test');

		$this->assertSame($encrypt, Encrypt::instance('test'));
		$this->assertSame('test text', (new Encrypt(['key' => $this->key]))->decode($encrypt->encode('test text')));
	}

	/**
	 * @test
	 * @covers Encrypt::instance
	 */
	public function test_instance_without_key_throws_exception()
	{
		$this->setEnvironment([
			'Encrypt::$instances' => [],
			'encrypt.test' => ['type' => 'openssl'],
		]);

		$this->expectException('Kohana_Exception');
		$this->expectExceptionMessage('No encryption key is defined in the encryption configuration group: test');

		Encrypt::instance('test');
	}
}
