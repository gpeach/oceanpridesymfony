import { v4 as uuidv4 } from 'uuid';

export async function uploadToS3(file, type, onProgress) {
    const extension = file.name.split('.').pop().toLowerCase();
    const uuidName = `${uuidv4()}.${extension}`;

    const response = await fetch('/gallery/s3put', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: uuidName, type })
    });

    const data = await response.json();
    if (!data.url) {
        throw new Error('Missing PUT URL');
    }

    await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', data.url, true);
        xhr.setRequestHeader('Content-Type', type);

        xhr.upload.addEventListener('progress', function (e) {
            if (e.lengthComputable && typeof onProgress === 'function') {
                const percent = Math.round((e.loaded / e.total) * 100);
                onProgress(percent);
            }
        });

        xhr.onload = function () {
            if (xhr.status === 200) resolve();
            else reject(new Error('PUT upload failed'));
        };

        xhr.onerror = function () {
            reject(new Error('Network error during PUT upload'));
        };

        xhr.send(file);
    });

    return { filename: data.filename, storage: 's3' };
}

export async function saveToDatabase({ name, filename, type, storage }) {
    const response = await fetch('/gallery/metadata', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, file_path: filename, type, cloud_storage_type: storage })
    });

    return response.json();
}

// Upload handling
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('uploadForm');
    const storageType = document.body.dataset.storage;

    const progressBar = document.querySelector('.progress-bar');
    const progressWrapper = document.getElementById('uploadProgress');
    const result = document.getElementById('uploadResult');

    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const fileInput = form.elements['form[file]'];
        const nameInput = form.elements['form[name]'];
        const file = fileInput.files[0];
        const name = nameInput.value;
        const type = file.type;

        if (storageType === 's3') {
            try {
                progressWrapper.classList.remove('d-none');
                progressBar.style.width = '0%';
                progressBar.innerText = '0%';
                progressWrapper.style.display = 'block'; // force it

                const uploadInfo = await uploadToS3(file, type, (percent) => {
                    progressBar.style.width = percent + '%';
                    progressBar.innerText = percent + '%';
                });
                await saveToDatabase({
                    name,
                    filename: uploadInfo.filename,
                    type,
                    storage: uploadInfo.storage
                });

                result.classList.remove('d-none', 'alert-danger');
                result.classList.add('alert-success');
                result.innerHTML = '✅ Upload complete! <a href="/gallery" class="text-decoration-underline text-reset">View Gallery</a>';
                form.reset();
                progressBar.style.width = '0%';
                progressBar.innerText = '0%';
            } catch (err) {
                console.error('Upload failed', err);
                result.classList.remove('d-none', 'alert-success');
                result.classList.add('alert-danger');
                result.textContent = '❌ Upload failed.';
            }
        } else {
            // your original Dropbox flow
            const formData = new FormData(form);
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action'), true);

            xhr.upload.addEventListener('progress', function (e) {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    progressWrapper.classList.remove('d-none');
                    progressBar.style.width = percent + '%';
                    progressBar.innerText = percent + '%';
                }
            });

            xhr.onload = function () {
                if (xhr.status === 200) {
                    result.classList.remove('d-none', 'alert-danger');
                    result.classList.add('alert-success');
                    result.innerHTML = '✅ Upload complete! <a href="/gallery" class="text-decoration-underline text-reset">View Gallery</a>';
                    form.reset();
                    progressBar.style.width = '0%';
                    progressBar.innerText = '0%';
                } else {
                    result.classList.remove('d-none', 'alert-success');
                    result.classList.add('alert-danger');
                    result.textContent = '❌ Upload failed.';
                }
            };

            xhr.onerror = function () {
                result.classList.remove('d-none', 'alert-success');
                result.classList.add('alert-danger');
                result.textContent = '❌ Network error during upload.';
            };

            xhr.send(formData);
        }
    });
});
