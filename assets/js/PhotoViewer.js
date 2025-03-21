export class PhotoViewer {
    constructor(options) {
        this.mainImage = options.mainImage;
        this.thumbnails = options.thumbnails;
    }

    init() {
        this.thumbnails.forEach((thumbnail) => {
            thumbnail.addEventListener('click', () => {
                this.mainImage.src = thumbnail.src;
            });
        });
    }
}
