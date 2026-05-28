<?php

declare(strict_types=1);

namespace OCA\Uploader\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class PageController extends Controller {
	public function __construct(
		IRequest               $request,
		private IUserSession   $userSession,
		private IConfig        $config,
		private IDBConnection  $db,
		private IURLGenerator  $urlGenerator,
	) {
		parent::__construct('uploader', $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function index(): TemplateResponse {
		$user   = $this->userSession->getUser();
		$userId = $user?->getUID() ?? '';

		$uploadFolder = $this->config->getUserValue($userId, 'uploader', 'upload_folder', '');
		$uploadGroup  = $this->config->getUserValue($userId, 'uploader', 'upload_group', '');
		$masterUrl    = $this->config->getSystemValue('masterurl', $this->urlGenerator->getAbsoluteURL('/'));

		$memberGroups = $this->getMemberGroups($userId);

		return new TemplateResponse('uploader', 'main', [
			'upload_folder' => $uploadFolder,
			'upload_group'  => $uploadGroup,
			'masterurl'     => $masterUrl,
			'member_groups' => $memberGroups,
			'upload_url'    => $this->urlGenerator->linkToRoute('uploader.Upload.upload'),
			'cancel_url'    => $this->urlGenerator->linkToRoute('uploader.Upload.cancel'),
			'share_url'     => $this->urlGenerator->linkToRoute('uploader.Share.share'),
			'shared_files_url' => $this->urlGenerator->linkToRoute('files.view.index') . '?dir=%2F&view=sharingout&links_only=true&files_only=true',
		], 'user');
	}

	private function getMemberGroups(string $userId): array {
		if ($userId === '') {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.gid')
				->from('uga_group_members', 'm')
				->innerJoin('m', 'uga_groups', 'g', $qb->expr()->eq('m.gid', 'g.gid'))
				->where($qb->expr()->eq('m.uid', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('m.status', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->neq('g.storage_grant', $qb->createNamedParameter('')));
			$rows = $qb->executeQuery()->fetchAllAssociative();
			return array_map(fn($r) => ['gid' => $r['gid']], $rows);
		} catch (\Throwable) {
			return [];
		}
	}
}
