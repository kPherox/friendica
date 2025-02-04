<?php
/**
 * @file src/Model/User.php
 * @brief This file includes the User class with user related database functions
 */
namespace Friendica\Model;

use DivineOmega\PasswordExposed;
use Exception;
use Friendica\Core\Config;
use Friendica\Core\Hook;
use Friendica\Core\L10n;
use Friendica\Core\Logger;
use Friendica\Core\PConfig;
use Friendica\Core\Protocol;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Model\Photo;
use Friendica\Model\TwoFactor\AppSpecificPassword;
use Friendica\Object\Image;
use Friendica\Util\Crypto;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Network;
use Friendica\Util\Strings;
use Friendica\Worker\Delivery;
use LightOpenID;

/**
 * @brief This class handles User related functions
 */
class User
{
	/**
	 * Page/profile types
	 *
	 * PAGE_FLAGS_NORMAL is a typical personal profile account
	 * PAGE_FLAGS_SOAPBOX automatically approves all friend requests as Contact::SHARING, (readonly)
	 * PAGE_FLAGS_COMMUNITY automatically approves all friend requests as Contact::SHARING, but with
	 *      write access to wall and comments (no email and not included in page owner's ACL lists)
	 * PAGE_FLAGS_FREELOVE automatically approves all friend requests as full friends (Contact::FRIEND).
	 *
	 * @{
	 */
	const PAGE_FLAGS_NORMAL    = 0;
	const PAGE_FLAGS_SOAPBOX   = 1;
	const PAGE_FLAGS_COMMUNITY = 2;
	const PAGE_FLAGS_FREELOVE  = 3;
	const PAGE_FLAGS_BLOG      = 4;
	const PAGE_FLAGS_PRVGROUP  = 5;
	/**
	 * @}
	 */

	/**
	 * Account types
	 *
	 * ACCOUNT_TYPE_PERSON - the account belongs to a person
	 *	Associated page types: PAGE_FLAGS_NORMAL, PAGE_FLAGS_SOAPBOX, PAGE_FLAGS_FREELOVE
	 *
	 * ACCOUNT_TYPE_ORGANISATION - the account belongs to an organisation
	 *	Associated page type: PAGE_FLAGS_SOAPBOX
	 *
	 * ACCOUNT_TYPE_NEWS - the account is a news reflector
	 *	Associated page type: PAGE_FLAGS_SOAPBOX
	 *
	 * ACCOUNT_TYPE_COMMUNITY - the account is community forum
	 *	Associated page types: PAGE_COMMUNITY, PAGE_FLAGS_PRVGROUP
	 *
	 * ACCOUNT_TYPE_RELAY - the account is a relay
	 *      This will only be assigned to contacts, not to user accounts
	 * @{
	 */
	const ACCOUNT_TYPE_PERSON =       0;
	const ACCOUNT_TYPE_ORGANISATION = 1;
	const ACCOUNT_TYPE_NEWS =         2;
	const ACCOUNT_TYPE_COMMUNITY =    3;
	const ACCOUNT_TYPE_RELAY =        4;
	/**
	 * @}
	 */

	/**
	 * Returns true if a user record exists with the provided id
	 *
	 * @param  integer $uid
	 * @return boolean
	 * @throws Exception
	 */
	public static function exists($uid)
	{
		return DBA::exists('user', ['uid' => $uid]);
	}

	/**
	 * @param  integer       $uid
	 * @param array          $fields
	 * @return array|boolean User record if it exists, false otherwise
	 * @throws Exception
	 */
	public static function getById($uid, array $fields = [])
	{
		return DBA::selectFirst('user', $fields, ['uid' => $uid]);
	}

	/**
	 * @param  string        $nickname
	 * @param array          $fields
	 * @return array|boolean User record if it exists, false otherwise
	 * @throws Exception
	 */
	public static function getByNickname($nickname, array $fields = [])
	{
		return DBA::selectFirst('user', $fields, ['nickname' => $nickname]);
	}

	/**
	 * @brief Returns the user id of a given profile URL
	 *
	 * @param string $url
	 *
	 * @return integer user id
	 * @throws Exception
	 */
	public static function getIdForURL($url)
	{
		$self = DBA::selectFirst('contact', ['uid'], ['nurl' => Strings::normaliseLink($url), 'self' => true]);
		if (!DBA::isResult($self)) {
			return false;
		} else {
			return $self['uid'];
		}
	}

