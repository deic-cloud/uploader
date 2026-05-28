<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('uploader', 'style');
\OCP\Util::addScript('uploader', 'uploader');
?>

<div id="app-content">
<div id="uploader-app" class="app-uploader"
	data-upload-url="<?php p($_['upload_url']); ?>"
	data-cancel-url="<?php p($_['cancel_url']); ?>"
	data-share-url="<?php p($_['share_url']); ?>"
	data-upload-folder="<?php p($_['upload_folder']); ?>"
	data-upload-group="<?php p($_['upload_group']); ?>"
	data-masterurl="<?php p($_['masterurl']); ?>">

	<div id="uploader-share-bar" class="uploader-share hidden">
		<div class="uploader-share-top-row">
			<label class="bold" for="shareCheckbox"><?php p($l->t('Share uploaded files')); ?></label>
			<input type="checkbox" id="shareCheckbox">
			<button class="btn btn-primary uploader-share-btn" id="btn-share-uploaded" disabled>
				<?php p($l->t('Share')); ?>
			</button>
			<button class="btn btn-flat uploader-show-shared" id="btn-show-shared"
					data-url="<?php p($_['shared_files_url']); ?>">
				<?php p($l->t('Show already shared files')); ?>
			</button>
		</div>
		<div class="uploader-share-meta" id="share-meta-fields" style="display:none;">
			<div class="uploader-share-row">
				<label for="uploader-recipients"><?php p($l->t('Recipients')); ?></label>
				<span class="info" title="<?php p($l->t('Space- or comma-separated email addresses')); ?>">ⓘ</span>
				<input type="email" multiple id="uploader-recipients" autocomplete="off" placeholder="user@example.com">
			</div>
			<div class="uploader-share-row">
				<label><?php p($l->t('Expiration')); ?></label>
				<input type="checkbox" id="expirationCheckbox">
				<input type="date" id="expirationDate" style="display:none;" autocomplete="off">
			</div>
			<div class="uploader-share-row">
				<label><?php p($l->t('Password protect')); ?></label>
				<input type="checkbox" id="passwordCheckbox">
				<input type="password" id="linkPassText" autocomplete="off" placeholder="<?php p($l->t('Password')); ?>" style="display:none;">
			</div>
		</div>
	</div>

	<div id="uploader-folder-bar">
		<span class="bold"><?php p($l->t('Upload destination')); ?></span>
		<span class="info" title="<?php p($l->t('Target folder. Leave empty for home root.')); ?>">ⓘ</span>
		<button class="btn btn-flat btn-folder-toggle" id="btn-folder-toggle" title="<?php p($l->t('Choose upload folder')); ?>">»</button>
		<div id="uploader-folder-controls" style="display:none;">
			<input type="text" id="uploader-destdir"
				value="<?php p($_['upload_folder']); ?>"
				placeholder="<?php p($l->t('Folder')); ?>"
				autocomplete="off">
			<button class="btn btn-flat" id="btn-browse-folder"><?php p($l->t('Browse')); ?></button>
			<select id="uploader-group-select">
				<option value="" <?php if ($_['upload_group'] === '') { echo 'selected'; } ?>>
					<?php p($l->t('Home')); ?>
				</option>
				<?php foreach ($_['member_groups'] as $group) {
					$sel = ($_['upload_group'] === $group['gid']) ? ' selected' : '';
					echo '<option value="' . \OCP\Util::sanitizeHTML($group['gid']) . '"' . $sel . '>'
						. \OCP\Util::sanitizeHTML($group['gid']) . '</option>';
				} ?>
			</select>
		</div>
	</div>

	<div id="uploader-dropzone">
		<div id="uploader-dropzone-inner">
			<?php p($l->t('Drag & drop files here')); ?>
		</div>
	</div>

	<div id="uploader-buttons" style="display:none;">
		<button class="btn btn-primary" id="btn-upload" disabled><?php p($l->t('Upload')); ?></button>
		<button class="btn btn-flat"    id="btn-reset"  style="display:none;"><?php p($l->t('Clear list')); ?></button>
		<button class="btn btn-flat"    id="btn-abort"  style="display:none;"><?php p($l->t('Abort all')); ?></button>
	</div>

	<div id="uploader-info" style="display:none;">
		<p><?php p($l->t('This service allows ScienceData users to send large files to specific recipients — both other users and externals. Simply drag and drop files above, click "Upload", fill in recipients\' email addresses and click "Share".')); ?></p>
	</div>
</div>
</div>
