<?php

declare(strict_types=1);

namespace OCA\Uploader\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller {
	public function __construct(
		IRequest             $request,
		private IUserSession $userSession,
		private IConfig      $config,
	) {
		parent::__construct('uploader', $request);
	}

	#[NoAdminRequired]
	public function get(): DataResponse {
		$userId = $this->userSession->getUser()->getUID();
		return new DataResponse([
			'upload_folder' => $this->config->getUserValue($userId, 'uploader', 'upload_folder', ''),
			'upload_group'  => $this->config->getUserValue($userId, 'uploader', 'upload_group', ''),
		]);
	}

	#[NoAdminRequired]
	public function save(string $upload_folder = '', string $upload_group = ''): DataResponse {
		$userId = $this->userSession->getUser()->getUID();
		$this->config->setUserValue($userId, 'uploader', 'upload_folder', $upload_folder);
		$this->config->setUserValue($userId, 'uploader', 'upload_group', $upload_group);
		return new DataResponse(['status' => 'ok']);
	}
}
