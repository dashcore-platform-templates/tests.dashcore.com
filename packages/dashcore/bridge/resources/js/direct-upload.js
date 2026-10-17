// dashcore/bridge — uploads that go from the browser straight to storage,
// never through the app: Cloudflare refuses any request over 100 MB before
// it reaches us. The app only signs the way (a small JSON request, answered
// with DirectUpload::target) and checks the file afterwards
// (DirectUpload::verify).
//
//   import { requestUploadTarget, putFile, UploadError } from '../../vendor/dashcore/bridge/resources/js/direct-upload.js';
//
//   const target = await requestUploadTarget('/upload-url', csrf, { name: file.name, size: file.size });
//   await putFile(target, file, (fraction) => showProgress(fraction));
//   // then submit target.path with the form

export class UploadError extends Error {}

// Ask the app for a signed URL. A refusal (too large, wrong type) comes back
// as the reason, so it can be shown as it is.
export async function requestUploadTarget(url, csrf, details) {
    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(details),
        });
    } catch (e) {
        throw new UploadError('Upload failed. Please check your connection.');
    }

    if (response.status === 419) {
        throw new UploadError('Your session expired while you were filling in the form. Please refresh the page and try again.');
    }

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const reason = Object.values(body.errors || {})[0]?.[0];
        throw new UploadError(response.status === 422 && reason ? reason : 'Upload failed. Please try again.');
    }

    return body;
}

// PUT the file to the signed URL with exactly the headers that were signed.
export function putFile(target, file, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', target.url);
        Object.entries(target.headers || {}).forEach(([name, value]) => xhr.setRequestHeader(name, value));

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total);
        };
        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300
            ? resolve()
            : reject(new UploadError('Upload failed. Please try again.')));
        xhr.onerror = () => reject(new UploadError('Upload failed. Please check your connection.'));

        xhr.send(file);
    });
}
