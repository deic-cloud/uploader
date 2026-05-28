<?php

declare(strict_types=1);

namespace OCA\Uploader\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
	public function __construct(
		private IConfig      $config,
		private IUserSession $userSession,
	) {
	}

	public function getForm(): TemplateResponse {
		$userId       = $this->userSession->getUser()?->getUID() ?? '';
		$uploadFolder = $this->config->getUserValue($userId, 'uploader', 'upload_folder', '');
		$uploadGroup  = $this->config->getUserValue($userId, 'uploader', 'upload_group', '');

		return new TemplateResponse('uploader', 'personalsettings', [
			'upload_folder' => $uploadFolder,
			'upload_group'  => $uploadGroup,
		], 'blank');
	}

	public function getSection(): string {
		return 'uploader';
	}

	public function getPriority(): int {
		return 50;
	}
}
