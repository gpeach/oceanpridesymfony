// assets/js/gallery.js
// const galleryImages = document.querySelectorAll('.grid-item img');
//
// galleryImages.forEach((image) => {
//     image.addEventListener('load', () => {
//         image.classList.add('loaded');
//     });
// });

const fileInput = document.querySelector('#file');
if (fileInput) {
    fileInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        const fileSize = file.size / 1024 / 1024;

        if (fileSize > 200) {
            alert('File size exceeds 200MB');
            e.target.value = '';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // if (!document.body.classList.contains('gallery-page')) return;

    // Handle gallery card thumbnail image and video (hidden right now, using poster images)
    document.querySelectorAll('[data-id]').forEach(card => {
        const id = card.dataset.id;

        fetch(`/media/play/${id}`)
            .then(res => res.json())
            .then(data => {
                if (!data.url) {
                    console.error(`No video URL returned for card ID ${id}:`, data);
                    return;
                }

                const video = document.getElementById(`thumb-video-${id}`);
                const img = document.getElementById(`thumb-image-${id}`);

                if (img !== null) {
                    img.src = data.url;
                }
                if (!video) {
                    console.warn(`Missing elements for card ID ${id}`);
                    return;
                }

                video.crossOrigin = 'anonymous'; // Must be set before assigning .src
                video.src = data.url;

                video.addEventListener('loadeddata', () => {
                    video.currentTime = 0.5;
                });
            })
            .catch(err => console.error(`Error fetching signed URL for card ID ${id}:`, err));
    });

    // Handle modal image and video preview buttons
    document.querySelectorAll('[data-bs-target^="#previewModal"]').forEach(button => {
        button.addEventListener('click', () => {
            const modalId = button.getAttribute('data-bs-target');
            const id = modalId.replace('#previewModal', '');

            fetch(`/media/play/${id}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.url) {
                        console.error(`No video URL returned for modal ID ${id}:`, data);
                        return;
                    }
                    const video = document.getElementById(`modal-video-${id}`);
                    const image = document.getElementById(`modal-image-${id}`);
                    if (image !== null) {
                        image.src = data.url;
                        image.style.display = "block";
                    }
                    if (video !== null) {
                        video.src = data.url;
                    }
                    video.load();

                    // Try to autoplay (some browsers may block it unless muted or interacted)
                    video.play().catch(err => {
                        console.warn(`Autoplay blocked for modal video ID ${id}:`, err);
                    });
                })
                .catch(err => console.error(`Error fetching signed URL for modal ID ${id}:`, err));
        });
    });

    //image handler full screen display with close button
    document.querySelectorAll('[data-action="full-size"]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const id = link.dataset.id;
            const type = link.closest('[data-id]')?.dataset?.type;

            fetch(`/media/play/${id}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.url) {
                        console.error(`No full-size URL for ID ${id}:`, data);
                        return;
                    }

                    if (type === 'video') {
                        window.open(data.url, '_blank');
                        return;
                    }

                    // IMAGE fullscreen
                    const container = document.getElementById('fullscreen-image-container');
                    const img = document.getElementById('fullscreen-image');
                    const closeBtn = document.getElementById('close-fullscreen-image');

                    img.src = data.url;
                    container.style.display = 'flex';
                    img.style.display = 'block';

                    const requestFS = container.requestFullscreen || container.webkitRequestFullscreen || container.msRequestFullscreen;
                    const exitFS = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;

                    if (requestFS) requestFS.call(container);

                    const exitHandler = () => {
                        const isFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement);

                        if (!isFullscreen) {
                            img.src = '';
                            img.style.display = 'none';
                            container.style.display = 'none';

                            document.removeEventListener('fullscreenchange', exitHandler);
                            document.removeEventListener('webkitfullscreenchange', exitHandler);
                        }
                    };

                    document.addEventListener('fullscreenchange', exitHandler);
                    document.addEventListener('webkitfullscreenchange', exitHandler);

                    closeBtn.onclick = () => {
                        if (exitFS) exitFS.call(document);
                    };
                })
                .catch(err => console.error(`Error fetching full-size URL for ID ${id}:`, err));
        });
    });

    //delete button handler
    document.querySelectorAll('.delete-button').forEach(button => {
        button.addEventListener('click', async () => {
            const confirmed = confirm('Are you sure you want to delete this file?');
            if (!confirmed) return;

            const name = button.dataset.name;
            const imageId = button.dataset.imageId;

            try {
                const response = await fetch('/gallery/delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({name, imageId})
                });

                const result = await response.json();
                if (result.success) {
                    alert('✅ File deleted!');
                    location.reload();
                } else {
                    alert('❌ Error: ' + result.error);
                }
            } catch (err) {
                alert('❌ Network error');
                console.error(err);
            }
        });
    });

    //video player setup in full screen
    document.querySelectorAll('[data-action="fullscreen-preview"]').forEach(btn => {
        btn.addEventListener('click', e => {
            e.preventDefault();
            const id = btn.getAttribute('data-id');

            fetch(`/media/play/${id}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.url) {
                        console.error(`No URL returned for preview ID ${id}`, data);
                        return;
                    }

                    const player = document.getElementById('preview-fullscreen-player');
                    player.src = data.url;
                    player.style.display = 'block';
                    player.play();

                    if (player.requestFullscreen) {
                        player.requestFullscreen();
                    } else if (player.webkitRequestFullscreen) {
                        player.webkitRequestFullscreen();
                    } else if (player.msRequestFullscreen) {
                        player.msRequestFullscreen();
                    }

                    const exitHandler = () => {
                        if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                            player.pause();
                            player.style.display = 'none';
                            player.removeAttribute('src');
                            player.load();
                            document.removeEventListener('fullscreenchange', exitHandler);
                            document.removeEventListener('webkitfullscreenchange', exitHandler);
                        }
                    };

                    document.addEventListener('fullscreenchange', exitHandler);
                    document.addEventListener('webkitfullscreenchange', exitHandler);
                });
        });
    });
});
