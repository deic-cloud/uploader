<?php

declare(strict_types=1);

namespace OCA\Uploader\Controller;

use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

class ShareController extends Controller {
	public function __construct(
		IRequest              $request,
		private IUserSession  $userSession,
		private IShareManager $shareManager,
		private IRootFolder   $rootFolder,
		private IMailer       $mailer,
		private IURLGenerator $urlGenerator,
		private IConfig       $config,
		private IAppManager   $appManager,
		private IL10N         $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct('uploader', $request);
	}

	/**
	 * Create public share links for uploaded files and optionally email them.
	 *
	 * @param array  $files      [['path'=>string, 'group'=>string, 'filename'=>string], …]
	 * @param string $recipient  Space- or comma-separated email addresses
	 * @param string $expiration ISO date string (optional)
	 * @param string $password   Share password (optional)
	 * @param bool   $requireLogin Only people with an account may open the links
	 *               (files_sharding's "Require login"; ignored without it)
	 */
	#[NoAdminRequired]
	public function share(
		array  $files     = [],
		string $recipient = '',
		string $expiration = '',
		string $password   = '',
		bool   $requireLogin = false,
	): JSONResponse {
		$requireLogin = $requireLogin && self::clusterAvailable($this->appManager);
		$user   = $this->userSession->getUser();
		$userId = $user->getUID();

		$links = [];
		foreach ($files as $f) {
			$path     = (string)($f['path']     ?? '');
			$group    = (string)($f['group']    ?? '');
			$filename = (string)($f['filename'] ?? basename($path));

			try {
				$node = $this->resolveNode($userId, $group, $path);
				$share = $this->shareManager->newShare();
				$share->setNode($node);
				$share->setShareType(IShare::TYPE_LINK);
				$share->setShareOwner($userId);
				$share->setSharedBy($userId);
				$share->setPermissions(Constants::PERMISSION_READ);

				if ($expiration !== '') {
					$share->setExpirationDate(new \DateTime($expiration));
				}
				if ($password !== '') {
					$share->setPassword($password);
				}

				$share = $this->shareManager->createShare($share);
				if ($requireLogin) {
					\OCP\Server::get(\OCA\FilesSharding\Service\LinkPolicy::class)->setRequireLogin($share, true);
				}
				$token = $share->getToken();
				$url   = $this->publicUrl($token);
				$links[] = ['path' => $path, 'url' => $url, 'filename' => $filename, 'token' => $token];
			} catch (\Throwable $e) {
				$this->logger->error('uploader: share failed for ' . $path . ': ' . $e->getMessage());
			}
		}

		$errors = [];
		if ($recipient !== '' && !empty($links)) {
			$errors = $this->sendEmail($user->getDisplayName(), $user->getEMailAddress() ?? '', $recipient, $links, $expiration, $requireLogin);
		}

		return new JSONResponse(['links' => $links, 'errors' => $errors]);
	}

	private function resolveNode(string $userId, string $group, string $path): \OCP\Files\Node {
		if ($group !== '') {
			$base = $this->rootFolder->getUserFolder($userId)->get('.uga_grants/' . $group);
		} else {
			$base = $this->rootFolder->getUserFolder($userId);
		}
		return $base->get($path);
	}

	/** @return string[] failed recipients */
	/** files_sharding present and enabled (it provides Require login and cluster-wide link addresses). */
	public static function clusterAvailable(IAppManager $appManager): bool {
		return $appManager->isEnabledForAnyone('files_sharding')
			&& class_exists(\OCA\FilesSharding\Service\LinkPolicy::class)
			&& class_exists(\OCA\FilesSharding\Service\ShardingService::class);
	}

	/** The link's lasting address: the cluster's (files_sharding), else this server's share page. */
	private function publicUrl(string $token): string {
		if (self::clusterAvailable($this->appManager)) {
			try {
				return \OCP\Server::get(\OCA\FilesSharding\Service\ShardingService::class)->publicLinkUrl($token);
			} catch (\Throwable) {
			}
		}
		return $this->urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $token]);
	}

	private function sendEmail(string $senderName, string $senderEmail, string $recipient, array $links, string $expiration, bool $requireLogin = false): array {
		$count   = count($links);
		$l       = $this->l10n;
		$subject = $count === 1
			? (string)$l->t('%s has shared a file with you', [$senderName])
			: (string)$l->t('%s has shared %d files with you', [$senderName, $count]);

		$html  = $this->buildHtmlEmail($senderName, $links, $expiration, $requireLogin);
		$plain = $this->buildPlainEmail($senderName, $links, $expiration, $requireLogin);

		$failed = [];
		$recipients = array_filter(array_map('trim', preg_split('/[\s,;]+/', $recipient) ?: []));
		foreach ($recipients as $addr) {
			if ($addr === '') {
				continue;
			}
			try {
				$msg = $this->mailer->createMessage();
				$msg->setSubject($subject);
				$msg->setFrom([$this->config->getSystemValue('mail_from_address', 'noreply') . '@' . $this->config->getSystemValue('mail_domain', 'localhost') => $senderName]);
				if ($senderEmail !== '') {
					$msg->setReplyTo([$senderEmail => $senderName]);
				}
				$msg->setTo([$addr]);
				$msg->setHtmlBody($html);
				$msg->setPlainTextBody($plain);
				$this->mailer->send($msg);
			} catch (\Throwable $e) {
				$this->logger->error('uploader: mail to ' . $addr . ' failed: ' . $e->getMessage());
				$failed[] = $addr;
			}
		}
		return $failed;
	}

	private function buildHtmlEmail(string $senderName, array $links, string $expiration, bool $requireLogin = false): string {
		$items = '';
		foreach ($links as $link) {
			$url  = htmlspecialchars($link['url'],      ENT_QUOTES);
			$name = htmlspecialchars($link['filename'], ENT_QUOTES);
			$items .= "<li><a href=\"$url\">$name</a></li>\n";
		}
		$exp = $expiration !== '' ? '<p>This share expires on ' . htmlspecialchars($expiration, ENT_QUOTES) . '.</p>' : '';
		$exp .= $requireLogin ? '<p>You need to log in to open the link' . (count($links) > 1 ? 's' : '') . '.</p>' : '';
		$who = htmlspecialchars($senderName, ENT_QUOTES);
		return "<html><body>
<p>Hi,</p>
<p>$who has shared the following file(s) with you:</p>
<ul>$items</ul>
$exp
</body></html>";
	}

	private function buildPlainEmail(string $senderName, array $links, string $expiration, bool $requireLogin = false): string {
		$lines = ["$senderName has shared the following file(s) with you:", ''];
		foreach ($links as $link) {
			$lines[] = $link['filename'] . ': ' . $link['url'];
		}
		if ($expiration !== '') {
			$lines[] = '';
			$lines[] = 'This share expires on ' . $expiration . '.';
		}
		if ($requireLogin) {
			$lines[] = '';
			$lines[] = 'You need to log in to open the link' . (count($links) > 1 ? 's' : '') . '.';
		}
		return implode("\n", $lines);
	}
}
