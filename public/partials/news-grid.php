<div class="news-grid">
<?php foreach ($latest as $news): ?>
    <article class="news-card">
        <a href="/?page=detail&id=<?=(int)$news['id']?>">
            <div class="news-image">
                <img loading="lazy" src="<?=e(asset($news['image']))?>" alt="<?=e($news['title'])?>">
            </div>
            <div class="news-info">
                <span class="category-tag"><?=e($news['category_name'] ?? 'Berita')?></span>
                <h3><?=e($news['title'])?></h3>
                <p><?=e(excerpt($news['excerpt'] ?: $news['content']))?></p>
                <small><?=e(dateID($news['created_at']))?></small>
            </div>
        </a>
    </article>
<?php endforeach; ?>
</div>
<?php if (!$latest): ?><p class="empty">Belum ada berita untuk ditampilkan.</p><?php endif; ?>
