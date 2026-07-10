<?php
declare(strict_types=1);

use App\Core\View;
use App\Models\LocationReview;

$locationId = isset($locationId) ? (int)$locationId : 0;
$reviewSummary = isset($reviewSummary) && is_array($reviewSummary) ? $reviewSummary : [];
$visibleReviewItems = isset($visibleReviewItems) && is_array($visibleReviewItems) ? $visibleReviewItems : [];
$showReviewEntry = isset($showReviewEntry) && (bool)$showReviewEntry;
$userLocationReview = $userLocationReview ?? null;
$reviewPage = isset($reviewPage) ? (int)$reviewPage : 1;
$totalReviewPages = isset($totalReviewPages) ? (int)$totalReviewPages : 1;

$reviewCount = (int)($reviewSummary['review_count'] ?? 0);
$averageRating = (float)($reviewSummary['average_rating'] ?? 0);

$getPublicReviewerName = static function(?array $user): string {
    if($user === null){
        return '已注销用户';
    }

    $username = trim((string)($user['username'] ?? ''));

    if($username === ''){
        return '匿名用户';
    }

    $length = mb_strlen($username, 'UTF-8');

    if($length <= 1){
        return $username . '***';
    }

    if($length === 2){
        return mb_substr($username, 0, 1, 'UTF-8') . '***';
    }

    return mb_substr($username, 0, 1, 'UTF-8')
        . '***'
        . mb_substr($username, -1, 1, 'UTF-8');
};

$buildReviewPageUrl = static function(int $locationId, int $reviewPage): string {
    return 'detail.php?id='
        . urlencode((string)$locationId)
        . '&review_page='
        . urlencode((string)$reviewPage)
        . '#location-reviews';
};
?>

<section class="reviews" id="location-reviews">
    <div class="reviews-head">
        <div>
            <h3>站点评价</h3>
            <p>以下评价来自在该站点完成过充电的用户。</p>
        </div>

        <?php if($showReviewEntry): ?>
            <a
                class="primary-button"
                href="review.php?id=<?= View::escape((string)$locationId) ?>"
            >
                <?= $userLocationReview instanceof LocationReview ? '修改我的评价' : '提交站点评价' ?>
            </a>
        <?php endif; ?>
    </div>

    <div class="review-summary">
        <div class="score-card">
            <p class="score-main">
                <?= View::escape(number_format($averageRating, 2)) ?>
            </p>

            <div class="review-stars">
                <?php
                $roundedAverageRating = (int)round($averageRating);
                $roundedAverageRating = max(0, min(5, $roundedAverageRating));
                ?>

                <?= View::escape(str_repeat('★', $roundedAverageRating)) ?>
                <?= View::escape(str_repeat('☆', 5 - $roundedAverageRating)) ?>
            </div>

            <p class="score-count">
                共 <?= View::escape((string)$reviewCount) ?> 条公开评价
            </p>
        </div>

        <div class="rating-list">
            <?php for($ratingValue = 5; $ratingValue >= 1; $ratingValue--): ?>
                <?php
                $countKey = 'rating_' . $ratingValue . '_count';
                $ratingCount = (int)($reviewSummary[$countKey] ?? 0);
                $ratingPercent = $reviewCount > 0
                    ? round($ratingCount / $reviewCount * 100)
                    : 0;
                ?>

                <div class="rating-row">
                    <span><?= View::escape((string)$ratingValue) ?>星</span>

                    <div class="rating-bar">
                        <div
                            class="rating-fill"
                            style="width: <?= View::escape((string)$ratingPercent) ?>%;"
                        ></div>
                    </div>

                    <span><?= View::escape((string)$ratingCount) ?>条</span>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <?php if($visibleReviewItems === []): ?>
        <div class="empty-state">
            当前还没有公开评价。

            <?php if(!$showReviewEntry): ?>
                <br><br>
                完成该站点的充电订单后，您可以提交第一条站点评价。
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="review-list">
            <?php foreach($visibleReviewItems as $reviewItem): ?>
                <?php
                $review = $reviewItem['review'];
                $reviewUser = $reviewItem['user'];
                $replyAdmin = $reviewItem['reply_admin'];
                $replyAdminName = '';

                if($replyAdmin !== null){
                    $replyAdminName = trim((string)($replyAdmin['real_name'] ?? $replyAdmin['username'] ?? ''));
                }
                ?>

                <article class="review-card">
                    <div class="review-head">
                        <div>
                            <p class="reviewer">
                                <?= View::escape($getPublicReviewerName($reviewUser)) ?>
                            </p>

                            <p class="review-time">
                                评价时间：<?= View::escape($review->getCreatedAt()) ?>
                            </p>
                        </div>

                        <div class="review-stars">
                            <?= View::escape($review->getRatingLabel()) ?>
                        </div>
                    </div>

                    <p class="review-text">
                        <?= View::escape($review->getContent()) ?>
                    </p>

                    <?php if($review->hasAdminReply()): ?>
                        <div class="reply-box">
                            <p class="reply-title">
                                管理员回复
                                <?php if($replyAdminName !== ''): ?>
                                    ：<?= View::escape($replyAdminName) ?>
                                <?php endif; ?>
                            </p>

                            <p class="reply-text">
                                <?= View::escape($review->getAdminReply()) ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if($totalReviewPages > 1): ?>
            <nav class="review-pages" aria-label="站点评价分页">
                <?php if($reviewPage > 1): ?>
                    <a href="<?= View::escape($buildReviewPageUrl($locationId, $reviewPage - 1)) ?>">
                        上一页
                    </a>
                <?php else: ?>
                    <span>上一页</span>
                <?php endif; ?>

                <span>
                    第 <?= View::escape((string)$reviewPage) ?>
                    /
                    <?= View::escape((string)$totalReviewPages) ?> 页
                </span>

                <?php if($reviewPage < $totalReviewPages): ?>
                    <a href="<?= View::escape($buildReviewPageUrl($locationId, $reviewPage + 1)) ?>">
                        下一页
                    </a>
                <?php else: ?>
                    <span>下一页</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>