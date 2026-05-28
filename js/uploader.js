/* Uploader — NC34 port, vanilla JS (no jQuery dependency) */
/* global OC, t */
(function () {
	'use strict';

	// Helpers
	function el(id)    { return document.getElementById(id); }
	function qs(sel)   { return document.querySelector(sel); }
	function qsa(sel)  { return document.querySelectorAll(sel); }
	function show(id)  { var e = el(id); if (e) e.style.display = ''; }
	function hide(id)  { var e = el(id); if (e) e.style.display = 'none'; }
	function hasClass(id, cls) { var e = el(id); return e && e.classList.contains(cls); }

	var Uploader = {
		CHUNK_SIZE: 300 * 1024 * 1024,
		files:      [],
		counter:    0,
		inProgress: false,
		xhr:        null,
		tmpSent:    0,
		chunkIndex: 0,

		// Speed tracking
		speedEma:      0,   // exponential moving average bytes/sec
		speedLastTime: 0,
		speedLastBytes: 0,

		uploadUrl: '',
		cancelUrl: '',
		shareUrl:  '',

		init: function () {
			var app = el('uploader-app');
			this.uploadUrl = app.dataset.uploadUrl;
			this.cancelUrl = app.dataset.cancelUrl;
			this.shareUrl  = app.dataset.shareUrl;
			this.bindEvents();
			var params = new URLSearchParams(window.location.search);
			if (params.has('filetransfer')) {
				var info = el('uploader-info');
				if (info) { info.style.display = ''; }
			}
		},

		bindEvents: function () {
			var self = this;

			// Drag & drop
			var dz = el('uploader-dropzone');
			dz.addEventListener('dragover',  function (e) { e.preventDefault(); e.stopPropagation(); e.dataTransfer.dropEffect = 'copy'; });
			dz.addEventListener('dragenter', function (e) { e.preventDefault(); dz.classList.add('hover'); });
			dz.addEventListener('dragleave', function (e) { e.preventDefault(); dz.classList.remove('hover'); });
			dz.addEventListener('drop',      function (e) { e.preventDefault(); e.stopPropagation(); dz.classList.remove('hover'); self.handleDrop(e.dataTransfer.files); });

			el('btn-upload').addEventListener('click', function () { self.startUpload(); });
			el('btn-reset').addEventListener('click',  function () { self.clearList(); });
			el('btn-abort').addEventListener('click',  function () { self.abortAll(); });

			el('btn-folder-toggle').addEventListener('click', function () {
				var controls = el('uploader-folder-controls');
				var visible = controls.style.display !== 'none';
				controls.style.display = visible ? 'none' : '';
				this.textContent = visible ? '»' : '«';
			});

			el('btn-browse-folder').addEventListener('click', function () {
				OC.dialogs.filepicker(
					t('uploader', 'Choose upload folder'),
					function (path) { el('uploader-destdir').value = path; },
					false,
					'httpd/unix-directory',
					true,
					OC.dialogs.FILEPICKER_TYPE_CHOOSE
				);
			});

			el('btn-show-shared').addEventListener('click', function () {
				window.open(this.dataset.url, '_blank');
			});

			el('shareCheckbox').addEventListener('change', function () {
				var meta = el('share-meta-fields');
				meta.style.display = this.checked ? '' : 'none';
			});
			el('passwordCheckbox').addEventListener('change', function () {
				var pw = el('linkPassText');
				pw.style.display = this.checked ? '' : 'none';
			});
			el('expirationCheckbox').addEventListener('change', function () {
				var exp = el('expirationDate');
				exp.style.display = this.checked ? '' : 'none';
			});

			el('btn-share-uploaded').addEventListener('click', function () { self.doShare(); });

			window.addEventListener('beforeunload', function (e) {
				if (self.hasPendingShares()) {
					e.preventDefault();
					e.returnValue = t('uploader', "You have uploaded files that haven't been shared yet.");
					return e.returnValue;
				}
			});

			el('uploader-recipients').addEventListener('blur', function () {
				if (!this.validity.valid && this.value !== '') {
					OC.dialogs.alert(t('uploader', 'Please enter valid email addresses.'), t('uploader', 'Invalid email'));
				}
			});
		},

		handleDrop: function (fileList) {
			if (this.inProgress) { return; }
			el('uploader-buttons').style.display = '';
			show('btn-reset');

			var tbl = this.ensureTable();
			for (var i = 0; i < fileList.length; i++) {
				this.files.push(fileList[i]);
				this.addFileRow(tbl, fileList[i]);
			}
			el('btn-upload').disabled = false;
		},

		ensureTable: function () {
			var existing = el('uploader-tbody');
			if (existing) { return existing; }

			var tbl = document.createElement('table');
			tbl.id = 'uploader-table';
			tbl.className = 'panel';

			var thead = tbl.createTHead();
			var hr = thead.insertRow();
			['Filename', 'Sharing link', 'Progress'].forEach(function (h) {
				var th = document.createElement('th');
				th.textContent = t('uploader', h);
				hr.appendChild(th);
			});

			var tbody = document.createElement('tbody');
			tbody.id = 'uploader-tbody';
			tbl.appendChild(tbody);

			el('uploader-app').appendChild(tbl);
			return tbody;
		},

		addFileRow: function (tbody, file) {
			var tr = document.createElement('tr');
			tr.id = 'tr-' + file.name;

			// Col 1: name + size + remove button
			var td1 = document.createElement('td');
			var rm = document.createElement('button');
			rm.className = 'btn btn-flat btn-remove-file'; rm.title = t('uploader', 'Remove');
			rm.textContent = '×';
			rm.addEventListener('click', function () { Uploader.removeFile(file.name); });
			var nm = document.createElement('span'); nm.className = 'filename'; nm.textContent = file.name;
			var sz = document.createElement('span'); sz.className = 'filesize';  sz.textContent = this.humanSize(file.size);
			td1.appendChild(rm); td1.appendChild(nm); td1.appendChild(document.createElement('br')); td1.appendChild(sz);

			// Col 2: share link (filled in later)
			var td2 = document.createElement('td'); td2.className = 'sharing-link';

			// Col 3: progress + abort button
			var bar  = document.createElement('div'); bar.className  = 'progress-bar-inner'; bar.id = 'bar-' + file.name;
			var pbar = document.createElement('div'); pbar.className = 'progress-bar'; pbar.appendChild(bar);
			var pct  = document.createElement('span'); pct.className  = 'progress-pct'; pct.id  = 'pct-' + file.name; pct.textContent = '0%';
			var spd = document.createElement('span'); spd.className = 'upload-speed'; spd.id = 'speed-' + file.name;
			var abort = document.createElement('button');
			abort.className = 'btn btn-flat btn-abort-one'; abort.id = 'abort-' + file.name;
			abort.textContent = t('uploader', 'Abort'); abort.style.display = 'none';
			abort.addEventListener('click', function () { Uploader.abortOne(); });
			var td3 = document.createElement('td');
			td3.appendChild(pbar); td3.appendChild(pct); td3.appendChild(spd); td3.appendChild(abort);

			tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
			tbody.appendChild(tr);
		},

		removeFile: function (name) {
			if (this.inProgress) { return; }
			this.files = this.files.filter(function (f) { return f.name !== name; });
			var tr = el('tr-' + name);
			if (tr) { tr.parentNode.removeChild(tr); }
			if (this.files.length === 0) {
				var tbl = el('uploader-table');
				if (tbl) { tbl.parentNode.removeChild(tbl); }
				el('btn-upload').disabled = true;
				hide('btn-reset');
				el('uploader-buttons').style.display = 'none';
			}
		},

		startUpload: function () {
			if (this.inProgress || this.files.length === 0) { return; }
			if (window.Notification) { Notification.requestPermission(); }
			this.inProgress = true;
			this.counter    = 0;
			el('btn-upload').disabled = true;
			hide('btn-reset');
			show('btn-abort');
			qsa('.btn-remove-file').forEach(function (b) { b.style.display = 'none'; });
			this.uploadNext();
		},

		uploadNext: function () {
			if (this.counter >= this.files.length) { this.onAllDone(); return; }
			this.tmpSent      = 0;
			this.chunkIndex   = 0;
			this.speedEma     = 0;
			this.speedLastTime  = 0;
			this.speedLastBytes = 0;
			this.sendChunk(this.files[this.counter], 0, 0);
		},

		sendChunk: function (file, index, start) {
			var self  = this;
			var end   = Math.min(start + this.CHUNK_SIZE, file.size);
			var chunk = file.slice(start, end);
			var isLast = (end >= file.size);

			var fd = new FormData();
			fd.append('fileToUpload', chunk);
			fd.append('fileName',    file.name);
			fd.append('fileIndex',   index);
			fd.append('destination', el('uploader-destdir').value);
			fd.append('group',       el('uploader-group-select').value);
			if (isLast) { fd.append('fileDone', '1'); }

			var abortBtn = el('abort-' + file.name);
			if (abortBtn) { abortBtn.style.display = ''; }

			var xhr = new XMLHttpRequest();
			xhr.upload.addEventListener('progress', function (e) { self.onProgress(e, file); });
			xhr.addEventListener('load',  function () { self.onChunkDone(xhr, file, index, end, isLast); });
			xhr.addEventListener('error', function () { self.onUploadError(file); });
			xhr.addEventListener('abort', function () { self.onUploadAborted(file); });
			xhr.open('POST', this.uploadUrl);
			xhr.setRequestHeader('requesttoken', OC.requestToken);
			this.xhr = xhr;
			xhr.send(fd);
		},

		onProgress: function (e, file) {
			if (!e.lengthComputable) { return; }
			var totalSent = this.tmpSent + e.loaded;
			var pct = Math.min(100, Math.round(totalSent * 100 / file.size));
			var pctEl   = el('pct-'   + file.name);
			var barEl   = el('bar-'   + file.name);
			var speedEl = el('speed-' + file.name);
			if (pctEl) { pctEl.textContent = pct + '%'; }
			if (barEl) { barEl.style.width = pct + '%'; }

			// Speed (exponential moving average, α=0.3)
			var now = Date.now();
			if (this.speedLastTime > 0) {
				var dt = (now - this.speedLastTime) / 1000;
				if (dt >= 0.25) {
					var instantSpeed = (totalSent - this.speedLastBytes) / dt;
					this.speedEma = this.speedEma > 0
						? 0.3 * instantSpeed + 0.7 * this.speedEma
						: instantSpeed;
					this.speedLastTime  = now;
					this.speedLastBytes = totalSent;
					if (speedEl) { speedEl.textContent = this.humanSize(this.speedEma) + '/s'; }
				}
			} else {
				this.speedLastTime  = now;
				this.speedLastBytes = totalSent;
			}
		},

		onChunkDone: function (xhr, file, index, end, isLast) {
			if (!isLast) {
				this.tmpSent = end;
				this.chunkIndex++;
				this.sendChunk(file, this.chunkIndex, end);
				return;
			}
			var pctEl   = el('pct-'   + file.name);
			var barEl   = el('bar-'   + file.name);
			var speedEl = el('speed-' + file.name);
			var abortEl = el('abort-' + file.name);
			var trEl    = el('tr-'    + file.name);
			if (pctEl)   { pctEl.textContent = '100%'; }
			if (barEl)   { barEl.style.width = '100%'; }
			if (speedEl) { speedEl.textContent = ''; }
			if (abortEl) { abortEl.style.display = 'none'; }
			if (trEl)    { trEl.classList.add('upload-complete'); }

			try {
				var res = JSON.parse(xhr.responseText);
				if (trEl) {
					trEl.dataset.filepath = res.path  || '';
					trEl.dataset.group    = res.group || '';
					trEl.dataset.filename = file.name;
				}
			} catch (ex) {
				console.error('uploader: bad response for ' + file.name, xhr.responseText);
			}
			this.counter++;
			this.uploadNext();
		},

		onAllDone: function () {
			this.inProgress = false;
			hide('btn-abort');
			show('btn-reset');
			if (window.Notification) {
				new Notification(t('uploader', 'Upload complete'), { body: t('uploader', 'All files uploaded.') });
			}
			var shareBar = el('uploader-share-bar');
			if (shareBar) { shareBar.classList.remove('hidden'); }
			el('btn-share-uploaded').disabled = false;

			var params = new URLSearchParams(window.location.search);
			if (params.get('filetransfer') === 'true') {
				var meta = el('share-meta-fields');
				if (meta) { meta.style.display = ''; }
				var cb = el('shareCheckbox');
				if (cb) { cb.checked = true; }
			}
		},

		onUploadError: function (file) {
			var abortEl = el('abort-' + file.name);
			var trEl    = el('tr-' + file.name);
			if (abortEl) { abortEl.style.display = 'none'; }
			if (trEl)    { trEl.classList.add('upload-error'); }
		},

		onUploadAborted: function (file) {
			var fd = new FormData();
			fd.append('fileName', file.name);
			var xhr = new XMLHttpRequest();
			xhr.open('POST', this.cancelUrl);
			xhr.setRequestHeader('requesttoken', OC.requestToken);
			xhr.send(fd);
			var abortEl = el('abort-' + file.name);
			var trEl    = el('tr-' + file.name);
			if (abortEl) { abortEl.style.display = 'none'; }
			if (trEl)    { trEl.classList.add('upload-aborted'); }
		},

		abortOne: function () {
			if (this.xhr) { this.xhr.abort(); }
			this.chunkIndex = 0;
			this.tmpSent    = 0;
			this.counter++;
			this.uploadNext();
		},

		abortAll: function () {
			if (this.xhr) { this.xhr.abort(); }
			for (var i = this.counter; i < this.files.length; i++) {
				var tr = el('tr-' + this.files[i].name);
				var ab = el('abort-' + this.files[i].name);
				if (tr) { tr.classList.add('upload-aborted'); }
				if (ab) { ab.style.display = 'none'; }
			}
			this.files      = [];
			this.inProgress = false;
			hide('btn-abort');
			show('btn-reset');
		},

		clearList: function () {
			var tbl = el('uploader-table');
			if (tbl) { tbl.parentNode.removeChild(tbl); }
			this.files      = [];
			this.counter    = 0;
			this.inProgress = false;
			el('btn-upload').disabled = true;
			hide('btn-reset');
			el('uploader-buttons').style.display = 'none';
			var shareBar = el('uploader-share-bar');
			if (shareBar) { shareBar.classList.add('hidden'); }
		},

		hasPendingShares: function () {
			var rows = qsa('#uploader-table tr.upload-complete[data-filepath]');
			return rows.length > 0 && !el('btn-share-uploaded').disabled;
		},

		doShare: function () {
			var self = this;
			var files = [];
			qsa('#uploader-table tr.upload-complete').forEach(function (tr) {
				if (tr.dataset.filepath && !tr.dataset.shared) {
					files.push({
						path:     tr.dataset.filepath,
						group:    tr.dataset.group    || '',
						filename: tr.dataset.filename || '',
					});
				}
			});

			if (files.length === 0) {
				OC.dialogs.alert(t('uploader', 'No files to share.'), t('uploader', 'No uploads'));
				return;
			}

			var expEl  = el('expirationDate');
			var pwEl   = el('linkPassText');
			var payload = {
				files:      files,
				recipient:  el('uploader-recipients').value,
				expiration: (expEl  && expEl.style.display  !== 'none') ? expEl.value  : '',
				password:   (pwEl   && pwEl.style.display   !== 'none') ? pwEl.value   : '',
			};

			var msg = t('uploader',
				'Sharing via public links to unauthenticated users may not be appropriate for confidential or sensitive data.' +
				' You are solely responsible for data shared via public links.\n\nDo you wish to proceed?');

			OC.dialogs.confirm(msg, t('core', 'Confirm'), function (ok) {
				if (!ok) { return; }
				fetch(self.shareUrl, {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
					body:    JSON.stringify(payload),
				})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					(res.links || []).forEach(function (link) {
						var tr = qs('#uploader-table tr[data-filepath="' + CSS.escape(link.path) + '"]');
						if (tr) {
							tr.querySelector('td.sharing-link').innerHTML =
								'<a href="' + link.url + '" target="_blank" title="' + t('uploader', 'Open') + '">' +
								'<img src="' + OC.webroot + '/core/img/actions/public.svg" class="link-icon"></a> ' +
								'<a class="copy-link" href="' + link.url + '" title="' + t('uploader', 'Copy URL') + '">' +
								'<img src="' + OC.webroot + '/core/img/actions/clippy.svg" class="link-icon"></a>';
							tr.dataset.shared = '1';
						}
					});
					el('btn-share-uploaded').disabled = true;
				})
				.catch(function () {
					OC.dialogs.alert(t('uploader', 'Sharing failed.'), t('uploader', 'Error'));
				});
			}, true);
		},

		humanSize: function (bytes) {
			var units = ['B', 'KB', 'MB', 'GB', 'TB'];
			var i = 0;
			while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
			return Math.round(bytes * 10) / 10 + ' ' + units[i];
		}
	};

	// Copy-link click (event delegation)
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a.copy-link');
		if (!a) { return; }
		e.preventDefault();
		navigator.clipboard.writeText(a.href);
	});

	document.addEventListener('DOMContentLoaded', function () {
		if (el('uploader-app')) {
			Uploader.init();
		}
	});

}());