	/**
	 * Get a user based on its email
	 *
	 * @param string        $email
	 * @param array          $fields
	 *
	 * @return array|boolean User record if it exists, false otherwise
	 *
	 * @throws Exception
	 */
	public static function getByEmail($email, array $fields = [])
	{
		return DBA::selectFirst('user', $fields, ['email' => $email]);
	}

	/**
	 * @brief Get owner data by user id
	 *
	 * @param int $uid
	 * @param boolean $check_valid Test if data is invalid and correct it
	 * @return boolean|array
	 * @throws Exception
	 */
	public static function getOwnerDataById($uid, $check_valid = true) {
		$r = DBA::fetchFirst("SELECT
			`contact`.*,
			`user`.`prvkey` AS `uprvkey`,
			`user`.`timezone`,
			`user`.`nickname`,
			`user`.`sprvkey`,
			`user`.`spubkey`,
			`user`.`page-flags`,
			`user`.`account-type`,
			`user`.`prvnets`,
			`user`.`account_removed`
			FROM `contact`
			INNER JOIN `user`
				ON `user`.`uid` = `contact`.`uid`
			WHERE `contact`.`uid` = ?
			AND `contact`.`self`
			LIMIT 1",
			$uid
		);
		if (!DBA::isResult($r)) {
			return false;
		}

		if (empty($r['nickname'])) {
			return false;
		}

		if (!$check_valid) {
			return $r;
		}

		// Check if the returned data is valid, otherwise fix it. See issue #6122

		// Check for correct url and normalised nurl
		$url = System::baseUrl() . '/profile/' . $r['nickname'];
		$repair = ($r['url'] != $url) || ($r['nurl'] != Strings::normaliseLink($r['url']));

		if (!$repair) {
			// Check if "addr" is present and correct
			$addr = $r['nickname'] . '@' . substr(System::baseUrl(), strpos(System::baseUrl(), '://') + 3);
			$repair = ($addr != $r['addr']);
		}

		if (!$repair) {
			// Check if the avatar field is filled and the photo directs to the correct path
			$avatar = Photo::selectFirst(['resource-id'], ['uid' => $uid, 'profile' => true]);
			if (DBA::isResult($avatar)) {
				$repair = empty($r['avatar']) || !strpos($r['photo'], $avatar['resource-id']);
			}
		}

		if ($repair) {
			Contact::updateSelfFromUserID($uid);
			// Return the corrected data and avoid a loop
			$r = self::getOwnerDataById($uid, false);
		}

		return $r;
	}

	/**
	 * @brief Get owner data by nick name
	 *
	 * @param int $nick
	 * @return boolean|array
	 * @throws Exception
	 */
	public static function getOwnerDataByNick($nick)
	{
		$user = DBA::selectFirst('user', ['uid'], ['nickname' => $nick]);

		if (!DBA::isResult($user)) {
			return false;
		}

		return self::getOwnerDataById($user['uid']);
	}

	/**
	 * @brief Returns the default group for a given user and network
	 *
	 * @param int $uid User id
	 * @param string $network network name
	 *
	 * @return int group id
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function getDefaultGroup($uid, $network = '')
	{
		$default_group = 0;

		if ($network == Protocol::OSTATUS) {
			$default_group = PConfig::get($uid, "ostatus", "default_group");
		}

		if ($default_group != 0) {
			return $default_group;
		}

		$user = DBA::selectFirst('user', ['def_gid'], ['uid' => $uid]);

		if (DBA::isResult($user)) {
			$default_group = $user["def_gid"];
		}

		return $default_group;
	}


	/**
	 * Authenticate a user with a clear text password
	 *
	 * @brief      Authenticate a user with a clear text password
	 * @param mixed  $user_info
	 * @param string $password
	 * @param bool   $third_party
	 * @return int|boolean
	 * @deprecated since version 3.6
	 * @see        User::getIdFromPasswordAuthentication()
	 */
	public static function authenticate($user_info, $password, $third_party = false)
	{
		try {
			return self::getIdFromPasswordAuthentication($user_info, $password, $third_party);
		} catch (Exception $ex) {
			return false;
		}
	}

