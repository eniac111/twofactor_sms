<?php

/**
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * ownCloud - Two-factor SMS
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\TwoFactor_Sms\Provider;

use Base32\Base32;
use OCA\TwoFactor_Sms\Exception\SmsTransmissionException;
use OCA\TwoFactor_Sms\Service\ISmsService;
use OCP\Authentication\TwoFactorAuth\IProvider;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\Template;
use Otp\GoogleAuthenticator;
use Otp\Otp;

class SmsProvider implements IProvider {

	/** @var ISmsService */
	private $smsService;

	/** @var ISession */
	private $session;

	/** @var IConfig */
	private $config;

	/**
	 * @param ISmsService $smsService
	 * @param ISession $session
	 * @param IConfig $config
	 */
	public function __construct(ISmsService $smsService, ISession $session, IConfig $config) {
		$this->smsService = $smsService;
		$this->session = $session;
		$this->config = $config;
	}

	/**
	 * Get unique identifier of this 2FA provider
	 *
	 * @since 9.1.0
	 *
	 * @return string
	 */
	public function getId() {
		return 'sms';
	}

	/**
	 * Get the display name for selecting the 2FA provider
	 *
	 * Example: "Email"
	 *
	 * @since 9.1.0
	 *
	 * @return string
	 */
	public function getDisplayName() {
		return 'SMS';
	}

	/**
	 * Get the description for selecting the 2FA provider
	 *
	 * Example: "Get a token via e-mail"
	 *
	 * @since 9.1.0
	 *
	 * @return string
	 */
	public function getDescription() {
		return 'Get a token via SMS';
	}

	/**
	 * Get the template for rending the 2FA provider view
	 *
	 * @since 9.1.0
	 *
	 * @param IUser $user
	 * @return Template
	 */
	public function getTemplate(IUser $user) {
		$otp = new Otp();
		$secret = GoogleAuthenticator::generateRandom();
		$this->session->set('twofactor_sms_secret', $secret);
		$totp = $otp->totp(Base32::decode($secret));

		$phoneNumber = (int) $this->config->getUserValue($user->getUID(), 'twofactor_sms', 'phone');
		try {
			$this->smsService->send($phoneNumber, "Your ownCloud code is $totp");
		} catch (SmsTransmissionException $ex) {
			$tmpl = new Template('twofactor_sms', 'error');
			return $tmpl;
		}

		$tmpl = new Template('twofactor_sms', 'challenge');
		$tmpl->assign('phone', $this->protectPhoneNumber($phoneNumber));
		if ($this->config->getSystemValue('debug', false)) {
			$tmpl->assign('secret', $totp);
		}
		return $tmpl;
	}

	/**
	 * convert 123456789 to ******789
	 *
	 * @param string $number
	 * @return string
	 */
	private function protectPhoneNumber($number) {
		$length = strlen($number);
		$start = $length - 3;

		return str_repeat('*', $start) . substr($number, $start);
	}

	/**
	 * Verify the given challenge
	 *
	 * @since 9.1.0
	 *
	 * @param IUser $user
	 * @param string $challenge
	 */
	public function verifyChallenge(IUser $user, $challenge) {
		$otp = new Otp();
		$secret = $this->session->get('twofactor_sms_secret');
		return $otp->checkTotp(Base32::decode($secret), $challenge);
	}

	/**
	 * Decides whether 2FA is enabled for the given user
	 *
	 * @since 9.1.0
	 *
	 * @param IUser $user
	 * @return boolean
	 */
	public function isTwoFactorAuthEnabledForUser(IUser $user) {
		return $this->config->getUserValue($user->getUID(), 'twofactor_sms', null) !== null;
	}

}
