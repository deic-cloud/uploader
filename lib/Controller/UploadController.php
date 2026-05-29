<?php

declare(strict_types=1);

namespace OCA\Uploader\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class UploadController extends Controller {
	public function __construct(
		IRequest             $request,
		private IUserSession $userSession,
		private IRootFolder  $rootFolder,
		private IConfig      $config,
		private LoggerInterface $logger,
	) {
		parent::__construct('uploader', $request);
	}

	#[NoAdminRequired]
	public function upload(
		string  $fileName,
		int     $fileIndex,
		string  $destination = '',
		string  $group       = '',
		?string $fileDone    = null,
	): JSONResponse {
		$userId  = $this->userSession->getUser()->getUID();
		$tmpDir  = $this->getTmpDir($userId);

		if (!is_dir($tmpDir)) {
			mkdir($tmpDir, 0750, true);
		}

		// Sanitise filename to prevent path traversal
		$safeFileName = basename($fileName);
		$chunkPath    = $tmpDir . $safeFileName . '_' . $fileIndex;

		$uploaded = $this->request->getUploadedFile('fileToUpload');
		if (empty($uploaded) || $uploaded['error'] !== UPLOAD_ERR_OK) {
			return new JSONResponse(['error' => 'Upload error'], 400);
		}

		if (!move_uploaded_file($uploaded['tmp_name'], $chunkPath)) {
			return new JSONResponse(['error' => 'Failed to save chunk'], 500);
		}

		if ($fileDone === null) {
			return new JSONResponse(['status' => 'chunk_received', 'index' => $fileIndex]);
		}

		// Assemble all chunks into a single temp file
		$assembledPath = $tmpDir . $safeFileName . '_assembled';
		$dst = fopen($assembledPath, 'wb');
		for ($i = 0; file_exists($tmpDir . $safeFileName . '_' . $i); $i++) {
			$src = fopen($tmpDir . $safeFileName . '_' . $i, 'rb');
			stream_copy_to_stream($src, $dst);
			fclose($src);
			unlink($tmpDir . $safeFileName . '_' . $i);
		}
		fclose($dst);

		try {
			$baseFolder = $this->resolveBaseFolder($userId, $group);
			$targetFolder = $this->ensureFolderPath($baseFolder, $destination);

			if ($targetFolder->nodeExists($safeFileName)) {
				$file = $targetFolder->get($safeFileName);
				$file->putContent(fopen($assembledPath, 'r'));
			} else {
				$file = $targetFolder->newFile($safeFileName);
				$file->putContent(fopen($assembledPath, 'r'));
			}
			unlink($assembledPath);

			$relativePath = ltrim(($destination !== '' ? $destination . '/' : '') . $safeFileName, '/');
			return new JSONResponse([
				'fileid'   => $file->getId(),
				'etag'     => $file->getEtag(),
				'mimetype' => $file->getMimetype(),
				'path'     => $relativePath,
				'group'    => $group,
			]);
		} catch (\Throwable $e) {
			$this->logger->error('uploader: assembly failed for ' . $safeFileName . ': ' . $e->getMessage());
			@unlink($assembledPath);
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}

	#[NoAdminRequired]
	public function cancel(string $fileName): DataResponse {
		$userId = $this->userSession->getUser()->getUID();
		$tmpDir = $this->getTmpDir($userId);
		$safe   = basename($fileName);
		$i      = 0;
		while (file_exists($tmpDir . $safe . '_' . $i)) {
			unlink($tmpDir . $safe . '_' . $i);
			$i++;
		}
		return new DataResponse(['deleted' => $i]);
	}

	private function getTmpDir(string $userId): string {
		$dataDir = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data');
		return $dataDir . '/' . $userId . '/cache/uploader/';
	}

	private function resolveBaseFolder(string $userId, string $group): \OCP\Files\Folder {
		if ($group !== '') {
			return $this->rootFolder->getUserFolder($userId)->get('Grants/' . $group);
		}
		return $this->rootFolder->getUserFolder($userId);
	}

	private function ensureFolderPath(\OCP\Files\Folder $base, string $path): \OCP\Files\Folder {
		if ($path === '') {
			return $base;
		}
		$current = $base;
		foreach (explode('/', trim($path, '/')) as $part) {
			if ($part === '') {
				continue;
			}
			if ($current->nodeExists($part)) {
				$node = $current->get($part);
				if (!$node instanceof \OCP\Files\Folder) {
					throw new \RuntimeException("$part is not a folder");
				}
				$current = $node;
			} else {
				$current = $current->newFolder($part);
			}
		}
		return $current;
	}
}
