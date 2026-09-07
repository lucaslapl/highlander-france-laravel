/* Gestion des maps ETF2L (panel admin) : drag & drop .bsp, upload avec
   barre de progression, ajout / édition ajax. */

(function () {
    'use strict';

    const MAX_BSP_MB = 100;
    const MAX_IMG_MB = 10;

    const dropzone = document.getElementById('bsp-dropzone');
    const bspInput = document.getElementById('bsp-input');
    const pendingInput = document.getElementById('bsp_pending');
    const progressWrap = document.getElementById('bsp-progress');
    const progressFill = document.getElementById('bsp-progress-fill');
    const progressText = document.getElementById('bsp-progress-text');
    const bspStatus = document.getElementById('bsp-status');
    const addForm = document.getElementById('map-add-form');
    const addError = document.getElementById('map-add-error');
    const submitBtn = document.getElementById('map-submit-btn');

    let pendingName = null;

    /* ─── Token CSRF ────────────────────────────────────────────────────── */
    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) { return meta.getAttribute('content'); }
        const hidden = document.querySelector('input[name="_token"]');
        return hidden ? hidden.value : '';
    }

    /* ─── Drag & drop du .bsp ──────────────────────────────────────────── */
    function handleFile(file) {
        if (!file) { return; }
        if (!/\.bsp$/i.test(file.name)) {
            setStatus('danger', 'Ce fichier n\'est pas un .bsp.');
            return;
        }
        if (file.size > MAX_BSP_MB * 1024 * 1024) {
            setStatus('danger', 'Le fichier dépasse ' + MAX_BSP_MB + ' Mo.');
            return;
        }

        // Pré-remplit le nom de map à partir du nom du fichier.
        const nameInput = document.getElementById('map-name');
        const labelInput = document.getElementById('map-label');
        const base = file.name.replace(/\.bsp$/i, '');
        if (nameInput && !nameInput.value) { nameInput.value = base; }
        if (labelInput && !labelInput.value) { labelInput.value = base; }

        uploadBsp(file);
    }

    function uploadBsp(file) {
        const fd = new FormData();
        fd.append('bsp', file);
        fd.append('_token', csrfToken());

        showProgress(true, 0, 'Envoi en cours…');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/admin/maps/upload-pending');

        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                showProgress(true, pct, pct + ' % — ' + formatBytes(e.loaded) + ' / ' + formatBytes(e.total));
            }
        });

        xhr.addEventListener('load', function () {
            showProgress(false);
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        pendingName = data.name;
                        if (pendingInput) { pendingInput.value = pendingName; }
                        setStatus('success', 'Fichier « ' + file.name + ' » téléversé (' + formatBytes(file.size) + ').'
                            + (nameInputEmpty() ? ' Le nom a été pré-rempli.' : ''));
                        return;
                    }
                } catch (err) { /* fallthrough */ }
                setStatus('danger', 'Échec de l\'envoi du fichier.');
            } else if (xhr.status === 413) {
                setStatus('danger', 'Fichier trop volumineux pour le serveur.');
            } else {
                setStatus('danger', 'Erreur serveur (' + xhr.status + ').');
            }
        });

        xhr.addEventListener('error', function () {
            showProgress(false);
            setStatus('danger', 'Erreur réseau pendant l\'envoi.');
        });

        xhr.send(fd);
    }

    function setStatus(type, message) {
        if (!bspStatus) { return; }
        bspStatus.className = 'adm-maps-file-status adm-maps-file-status--' + type;
        bspStatus.textContent = message;
    }

    function showProgress(visible, pct, text) {
        if (!progressWrap || !progressFill || !progressText) { return; }
        progressWrap.hidden = !visible;
        if (pct !== undefined) { progressFill.style.width = pct + '%'; }
        if (text !== undefined) { progressText.textContent = text; }
    }

    function formatBytes(bytes) {
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1) + ' Mo'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(1) + ' Ko'; }
        return bytes + ' o';
    }

    function nameInputEmpty() {
        const n = document.getElementById('map-name');
        return !n || !n.value;
    }

    /* événements */
    if (dropzone) {
        dropzone.addEventListener('click', function (e) {
            if (e.target.closest('input')) { return; }
            if (bspInput) { bspInput.click(); }
        });

        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('is-dragging');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            const file = Array.from(e.dataTransfer ? e.dataTransfer.files : []).find(function (f) {
                return /\.bsp$/i.test(f.name);
            });
            if (file) { handleFile(file); }
        });
    }
    if (bspInput) {
        bspInput.addEventListener('change', function () {
            const file = bspInput.files ? bspInput.files[0] : null;
            if (file) { handleFile(file); }
        });
    }

    /* ─── Ajout / édition : envoi ajax multipart ──────────────────────── */
    function formAjaxOnSubmit(form, errEl, bspRequired) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Pour le formulaire d'ajout uniquement : un .bsp est obligatoire.
            if (bspRequired && !pendingName && (!bspInput || !bspInput.files.length)) {
                setStatus('danger', 'Ajoutez d\'abord le fichier .bsp (drag & drop).');
                return;
            }

            const fd = new FormData(form);
            if (bspRequired) {
                // Le .bsp passe par le dossier d'attente (drag & drop) : on ne
                // renvoie pas le champ brut, pour éviter un double traitement.
                if (pendingName) {
                    fd.set('bsp_pending', pendingName);
                    fd.delete('bsp');
                }
            }

            const btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }

            submitForm(fd, form.action)
                .then(function (data) {
                    if (!data) { return; }
                    if (data.ok && data.success) {
                        window.location.reload();
                    } else {
                        if (errEl) {
                            errEl.textContent = data.message || 'Erreur inconnue.';
                        }
                        if (btn) { btn.disabled = false; }
                    }
                })
                .catch(function () {
                    if (errEl) { errEl.textContent = 'Erreur réseau durant l\'enregistrement.'; }
                    if (btn) { btn.disabled = false; }
                });
        });
    }

    function submitForm(fd, url) {
        return fetch(url, {
            method: 'POST',
            body: fd,
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
        })
            .then(function (res) {
                if (res.redirected) {
                    window.location.href = res.url;
                    return null;
                }
                return res.json().then(function (data) {
                    return { ok: res.ok, success: data.success, message: data.message };
                });
            });
    }

    if (addForm && addError) {
        formAjaxOnSubmit(addForm, addError, true);
    }

    /* ─── Édition (modal) ──────────────────────────────────────────────── */
    const modal = document.getElementById('map-edit-modal');
    const editForm = document.getElementById('map-edit-form');
    const editError = document.getElementById('map-edit-error');

    let editingId = null;

    document.querySelectorAll('.adm-maps-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            editingId = btn.getAttribute('data-id');
            document.getElementById('edit-label').value = btn.getAttribute('data-label') || '';
            document.getElementById('edit-category').value = btn.getAttribute('data-category') || '6v6';
            document.getElementById('edit-is-active').checked = btn.getAttribute('data-active') === '1';
            if (editError) { editError.textContent = ''; }
            if (editForm) { editForm.action = '/admin/maps/' + editingId + '/update'; }
            if (modal) {
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
            }
        });
    });

    function closeEdit() {
        if (modal) { modal.hidden = true; }
        document.body.style.overflow = '';
    }
    const closeBtn = document.getElementById('map-edit-close');
    const cancelBtn = document.getElementById('map-edit-cancel');
    if (closeBtn) { closeBtn.addEventListener('click', closeEdit); }
    if (cancelBtn) { cancelBtn.addEventListener('click', closeEdit); }
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) { closeEdit(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) { closeEdit(); }
        });
    }
    if (editForm && editError) {
        formAjaxOnSubmit(editForm, editError, false);
    }
})();