	/**
	 * Returns the user id associated with a successful password authentication
	 *
	 * @brief Authenticate a user with a clear text password
	 * @param mixed  $user_info
	 * @param string $password
	 * @param bool   $third_party
	 * @return int User Id if authentication is successful
	 * @throws Exception
	 */
	public static function getIdFromPasswordAuthentication($user_info, $password, $third_party = false)
	{
		$user = self::getAuthenticationInfo($user_info);

		if ($third_party && PConfig::get($user['uid'], '2fa', 'verified')) {
			// Third-party apps can't verify two-factor authentication, we use app-specific passwords instead
			if (AppSpecificPassword::authenticateUser($user['uid'], $password)) {
				return $user['uid'];
			}
		} elseif (strpos($user['password'], '$') === false) {
			//Legacy hash that has not been replaced by a new hash yet
			if (self::hashPasswordLegacy($password) === $user['password']) {
				self::updatePasswordHashed($user['uid'], self::hashPassword($password));

				return $user['uid'];
			}
		} elseif (!empty($user['legacy_password'])) {
			//Legacy hash that has been double-hashed and not replaced by a new hash yet
			//Warning: `legacy_password` is not necessary in sync with the content of `password`
			if (password_verify(self::hashPasswordLegacy($password), $user['password'])) {
				self::updatePasswordHashed($user['uid'], self::hashPassword($password));

				return $user['uid'];
			}
		} elseif (password_verify($password, $user['password'])) {
			//New password hash
			if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
				self::updatePasswordHashed($user['uid'], self::hashPassword($password));
			}

			return $user['uid'];
		}

