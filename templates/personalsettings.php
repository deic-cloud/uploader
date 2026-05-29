<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addScript('uploader', 'personalsettings');
?>
<div id="uploader-personal-settings" class="section">
	<h3><?php p($l->t('Uploader defaults')); ?></h3>
	<p>
		<label for="uploader-default-folder"><?php p($l->t('Default upload folder')); ?></label>
		<input type="text" id="uploader-default-folder"
			value="<?php p($_['upload_folder']); ?>"
			placeholder="<?php p($l->t('e.g. Uploads')); ?>">
	</p>
	<p>
		<label for="uploader-default-group"><?php p($l->t('Default group')); ?></label>
		<input type="text" id="uploader-default-group"
			value="<?php p($_['upload_group']); ?>"
			placeholder="<?php p($l->t('Leave empty for home storage')); ?>">
	</p>
	<button class="btn btn-primary" id="btn-uploader-settings-save">
		<?php p($l->t('Save')); ?>
	</button>
	<span id="uploader-settings-status" style="margin-left:8px;display:none;"></span>
</div>
