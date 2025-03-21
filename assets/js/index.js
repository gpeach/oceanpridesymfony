import { PhotoViewer } from './PhotoViewer.js';

const photoViewer = new PhotoViewer({
    mainImage: document.querySelector('.photo-main'),
    thumbnails: document.querySelectorAll('.photo-thumbnail'),
});

photoViewer.init();