		throw new Exception(L10n::t('Login failed'));
	}

	/**
	 * Returns authentication info from various parameters types
	 *
	 * User info can be any of the following:
	 * - User DB object
	 * - User Id
	 * - User email or username or nickname
	 * - User array with at least the uid and the hashed password
	 *
	 * @param mixed $user_info
	 * @return array
	 * @throws Exception
	 */
	private static function getAuthenticationInfo($user_info)
	{
		$user = null;

		if (is_object($user_info) || is_array($user_info)) {
			if (is_object($user_info)) {
				$user = (array) $user_info;
			} else {
				$user = $user_info;
			}

			if (!isset($user['uid'])
				|| !isset($user['password'])
				|| !isset($user['legacy_password'])
			) {
				throw new Exception(L10n::t('Not enough information to authenticate'));
			}
		} elseif (is_int($user_info) || is_string($user_info)) {
			if (is_int($user_info)) {
				$user = DBA::selectFirst('user', ['uid', 'password', 'legacy_password'],
					[
						'uid' => $user_info,
						'blocked' => 0,
						'account_expired' => 0,
						'account_removed' => 0,
						'verified' => 1
					]
				);
			} else {
				$fields = ['uid', 'password', 'legacy_password'];
				$condition = ["(`email` = ? OR `username` = ? OR `nickname` = ?)
					AND NOT `blocked` AND NOT `account_expired` AND NOT `account_removed` AND `verified`",
					$user_info, $user_info, $user_info];
				$user = DBA::selectFirst('user', $fields, $condition);
			}

			if (!DBA::isResult($user)) {
				throw new Exception(L10n::t('User not found'));
			}
		}

		return $user;
	}

	/**
	 * Generates a human-readable random password
	 *
	 * @return string
	 */
	public static function generateNewPassword()
	{
		return ucfirst(Strings::getRandomName(8)) . mt_rand(1000, 9999);
	}

	/**
	 * Checks if the provided plaintext password has been exposed or not
	 *
	 * @param string $password
	 * @return bool
	 */
	public static function isPasswordExposed($password)
	{
		$cache = new \DivineOmega\DOFileCachePSR6\CacheItemPool();
		$cache->changeConfig([
			'cacheDirectory' => get_temppath() . '/password-exposed-cache/',
		]);

		$PasswordExposedCHecker = new PasswordExposed\PasswordExposedChecker(null, $cache);

		return $PasswordExposedCHecker->passwordExposed($password) === PasswordExposed\PasswordStatus::EXPOSED;
	}

	/**
	 * Legacy hashing function, kept for password migration purposes
	 *
	 * @param string $password
	 * @return string
	 */
	private static function hashPasswordLegacy($password)
	{
		return hash('whirlpool', $password);
	}

	/**
	 * Global user password hashing function
	 *
	 * @param string $password
	 * @return string
	 * @throws Exception
	 */
	public static function hashPassword($password)
	{
		if (!trim($password)) {
			throw new Exception(L10n::t('Password can\'t be empty'));
		}

		return password_hash($password, PASSWORD_DEFAULT);
	}

	/**
	 * Updates a user row with a new plaintext password
	 *
	 * @param int    $uid
	 * @param string $password
	 * @return bool
	 * @throws Exception
	 */
	public static function updatePassword($uid, $password)
	{
		$password = trim($password);

		if (empty($password)) {
			throw new Exception(L10n::t('Empty passwords are not allowed.'));
		}

		if (!Config::get('system', 'disable_password_exposed', false) && self::isPasswordExposed($password)) {
			throw new Exception(L10n::t('The new password has been exposed in a public data dump, please choose another.'));
		}

		$allowed_characters = '!"#$%&\'()*+,-./;<=>?@[\]^_`{|}~';

		if (!preg_match('/^[a-z0-9' . preg_quote($allowed_characters, '/') . ']+$/i', $password)) {
			throw new Exception(L10n::t('The password can\'t contain accentuated letters, white spaces or colons (:)'));
		}

		return self::updatePasswordHashed($uid, self::hashPassword($password));
	}

	/**
	 * Updates a user row with a new hashed password.
	 * Empties the password reset token field just in case.
	 *
	 * @param int    $uid
	 * @param string $pasword_hashed
	 * @return bool
	 * @throws Exception
	 */
	private static function updatePasswordHashed($uid, $pasword_hashed)
	{
		$fields = [
			'password' => $pasword_hashed,
			'pwdreset' => null,
			'pwdreset_time' => null,
			'legacy_password' => false
		];
		return DBA::update('user', $fields, ['uid' => $uid]);
	}

	/**
	 * @brief Checks if a nickname is in the list of the forbidden nicknames
	 *
	 * Check if a nickname is forbidden from registration on the node by the
	 * admin. Forbidden nicknames (e.g. role namess) can be configured in the
	 * admin panel.
	 *
	 * @param string $nickname The nickname that should be checked
	 * @return boolean True is the nickname is blocked on the node
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function isNicknameBlocked($nickname)
	{
		$forbidden_nicknames = Config::get('system', 'forbidden_nicknames', '');

		// if the config variable is empty return false
		if (empty($forbidden_nicknames)) {
			return false;
		}

		// check if the nickname is in the list of blocked nicknames
		$forbidden = explode(',', $forbidden_nicknames);
		$forbidden = array_map('trim', $forbidden);
		if (in_array(strtolower($nickname), $forbidden)) {
			return true;
		}

		// else return false
		return false;
	}

	/**
	 * @brief Catch-all user creation function
	 *
	 * Creates a user from the provided data array, either form fields or OpenID.
	 * Required: { username, nickname, email } or { openid_url }
	 *
	 * Performs the following:
	 * - Sends to the OpenId auth URL (if relevant)
	 * - Creates new key pairs for crypto
	 * - Create self-contact
	 * - Create profile image
	 *
	 * @param  array $data
	 * @return array
	 * @throws \ErrorException
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 * @throws \ImagickException
	 * @throws Exception
	 */
	public static function create(array $data)
	{
		$a = \get_app();
		$return = ['user' => null, 'password' => ''];

		$using_invites = Config::get('system', 'invitation_only');

		$invite_id  = !empty($data['invite_id'])  ? Strings::escapeTags(trim($data['invite_id']))  : '';
		$username   = !empty($data['username'])   ? Strings::escapeTags(trim($data['username']))   : '';
		$nickname   = !empty($data['nickname'])   ? Strings::escapeTags(trim($data['nickname']))   : '';
		$email      = !empty($data['email'])      ? Strings::escapeTags(trim($data['email']))      : '';
		$openid_url = !empty($data['openid_url']) ? Strings::escapeTags(trim($data['openid_url'])) : '';
		$photo      = !empty($data['photo'])      ? Strings::escapeTags(trim($data['photo']))      : '';
		$password   = !empty($data['password'])   ? trim($data['password'])           : '';
		$password1  = !empty($data['password1'])  ? trim($data['password1'])          : '';
		$confirm    = !empty($data['confirm'])    ? trim($data['confirm'])            : '';
		$blocked    = !empty($data['blocked']);
		$verified   = !empty($data['verified']);
		$language   = !empty($data['language'])   ? Strings::escapeTags(trim($data['language']))   : 'en';

		$publish = !empty($data['profile_publish_reg']);
		$netpublish = $publish && Config::get('system', 'directory');

		if ($password1 != $confirm) {
			throw new Exception(L10n::t('Passwords do not match. Password unchanged.'));
		} elseif ($password1 != '') {
			$password = $password1;
		}

		if ($using_invites) {
			if (!$invite_id) {
				throw new Exception(L10n::t('An invitation is required.'));
			}

			if (!Register::existsByHash($invite_id)) {
				throw new Exception(L10n::t('Invitation could not be verified.'));
			}
		}

		if (empty($username) || empty($email) || empty($nickname)) {
			if ($openid_url) {
				if (!Network::isUrlValid($openid_url)) {
					throw new Exception(L10n::t('Invalid OpenID url'));
				}
				$_SESSION['register'] = 1;
				$_SESSION['openid'] = $openid_url;

				$openid = new LightOpenID($a->getHostName());
				$openid->identity = $openid_url;
				$openid->returnUrl = System::baseUrl() . '/openid';
				$openid->required = ['namePerson/friendly', 'contact/email', 'namePerson'];
				$openid->optional = ['namePerson/first', 'media/image/aspect11', 'media/image/default'];
				try {
					$authurl = $openid->authUrl();
				} catch (Exception $e) {
					throw new Exception(L10n::t('We encountered a problem while logging in with the OpenID you provided. Please check the correct spelling of the ID.') . EOL . EOL . L10n::t('The error message was:') . $e->getMessage(), 0, $e);
				}
				System::externalRedirect($authurl);
				// NOTREACHED
			}

			throw new Exception(L10n::t('Please enter the required information.'));
		}

		if (!Network::isUrlValid($openid_url)) {
			$openid_url = '';
		}

		// collapse multiple spaces in name
		$username = preg_replace('/ +/', ' ', $username);

		$username_min_length = max(1, min(64, intval(Config::get('system', 'username_min_length', 3))));
		$username_max_length = max(1, min(64, intval(Config::get('system', 'username_max_length', 48))));

		if ($username_min_length > $username_max_length) {
			Logger::log(L10n::t('system.username_min_length (%s) and system.username_max_length (%s) are excluding each other, swapping values.', $username_min_length, $username_max_length), Logger::WARNING);
			$tmp = $username_min_length;
			$username_min_length = $username_max_length;
			$username_max_length = $tmp;
		}

		if (mb_strlen($username) < $username_min_length) {
			throw new Exception(L10n::tt('Username should be at least %s character.', 'Username should be at least %s characters.', $username_min_length));
		}

		if (mb_strlen($username) > $username_max_length) {
			throw new Exception(L10n::tt('Username should be at most %s character.', 'Username should be at most %s characters.', $username_max_length));
		}

		// So now we are just looking for a space in the full name.
		$loose_reg = Config::get('system', 'no_regfullname');
		if (!$loose_reg) {
			$username = mb_convert_case($username, MB_CASE_TITLE, 'UTF-8');
			if (strpos($username, ' ') === false) {
				throw new Exception(L10n::t("That doesn't appear to be your full (First Last) name."));
			}
		}

		if (!Network::isEmailDomainAllowed($email)) {
			throw new Exception(L10n::t('Your email domain is not among those allowed on this site.'));
		}

		if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !Network::isEmailDomainValid($email)) {
			throw new Exception(L10n::t('Not a valid email address.'));
		}
		if (self::isNicknameBlocked($nickname)) {
			throw new Exception(L10n::t('The nickname was blocked from registration by the nodes admin.'));
		}

		if (Config::get('system', 'block_extended_register', false) && DBA::exists('user', ['email' => $email])) {
			throw new Exception(L10n::t('Cannot use that email.'));
		}

		// Disallow somebody creating an account using openid that uses the admin email address,
		// since openid bypasses email verification. We'll allow it if there is not yet an admin account.
		if (Config::get('config', 'admin_email') && strlen($openid_url)) {
			$adminlist = explode(',', str_replace(' ', '', strtolower(Config::get('config', 'admin_email'))));
			if (in_array(strtolower($email), $adminlist)) {
				throw new Exception(L10n::t('Cannot use that email.'));
			}
		}

		$nickname = $data['nickname'] = strtolower($nickname);

		if (!preg_match('/^[a-z0-9][a-z0-9\_]*$/', $nickname)) {
			throw new Exception(L10n::t('Your nickname can only contain a-z, 0-9 and _.'));
		}

		// Check existing and deleted accounts for this nickname.
		if (DBA::exists('user', ['nickname' => $nickname])
			|| DBA::exists('userd', ['username' => $nickname])
		) {
			throw new Exception(L10n::t('Nickname is already registered. Please choose another.'));
		}

		$new_password = strlen($password) ? $password : User::generateNewPassword();
		$new_password_encoded = self::hashPassword($new_password);

		$return['password'] = $new_password;

		$keys = Crypto::newKeypair(4096);
		if ($keys === false) {
			throw new Exception(L10n::t('SERIOUS ERROR: Generation of security keys failed.'));
		}

		$prvkey = $keys['prvkey'];
		$pubkey = $keys['pubkey'];

		// Create another keypair for signing/verifying salmon protocol messages.
		$sres = Crypto::newKeypair(512);
		$sprvkey = $sres['prvkey'];
		$spubkey = $sres['pubkey'];

		$insert_result = DBA::insert('user', [
			'guid'     => System::createUUID(),
			'username' => $username,
			'password' => $new_password_encoded,
			'email'    => $email,
			'openid'   => $openid_url,
			'nickname' => $nickname,
			'pubkey'   => $pubkey,
			'prvkey'   => $prvkey,
			'spubkey'  => $spubkey,
			'sprvkey'  => $sprvkey,
			'verified' => $verified,
			'blocked'  => $blocked,
			'language' => $language,
			'timezone' => 'UTC',
			'register_date' => DateTimeFormat::utcNow(),
			'default-location' => ''
		]);

		if ($insert_result) {
			$uid = DBA::lastInsertId();
			$user = DBA::selectFirst('user', [], ['uid' => $uid]);
		} else {
			throw new Exception(L10n::t('An error occurred during registration. Please try again.'));
		}

		if (!$uid) {
			throw new Exception(L10n::t('An error occurred during registration. Please try again.'));
		}

		// if somebody clicked submit twice very quickly, they could end up with two accounts
		// due to race condition. Remove this one.
		$user_count = DBA::count('user', ['nickname' => $nickname]);
		if ($user_count > 1) {
			DBA::delete('user', ['uid' => $uid]);

			throw new Exception(L10n::t('Nickname is already registered. Please choose another.'));
		}

		$insert_result = DBA::insert('profile', [
			'uid' => $uid,
			'name' => $username,
			'photo' => System::baseUrl() . "/photo/profile/{$uid}.jpg",
			'thumb' => System::baseUrl() . "/photo/avatar/{$uid}.jpg",
			'publish' => $publish,
			'is-default' => 1,
			'net-publish' => $netpublish,
			'profile-name' => L10n::t('default')
		]);
		if (!$insert_result) {
			DBA::delete('user', ['uid' => $uid]);

			throw new Exception(L10n::t('An error occurred creating your default profile. Please try again.'));
		}

		// Create the self contact
		if (!Contact::createSelfFromUserId($uid)) {
			DBA::delete('user', ['uid' => $uid]);

			throw new Exception(L10n::t('An error occurred creating your self contact. Please try again.'));
		}

		// Create a group with no members. This allows somebody to use it
		// right away as a default group for new contacts.
		$def_gid = Group::create($uid, L10n::t('Friends'));
		if (!$def_gid) {
			DBA::delete('user', ['uid' => $uid]);

			throw new Exception(L10n::t('An error occurred creating your default contact group. Please try again.'));
		}

		$fields = ['def_gid' => $def_gid];
		if (Config::get('system', 'newuser_private') && $def_gid) {
			$fields['allow_gid'] = '<' . $def_gid . '>';
		}

		DBA::update('user', $fields, ['uid' => $uid]);

		// if we have no OpenID photo try to look up an avatar
		if (!strlen($photo)) {
			$photo = Network::lookupAvatarByEmail($email);
		}

		// unless there is no avatar-addon loaded
		if (strlen($photo)) {
			$photo_failure = false;

			$filename = basename($photo);
			$img_str = Network::fetchUrl($photo, true);
			// guess mimetype from headers or filename
			$type = Image::guessType($photo, true);

			$Image = new Image($img_str, $type);
			if ($Image->isValid()) {
				$Image->scaleToSquare(300);

				$hash = Photo::newResource();

				$r = Photo::store($Image, $uid, 0, $hash, $filename, L10n::t('Profile Photos'), 4);

				if ($r === false) {
					$photo_failure = true;
				}

				$Image->scaleDown(80);

				$r = Photo::store($Image, $uid, 0, $hash, $filename, L10n::t('Profile Photos'), 5);

				if ($r === false) {
					$photo_failure = true;
				}

				$Image->scaleDown(48);

				$r = Photo::store($Image, $uid, 0, $hash, $filename, L10n::t('Profile Photos'), 6);

				if ($r === false) {
					$photo_failure = true;
				}

				if (!$photo_failure) {
					Photo::update(['profile' => 1], ['resource-id' => $hash]);
				}
			}
		}

		Hook::callAll('register_account', $uid);

		$return['user'] = $user;
		return $return;
	}

	/**
	 * @brief Sends pending registration confirmation email
	 *
	 * @param array  $user     User record array
	 * @param string $sitename
	 * @param string $siteurl
	 * @param string $password Plaintext password
	 * @return NULL|boolean from notification() and email() inherited
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function sendRegisterPendingEmail($user, $sitename, $siteurl, $password)
	{
		$body = Strings::deindent(L10n::t('
			Dear %1$s,
				Thank you for registering at %2$s. Your account is pending for approval by the administrator.

			Your login details are as follows:

			Site Location:	%3$s
			Login Name:		%4$s
			Password:		%5$s
		',
			$user['username'], $sitename, $siteurl, $user['nickname'], $password
		));

		return notification([
			'type'     => SYSTEM_EMAIL,
			'uid'      => $user['uid'],
			'to_email' => $user['email'],
			'subject'  => L10n::t('Registration at %s', $sitename),
			'body'     => $body
		]);
	}

	/**
	 * @brief Sends registration confirmation
	 *
	 * It's here as a function because the mail is sent from different parts
	 *
	 * @param array  $user     User record array
	 * @param string $sitename
	 * @param string $siteurl
	 * @param string $password Plaintext password
	 * @return NULL|boolean from notification() and email() inherited
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function sendRegisterOpenEmail($user, $sitename, $siteurl, $password)
	{
		$preamble = Strings::deindent(L10n::t('
			Dear %1$s,
				Thank you for registering at %2$s. Your account has been created.
		',
			$user['username'], $sitename
		));
		$body = Strings::deindent(L10n::t('
			The login details are as follows:

			Site Location:	%3$s
			Login Name:		%1$s
			Password:		%5$s

			You may change your password from your account "Settings" page after logging
			in.

			Please take a few moments to review the other account settings on that page.

			You may also wish to add some basic information to your default profile
			' . "\x28" . 'on the "Profiles" page' . "\x29" . ' so that other people can easily find you.

			We recommend setting your full name, adding a profile photo,
			adding some profile "keywords" ' . "\x28" . 'very useful in making new friends' . "\x29" . ' - and
			perhaps what country you live in; if you do not wish to be more specific
			than that.

			We fully respect your right to privacy, and none of these items are necessary.
			If you are new and do not know anybody here, they may help
			you to make some new and interesting friends.

			If you ever want to delete your account, you can do so at %3$s/removeme

			Thank you and welcome to %2$s.',
			$user['nickname'], $sitename, $siteurl, $user['username'], $password
		));

		return notification([
			'uid'      => $user['uid'],
			'language' => $user['language'],
			'type'     => SYSTEM_EMAIL,
			'to_email' => $user['email'],
			'subject'  => L10n::t('Registration details for %s', $sitename),
			'preamble' => $preamble,
			'body'     => $body
		]);
	}

	/**
	 * @param object $uid user to remove
	 * @return bool
	 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
	 */
	public static function remove($uid)
	{
		if (!$uid) {
			return false;
		}

		Logger::log('Removing user: ' . $uid);

		$user = DBA::selectFirst('user', [], ['uid' => $uid]);

		Hook::callAll('remove_user', $user);

		// save username (actually the nickname as it is guaranteed
		// unique), so it cannot be re-registered in the future.
		DBA::insert('userd', ['username' => $user['nickname']]);

		// The user and related data will be deleted in "cron_expire_and_remove_users" (cronjobs.php)
		DBA::update('user', ['account_removed' => true, 'account_expires_on' => DateTimeFormat::utc('now + 7 day')], ['uid' => $uid]);
		Worker::add(PRIORITY_HIGH, 'Notifier', Delivery::REMOVAL, $uid);

		// Send an update to the directory
		$self = DBA::selectFirst('contact', ['url'], ['uid' => $uid, 'self' => true]);
		Worker::add(PRIORITY_LOW, 'Directory', $self['url']);

		// Remove the user relevant data
		Worker::add(PRIORITY_NEGLIGIBLE, 'RemoveUser', $uid);

		return true;
	}

	/**
	 * Return all identities to a user
	 *
	 * @param int $uid The user id
	 * @return array All identities for this user
	 *
	 * Example for a return:
	 *    [
	 *        [
	 *            'uid' => 1,
	 *            'username' => 'maxmuster',
	 *            'nickname' => 'Max Mustermann'
	 *        ],
	 *        [
	 *            'uid' => 2,
	 *            'username' => 'johndoe',
	 *            'nickname' => 'John Doe'
	 *        ]
	 *    ]
	 * @throws Exception
	 */
	public static function identities($uid)
	{
		$identities = [];

		$user = DBA::selectFirst('user', ['uid', 'nickname', 'username', 'parent-uid'], ['uid' => $uid]);
		if (!DBA::isResult($user)) {
			return $identities;
		}

		if ($user['parent-uid'] == 0) {
			// First add our own entry
			$identities = [['uid' => $user['uid'],
				'username' => $user['username'],
				'nickname' => $user['nickname']]];

			// Then add all the children
			$r = DBA::select('user', ['uid', 'username', 'nickname'],
				['parent-uid' => $user['uid'], 'account_removed' => false]);
			if (DBA::isResult($r)) {
				$identities = array_merge($identities, DBA::toArray($r));
			}
		} else {
			// First entry is our parent
			$r = DBA::select('user', ['uid', 'username', 'nickname'],
				['uid' => $user['parent-uid'], 'account_removed' => false]);
			if (DBA::isResult($r)) {
				$identities = DBA::toArray($r);
			}

			// Then add all siblings
			$r = DBA::select('user', ['uid', 'username', 'nickname'],
				['parent-uid' => $user['parent-uid'], 'account_removed' => false]);
			if (DBA::isResult($r)) {
				$identities = array_merge($identities, DBA::toArray($r));
			}
		}

		$r = DBA::p("SELECT `user`.`uid`, `user`.`username`, `user`.`nickname`
			FROM `manage`
			INNER JOIN `user` ON `manage`.`mid` = `user`.`uid`
			WHERE `user`.`account_removed` = 0 AND `manage`.`uid` = ?",
			$user['uid']
		);
		if (DBA::isResult($r)) {
			$identities = array_merge($identities, DBA::toArray($r));
		}

		return $identities;
	}

	/**
	 * Returns statistical information about the current users of this node
	 *
	 * @return array
	 *
	 * @throws Exception
	 */
	public static function getStatistics()
	{
		$statistics = [
			'total_users'           => 0,
			'active_users_halfyear' => 0,
			'active_users_monthly'  => 0,
		];

		$userStmt = DBA::p("SELECT `user`.`uid`, `user`.`login_date`, `contact`.`last-item`
			FROM `user`
			INNER JOIN `profile` ON `profile`.`uid` = `user`.`uid` AND `profile`.`is-default`
			INNER JOIN `contact` ON `contact`.`uid` = `user`.`uid` AND `contact`.`self`
			WHERE (`profile`.`publish` OR `profile`.`net-publish`) AND `user`.`verified`
				AND NOT `user`.`blocked` AND NOT `user`.`account_removed`
				AND NOT `user`.`account_expired`");

		if (!DBA::isResult($userStmt)) {
			return $statistics;
		}

		$halfyear = time() - (180 * 24 * 60 * 60);
		$month = time() - (30 * 24 * 60 * 60);

		while ($user = DBA::fetch($userStmt)) {
			$statistics['total_users']++;

			if ((strtotime($user['login_date']) > $halfyear) ||
				(strtotime($user['last-item']) > $halfyear)) {
				$statistics['active_users_halfyear']++;
			}

			if ((strtotime($user['login_date']) > $month) ||
				(strtotime($user['last-item']) > $month)) {
				$statistics['active_users_monthly']++;
			}
		}

		return $statistics;
	}
}
