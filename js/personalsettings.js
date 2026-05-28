/* global OC, t */
document.addEventListener('DOMContentLoaded', function () {
	var btn = document.getElementById('btn-uploader-settings-save');
	if (!btn) { return; }
	btn.addEventListener('click', function () {
		var status = document.getElementById('uploader-settings-status');
		fetch(OC.generateUrl('/apps/uploader/settings'), {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
			body:    JSON.stringify({
				upload_folder: document.getElementById('uploader-default-folder').value,
				upload_group:  document.getElementById('uploader-default-group').value,
			}),
		})
		.then(function (r) { return r.json(); })
		.then(function () {
			if (status) {
				status.textContent = t('uploader', 'Saved');
				status.style.display = '';
				setTimeout(function () { status.style.display = 'none'; }, 2000);
			}
		})
		.catch(function () {
			if (status) {
				status.textContent = t('uploader', 'Error saving settings');
				status.style.display = '';
			}
		});
	});
});
