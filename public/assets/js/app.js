document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('img').forEach(img => {
        img.addEventListener('error', () => {
            if (!img.dataset.fallback) {
                img.dataset.fallback = '1';
                img.src = 'https://assets.korpolairud.com/images_default.png';
            }
        }, {once:true});
    });
});
