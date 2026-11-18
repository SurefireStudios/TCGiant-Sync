<?php
/**
 * TEST-ONLY Ed25519, in pure PHP with bcmath: RFC 8032's reference
 * implementation (section 6), for test worlds whose PHP has no ext-sodium.
 *
 * It plays libsodium's sodium_crypto_sign_* functions, defining each only
 * when it is absent, as WordPress's sodium_compat does on a site. It is slow,
 * not constant-time, and must never be shipped or used outside tests.
 */

class Tcg_Ed25519_Test_Shim
{
	const P = '57896044618658097711785492504343953926634992332820282019728792003956564819949';
	const Q = '7237005577332262213973186563042994240857116359379907606001950938285454250989';

	private static $d    = null;
	private static $sqm1 = null;
	private static $g    = null;

	private static function mod($a, $m = null)
	{
		$m = null === $m ? self::P : $m;
		$r = bcmod($a, $m);
		if (0 === strpos($r, '-')) {
			$r = bcadd($r, $m);
		}
		return $r;
	}

	private static function inv($x)
	{
		return bcpowmod(self::mod($x), bcsub(self::P, '2'), self::P);
	}

	private static function init()
	{
		if (null !== self::$d) {
			return;
		}
		self::$d    = self::mod(bcmul('-121665', self::inv('121666')));
		self::$sqm1 = bcpowmod('2', bcdiv(bcsub(self::P, '1'), '4', 0), self::P);
		$gy         = self::mod(bcmul('4', self::inv('5')));
		$gx         = self::recover_x($gy, 0);
		self::$g    = array($gx, $gy, '1', self::mod(bcmul($gx, $gy)));
	}

	private static function from_le($bytes)
	{
		$n = '0';
		for ($i = strlen($bytes) - 1; $i >= 0; $i--) {
			$n = bcadd(bcmul($n, '256'), (string) ord($bytes[$i]));
		}
		return $n;
	}

	private static function to_le($n, $len)
	{
		$out = '';
		for ($i = 0; $i < $len; $i++) {
			$out .= chr((int) bcmod($n, '256'));
			$n = bcdiv($n, '256', 0);
		}
		return $out;
	}

	private static function add($p1, $p2)
	{
		$a = self::mod(bcmul(bcsub($p1[1], $p1[0]), bcsub($p2[1], $p2[0])));
		$b = self::mod(bcmul(bcadd($p1[1], $p1[0]), bcadd($p2[1], $p2[0])));
		$c = self::mod(bcmul(bcmul(bcmul('2', $p1[3]), $p2[3]), self::$d));
		$dd = self::mod(bcmul(bcmul('2', $p1[2]), $p2[2]));
		$e = bcsub($b, $a);
		$f = bcsub($dd, $c);
		$g = bcadd($dd, $c);
		$h = bcadd($b, $a);
		return array(self::mod(bcmul($e, $f)), self::mod(bcmul($g, $h)), self::mod(bcmul($f, $g)), self::mod(bcmul($e, $h)));
	}

	private static function mul($s, $pt)
	{
		$q = array('0', '1', '1', '0');
		while (1 === bccomp($s, '0')) {
			if ('1' === bcmod($s, '2')) {
				$q = self::add($q, $pt);
			}
			$pt = self::add($pt, $pt);
			$s  = bcdiv($s, '2', 0);
		}
		return $q;
	}

	private static function equal($p1, $p2)
	{
		return '0' === self::mod(bcsub(bcmul($p1[0], $p2[2]), bcmul($p2[0], $p1[2])))
			&& '0' === self::mod(bcsub(bcmul($p1[1], $p2[2]), bcmul($p2[1], $p1[2])));
	}

	private static function recover_x($y, $sign)
	{
		if (bccomp($y, self::P) >= 0) {
			return null;
		}
		$y2 = bcmul($y, $y);
		$x2 = self::mod(bcmul(bcsub($y2, '1'), self::inv(bcadd(bcmul(self::$d, $y2), '1'))));
		if ('0' === $x2) {
			return $sign ? null : '0';
		}
		$x = bcpowmod($x2, bcdiv(bcadd(self::P, '3'), '8', 0), self::P);
		if ('0' !== self::mod(bcsub(bcmul($x, $x), $x2))) {
			$x = self::mod(bcmul($x, self::$sqm1));
		}
		if ('0' !== self::mod(bcsub(bcmul($x, $x), $x2))) {
			return null;
		}
		if ((int) bcmod($x, '2') !== $sign) {
			$x = bcsub(self::P, $x);
		}
		return $x;
	}

