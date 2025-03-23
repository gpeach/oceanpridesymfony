// assets/js/gallery.js
const galleryImages = document.querySelectorAll('.grid-item img');

galleryImages.forEach((image) => {
    image.addEventListener('load', () => {
        image.classList.add('loaded');
    });
});

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

