<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019, Roeland Jago Douma <roeland@famdouma.nl>
 * @copyright Copyright (c) 2024, Joshua Richards (<josh.t.richards@gmail.com>)
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\SuspiciousLogin\Listener;

use Exception;
use OCP\IConfig;
use OCA\SuspiciousLogin\Event\SuspiciousLoginEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;

class LoginMailListener implements IEventListener {

	/** @var ILogger */
	private $logger;
	
	/** @var IMailer */
	private $mailer;
	
	/** @var IUserManager */
	private $userManager;
	
	/** @var IL10N */
	private $l;
	
	/** @var IConfig */
	protected $config;

	public function __construct(ILogger $logger, IMailer $mailer, IUserManager $userManager, IL10N $l, IConfig $config) {
		$this->logger = $logger;
		$this->mailer = $mailer;
		$this->userManager = $userManager;
		$this->l = $l;
		$this->config = $config;
	}

	public function handle(Event $event): void {
		if (!($event instanceof SuspiciousLoginEvent)) {
			return;
		}

		$uid = $event->getUid();
		$user = $this->userManager->get($uid);
		if ($user === null) {
			$this->logger->warning("not sending suspicious login email because user <$uid> does not exist (anymore)");
			return;
		}
		if ($user->getEMailAddress() === null) {
			$this->logger->info("not sending a suspicous login email because user <$uid> has no email set");
			return;
		}

		try {
			$this->mailer->send(
				$this->getMail($event, $user)
			);
		} catch (Exception $e) {
			$this->logger->logException($e, [
				'message' => "Could not send suspicious login email to <$uid>",
				'level' => ILogger::ERROR,
			]);
		}
	}

	private function getMail(SuspiciousLoginEvent $event, IUser $user): IMessage {
		$suspiciousIp = $event->getIp() ?? '';
		$addButton = $this->config->getAppValue('suspicious_login', 'show_more_info_button', '1') === "1";

		$message = $this->mailer->createMessage();
		$emailTemplate = $this->mailer->createEMailTemplate('suspiciousLogin.suspiciousLoginDetected');

		$emailTemplate->setSubject($this->l->t('New login location detected'));
		$emailTemplate->addHeader();
		$emailTemplate->addHeading(
			$this->l->t('New login location detected')
		);
		
		$additionalText = '';
		// Add explanation for more information about the IP-address (if enabled)
		if ($addButton) {
			$additionalText = ' ' . $this->l->t('You can get more info by pressing the button which will open %s and show info about the suspicious IP-address.', 'https://iplookup.flagfox.net');
		}
		$emailTemplate->addBodyText(
			$this->l->t('A new login into your account was detected. The IP address %s was classified as suspicious. If this was you, you can ignore this message. Otherwise you should change your password.', $suspiciousIp) . $additionalText
		);
		// Add button for more information about the IP-address (if enabled)
		if ($addButton) {
			$link = 'https://iplookup.flagfox.net/?ip=' . $suspiciousIp;
			$emailTemplate->addBodyButton(
				htmlspecialchars($this->l->t('More information ↗')),
				$link,
				false
			);
		}
		$emailTemplate->addFooter();
		$message->setTo([$user->getEMailAddress()]);
		$message->useTemplate($emailTemplate);

		return $message;
	}
}
