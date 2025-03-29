// assets/js/gallery.js
// const galleryImages = document.querySelectorAll('.grid-item img');
//
// galleryImages.forEach((image) => {
//     image.addEventListener('load', () => {
//         image.classList.add('loaded');
//     });
// });

const fileInput = document.querySelector('#file');
if(fileInput) {
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
    if (!document.body.classList.contains('gallery-page')) return;

    // Handle gallery card thumbnails
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

                if(img !== null){
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

                // video.addEventListener('seeked', () => {
                //     try {
                //         img.src = canvas.toDataURL('image/jpeg');
                //     } catch (err) {
                //         console.warn(`Canvas export blocked for card ID ${id}:`, err);
                //         // Leave placeholder image or fallback visual
                //     }
                // });
            })
            .catch(err => console.error(`Error fetching signed URL for card ID ${id}:`, err));
    });

    // Handle modal video previews
    document.querySelectorAll('[data-bs-target^="#previewModal"]').forEach(button => {
        button.addEventListener('click', () => {
            const modalId = button.getAttribute('data-bs-target');
            const id = modalId.replace('#previewModal', '');

            //if (!video) return;

            fetch(`/media/play/${id}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.url) {
                        console.error(`No video URL returned for modal ID ${id}:`, data);
                        return;
                    }
                    const video = document.getElementById(`modal-video-${id}`);
                    const image = document.getElementById(`modal-image-${id}`);
                    if(image !== null){
                        image.src = data.url;
                        image.style.display="block";
                    }
                    if(video !== null) {
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
    //Handle "Full Size" button for videos
    // document.querySelectorAll('[data-action="full-size"]').forEach(link => {
    //     link.addEventListener('click', (e) => {
    //         e.preventDefault();
    //         const id = link.dataset.id;
    //
    //         fetch(`/media/play/${id}`)
    //             .then(res => res.json())
    //             .then(data => {
    //                 if (!data.url) {
    //                     console.error(`No full-size URL for ID ${id}:`, data);
    //                     return;
    //                 }
    //
    //                 window.open(data.url, '_blank');
    //             })
    //             .catch(err => console.error(`Error fetching full-size URL for ID ${id}:`, err));
    //     });
    // });

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

                    // Fullscreen image logic
                    const container = document.getElementById('fullscreen-image-container');
                    const img = document.getElementById('fullscreen-image');

                    img.src = data.url;
                    img.style.display = 'block';
                    container.style.display = 'flex';

                    const requestFS = container.requestFullscreen || container.webkitRequestFullscreen || container.msRequestFullscreen;
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
                })
                .catch(err => console.error(`Error fetching full-size URL for ID ${id}:`, err));
        });
    });

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