	private static function compress($pt)
	{
		$zi = self::inv($pt[2]);
		$x  = self::mod(bcmul($pt[0], $zi));
		$y  = self::mod(bcmul($pt[1], $zi));
		if ('1' === bcmod($x, '2')) {
			$y = bcadd($y, bcpow('2', '255'));
		}
		return self::to_le($y, 32);
	}

	private static function decompress($s)
	{
		if (32 !== strlen($s)) {
			return null;
		}
		$y    = self::from_le($s);
		$top  = bcpow('2', '255');
		$sign = bccomp($y, $top) >= 0 ? 1 : 0;
		if ($sign) {
			$y = bcsub($y, $top);
		}
		$x = self::recover_x($y, $sign);
		return null === $x ? null : array($x, $y, '1', self::mod(bcmul($x, $y)));
	}

	private static function expand($seed)
	{
		$h = hash('sha512', $seed, true);
		$a = substr($h, 0, 32);
		$a[0]  = chr(ord($a[0]) & 248);
		$a[31] = chr((ord($a[31]) & 127) | 64);
		return array(self::from_le($a), substr($h, 32));
	}

	private static function h_modq($bytes)
	{
		return bcmod(self::from_le(hash('sha512', $bytes, true)), self::Q);
	}

	public static function public_from_seed($seed)
	{
		self::init();
		list($a) = self::expand($seed);
		return self::compress(self::mul($a, self::$g));
	}

	public static function sign($msg, $sk)
	{
		self::init();
		if (64 !== strlen($sk)) {
			throw new SodiumException('secret key must be 64 bytes');
		}
		$seed = substr($sk, 0, 32);
		list($a, $prefix) = self::expand($seed);
		$pub = self::compress(self::mul($a, self::$g));
		$r   = self::h_modq($prefix . $msg);
		$rs  = self::compress(self::mul($r, self::$g));
		$h   = self::h_modq($rs . $pub . $msg);
		$s   = bcmod(bcadd($r, bcmul($h, $a)), self::Q);
		return $rs . self::to_le($s, 32);
	}

	public static function verify($sig, $msg, $pub)
	{
		self::init();
		if (64 !== strlen($sig)) {
			throw new SodiumException('signature must be 64 bytes');
		}
		if (32 !== strlen($pub)) {
			throw new SodiumException('public key must be 32 bytes');
		}
		$a = self::decompress($pub);
		if (null === $a) {
			return false;
		}
		$rs = substr($sig, 0, 32);
		$r  = self::decompress($rs);
		if (null === $r) {
			return false;
		}
		$s = self::from_le(substr($sig, 32));
		if (bccomp($s, self::Q) >= 0) {
			return false;
		}
		$h = self::h_modq($rs . $pub . $msg);
		return self::equal(self::mul($s, self::$g), self::add($r, self::mul($h, $a)));
	}
}

if (!class_exists('SodiumException')) {
	class SodiumException extends Exception {} // phpcs:ignore
}

if (!function_exists('sodium_crypto_sign_detached')) {
	function sodium_crypto_sign_detached($msg, $sk) { return Tcg_Ed25519_Test_Shim::sign($msg, $sk); }
}
if (!function_exists('sodium_crypto_sign_verify_detached')) {
	function sodium_crypto_sign_verify_detached($sig, $msg, $pk) { return Tcg_Ed25519_Test_Shim::verify($sig, $msg, $pk); }
}
if (!function_exists('sodium_crypto_sign_seed_keypair')) {
	function sodium_crypto_sign_seed_keypair($seed)
	{
		$pub = Tcg_Ed25519_Test_Shim::public_from_seed($seed);
		return $seed . $pub . $pub;
	}
}
if (!function_exists('sodium_crypto_sign_keypair')) {
	function sodium_crypto_sign_keypair() { return sodium_crypto_sign_seed_keypair(random_bytes(32)); }
}
if (!function_exists('sodium_crypto_sign_secretkey')) {
	function sodium_crypto_sign_secretkey($kp) { return substr($kp, 0, 64); }
}
if (!function_exists('sodium_crypto_sign_publickey')) {
	function sodium_crypto_sign_publickey($kp) { return substr($kp, 64, 32); }
}
if (!function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
	function sodium_crypto_sign_publickey_from_secretkey($sk) { return substr($sk, 32, 32); }
}
