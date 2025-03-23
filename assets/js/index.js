import { PhotoViewer } from './PhotoViewer.js';

const photoViewer = new PhotoViewer({
    mainImage: document.querySelector('.photo-carousel, .photo-carousel-landscape'),
    thumbnails: document.querySelectorAll('.photo-thumbnail'),
});

photoViewer.init();
